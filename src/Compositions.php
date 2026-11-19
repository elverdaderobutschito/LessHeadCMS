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
 * Compositions of a whole (`Seite *-- "n" TextBlock : auf`, see PumlParser): the FK columns with foreign_key.composition
 * that point at it, grouped the way the form of the whole shows them.
 *
 * Parts whose sort field is one order SHARED by several classes (sort in an abstract class, {by} = exactly the column of
 * the composition) form ONE group: one section in the form, one mixed list in that order. Every other part is a group of
 * its own. Groups follow the model order of their first part.
 *
 * Group: [
 *   'name'     => key in /api/{whole}/{id}/parts/{name}: the normalized label (as for FK column names); without a label the
 *                 table of the part (shared: of the abstract class); on a collision with "_<table>" appended,
 *   'label'    => label as written ('' = none),
 *   'abstract' => name of the abstract class of a shared order (heading of the section) or null,
 *   'parts'    => [table => FK column] in model order,
 *   'min'      => [tables with the minimum count "1..*"],
 *   'shared'   => bool (one order over several classes),
 *   'sort'     => [table => sort field name] - only for parts whose order is grouped by this column (otherwise the parts have
 *                 no order of their own within the whole and are listed by id),
 *   'group_by' => [table => the further {by} columns of that order] (subgroups within the whole, e.g. the parent item of a
 *                 navigation item; [] = one order per whole)
 * ]
 */
final class Compositions
{
    /** @return array<int,array> groups of the whole $table, see class comment */
    public static function of(array $model, string $table): array
    {
        $groups = [];
        foreach ($model['entities'] as $t => $e) {
            foreach ($e['fields'] as $f) {
                if (empty($f['foreign_key']['composition']) || $f['foreign_key']['table'] !== $table) {
                    continue;
                }
                $sort = Sorting::fieldOf($e);
                // the order of the part is one within its whole if it is grouped by the column of the composition (perhaps
                // together with further columns - e.g. the parent item of a navigation item: subgroups within the whole)
                $own = $sort !== null && in_array($f['name'], $sort['sort']['by'], true);
                $shared = $own && Sorting::shared($sort) && $sort['sort']['by'] === [$f['name']];
                $key = $shared ? 'shared:' . implode(',', $sort['sort']['tables']) : "part:$t";
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'label' => $f['foreign_key']['label'] ?? '', 'abstract' => $shared ? self::base($model, $e) : null,
                        'parts' => [], 'min' => [], 'shared' => $shared, 'sort' => [], 'group_by' => [],
                    ];
                }
                $groups[$key]['parts'][(string) $t] = $f['name'];
                if (!empty($f['foreign_key']['min_required'])) {
                    $groups[$key]['min'][] = (string) $t;
                }
                if ($own) {
                    $groups[$key]['sort'][(string) $t] = $sort['name'];
                    $groups[$key]['group_by'][(string) $t] = array_values(array_diff($sort['sort']['by'], [$f['name']]));
                }
            }
        }
        $out = [];
        $used = [];
        foreach ($groups as $g) {
            $first = (string) array_key_first($g['parts']);
            $name = PumlParser::normalizeLabel($g['label']);
            if ($name === '') {
                $name = $g['abstract'] !== null ? strtolower($g['abstract']) : $first;
            }
            if (isset($used[$name])) {
                $name .= '_' . $first;
            }
            $used[$name] = true;
            $out[] = ['name' => $name] + $g;
        }
        return $out;
    }

    /** The group $name (or with that label, case-insensitive) of the whole $table, null if there is none */
    public static function find(array $model, string $table, string $name): ?array
    {
        $groups = self::of($model, $table);
        foreach ($groups as $g) {
            if ($g['name'] === $name) {
                return $g;
            }
        }
        $wanted = PumlParser::normalizeLabel($name);
        foreach ($groups as $g) {
            if ($wanted !== '' && PumlParser::normalizeLabel($g['label']) === $wanted) {
                return $g;
            }
        }
        return null;
    }

    /**
     * The whole of a part: [FK column, table of the whole] or null if $e is no part. A class is part of at most one whole
     * (PumlParser).
     *
     * @return array{0:string,1:string}|null
     */
    public static function ownerOf(array $e): ?array
    {
        foreach ($e['fields'] as $f) {
            if (!empty($f['foreign_key']['composition'])) {
                return [$f['name'], $f['foreign_key']['table']];
            }
        }
        return null;
    }

    /** Name of the abstract class the parts of a shared order inherit from (the direct base of the part), or null */
    private static function base(array $model, array $e): ?string
    {
        return $e['extends'] ?? null;
    }
}
