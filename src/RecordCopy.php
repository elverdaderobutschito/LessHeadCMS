<?php
/*
 * LessHeadCMS - headless CMS generator (PlantUML -> SQLite -> REST API)
 * Copyright (C) 2026 Udo Butschinek
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace LessHeadCMS;

use PDO;

/**
 * Deep copy of a record: the row itself and all parts of its compositions (`*--`), recursively over every level, with
 * their media links (same media, same order, metadata of the link) and n:n links. The caller runs it inside ONE
 * transaction and writes versions/webhooks itself (Cms::translate()); nothing here commits.
 *
 * Generic on purpose - "Übersetzung anlegen" uses it with a language suffix and redirects, a later "Duplizieren mit Teilen"
 * only needs other overrides and no redirect:
 *
 * - Root: all own fields, then $overrides (column => value). Parts: all own fields, then the values of $partOverrides
 *   (fn(array $e): array, e.g. visibility false - "Übersetzung anlegen" creates the parts as drafts as well); the FK of the
 *   composition points at the copy of their whole. System fields (created_*, updated_*) are set anew, a workflow starts in its initial state.
 * - Order (sort): parts whose order is grouped by their whole keep their values (same order in the new whole); every other
 *   copied row with a sort field goes to the end of its group, as on creation.
 * - References between copied rows (e.g. the parent item of a navigation item, a tile linking to the page that is copied)
 *   point at the copy. Other n:1/1:1 references go through $redirect (null = unchanged).
 * - n:n links: links to a copied row point at the copy, the others go through $redirectLink (null = unchanged).
 * - {unique} groups (the unnamed one and every named {unique: name}): if the combination of a group is taken, the first text
 *   field of that group gets "-<suffix>" ("-en", then "-en-2", ...); without a text field -> 409 (the caller's transaction rolls everything back). A 1:1 reference that is taken is
 *   left empty if optional (summary 'dropped'), otherwise 409.
 *
 * Not copied: versions, follow-ups, rows that only reference the record without being its parts.
 */
final class RecordCopy
{
    /** Field types that get the suffix on a {unique} collision */
    private const TEXT_TYPES = ['string', 'text'];

    /** @var PDO */
    private $pdo;
    /** @var array */
    private $model;
    /** @var array|null */
    private $user;
    /** @var string */
    private $suffix;
    /** @var callable|null fn(array $target, int $id): array{0:int,1:string} - new id and 'redirected'|'untranslated'|'same' */
    private $redirect;
    /** @var callable|null fn(array $e, array $m, int $other): array{0:?int,1:string} - new id (null = drop) and status */
    private $redirectLink;
    /** @var callable fn(array $e, array $fields, array $values, int $existing, ?string $group): ApiException - 409 of a taken combination */
    private $duplicate;

