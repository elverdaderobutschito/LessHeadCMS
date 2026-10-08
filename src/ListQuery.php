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

/**
 * Query parameters of GET /api/{entity} -> WHERE/ORDER BY/LIMIT for Cms::page(): paging, sorting and filtering on the
 * server instead of in the browser. Checks everything in advance (422 with the name of the parameter) - an unknown
 * column or a per_page that is too large is never silently ignored or capped.
 *
 *   page        page from 1 (default 1); a page past the end returns an empty list
 *   per_page    rows per page, 1 to MAX_PER_PAGE (default DEFAULT_PER_PAGE)
 *   sort        field of the entity (also id and FK columns; not n:n, not a media field), order = asc | desc.
 *               Empty values come last in both directions, ties are resolved by the id. Text: case-insensitive,
 *               umlauts like their base letter, numbers in the text numerically ("Hafen 2" before "Hafen 12"); FK
 *               column: by the label of the target row (title_field). Without sort: by id.
 *   filter[f]   depending on the kind of field (like the filter row of the UI):
 *                 string/text/richtext   substring, case-insensitive (richtext: in the plain text without Markdown syntax)
 *                 FK column, n:n list    substring of the label "<entity> #<id> – <title_field>". <entity> is the
 *                                        name the caller sees (translation of their display language, public
 *                                        API: display text of the default language) AND the name from the diagram -
 *                                        both match, as does "#12", independent of the language
 *                 enum                   one of the values, several separated by commas (exact)
 *                 bool/visibility        true | false (false also matches empty)
 *                 int/decimal/id/date    filter[f][from] and/or filter[f][to], bounds inclusive
 *   eq[f]       exact value of a field (also an FK column: the id of the target row); empty value = field is empty
 *   ids         only these rows (separated by commas, at most MAX_PER_PAGE)
 *   q           substring across all fields except text/richtext/bool, including the labels of FK columns
 *               and n:n lists (search of the selection dialog)
 *   for         <entity>.<field>: only rows that can be chosen as a value of this relationship (FK column or n:n list
 *               of an entity that points to this entity) - selection in the form and in the selection dialog. for_id =
 *               the row of <entity> currently being edited (missing when creating). Excluded are
 *                 self-reference (FK to the own entity)   the row for_id itself and all its descendants
 *                 1:1 relationship                        target rows already taken by a row OTHER than for_id
 *                 n:n to the own entity                   the row for_id itself
 *               The exclusions are part of the WHERE: total and the pages only count selectable rows. When saving, Cms
 *               checks the same rules again independently (422/409).
 * Several conditions apply together (AND).
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 500;

    /** @var string */
    public $where = '';
    /** @var array */
    public $params = [];
    /** @var string */
    public $orderBy = 'e."id"';
    /** @var int */
    public $page = 1;
    /** @var int */
    public $perPage = self::DEFAULT_PER_PAGE;

    /** @var array */
    private $model;
    /** @var array */
    private $entity;
    /** @var array<string,true> */
    private $denied;
    /** @var callable|null fn(string $table): ?array - denied properties of another entity, null = entity denied */
    private $deniedOf;
    /** @var callable|null fn(): array<string,string> table => displayed (translated) entity name; only called on demand */
    private $entityNames;
    /** @var array<string,string>|null result of $entityNames */
    private $shownNames;
    /** @var array<string,string> */
    private $errors = [];

    /**
     * @param array $query like $_GET
     * @param array<string,true> $denied properties denied for the caller (they do not exist for them)
     * @param string[] $conditions fixed conditions (e.g. only published rows), alias of the table: e
     * @param callable|null $deniedOf fn(string $table): ?array - properties of another entity denied for the caller
     *        (parameter for), null = the entity does not exist for them; if not given, nothing is denied
     * @param callable|null $entityNames fn(): array<string,string> table => entity name in the caller's display
     *        language (translated ones only); only called if a label is searched
     */
    public function __construct(array $model, array $entity, array $denied, array $query, array $conditions = [],
        ?callable $deniedOf = null, ?callable $entityNames = null)
    {
        $this->model = $model;
        $this->entity = $entity;
        $this->denied = $denied;
        $this->deniedOf = $deniedOf;
        $this->entityNames = $entityNames;
        $this->page = $this->positiveInt($query, 'page', 1, PHP_INT_MAX);
        $this->perPage = $this->positiveInt($query, 'per_page', self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);

        foreach (['filter', 'eq'] as $key) {
            if (isset($query[$key]) && !is_array($query[$key])) {
                $this->errors[$key] = $key . '[<feld>]=<wert> erwartet';
                $query[$key] = [];
            }
        }
        foreach ($query['filter'] ?? [] as $name => $value) {
            $sql = $this->filter((string) $name, $value);
            if ($sql !== null) {
                $conditions[] = $sql;
            }
        }
        foreach ($query['eq'] ?? [] as $name => $value) {
            $sql = $this->equals((string) $name, $value);
            if ($sql !== null) {
                $conditions[] = $sql;
            }
        }
        if (isset($query['ids'])) {
            $ids = is_string($query['ids']) ? array_filter(explode(',', $query['ids']), 'strlen') : null;
            if ($ids === null || array_filter($ids, function ($v) {
                return !ctype_digit($v);
            }) || count($ids) > self::MAX_PER_PAGE) {
                $this->errors['ids'] = 'IDs durch Komma getrennt erwartet, höchstens ' . self::MAX_PER_PAGE;
            } else {
                $conditions[] = $ids ? 'e."id" IN (' . implode(', ', array_map('intval', $ids)) . ')' : '0';
            }
        }
        if (isset($query['q'])) {
            if (!is_string($query['q'])) {
                $this->errors['q'] = 'Text erwartet';
            } elseif (trim($query['q']) !== '') {
                $conditions[] = $this->search(trim($query['q']));
            }
        }
        foreach ($this->selectable($query) as $sql) {
            $conditions[] = $sql;
        }
        $this->sort($query);

        if ($this->errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Abfrage: '
                . implode('; ', array_map(function ($k, $v) {
                    return "$k – $v";
                }, array_keys($this->errors), $this->errors)), 'errors' => $this->errors]);
        }
        $this->where = $conditions ? ' WHERE (' . implode(') AND (', $conditions) . ')' : '';
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private function positiveInt(array $query, string $key, int $default, int $max): int
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return $default;
        }
        $v = $query[$key];
        if (!is_string($v) || !ctype_digit($v) || (int) $v < 1 || strlen($v) > 12) {
            $this->errors[$key] = 'ganze Zahl ab 1 erwartet';
            return $default;
        }
        if ((int) $v > $max) {
            $this->errors[$key] = "höchstens $max" . ($key === 'per_page' ? ' Zeilen je Seite – größere Mengen bitte seitenweise abrufen (page)' : '');
            return $default;
        }
        return (int) $v;
    }

    /** Field of the entity that the caller sees (otherwise null) */
    private function field(string $name): ?array
    {
        if (isset($this->denied[$name])) {
            return null;
        }
        foreach ($this->entity['fields'] as $f) {
            if ($f['name'] === $name) {
                return $f;
            }
        }
        return null;
    }

    private function many(string $name): ?array
    {
        if (isset($this->denied[$name])) {
            return null;
        }
        foreach ($this->entity['many_to_many'] as $m) {
            if ($m['name'] === $name) {
                return $m;
            }
        }
        return null;
    }

    private static function col(array $f): string
    {
        return 'e.' . SqlGenerator::q($f['name']);
    }

    /**
     * Case-insensitive LIKE condition "contains" on an SQL expression. If the search text consists of ASCII characters
     * only, SQLite's LIKE is enough (it ignores case exactly for ASCII) - without the detour through PHP per row; only with
     * umlauts and other non-ASCII characters in the search text is the column value lower-cased via lhc_fold() first.
     */
    private function contains(string $expr, string $needle): string
    {
        $ascii = !preg_match('/[^\x00-\x7F]/', $needle);
        $this->params[] = '%' . addcslashes($ascii ? $needle : (string) Database::fold($needle), '\\%_') . '%';
        return ($ascii ? $expr : "lhc_fold($expr)") . " LIKE ? ESCAPE '\\'";
    }

    /**
     * Condition: the FK column points to a target row whose label contains the text. As an IN subquery over the
     * target table - it is evaluated once, not per row of this table.
     */
    private function fkContains(array $f, string $needle): string
    {
        $target = $this->model['entities'][$f['foreign_key']['table']];
        return self::col($f) . ' IN (SELECT t."id" FROM ' . SqlGenerator::q($target['table']) . ' t WHERE ' . $this->labelContains($target, $needle) . ')';
    }

    /**
     * Condition: the label of the target row (alias t) contains the text. Checked is the label as the caller
     * sees it (translated entity name) and additionally the one with the name from the diagram - this way the same input
     * finds the same thing in every display language, and whoever types the original name finds the rows too.
     */
    private function labelContains(array $target, string $needle): string
    {
        $names = [self::entityTitle($target['table'])];
        if ($this->shownNames === null) {
            $this->shownNames = $this->entityNames !== null ? ($this->entityNames)() : []; // once per query
        }
        $shown = $this->shownNames[$target['table']] ?? null;
        if (is_string($shown) && $shown !== '' && $shown !== $names[0]) {
            array_unshift($names, $shown);
        }
        $parts = [];
        foreach ($names as $name) {
            [, $label] = $this->titleExpr($target, 't', $name);
            $parts[] = $this->contains($label, $needle);
        }
        return count($parts) > 1 ? '(' . implode(' OR ', $parts) . ')' : $parts[0];
    }

    /** Entity name without translation, as the UI derives it from the table name (capitalize() in formUtils.js) */
    private static function entityTitle(string $table): string
    {
        return ucfirst(str_replace('_', ' ', $table));
    }

    /**
     * Label of a row of the target table (alias $alias) as in the UI: [text of the title_field values, separated by
     * commas, empty ones omitted; "<entity> #<id>" or "<entity> #<id> – <text>"]
     *
     * @param string|null $entityName displayed entity name (translation), otherwise the one derived from the table name
     * @return array{0:string,1:string} SQL expressions
     */
    private function titleExpr(array $target, string $alias, ?string $entityName = null): array
    {
        $parts = [];
        foreach (Cms::titleFieldOf($target) as $name) {
            $c = $alias . '.' . SqlGenerator::q($name);
            $parts[] = "CASE WHEN $c IS NULL OR $c = '' THEN '' ELSE ', ' || $c END";
        }
        $text = 'SUBSTR(' . implode(' || ', $parts) . ', 3)';
        $name = "'" . str_replace("'", "''", $entityName ?? self::entityTitle($target['table'])) . " #' || $alias.\"id\"";
        return [$text, "$name || CASE WHEN $text = '' THEN '' ELSE ' – ' || $text END"];
    }

    /** Subquery: sort text of the target row the FK column points to (its title_field text, otherwise "<entity> #<id>") */
    private function fkSortExpr(array $f): string
    {
        $target = $this->model['entities'][$f['foreign_key']['table']];
        [$text, $label] = $this->titleExpr($target, 't');
        $expr = "CASE WHEN $text = '' THEN $label ELSE $text END";
        return "(SELECT $expr FROM " . SqlGenerator::q($target['table']) . ' t WHERE t."id" = ' . self::col($f) . ')';
    }

    /** Condition: any linked row of the n:n list carries the text in its label */
    private function manyContains(array $m, string $needle): string
    {
        $target = $this->model['entities'][$m['table']];
        $j = SqlGenerator::q($m['junction']);
        $own = 'j.' . SqlGenerator::q($m['own_column']);
        $other = 'j.' . SqlGenerator::q($m['other_column']);
        $t = SqlGenerator::q($target['table']);
        // first the matching target rows (once), then via the link table to the own rows
        $sql = "e.\"id\" IN (SELECT $own FROM $j j WHERE $other IN (SELECT t.\"id\" FROM $t t WHERE " . $this->labelContains($target, $needle) . '))';
        if (!empty($m['symmetric'])) {
            // {symmetric}: the pair is stored only once - the row can be on either side
            $sql = "($sql OR e.\"id\" IN (SELECT $other FROM $j j WHERE $own IN (SELECT t.\"id\" FROM $t t WHERE "
                . $this->labelContains($target, $needle) . ')))';
        }
        return $sql;
    }

    private static function kind(array $f): string
    {
        if ($f['foreign_key']) {
            return 'fk';
        }
        switch ($f['type']) {
            case 'int':
            case 'decimal':
                return 'number';
            case 'date':
                return 'date';
            case 'bool':
            case 'visibility':
                return 'bool';
            case 'enum':
                return 'enum';
            default:
                return $f['primary'] ? 'number' : 'text';
        }
    }

    private function filter(string $name, $value): ?string
    {
        $key = "filter[$name]";
        $m = $this->many($name);
        if ($m !== null) {
            if (!is_string($value)) {
                $this->errors[$key] = 'Text erwartet';
                return null;
            }
            return trim($value) === '' ? null : $this->manyContains($m, trim($value));
        }
        $f = $this->field($name);
        if ($f === null) {
            $this->errors[$key] = 'unbekanntes oder nicht filterbares Feld';
            return null;
        }
        $kind = self::kind($f);
        $col = self::col($f);
        if ($kind === 'number' || $kind === 'date') {
            if (!is_array($value) || array_diff(array_keys($value), ['from', 'to'])) {
                $this->errors[$key] = "Bereich erwartet: {$key}[from]= und/oder {$key}[to]=";
                return null;
            }
            $parts = [];
            foreach (['from' => '>=', 'to' => '<='] as $bound => $op) {
                $v = $value[$bound] ?? '';
                if ($v === '') {
                    continue;
                }
                $ok = is_string($v) && ($kind === 'date' ? (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) : is_numeric($v));
                if (!$ok) {
                    $this->errors["{$key}[$bound]"] = $kind === 'date' ? 'Datum JJJJ-MM-TT erwartet' : 'Zahl erwartet';
                    continue;
                }
                $this->params[] = $kind === 'date' ? $v : $v + 0;
                $parts[] = "$col $op ?";
            }
            // as in the UI: with a range, empty values drop out
            return $parts ? "$col IS NOT NULL AND " . ($kind === 'date' ? "$col <> '' AND " : '') . implode(' AND ', $parts) : null;
        }
        if (!is_string($value)) {
            $this->errors[$key] = $kind === 'bool' ? 'true oder false erwartet' : 'Text erwartet';
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($kind === 'bool') {
            if ($value !== 'true' && $value !== 'false') {
                $this->errors[$key] = 'true oder false erwartet';
                return null;
            }
            return $value === 'true' ? "$col = 1" : "($col IS NULL OR $col = 0)";
        }
        if ($kind === 'enum') {
            $values = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
            array_push($this->params, ...$values);
            return "$col IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
        }
        if ($kind === 'fk') {
            return $this->fkContains($f, $value);
        }
        return $this->contains($f['type'] === 'richtext' ? "lhc_plain($col)" : "CAST($col AS TEXT)", $value);
    }

    private function equals(string $name, $value): ?string
    {
        $f = $this->field($name);
        if ($f === null || !is_string($value)) {
            $this->errors["eq[$name]"] = $f === null ? 'unbekanntes Feld' : 'Wert erwartet';
            return null;
        }
        $col = self::col($f);
        if ($value === '') {
            return "($col IS NULL OR $col = '')";
        }
        $kind = self::kind($f);
        if ($kind === 'bool') {
            if ($value !== 'true' && $value !== 'false') {
                $this->errors["eq[$name]"] = 'true oder false erwartet';
                return null;
            }
            return $value === 'true' ? "$col = 1" : "($col IS NULL OR $col = 0)";
        }
        if ($kind === 'number' || $kind === 'fk') {
            if (!is_numeric($value)) {
                $this->errors["eq[$name]"] = 'Zahl erwartet';
                return null;
            }
            $this->params[] = $value + 0;
            return "$col = ?";
        }
        $this->params[] = $value;
        return "$col = ?";
    }

    private function search(string $needle): string
    {
        $parts = [];
        foreach ($this->entity['fields'] as $f) {
            if (isset($this->denied[$f['name']]) || in_array($f['type'], ['text', 'richtext', 'bool', 'visibility'], true)) {
                continue;
            }
            $parts[] = $f['foreign_key'] ? $this->fkContains($f, $needle)
                : $this->contains('CAST(' . self::col($f) . ' AS TEXT)', $needle);
        }
        foreach ($this->entity['many_to_many'] as $m) {
            if (!isset($this->denied[$m['name']])) {
                $parts[] = $this->manyContains($m, $needle);
            }
        }
        return $parts ? implode(' OR ', $parts) : '0';
    }

    /**
     * Parameters for/for_id: conditions that exclude rows that cannot be selected (see class comment). The id is
     * checked and goes directly into the SQL as a number.
     *
     * @return string[]
     */
    private function selectable(array $query): array
    {
        $for = $query['for'] ?? null;
        $forId = $query['for_id'] ?? null;
        $id = null;
        if ($forId !== null && $forId !== '') {
            if (!is_string($forId) || !ctype_digit($forId) || (int) $forId < 1 || strlen($forId) > 12) {
                $this->errors['for_id'] = 'ganze Zahl ab 1 erwartet';
                return [];
            }
            $id = (int) $forId;
        }
        if ($for === null || $for === '') {
            if ($id !== null) {
                $this->errors['for_id'] = 'nur zusammen mit for=<entität>.<feld>';
            }
            return [];
        }
        $source = is_string($for) && preg_match('/^([^.]+)\.([^.]+)$/', $for, $m) ? ($this->model['entities'][strtolower($m[1])] ?? null) : null;
        $denied = $source === null ? null : ($this->deniedOf !== null ? ($this->deniedOf)($source['table']) : []);
        $own = $this->entity['table'];
        $out = null;
        if ($denied !== null && !isset($denied[$m[2]])) {
            foreach ($source['fields'] as $f) {
                $fk = $f['foreign_key'];
                if ($f['name'] !== $m[2] || !$fk || $fk['table'] !== $own) {
                    continue;
                }
                $out = [];
                $t = SqlGenerator::q($source['table']);
                $col = SqlGenerator::q($f['name']);
                if ($source['table'] === $own && $id !== null) {
                    // the row itself and all descendants; UNION (instead of UNION ALL) also ends with existing legacy cycles
                    $out[] = "e.\"id\" NOT IN (WITH RECURSIVE d(id) AS (SELECT $id UNION SELECT c.\"id\" FROM $t c JOIN d ON c.$col = d.id) SELECT id FROM d)";
                }
                if (!empty($fk['one_to_one'])) {
                    $out[] = "NOT EXISTS (SELECT 1 FROM $t s WHERE s.$col = e.\"id\"" . ($id !== null ? " AND s.\"id\" <> $id" : '') . ')';
                }
            }
            foreach ($source['many_to_many'] as $n) {
                if ($n['name'] === $m[2] && $n['table'] === $own) {
                    $out = $source['table'] === $own && $id !== null ? ["e.\"id\" <> $id"] : [];
                }
            }
        }
        if ($out === null) {
            $this->errors['for'] = '<entität>.<feld> einer Beziehung (FK-Spalte oder n:n-Liste) auf diese Entität erwartet';
            return [];
        }
        return $out;
    }

    private function sort(array $query): void
    {
        $order = $query['order'] ?? 'asc';
        if ($order !== 'asc' && $order !== 'desc') {
            $this->errors['order'] = 'asc oder desc erwartet';
            $order = 'asc';
        }
        if (!isset($query['sort']) || $query['sort'] === '') {
            if (isset($query['order']) && !isset($this->errors['order'])) {
                $this->orderBy = 'e."id" ' . strtoupper($order);
            }
            return;
        }
        $f = is_string($query['sort']) ? $this->field($query['sort']) : null;
        if ($f === null) {
            $this->errors['sort'] = 'unbekanntes oder nicht sortierbares Feld';
            return;
        }
        $col = self::col($f);
        $kind = self::kind($f);
        if ($kind === 'fk') {
            $expr = 'lhc_sortkey(' . $this->fkSortExpr($f) . ')';
            $empty = "$col IS NULL";
        } elseif ($kind === 'text' || $kind === 'enum') {
            $expr = $f['type'] === 'richtext' ? "lhc_sortkey(lhc_plain($col))" : "lhc_sortkey($col)";
            $empty = "($col IS NULL OR $col = '')";
        } else {
            $expr = $col;
            $empty = "($col IS NULL OR $col = '')";
        }
        $this->orderBy = "$empty, $expr " . strtoupper($order) . ', e."id"';
    }
}
