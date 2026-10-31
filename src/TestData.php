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
 * Test data generator (POST /api/_testdata/generate, admins only): creates a number of random, plausible records for
 * every entity of the current schema - IN ADDITION to existing data, nothing is changed or deleted.
 *
 * Writing is done exclusively via Cms::create() and Cms::update() (n:n), i.e. with exactly the validation an
 * editor goes through as well. Only the existing IDs are read directly (for FK and n:n values).
 *
 * Order: entities whose FK targets are done come first (diagram order as a secondary criterion). Self-references
 * only point to rows that already exist, so no cycles arise; with an optional self-reference about 70 % are
 * roots. Whatever cannot be created at all via the normal POST logic is skipped with a reason instead of forced:
 * a required FK to an empty table, a required self-reference in an empty table (the first row would need a
 * parent), tables that need each other via required FKs. n:n links (0-3 per new row) are set in
 * a second pass when all tables are filled - only for the newly created rows. With a
 * {unique} group, an already used combination is retried with new random values (see createUnique()).
 * 1:1 columns only get target rows not yet assigned; if none is free any more for a required 1:1, the
 * table aborts with a reason in failed (see exhaustedOneToOne()).
 *
 * Minimum count ("1..*" on the n side, e.g. Position "1..*" --> "1" Rechnung): the rows of the dependent table
 * (Position) first go to parent rows (Rechnung) that do not have any yet; if newly created parent rows are still
 * without one afterwards, additional rows are created for them (result: min_extra, they count towards created) - this
 * way fresh test data does not trigger the warning right away. Not for a self-reference (every new row would need one
 * again) and not if 0 is requested for the dependent table or it cannot be created.
 *
 * Media fields (type media) deliberately stay empty - there are no sensibly invented image/video files. This also
 * applies to required media fields (Cms::create() with $allowEmptyMedia); the result lists them under media_empty.
 *
 * Field rules (Validation): the values keep to them - texts are cut or extended to minLength/maxLength (for richtext the
 * plain text counts, so such fields get plain text), numbers and dates lie within min/max. A field with {pattern: x}
 * gets the example value of the pattern (it never invents a value that might not match). Without an example (or with
 * one that violates another rule of the field) an optional field stays empty (result: pattern_empty), a required one
 * means the table is skipped with a reason - no invalid data.
 */
final class TestData
{
    public const DEFAULT_COUNT = 10;
    /** Default - can be changed under System → Einstellungen (Settings::TESTDATA_MAX_COUNT). */
    public const MAX_COUNT = 1000;

    private const WORDS = [
        'Sonne', 'Garten', 'Kaffee', 'Berg', 'Fluss', 'Stadt', 'Wald', 'Licht', 'Reise', 'Sommer', 'Winter', 'Blume',
        'Brücke', 'Hafen', 'Markt', 'Wolke', 'Feld', 'Insel', 'Straße', 'Buch', 'Musik', 'Farbe', 'Stern', 'Weg',
    ];
    private const FIRST_NAMES = ['Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas', 'Lena', 'Max'];
    private const LAST_NAMES = ['Müller', 'Schmidt', 'Weber', 'Fischer', 'Wagner', 'Becker', 'Hoffmann', 'Koch', 'Richter', 'Wolf'];
    private const SENTENCES = [
        'Der Morgen beginnt ruhig und das Licht fällt schräg durch das Fenster.',
        'Im Garten blühen die ersten Blumen, und die Luft riecht nach Regen.',
        'Die Reise führte über drei Brücken und einen langen Weg am Fluss entlang.',
        'Am Markt gab es frischen Kaffee und viele Gespräche über das Wetter.',
        'Hinter dem Berg liegt eine kleine Stadt mit einem alten Hafen.',
        'Die Musik klang leise durch die Straße, als die Sterne aufgingen.',
        'Ein neues Buch lag auf dem Tisch, daneben eine Tasse Tee.',
        'Im Winter wird es früh dunkel, im Sommer bleibt es lange hell.',
    ];

    /** @var Cms */
    private $cms;
    /** @var PDO */
    private $pdo;
    /** @var array */
    private $model;
    /** @var array<string,int[]> table => all existing IDs (previously existing + newly created) */
    private $ids = [];
    /** @var array<string,int[]> only while a table is being filled: FK column with a minimum count => parent rows without a row */
    private $lacking = [];
    /** @var Validation */
    private $validation;
    /** @var array<string,array> pattern library (Patterns::all()) */
    private $patterns;

