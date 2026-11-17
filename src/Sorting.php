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
 * Manual order of records (field type `sort`, see PumlParser): the values of the INTEGER column.
 *
 * A group is the combination of the values of the `{by: …}` FK columns (none = the whole table; an empty optional parent
 * is a group of its own). Within a group the values are distinct; gaps are fine (deleting leaves one). If the field is
 * written in an abstract class, all its concrete subclasses share the numbers of a group ('tables' of the field): a new
 * record gets the highest value of the group over all of these tables + 1.
 *
 *  - next():      value for a new record or one that changed its group - the end of the group
 *  - neighbour(): the record a move swaps with (Cms::move() - the caller passes the conditions of the list filter)
 *  - renumber():  1, 2, 3, ... per group in the existing order (value, id, table) - schema migration (new sort field, int
 *                 -> sort, changed groups) and the repair of equal values (rows written past the CMS)
 *  - groupPaths(): what the list view needs for the group headings
 */
final class Sorting
{
    /** Value that sorts after every real one: existing rows without a value yet (schema migration), numbered right after */
    public const UNSET = 2147483647;

    /** The sort field of an entity (at most one, see PumlParser) or null */
    public static function fieldOf(array $entity): ?array
    {
        foreach ($entity['fields'] as $f) {
            if ($f['type'] === PumlParser::SORT_TYPE && isset($f['sort'])) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Values of the group columns of a row: column => int|null (in the order of {by: …})
     *
     * @param array $row values by column name (missing = null)
     */
    public static function groupOf(array $field, array $row): array
    {
        $out = [];
        foreach ($field['sort']['by'] as $col) {
            $v = $row[$col] ?? null;
            $out[$col] = $v === null || $v === '' ? null : (int) $v;
        }
        return $out;
    }

    /** SQL condition "row belongs to this group" for the table of $field (alias with a dot, or '') */
    public static function groupCondition(array $field, array $group, string $alias = ''): string
    {
        $parts = [];
        foreach ($field['sort']['by'] as $col) {
            $v = $group[$col] ?? null;
            $parts[] = $alias . SqlGenerator::q($col) . ($v === null ? ' IS NULL' : ' = ' . (int) $v);
        }
        return $parts ? implode(' AND ', $parts) : '1';
    }

    /**
     * The group of $field's table expressed for every table that shares the order: table => [sort field of that table,
     * group with its column names] (the FK columns can be named differently per subclass, the positions match).
     *
     * @return array<string,array{0:array,1:array}>
     */
    private static function perTable(array $model, array $field, array $group): array
    {
        $values = array_values($group);
        $out = [];
        foreach ($field['sort']['tables'] as $t) {
            $f = isset($model['entities'][$t]) ? self::fieldOf($model['entities'][$t]) : null;
            if ($f === null) {
                continue;
            }
            $g = [];
            foreach ($f['sort']['by'] as $i => $col) {
                $g[$col] = $values[$i] ?? null;
            }
            $out[$t] = [$f, $g];
        }
        return $out;
    }

    /** Next free value at the end of a group (highest value of the group over all tables sharing the order + 1) */
    public static function next(PDO $pdo, array $model, array $field, array $group): int
    {
        $max = 0;
        foreach (self::perTable($model, $field, $group) as $t => [$f, $g]) {
            $v = $pdo->query('SELECT MAX(' . SqlGenerator::q($f['name']) . ') FROM ' . SqlGenerator::q((string) $t) . ' WHERE '
                . self::groupCondition($f, $g) . ' AND ' . SqlGenerator::q($f['name']) . ' < ' . self::UNSET)->fetchColumn();
            $max = max($max, (int) $v);
        }
        return $max + 1;
    }

    /**
     * Rank of a record in its group right now (1 = first), counted over all tables sharing the order: the number of rows
     * before it (smaller value; equal values by id) + 1. Stored with every version (Revisions) - the value itself says
     * little once there are gaps.
     *
     * @param array $row the record (its id, sort value and group columns)
     */
    public static function place(PDO $pdo, array $model, array $field, array $row): int
    {
        $value = (int) ($row[$field['name']] ?? 0);
        $id = (int) $row['id'];
        $before = 0;
        foreach (self::perTable($model, $field, self::groupOf($field, $row)) as $t => [$f, $g]) {
            $col = SqlGenerator::q($f['name']);
            $before += (int) $pdo->query('SELECT COUNT(*) FROM ' . SqlGenerator::q((string) $t) . ' WHERE ' . self::groupCondition($f, $g)
                . " AND ($col < $value OR ($col = $value AND \"id\" < $id))")->fetchColumn();
        }
        return $before + 1;
    }

    /**
     * The record a move swaps with: the next one before (up) or after (down) $row in its group and its own table that
     * meets $where - [id, value] or null at the edge of the group.
     *
     * @param string $where ' WHERE (...)' of the list query (alias e) or ''
     * @param array $params its parameters
     * @return array{0:int,1:int}|null
     */
    public static function neighbour(PDO $pdo, array $entity, array $field, array $row, bool $up, string $where, array $params): ?array
    {
        $col = 'e.' . SqlGenerator::q($field['name']);
        $value = (int) $row[$field['name']];
        $id = (int) $row['id'];
        $op = $up ? '<' : '>';
        $dir = $up ? 'DESC' : 'ASC';
        $cond = self::groupCondition($field, self::groupOf($field, $row), 'e.')
            . " AND ($col $op $value OR ($col = $value AND e.\"id\" $op $id))";
        $stmt = $pdo->prepare("SELECT e.\"id\", $col FROM " . SqlGenerator::q($entity['table']) . ' e'
            . ($where === '' ? ' WHERE ' : $where . ' AND ') . "$cond ORDER BY $col $dir, e.\"id\" $dir LIMIT 1");
        $stmt->execute($params);
        $hit = $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();
        return $hit === false ? null : [(int) $hit[0], (int) $hit[1]];
    }

    /**
     * Number the rows 1, 2, 3, ... per group, keeping their order (value, then id, then table in model order); rows with
     * UNSET come last. Only rows whose value changes are written.
     *
     * @param array $field sort field of any of the tables sharing the order
     * @param array|null $group only this group (as groupOf() of $field's table); null = every group
     * @return int number of rows that got another value
     */
    public static function renumber(PDO $pdo, array $model, array $field, ?array $group = null): int
    {
        $rows = [];
        $tables = [];
        foreach ($field['sort']['tables'] as $i => $t) {
            $f = isset($model['entities'][$t]) ? self::fieldOf($model['entities'][$t]) : null;
            if ($f === null) {
                continue;
            }
            $tables[$i] = [$t, $f];
            $cols = array_merge(['id', $f['name']], $f['sort']['by']);
            $sql = 'SELECT ' . implode(', ', array_map([SqlGenerator::class, 'q'], $cols)) . ' FROM ' . SqlGenerator::q((string) $t);
            if ($group !== null) {
                $g = self::perTable($model, $field, $group)[$t][1];
                $sql .= ' WHERE ' . self::groupCondition($f, $g);
            }
            $stmt = $pdo->query($sql);
            while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $key = implode(':', array_map(function ($v) {
                    return $v === null ? '' : (string) (int) $v;
                }, array_slice($r, 2)));
                $rows[$key][] = [$r[1] === null ? self::UNSET : (int) $r[1], (int) $r[0], $i];
            }
            $stmt->closeCursor();
        }
        $changed = 0;
        $update = [];
        foreach ($rows as $list) {
            usort($list, function ($a, $b) {
                return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
            });
            foreach ($list as $n => [$value, $id, $i]) {
                if ($value === $n + 1) {
                    continue;
                }
                if (!isset($update[$i])) {
                    [$t, $f] = $tables[$i];
                    $update[$i] = $pdo->prepare('UPDATE ' . SqlGenerator::q((string) $t) . ' SET ' . SqlGenerator::q($f['name']) . ' = ? WHERE "id" = ?');
                }
                $update[$i]->execute([$n + 1, $id]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Group headings of a list page: key of a group ("3:" - the values of the group columns, empty = no parent) => path of
     * the parents, one entry per {by: …} value: ['table' => target table, 'id' => id|null, 'self' => bool (relationship to
     * the own class)]. For a self-reference the ancestors come first (root first), so the path reads "Leistungen › Web".
     *
     * @param array[] $groups groups as groupOf()
     * @return array<string,array[]>
     */
    public static function groupPaths(PDO $pdo, array $entity, array $field, array $groups): array
    {
        $out = [];
        $selfCol = null; // FK column of the self-reference: the chain of ancestors follows it
        foreach ($field['sort']['by'] as $i => $col) {
            if ($field['sort']['targets'][$i] === $entity['table']) {
                $selfCol = $col;
            }
        }
        $parent = [];
        if ($selfCol !== null) {
            $todo = [];
            foreach ($groups as $g) {
                if ($g[$selfCol] !== null) {
                    $todo[$g[$selfCol]] = true;
                }
            }
            for ($level = 0; $todo && $level < 20; $level++) {
                $stmt = $pdo->query('SELECT "id", ' . SqlGenerator::q($selfCol) . ' FROM ' . SqlGenerator::q($entity['table'])
                    . ' WHERE "id" IN (' . implode(', ', array_map('intval', array_keys($todo))) . ')');
                $todo = [];
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $up]) {
                    $parent[(int) $id] = $up === null ? null : (int) $up;
                    if ($up !== null && !array_key_exists((int) $up, $parent)) {
                        $todo[(int) $up] = true;
                    }
                }
            }
        }
        foreach ($groups as $g) {
            $path = [];
            foreach ($field['sort']['by'] as $i => $col) {
                $table = $field['sort']['targets'][$i];
                $self = $table === $entity['table'];
                if ($g[$col] === null || !$self) {
                    $path[] = ['table' => $table, 'id' => $g[$col], 'self' => $self];
                    continue;
                }
                $chain = [];
                $seen = [];
                for ($id = $g[$col]; $id !== null && !isset($seen[$id]) && count($chain) < 20; $id = $parent[$id] ?? null) {
                    $seen[$id] = true;
                    array_unshift($chain, ['table' => $table, 'id' => $id, 'self' => true]);
                }
                $path = array_merge($path, $chain);
            }
            $out[self::key($g)] = $path;
        }
        return $out;
    }

    /** Key of a group: the values of its columns joined by ":" (empty = no parent) */
    public static function key(array $group): string
    {
        return implode(':', array_map(function ($v) {
            return $v === null ? '' : (string) $v;
        }, $group));
    }
}
