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

/** Generates SQLite CREATE TABLE statements from the schema model. */
final class SqlGenerator
{
    /** @return array<string,string> table name => CREATE TABLE ... */
    public static function createStatements(array $model): array
    {
        $sql = [];

        foreach ($model['entities'] as $table => $entity) {
            $lines = [];
            $constraints = [];
            foreach ($entity['fields'] as $f) {
                $col = self::q($f['name']);
                if ($f['primary']) {
                    $lines[] = "$col INTEGER PRIMARY KEY AUTOINCREMENT";
                    continue;
                }
                // required visibility: default value false (draft) - a new record is never public by accident
                $default = $f['required'] && $f['type'] === 'visibility' ? ' DEFAULT 0' : '';
                // Enum: only the declared values, also with direct SQL (NULL stays allowed for optional fields,
                // a CHECK with a NULL result counts as satisfied in SQL). Cms::coerce() checks beforehand itself for a
                // comprehensible 422.
                $check = $f['type'] === 'enum' ? ' CHECK (' . $col . ' IN (' . implode(', ', array_map(function ($v) {
                    return "'" . str_replace("'", "''", $v) . "'";
                }, $f['enum_values'])) . '))' : '';
                $lines[] = "$col {$f['sql_type']}" . ($f['required'] ? ' NOT NULL' : '') . $default . $check;
                if ($f['foreign_key']) {
                    $fk = $f['foreign_key'];
                    // {cascade}: the database itself cascades as well (also with direct SQL). Restrict deliberately
                    // stays at the SQLite default NO ACTION instead of RESTRICT: NO ACTION can be deferred until the
                    // COMMIT via defer_foreign_keys, which Cms::delete() needs when deleting several
                    // interdependent rows in one transaction.
                    $constraints[] = "FOREIGN KEY ($col) REFERENCES " . self::q($fk['table']) . '(' . self::q($fk['column']) . ')'
                        . (($fk['on_delete'] ?? 'restrict') === 'cascade' ? ' ON DELETE CASCADE' : '');
                }
            }
            // Uniqueness ({unique} group, 1:1 columns, see uniqueGroups()). Cms checks beforehand itself to return a
            // comprehensible 409; the constraint also covers parallel requests and direct SQL.
            // NULL values count (SQL standard) as distinct here.
            foreach (self::uniqueGroups($entity) as $group) {
                $constraints[] = 'UNIQUE (' . implode(', ', array_map([self::class, 'q'], $group)) . ')';
            }
            $sql[$table] = "CREATE TABLE " . self::q($table) . " (\n  "
                . implode(",\n  ", array_merge($lines, $constraints)) . "\n)";
        }

        foreach ($model['junctions'] as $j) {
            $l = self::q($j['left_column']);
            $r = self::q($j['right_column']);
            // n:n self-reference: no row linked to itself; {symmetric} stores every pair exactly once,
            // canonically with the smaller ID on the left (Cms::syncLinks) - "<" rules out both at once
            $check = '';
            if ($j['left'] === $j['right']) {
                $check = "  CHECK ($l " . (!empty($j['symmetric']) ? '<' : '<>') . " $r),\n";
            }
            $sql[$j['table']] = "CREATE TABLE " . self::q($j['table']) . " (\n"
                . "  $l INTEGER NOT NULL,\n"
                . "  $r INTEGER NOT NULL,\n"
                . "  PRIMARY KEY ($l, $r),\n"
                . $check
                . "  FOREIGN KEY ($l) REFERENCES " . self::q($j['left']) . "(\"id\") ON DELETE CASCADE,\n"
                . "  FOREIGN KEY ($r) REFERENCES " . self::q($j['right']) . "(\"id\") ON DELETE CASCADE\n"
                . ")";
        }

        // Media fields: link table to the system table media (created by Media::ensureTables(), an FK to a table that
        // does not exist yet is allowed in SQLite). A medium appears at most once in the same list; position
        // is the order (0, 1, ...). Deleting the row takes its links along; a medium that is still linked, in contrast,
        // cannot be deleted (no ON DELETE, Media::delete() checks beforehand itself for a comprehensible 409).
        foreach ($model['entities'] as $table => $entity) {
            foreach ($entity['media'] ?? [] as $m) {
                $own = self::q($m['own_column']);
                $sql[$m['junction']] = "CREATE TABLE " . self::q($m['junction']) . " (\n"
                    . "  $own INTEGER NOT NULL,\n"
                    . "  \"media_id\" INTEGER NOT NULL,\n"
                    . "  \"position\" INTEGER NOT NULL,\n"
                    . "  PRIMARY KEY ($own, \"media_id\"),\n"
                    . "  FOREIGN KEY ($own) REFERENCES " . self::q($table) . "(\"id\") ON DELETE CASCADE,\n"
                    . "  FOREIGN KEY (\"media_id\") REFERENCES \"media\"(\"id\")\n"
                    . ")";
            }
        }

        return $sql;
    }

    /**
     * Uniqueness rules of an entity: the {unique} group (PumlParser: unique_fields, composite) and, per
     * 1:1 relationship, its FK column alone (foreign_key.one_to_one). A 1:1 column that already forms the {unique} group
     * on its own does not appear twice.
     *
     * @return string[][] list of column lists
     */
    public static function uniqueGroups(array $entity): array
    {
        $groups = [];
        if (!empty($entity['unique_fields'])) {
            $groups[] = $entity['unique_fields'];
        }
        foreach ($entity['fields'] as $f) {
            if (!empty($f['foreign_key']['one_to_one']) && !in_array([$f['name']], $groups, true)) {
                $groups[] = [$f['name']];
            }
        }
        return $groups;
    }

    /** Quote an identifier (names are already restricted to [A-Za-z0-9_] by the parser). */
    public static function q(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