    /** Generated records call no webhooks (otherwise every global webhook once per record), see Cms::withoutWebhooks() */
    public function __construct(Cms $cms, PDO $pdo, array $model)
    {
        $this->cms = $cms->withoutWebhooks();
        $this->pdo = $pdo;
        $this->model = $model;
        $this->validation = new Validation($pdo);
        $this->patterns = Patterns::all($pdo);
    }

    /**
     * @param array $input {"count": 10} (for all) and/or {"counts": {"<table>": n}} (individually, takes precedence)
     * @return array{created: array<string,int>, links: array<string,int>, skipped: array<string,string>,
     *                skipped_codes: object, failed: array<string,string>, failed_codes: object, order: string[],
     *                media_empty: array<string,bool>,
     *                min_extra?: array<string,int>, pattern_empty?: string[]} (min_extra only if additional rows were created
     *                for a minimum count; pattern_empty only if optional {pattern} fields stayed empty, see class comment)
     */
    public function generate(array $input): array
    {
        $counts = $this->counts($input);
        @set_time_limit(300);

        foreach (array_keys($this->model['entities']) as $table) {
            $this->ids[$table] = array_map('intval', $this->pdo->query(
                'SELECT "id" FROM ' . SqlGenerator::q($table) . ' ORDER BY "id"'
            )->fetchAll(PDO::FETCH_COLUMN));
        }

        $created = [];
        $minExtra = [];
        $newIds = [];
        $skipped = [];
        $failed = [];
        $order = [];
        $remaining = array_keys(array_filter($counts));
        while ($remaining) {
            // ready: all FK targets (except the own table) are processed or not requested at all
            $ready = array_values(array_filter($remaining, function ($t) use ($remaining) {
                return !array_intersect($this->fkTargets($t, false), $remaining);
            }));
            if (!$ready) {
                // Cycle between tables: continue with the first table whose still open required targets already have rows
                // (e.g. from an earlier run) or which only has optional FKs to them (those then stay empty)
                foreach ($remaining as $t) {
                    $open = array_intersect($this->fkTargets($t, true), $remaining);
                    if (!array_filter($open, function ($target) {
                        return !$this->ids[$target];
                    })) {
                        $ready = [$t];
                        break;
                    }
                }
            }
            if (!$ready) {
                foreach ($remaining as $t) {
                    $skipped[$t] = Msg::of('msg_testdata.mutual', 'Tables reference each other via required foreign keys ({tables}) '
                        . '– so the first row cannot be created.', ['tables' => implode(', ', $remaining)]);
                }
                break;
            }
            foreach ($ready as $t) {
                $remaining = array_values(array_diff($remaining, [$t]));
                $order[] = $t;
                $problem = $this->blocker($t);
                if ($problem !== null) {
                    $skipped[$t] = $problem;
                    continue;
                }
                $newIds[$t] = [];
                $this->lacking = $this->lackingParents($t);
                try {
                    // after the requested rows: further ones as long as parent rows created in this run are still without one
                    for ($i = 0; $i < $counts[$t] || $this->lackingNew($t, $newIds); $i++) {
                        $full = $this->exhaustedOneToOne($t);
                        if ($full !== null) {
                            $failed[$t] = self::aborted(count($newIds[$t]), $full);
                            break;
                        }
                        $extra = $i >= $counts[$t];
                        if ($extra) {
                            // only for the new parent rows now (previously existing ones stay as they are)
                            foreach ($this->lacking as $column => $parents) {
                                $target = $this->fkOf($t, $column)['table'];
                                $this->lacking[$column] = array_values(array_intersect($parents, $newIds[$target] ?? []));
                            }
                        }
                        $row = $this->createUnique($t, $i, max($counts[$t], $i + 1));
                        if ($row === null) {
                            $failed[$t] = self::aborted(count($newIds[$t]), Msg::of('msg_testdata.no_unique', 'no further free '
                                . 'combination found for the {unique} fields ({fields}).', [
                                    'unique' => '{unique}', 'fields' => implode(', ', $this->model['entities'][$t]['unique_fields']),
                                ]));
                            break;
                        }
                        $newIds[$t][] = $row['id'];
                        $this->ids[$t][] = $row['id'];
                        $open = array_sum(array_map('count', $this->lacking));
                        foreach ($this->lacking as $column => $parents) {
                            $this->lacking[$column] = array_values(array_diff($parents, [$row[$column]]));
                        }
                        if ($extra) {
                            $minExtra[$t] = ($minExtra[$t] ?? 0) + 1;
                            if (array_sum(array_map('count', $this->lacking)) >= $open) {
                                break; // safeguard: every additional row must supply a parent row, otherwise stop
                            }
                        }
                    }
                } catch (ApiException $e) {
                    // should not happen; if it does: report it instead of aborting the whole run with half-created data
                    $failed[$t] = self::aborted(count($newIds[$t]), isset($e->payload['code']) && !isset($e->payload['errors'])
                        ? Msg::fromArray($e->payload)
                        : Msg::of('msg_api.plain', '{text}', [
                            'text' => $e->getMessage() . (isset($e->payload['errors']) ? ' ' . json_encode($e->payload['errors'], JSON_UNESCAPED_UNICODE) : ''),
                        ]));
                }
                $this->lacking = [];
                $created[$t] = count($newIds[$t]);
            }
        }

        // n:n: only now so that all target tables are filled; only the new rows, via the normal PUT logic
        $links = [];
        foreach ($newIds as $t => $rows) {
            foreach ($this->model['entities'][$t]['many_to_many'] as $m) {
                $n = 0;
                foreach ($rows as $id) {
                    // self-reference: never with itself (Cms rejects that with 422)
                    $candidates = $m['table'] === $t ? array_values(array_diff($this->ids[$m['table']], [$id])) : $this->ids[$m['table']];
                    $pick = $this->sample($candidates, mt_rand(0, 3));
                    if (!$pick) {
                        continue;
                    }
                    if (!empty($m['symmetric'])) {
                        // {symmetric}: the list of the row also contains pairs an earlier row has already created -
                        // add instead of replace, otherwise every row would delete the links of its predecessors again
                        $current = $this->cms->find($t, $id)[$m['name']];
                        $pick = array_values(array_diff($pick, $current));
                        if (!$pick) {
                            continue;
                        }
                        $this->cms->update($t, $id, [$m['name'] => array_merge($current, $pick)]);
                    } else {
                        $this->cms->update($t, $id, [$m['name'] => $pick]);
                    }
                    $n += count($pick);
                }
                $links["$t.{$m['name']}"] = $n;
            }
        }

        // media fields of the filled entities: stay empty (see class comment), "<table>.<field>" => required?
        $mediaEmpty = [];
        foreach ($newIds as $t => $rows) {
            foreach ($rows ? $this->model['entities'][$t]['media'] ?? [] : [] as $m) {
                $mediaEmpty["$t.{$m['name']}"] = $m['required'];
            }
        }

        // optional fields with {pattern} but without a usable example value: stay empty, "<table>.<field>"
        $patternEmpty = [];
        foreach ($newIds as $t => $rows) {
            foreach ($rows ? $this->model['entities'][$t]['fields'] : [] as $f) {
                if (isset($f['rules']['pattern']) && $this->patternExample($f) === null) {
                    $patternEmpty[] = "$t.{$f['name']}";
                }
            }
        }

        return [
            'created' => $created, 'links' => $links,
            'skipped' => self::texts($skipped), 'skipped_codes' => self::codes($skipped),
            'failed'  => self::texts($failed), 'failed_codes' => self::codes($failed),
            'order'   => $order, 'media_empty' => $mediaEmpty,
        ] + ($minExtra ? ['min_extra' => $minExtra] : []) + ($patternEmpty ? ['pattern_empty' => $patternEmpty] : []);
    }