    public function __construct(PDO $pdo, array $model, ?array $user, string $suffix, callable $duplicate, ?callable $redirect = null,
        ?callable $redirectLink = null)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->user = $user;
        $this->suffix = $suffix;
        $this->duplicate = $duplicate;
        $this->redirect = $redirect;
        $this->redirectLink = $redirectLink;
    }

    /** Tables of all parts below $table (compositions, recursively), in model order - e.g. for the permission check */
    public static function partTables(array $model, string $table): array
    {
        $out = [];
        $queue = [$table];
        while ($queue) {
            $t = array_shift($queue);
            foreach (Compositions::of($model, $t) as $g) {
                foreach (array_keys($g['parts']) as $part) {
                    $part = (string) $part;
                    if (!in_array($part, $out, true) && $part !== $table) {
                        $out[] = $part;
                        $queue[] = $part;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array $overrides column => value for the root row (applied after the copied values, never redirected)
     * @param callable|null $partOverrides fn(array $e): array column => value for every copied part of class $e (all levels)
     * @return array{id:int,rows:array<int,array{0:string,1:int}>,parts:array<string,int>,redirected:array[],untranslated:array[],
     *         dropped:array[],renamed:array[]} rows: [table, new id] of every created row (root first); parts: table => count;
     *         redirected/untranslated/dropped/renamed: what the summary of the caller reports
     */
    public function copy(string $table, int $id, array $overrides, ?callable $partOverrides = null): array
    {
        $rows = $this->collect($table, $id);
        $inSet = [];
        foreach ($rows as $r) {
            $inSet[$r[0] . ':' . $r[1]] = true;
        }
        $newIds = [];
        $patches = [];
        $out = ['id' => 0, 'rows' => [], 'parts' => [], 'redirected' => [], 'untranslated' => [], 'dropped' => [], 'renamed' => []];
        foreach ($rows as $i => [$t, $old, $whole, $wholeCol, $keepSort]) {
            $e = $this->model['entities'][$t];
            $raw = $this->raw($e, $old);
            $values = [];
            foreach ($e['fields'] as $f) {
                if (!$f['primary']) {
                    $values[$f['name']] = $raw[$f['name']] ?? null;
                }
            }
            $fixed = $i === 0 ? $overrides : ($partOverrides !== null ? $partOverrides($e) : []);
            if ($whole !== null) {
                $fixed[$wholeCol] = $newIds[$rows[$whole][0] . ':' . $rows[$whole][1]];
            }
            $pending = [];
            foreach ($e['fields'] as $f) {
                $fk = $f['foreign_key'];
                if ($fk === null || array_key_exists($f['name'], $fixed) || $values[$f['name']] === null) {
                    continue;
                }
                $v = (int) $values[$f['name']];
                $key = $fk['table'] . ':' . $v;
                if (isset($inSet[$key])) {
                    if (isset($newIds[$key])) {
                        $values[$f['name']] = $newIds[$key];
                    } else {
                        $pending[$f['name']] = $key; // copied later in this run: patched once it exists
                    }
                    continue;
                }
                if ($this->redirect !== null) {
                    [$nv, $status] = ($this->redirect)($this->model['entities'][$fk['table']], $v);
                    $values[$f['name']] = $nv;
                    if ($status !== 'same') {
                        $out[$status][] = ['entity' => $t, 'field' => $f['name'], 'target' => $fk['table'], 'from' => $v, 'to' => $nv];
                    }
                }
            }
            $values = $fixed + $values;
            $this->freeOneToOne($e, $values, $pending, $out);
            $workflow = Workflows::fieldOf($e);
            if ($workflow !== null) {
                $values[$workflow['name']] = $this->model['workflows'][$workflow['workflow']]['initial'] ?? $values[$workflow['name']];
            }
            $sort = Sorting::fieldOf($e);
            if ($sort !== null && !$keepSort) {
                $values[$sort['name']] = Sorting::next($this->pdo, $this->model, $sort, Sorting::groupOf($sort, $values));
            }
            $this->freeUnique($e, $values, $pending, $out);
            $row = $values + (RecordMeta::applies($e) ? RecordMeta::createValues($this->user) : []);
            $cols = implode(', ', array_map([SqlGenerator::class, 'q'], array_keys($row)));
            $stmt = $this->pdo->prepare('INSERT INTO ' . SqlGenerator::q($t) . " ($cols) VALUES ("
                . implode(', ', array_fill(0, count($row), '?')) . ')');
            $stmt->execute(array_map(function ($v) {
                return is_bool($v) ? (int) $v : $v;
            }, array_values($row)));
            $new = (int) $this->pdo->lastInsertId();
            $newIds[$t . ':' . $old] = $new;
            $out['rows'][] = [$t, $new];
            if ($i > 0) {
                $out['parts'][$t] = ($out['parts'][$t] ?? 0) + 1;
            }
            foreach ($pending as $col => $key) {
                $patches[] = [$t, $new, $col, $key];
            }
        }
        foreach ($patches as [$t, $new, $col, $key]) {
            $this->pdo->prepare('UPDATE ' . SqlGenerator::q($t) . ' SET ' . SqlGenerator::q($col) . ' = ? WHERE "id" = ?')
                ->execute([$newIds[$key], $new]);
        }
        foreach ($rows as [$t, $old]) {
            $e = $this->model['entities'][$t];
            $this->copyMedia($e, $old, $newIds[$t . ':' . $old]);
            $this->copyLinks($e, $old, $newIds[$t . ':' . $old], $newIds, $out);
        }
        $out['id'] = $newIds[$table . ':' . $id];
        return $out;
    }

    /**
     * The rows to copy, wholes before their parts: [table, id, index of the whole or null, FK column of the composition or
     * null, keep the sort value (order grouped by the whole)]
     */
    private function collect(string $table, int $id): array
    {
        $rows = [[$table, $id, null, null, false]];
        for ($i = 0; $i < count($rows); $i++) {
            [$t, $old] = $rows[$i];
            foreach (Compositions::of($this->model, $t) as $g) {
                foreach ($g['parts'] as $part => $col) {
                    $part = (string) $part;
                    $order = isset($g['sort'][$part]) ? SqlGenerator::q($g['sort'][$part]) . ', ' : '';
                    $stmt = $this->pdo->prepare('SELECT "id" FROM ' . SqlGenerator::q($part) . ' WHERE ' . SqlGenerator::q($col)
                        . " = ? ORDER BY $order\"id\"");
                    $stmt->execute([$old]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                        $rows[] = [$part, (int) $pid, $i, $col, isset($g['sort'][$part])];
                    }
                }
            }
        }
        return $rows;
    }

    private function raw(array $e, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . SqlGenerator::q($e['table']) . ' WHERE "id" = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        return $row;
    }

    /** Is the combination $values of $fields taken by another row of $e? (id of that row or null) */
    private function taken(array $e, array $fields, array $values): ?int
    {
        $where = [];
        $params = [];
        foreach ($fields as $name) {
            if (($values[$name] ?? null) === null) {
                return null; // as in the database: NULL never collides
            }
            $where[] = SqlGenerator::q($name) . ' = ?';
            $params[] = is_bool($values[$name]) ? (int) $values[$name] : $values[$name];
        }
        $stmt = $this->pdo->prepare('SELECT "id" FROM ' . SqlGenerator::q($e['table']) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        $hit = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $hit === false ? null : (int) $hit;
    }

    /** 1:1 references that are taken already: empty if optional (summary 'dropped'), otherwise 409 */
    private function freeOneToOne(array $e, array &$values, array $pending, array &$out): void
    {
        foreach ($e['fields'] as $f) {
            if (empty($f['foreign_key']['one_to_one']) || isset($pending[$f['name']])) {
                continue;
            }
            $hit = $this->taken($e, [$f['name']], $values);
            if ($hit === null) {
                continue;
            }
            if ($f['required']) {
                throw ($this->duplicate)($e, [$f['name']], $values, $hit);
            }
            $out['dropped'][] = ['entity' => $e['table'], 'field' => $f['name'], 'target' => $f['foreign_key']['table'],
                'from' => (int) $values[$f['name']], 'to' => null];
            $values[$f['name']] = null;
        }
    }

    /**
     * {unique} groups taken (the unnamed one and every named {unique: name}): suffix on the first text field of the violated
     * group (see class comment), otherwise 409. A suffix can make another group collide, hence until no group is taken any
     * more. The group of {translations} needs nothing: the new language makes it free.
     */
    private function freeUnique(array $e, array &$values, array $pending, array &$out): void
    {
        $groups = array_values(array_filter(SqlGenerator::uniqueRules($e), function (array $r) use ($pending) {
            // a reference that is only patched later: the database constraint decides
            return !$r['one_to_one'] && !array_intersect($r['columns'], array_keys($pending));
        }));
        for ($pass = 0, $changed = true; $changed && $pass <= count($groups); $pass++) {
            $changed = false;
            foreach ($groups as $g) {
                if ($this->freeGroup($e, $g, $values, $out)) {
                    $changed = true;
                }
            }
        }
    }

    /** One group of freeUnique(): true if a text field got a suffix */
    private function freeGroup(array $e, array $g, array &$values, array &$out): bool
    {
        $fields = $g['columns'];
        $hit = $this->taken($e, $fields, $values);
        if ($hit === null) {
            return false;
        }
        $text = null;
        foreach ($e['fields'] as $f) {
            if (in_array($f['name'], $fields, true) && in_array($f['type'], self::TEXT_TYPES, true) && $f['foreign_key'] === null) {
                $text = $f['name'];
                break;
            }
        }
        if ($text === null) {
            throw ($this->duplicate)($e, $fields, $values, $hit, $g['name']);
        }
        $base = (string) $values[$text];
        for ($n = 1; ; $n++) {
            $values[$text] = $base . '-' . $this->suffix . ($n > 1 ? "-$n" : '');
            if ($this->taken($e, $fields, $values) === null) {
                break;
            }
        }
        $out['renamed'][] = ['entity' => $e['table'], 'field' => $text, 'from' => $base, 'to' => $values[$text]]
            + ($g['name'] !== null ? ['group' => $g['name']] : []); // named group: which one (only then, as before otherwise)
        return true;
    }

    /** Media links of every media field: same media, same order, the metadata of each link */
    private function copyMedia(array $e, int $old, int $new): void
    {
        foreach ($e['media'] ?? [] as $m) {
            $own = SqlGenerator::q($m['own_column']);
            $cols = '"media_id", "position"';
            foreach (LinkFields::of($m) as $f) {
                $cols .= ', ' . SqlGenerator::q($f['name']);
            }
            $junction = SqlGenerator::q($m['junction']);
            $this->pdo->prepare("INSERT INTO $junction ($own, $cols) SELECT ?, $cols FROM $junction WHERE $own = ? ORDER BY \"position\"")
                ->execute([$new, $old]);
        }
    }

    /** n:n links of the copied row (see class comment); a symmetric pair between two copied rows is written once */
    private function copyLinks(array $e, int $old, int $new, array $newIds, array &$out): void
    {
        foreach ($e['many_to_many'] as $m) {
            $junction = SqlGenerator::q($m['junction']);
            $own = SqlGenerator::q($m['own_column']);
            $other = SqlGenerator::q($m['other_column']);
            $symmetric = !empty($m['symmetric']);
            $stmt = $this->pdo->prepare($symmetric
                ? "SELECT $other FROM $junction WHERE $own = ? UNION SELECT $own FROM $junction WHERE $other = ? ORDER BY 1"
                : "SELECT $other FROM $junction WHERE $own = ? ORDER BY 1");
            $stmt->execute($symmetric ? [$old, $old] : [$old]);
            $ins = $this->pdo->prepare("INSERT OR IGNORE INTO $junction ($own, $other) VALUES (?, ?)");
            foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $target) {
                $key = $m['table'] . ':' . $target;
                if (isset($newIds[$key])) {
                    $to = $newIds[$key];
                } elseif ($this->redirectLink !== null) {
                    [$to, $status] = ($this->redirectLink)($e, $m, $target);
                    if ($status !== 'same') {
                        $out[$status][] = ['entity' => $e['table'], 'field' => $m['name'], 'target' => $m['table'], 'from' => $target, 'to' => $to];
                    }
                    if ($to === null) {
                        continue;
                    }
                } else {
                    $to = $target;
                }
                if ($to === $new) {
                    continue; // a self-link is not possible (CHECK of the link table)
                }
                $ins->execute($symmetric ? [min($new, $to), max($new, $to)] : [$new, $to]);
            }
        }
    }
}
