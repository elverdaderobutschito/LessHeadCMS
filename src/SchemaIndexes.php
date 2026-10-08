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
 * Indexes the schema model needs but no rule in the diagram demands: one per foreign key column of an entity
 * and one per second column of a link table (n:n and media field - the first column already leads the composite
 * primary key). Without them, every delete (search for dependent rows, Cms::delete(); SQLite's own FK check)
 * and every resolution of "who references this row" reads the whole table.
 *
 * Name: idx_<table>_<column>. An FK column that already leads a uniqueness rule (1:1, first field of the
 * {unique} group) already has its index and does not get a second one.
 *
 * sync() establishes exactly this set: create missing ones, remove own ones (idx_...) that no longer match the model
 * (column/table renamed or removed). Called at bootstrap, at the end of every schema migration (tables are rebuilt
 * there and lose their indexes) and via "Fehlende Indizes nachrüsten" (POST /api/_schema/indexes)
 * for installations from before that. An index does not change any data - hence without backup and confirmation.
 */
final class SchemaIndexes
{
    private const PREFIX = 'idx_';

    /** @return array<string,string> index name => CREATE INDEX ... (exactly as it is stored in sqlite_master afterwards) */
    public static function statements(array $model): array
    {
        $out = [];
        $add = function (string $table, string $column) use (&$out) {
            $name = self::PREFIX . $table . '_' . $column;
            // "a" + "b_c" and "a_b" + "c" would yield the same name: number them in that case (model order)
            for ($n = 2, $base = $name; isset($out[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $out[$name] = 'CREATE INDEX ' . SqlGenerator::q($name) . ' ON ' . SqlGenerator::q($table) . ' (' . SqlGenerator::q($column) . ')';
        };
        foreach ($model['entities'] as $table => $entity) {
            $leading = array_column(SqlGenerator::uniqueGroups($entity), 0);
            foreach ($entity['fields'] as $f) {
                if ($f['foreign_key'] && !in_array($f['name'], $leading, true)) {
                    $add((string) $table, $f['name']);
                }
            }
        }
        foreach ($model['junctions'] as $j) {
            $add($j['table'], $j['right_column']);
        }
        foreach ($model['entities'] as $entity) {
            foreach ($entity['media'] ?? [] as $m) {
                $add($m['junction'], 'media_id');
            }
        }
        return $out;
    }

    /**
     * @return array{created:string[],existing:int,removed:string[]} created indexes, number of already existing ones,
     *         removed outdated ones
     */
    public static function sync(PDO $pdo, array $model): array
    {
        $want = self::statements($model);
        $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx\\_%' ESCAPE '\\' AND sql IS NOT NULL");
        $have = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmt->closeCursor();
        $result = ['created' => [], 'existing' => 0, 'removed' => []];
        foreach ($have as $name => $sql) {
            if (!isset($want[$name])) {
                $pdo->exec('DROP INDEX ' . SqlGenerator::q((string) $name));
                $result['removed'][] = (string) $name;
            } elseif ($sql !== $want[$name]) {
                // same name, different column/table (after a rename): recreate without counting it as "added"
                $pdo->exec('DROP INDEX ' . SqlGenerator::q((string) $name));
                $pdo->exec($want[$name]);
                $result['existing']++;
            } else {
                $result['existing']++;
            }
        }
        foreach (array_diff_key($want, $have) as $name => $sql) {
            $pdo->exec($sql);
            $result['created'][] = $name;
        }
        return $result;
    }
}