    /**
     * Create a row via Cms::create(). If the entity has a {unique} group, a random combination may already be
     * taken (409 duplicate) - then try again with new random values.
     *
     * @return array|null the new row; null = no free combination found after several attempts
     */
    private function createUnique(string $table, int $i, int $count): ?array
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                return $this->cms->create($table, $this->values($table, $i, $count), true);
            } catch (ApiException $e) {
                if (($e->payload['error'] ?? null) !== 'duplicate') {
                    throw $e;
                }
            }
        }
        return null;
    }

    private function fkOf(string $table, string $column): array
    {
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            if ($f['name'] === $column) {
                return $f['foreign_key'];
            }
        }
        throw new \LogicException("Unbekannte Spalte $table.$column");
    }

    /**
     * Minimum count: per FK column of $table with "1..*" the parent rows that do not have a row in $table yet (one
     * query per column). Self-references are left out (see class comment).
     *
     * @return array<string,int[]> FK column => IDs of the target table
     */
    private function lackingParents(string $table): array
    {
        $out = [];
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            $fk = $f['foreign_key'];
            if (!$fk || empty($fk['min_required']) || $fk['table'] === $table) {
                continue;
            }
            $col = SqlGenerator::q($f['name']);
            $out[$f['name']] = array_map('intval', $this->pdo->query(
                'SELECT "id" FROM ' . SqlGenerator::q($fk['table']) . ' WHERE "id" NOT IN (SELECT ' . $col . ' FROM '
                . SqlGenerator::q($table) . " WHERE $col IS NOT NULL) ORDER BY \"id\""
            )->fetchAll(PDO::FETCH_COLUMN));
        }
        return $out;
    }

    /** Are there parent rows created in this run that are still without a row for the table $table currently being filled? */
    private function lackingNew(string $table, array $newIds): bool
    {
        foreach ($this->lacking as $column => $parents) {
            if (array_intersect($parents, $newIds[$this->fkOf($table, $column)['table']] ?? [])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,int> table => count (0 = skip this table) */
    private function counts(array $input): array
    {
        $errors = [];
        $default = $input['count'] ?? self::DEFAULT_COUNT;
        $per = $input['counts'] ?? [];
        if (!is_array($per)) {
            throw ApiException::of(422, 'validation', Msg::of('msg_testdata.counts_object', 'counts must be an object'), ApiException::fields([
                'counts' => Msg::of('msg_testdata.object_expected', 'Object expected'),
            ]));
        }
        foreach (array_keys($per) as $t) {
            if (!isset($this->model['entities'][$t])) {
                $errors[$t] = Msg::of('msg_testdata.unknown_entity', 'Unknown entity');
            }
        }
        $counts = [];
        $max = (int) Settings::get($this->pdo, Settings::TESTDATA_MAX_COUNT);
        foreach (array_keys($this->model['entities']) as $t) {
            $v = array_key_exists($t, $per) ? $per[$t] : $default;
            if (!is_int($v) && !(is_string($v) && preg_match('/^\d+$/', $v))) {
                $errors[$t] = Msg::of('msg_records.int_expected', 'Integer expected');
                continue;
            }
            $v = (int) $v;
            if ($v < 0 || $v > $max) {
                $errors[$t] = Msg::of('msg_testdata.count_range', 'Allowed: 0 to {max}', ['max' => $max]);
                continue;
            }
            $counts[$t] = $v;
        }
        if ($errors) {
            throw ApiException::of(422, 'validation', Msg::of('msg_testdata.invalid_count', 'Invalid count'), ApiException::fields($errors));
        }
        return $counts;
    }

    /** FK target tables of an entity (without self-reference); $requiredOnly = required FKs only */
    private function fkTargets(string $table, bool $requiredOnly): array
    {
        $targets = [];
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            if ($f['foreign_key'] && $f['foreign_key']['table'] !== $table && (!$requiredOnly || $f['required'])) {
                $targets[] = $f['foreign_key']['table'];
            }
        }
        return array_unique($targets);
    }

    /** Reason why no row can be created for this table via POST, otherwise null */
    private function blocker(string $table): ?Msg
    {
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            if ($f['required'] && isset($f['rules']['pattern']) && $this->patternExample($f) === null) {
                $p = $this->patterns[$f['rules']['pattern']] ?? null;
                $params = ['field' => $f['name'], 'marker' => "{pattern: {$f['rules']['pattern']}}"];
                return $p === null || $p['example'] === null
                    ? Msg::of('msg_testdata.pattern_no_example', "Required field '{field}' with {marker}: The pattern has no example "
                        . 'value - please enter one under System → Settings → Patterns.', $params)
                    : Msg::of('msg_testdata.pattern_example_bad', "Required field '{field}' with {marker}: The example value '{example}' "
                        . 'violates another rule of the field.', $params + ['example' => $p['example']]);
            }
        }
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            $fk = $f['foreign_key'];
            if (!$fk || !$f['required'] || $this->ids[$fk['table']]) {
                continue;
            }
            return $fk['table'] === $table
                ? Msg::of('msg_testdata.self_ref', "Required self-reference '{field}': The first row would need a parent, but the "
                    . 'table is empty.', ['field' => $f['name']])
                : Msg::of('msg_testdata.fk_empty', "Required foreign key '{field}' references '{table}', which has no rows.", [
                    'field' => $f['name'], 'table' => $fk['table'],
                ]);
        }
        return null;
    }

    /** Values for the $i-th of $count new rows */
    private function values(string $table, int $i, int $count): array
    {
        $values = [];
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            if ($f['primary']) {
                continue;
            }
            $fk = $f['foreign_key'];
            if ($fk) {
                $values[$f['name']] = $this->fkValue($table, $f, $i, $count);
                continue;
            }
            $values[$f['name']] = $this->fieldValue($f, $i, $count);
        }
        return $values;
    }

    /** Target rows already referenced by a row of $table via the 1:1 column $column (must not be used again) */
    private function usedTargets(string $table, string $column): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT ' . SqlGenerator::q($column) . ' FROM ' . SqlGenerator::q($table) . ' WHERE ' . SqlGenerator::q($column) . ' IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function aborted(int $rows, Msg $reason): Msg
    {
        return Msg::of('msg_testdata.aborted', 'Aborted after {rows} row(s): {reason}', ['rows' => $rows, 'reason' => $reason]);
    }

    /** @param array<string,Msg> $messages @return array<string,string> */
    private static function texts(array $messages): array
    {
        return array_map(function (Msg $m) {
            return $m->text();
        }, $messages);
    }

    /** {table: {code, params}} next to the English texts, so that the UI shows them in the display language */
    private static function codes(array $messages): object
    {
        return (object) array_map(function (Msg $m) {
            return $m->codeOnly();
        }, $messages);
    }

    /** Reason why no further row is possible: required 1:1 column whose target table is already completely taken */
    private function exhaustedOneToOne(string $table): ?Msg
    {
        foreach ($this->model['entities'][$table]['fields'] as $f) {
            $fk = $f['foreign_key'];
            if ($fk && !empty($fk['one_to_one']) && $f['required']
                && !array_diff($this->ids[$fk['table']], $this->usedTargets($table, $f['name']))) {
                return Msg::of('msg_testdata.one_to_one_full', "Required 1:1 '{field}': all rows in '{table}' are already assigned.", [
                    'field' => $f['name'], 'table' => $fk['table'],
                ]);
            }
        }
        return null;
    }

    /** @return int|null */
    private function fkValue(string $table, array $f, int $i, int $count)
    {
        $target = $f['foreign_key']['table'];
        $pool = $this->ids[$target];
        if (!empty($f['foreign_key']['one_to_one'])) {
            // 1:1: only target rows not yet assigned; optionally empty if none is free any more
            $pool = array_values(array_diff($pool, $this->usedTargets($table, $f['name'])));
        }
        if ($target === $table) {
            // Self-reference: only to rows that already exist (no cycle possible). Optional: a fixed ~30 % children
            // (at least one from two rows on), evenly distributed - so that a hierarchy is guaranteed to arise.
            if (!$pool) {
                return null; // only reachable when optional, required is caught by blocker()
            }
            if (!$f['required'] && !self::spread($i, $count, 0.3, 1)) {
                return null;
            }
            return $pool[array_rand($pool)];
        }
        if (!$pool) {
            return null;
        }
        if (!empty($this->lacking[$f['name']])) {
            return $this->lacking[$f['name']][0]; // minimum count: first the parent rows that do not have a row yet
        }
        if (!$f['required'] && mt_rand(1, 100) <= 20) {
            return null; // optional FKs occasionally empty
        }
        return $pool[array_rand($pool)];
    }

    /**
     * Example value of the pattern of a field, if it satisfies all rules of the field (the pattern itself included);
     * otherwise null
     */
    private function patternExample(array $f): ?string
    {
        $example = $this->patterns[$f['rules']['pattern']]['example'] ?? null;
        return $example !== null && !$this->validation->check($f, $example) ? $example : null;
    }

    /** @return mixed */
    private function fieldValue(array $f, int $i, int $count)
    {
        $rules = $f['rules'] ?? [];
        if (isset($rules['pattern'])) {
            return $this->patternExample($f); // null: optional field stays empty (a required one is caught by blocker())
        }
        $value = $this->rawValue($f, $i, $count);
        if (isset($rules['minLength']) || isset($rules['maxLength'])) {
            // richtext: the plain text counts - plain text without Markdown keeps the count exact
            $text = $f['type'] === 'richtext' ? (string) Database::plainText((string) $value) : (string) $value;
            $value = self::fitLength($text, $rules['minLength'] ?? 0, $rules['maxLength'] ?? null);
        }
        return $value;
    }

    /** Text cut to at most $max characters (at a word boundary if possible) and extended to at least $min */
    private static function fitLength(string $text, int $min, ?int $max): string
    {
        if ($max !== null && mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $space = mb_strrpos($cut, ' ');
            $text = $space !== false && $space >= max(1, $min) ? rtrim(mb_substr($cut, 0, $space)) : $cut;
        }
        while (mb_strlen($text) < $min) {
            $text .= ($text === '' ? '' : ' ') . self::WORDS[array_rand(self::WORDS)];
        }
        if ($max !== null && mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }
        return $text;
    }

    /** @return mixed value by type, within min/max of the rules */
    private function rawValue(array $f, int $i, int $count)
    {
        $name = strtolower($f['name']);
        $min = $f['rules']['min'] ?? null;
        $max = $f['rules']['max'] ?? null;
        switch ($f['type']) {
            case 'int':
                $lo = $min ?? ($max !== null ? min(1, $max) : 1);
                return mt_rand($lo, $max ?? max($lo + 999, 1000));
            case 'decimal':
                $lo = $min ?? ($max !== null ? min(5.0, $max) : 5.0);
                $hi = $max ?? max($lo + 495, 500.0);
                $cents = [(int) ceil($lo * 100), (int) floor($hi * 100)];
                return $cents[0] <= $cents[1] ? mt_rand($cents[0], $cents[1]) / 100 : $lo;
            case 'bool':
                return (bool) mt_rand(0, 1);
            case 'visibility':
                // mostly published (~80 %), from two rows on always at least one draft
                return !self::spread($i, $count, 0.2, 1);
            case 'date':
                if ($min === null && $max === null) {
                    return date('Y-m-d', time() - mt_rand(0, 730) * 86400);
                }
                $day = 86400;
                $lo = $min !== null ? (int) strtotime($min . ' 12:00:00 UTC') : (int) strtotime($max . ' 12:00:00 UTC') - 730 * $day;
                $hi = $max !== null ? (int) strtotime($max . ' 12:00:00 UTC') : max($lo, time()) + ($lo > time() ? 730 * $day : 0);
                return gmdate('Y-m-d', $lo + mt_rand(0, (int) (($hi - $lo) / $day)) * $day);
            case 'enum':
                return self::pick($f['enum_values']);
            case 'text':
                return implode(' ', $this->sample(self::SENTENCES, mt_rand(2, 4)));
            case 'richtext':
                $s = $this->sample(self::SENTENCES, 3);
                $w = self::WORDS[array_rand(self::WORDS)];
                return "**$w** – {$s[0]} *{$s[1]}*\n\n{$s[2]}";
            default: // string
                if (strpos($name, 'mail') !== false) {
                    return strtolower(self::pick(self::FIRST_NAMES) . '.' . strtr(self::pick(self::LAST_NAMES), ['ü' => 'ue', 'ö' => 'oe', 'ä' => 'ae']))
                        . mt_rand(1, 99) . '@example.org';
                }
                // personal names only for unambiguous field names - "name" alone is e.g. also used for category or product
                if ($name === 'vorname') {
                    return self::pick(self::FIRST_NAMES);
                }
                if ($name === 'nachname') {
                    return self::pick(self::LAST_NAMES);
                }
                [$a, $b] = $this->sample(self::WORDS, 2);
                return "$a und $b" . (mt_rand(0, 2) === 0 ? ' ' . mt_rand(2, 99) : '');
        }
    }

    /**
     * Even, fixed distribution instead of rolling dice: true for about $share of all rows, from $minFrom + 1 rows on
     * at least one. Row 0 is never included (first row = root or published).
     */
    private static function spread(int $i, int $count, float $share, int $minFrom): bool
    {
        if ($i === 0 || $count <= $minFrom) {
            return false;
        }
        $n = max(1, (int) round(($count - 1) * $share));
        // distribute the $n affected rows evenly across 1..count-1
        for ($k = 1; $k <= $n; $k++) {
            if ($i === (int) round($k * ($count - 1) / $n)) {
                return true;
            }
        }
        return false;
    }

    /** @return mixed */
    private static function pick(array $a)
    {
        return $a[array_rand($a)];
    }

    /** up to $n different elements, at random */
    private function sample(array $a, int $n): array
    {
        $n = min($n, count($a));
        if ($n <= 0) {
            return [];
        }
        $keys = (array) array_rand($a, $n);
        shuffle($keys);
        return array_map(function ($k) use ($a) {
            return $a[$k];
        }, $keys);
    }
}
