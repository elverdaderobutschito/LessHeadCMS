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
 * Language versions of a class with `{translations}` (`Seite "n" --> "0..1" Seite : Original von {translations} {unique}`,
 * see PumlParser): purely structural - the relation is the FK with foreign_key.translations, the language field is the one
 * enum field in the uniqueness group the relation stands in (the unnamed {unique} group or a named {unique: name}).
 * Nothing depends on names.
 *
 * A translation group is flat: the original (relation empty) and every row whose relation points at it. Creating a version
 * from a version adds it to the same original (Cms::translate()).
 */
final class Translations
{
    /**
     * The structure of $e or null without {translations}:
     * ['relation' => FK column, 'language_field' => enum field, 'enum' => enum name, 'values' => enum values in order]
     */
    public static function of(array $e): ?array
    {
        $relation = null;
        foreach ($e['fields'] as $f) {
            if (!empty($f['foreign_key']['translations'])) {
                $relation = $f['name'];
            }
        }
        if ($relation === null) {
            return null;
        }
        // the group the relation stands in (the unnamed {unique} group or a named one, see PumlParser::checkTranslations())
        $rules = SqlGenerator::rulesWith($e, $relation);
        $group = $rules ? $rules[0]['columns'] : [];
        foreach ($e['fields'] as $f) {
            if ($f['type'] === 'enum' && !isset($f['workflow']) && in_array($f['name'], $group, true)) {
                return ['relation' => $relation, 'language_field' => $f['name'], 'enum' => $f['enum'] ?? null, 'values' => $f['enum_values']];
            }
        }
        return null; // cannot happen with a model the parser accepted
    }

    /** id of the original of row $id (the row itself if it is one), null if the row does not exist */
    public static function originalOf(PDO $pdo, array $e, array $tr, int $id): ?int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(' . SqlGenerator::q($tr['relation']) . ', "id") FROM ' . SqlGenerator::q($e['table'])
            . ' WHERE "id" = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $v === false ? null : (int) $v;
    }

    /**
     * The rows of the group of $original (original first, then by id): id => row with the language field, the relation and
     * $extra columns
     *
     * @param string[] $extra further columns (title, visibility)
     * @return array<int,array>
     */
    public static function rows(PDO $pdo, array $e, array $tr, int $original, array $extra = []): array
    {
        $rel = SqlGenerator::q($tr['relation']);
        $cols = array_unique(array_merge(['id', $tr['language_field'], $tr['relation']], $extra));
        $stmt = $pdo->prepare('SELECT ' . implode(', ', array_map([SqlGenerator::class, 'q'], $cols)) . ' FROM '
            . SqlGenerator::q($e['table']) . " WHERE \"id\" = ? OR $rel = ? ORDER BY ($rel IS NOT NULL), \"id\"");
        $stmt->execute([$original, $original]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** id of the version of row $id in $language (in the group of its original), null if there is none */
    public static function versionIn(PDO $pdo, array $e, array $tr, int $id, string $language): ?int
    {
        $original = self::originalOf($pdo, $e, $tr, $id);
        if ($original === null) {
            return null;
        }
        foreach (self::rows($pdo, $e, $tr, $original) as $rid => $r) {
            if ($r[$tr['language_field']] === $language) {
                return $rid;
            }
        }
        return null;
    }
}
