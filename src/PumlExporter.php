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
 * Writes a model JSON (see PumlParser::modelJson()) back as PlantUML text. The goal is semantic equality:
 * PumlParser::modelJson(toPuml($m)) yields the same entities, fields, relationships and enums as $m (without warnings) -
 * comments, formatting and decorative statements of the original text no longer exist in the model JSON.
 *
 * Used by the diagram editor (POST /api/_schema/export: model JSON edited in the diagram -> text for the text field,
 * check() beforehand) and by the round-trip tests (backend/tests/parser_test.php, e2e/fuzz).
 *
 * Order: enums, then the classes in model order (consecutive classes of the same package in one
 * shared package block - several blocks of the same name result in one group anyway), then the relationships in
 * model order. The order of classes, fields and relationships determines the column and table order, so it
 * is kept. Relationships are always written forwards (from_entity first, for n:1/1:1 with "-->").
 */
final class PumlExporter
{
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Checks a model JSON coming from outside (diagram editor) for its form and for every value ending up in the generated
     * text exactly where it belongs: names, types and enum values as identifiers, everything else single-line, package
     * without quotes, label without curly braces (otherwise "x {cascade}" would become a marker), multiplicity without
     * either - otherwise e.g. a name with "}" and a line break could create another class in the text. Whether the diagram
     * is valid as a schema is checked by the parser afterwards.
     *
     * @return string[] error messages (empty = fine)
     */
    public static function check($model): array
    {
        if (!is_array($model)) {
            return ['Model-JSON erwartet.'];
        }
        $errors = [];
        $list = function (string $key, array $in, string $where) use (&$errors): array {
            $v = $in[$key] ?? null;
            if (!is_array($v) || array_values($v) !== $v) {
                $errors[] = "$where: '$key' muss eine Liste sein.";
                return [];
            }
            return $v;
        };
        $text = function (array $in, string $key, string $where, string $pattern, bool $nullable = false) use (&$errors): void {
            $v = $in[$key] ?? null;
            if ($v === null && $nullable) {
                return;
            }
            if (!is_string($v) || !preg_match($pattern, $v)) {
                $errors[] = "$where: ungültiger Wert für '$key'" . (is_string($v) ? " ('$v')" : '') . '.';
            }
        };
        $flag = function (array $in, string $key, string $where) use (&$errors): void {
            if (!is_bool($in[$key] ?? null)) {
                $errors[] = "$where: '$key' muss true oder false sein.";
            }
        };
        $label = '/^[^\r\n{}]*$/u'; // one line, without {}
        foreach ($list('enums', $model, 'Model') as $i => $en) {
            $where = 'Enum ' . ($i + 1);
            if (!is_array($en)) {
                $errors[] = "$where: Objekt erwartet.";
                continue;
            }
            $text($en, 'name', $where, self::IDENT);
            foreach ($list('values', $en, $where) as $v) {
                if (!is_string($v) || !preg_match(self::IDENT, $v)) {
                    $errors[] = "$where: ungültiger Wert " . json_encode($v, JSON_UNESCAPED_UNICODE) . '.';
                }
            }
        }
        foreach ($list('entities', $model, 'Model') as $i => $e) {
            $where = 'Entität ' . ($i + 1);
            if (!is_array($e)) {
                $errors[] = "$where: Objekt erwartet.";
                continue;
            }
            $text($e, 'name', $where, self::IDENT);
            $text($e, 'package', $where, '/^(?=.*\S)[^\r\n"]+$/u', true);
            $text($e, 'extends', $where, self::IDENT, true);
            $text($e, 'renamed_from', $where, self::IDENT, true);
            $flag($e, 'abstract', $where);
            foreach (['title_fields', 'unique_fields'] as $k) {
                foreach ($list($k, $e, $where) as $n) {
                    if (!is_string($n)) {
                        $errors[] = "$where: '$k' enthält keinen Namen.";
                    }
                }
            }
            foreach ($list('fields', $e, $where) as $f) {
                if (!is_array($f)) {
                    $errors[] = "$where: Feld als Objekt erwartet.";
                    continue;
                }
                $text($f, 'name', $where, self::IDENT);
                $text($f, 'type', $where, self::IDENT);
                if (($f['type'] ?? null) === 'enum') {
                    $text($f, 'enum_name', $where, self::IDENT);
                }
                $text($f, 'renamed_from', $where, self::IDENT, true);
                $flag($f, 'required', $where);
            }
            foreach ($list('media', $e, $where) as $m) {
                if (!is_array($m)) {
                    $errors[] = "$where: Medien-Feld als Objekt erwartet.";
                    continue;
                }
                $text($m, 'name', $where, self::IDENT);
                $text($m, 'renamed_from', $where, self::IDENT, true);
                $flag($m, 'required', $where);
            }
        }
        foreach ($list('relations', $model, 'Model') as $i => $r) {
            $where = 'Beziehung ' . ($i + 1);
            if (!is_array($r)) {
                $errors[] = "$where: Objekt erwartet.";
                continue;
            }
            if (!in_array($r['kind'] ?? null, ['n1', 'one_to_one', 'nn'], true)) {
                $errors[] = "$where: 'kind' muss n1, one_to_one oder nn sein.";
            }
            $text($r, 'from_entity', $where, self::IDENT);
            $text($r, 'to_entity', $where, self::IDENT);
            $text($r, 'from_multiplicity', $where, '/^[0-9a-z.*]+$/i');
            $text($r, 'to_multiplicity', $where, '/^[0-9a-z.*]+$/i');
            $text($r, 'label', $where, $label);
            $text($r, 'renamed_from', $where, $label, true);
            foreach (['show_label', 'cascade', 'unique', 'symmetric'] as $k) {
                $flag($r, $k, $where);
            }
            // {filter_by}: "field" or "sourcefield=targetfield"; missing in model JSON from before this option (= no filter)
            if (($r['filter_by'] ?? null) !== null && $r['filter_by'] !== '') {
                $text($r, 'filter_by', $where, '/^\w+(=\w+)?$/');
            }
        }
        return $errors;
    }

    public static function toPuml(array $model): string
    {
        $out = ['@startuml', ''];
        foreach ($model['enums'] ?? [] as $enum) {
            if (!empty($enum['workflow'])) {
                continue; // states of a workflow: they live in the workflow file, not in the diagram text
            }
            $out[] = "enum {$enum['name']} {";
            foreach ($enum['values'] as $value) {
                $out[] = "  $value";
            }
            $out[] = '}';
            $out[] = '';
        }

        $package = null;
        foreach ($model['entities'] as $e) {
            if ($e['package'] !== $package) {
                if ($package !== null) {
                    $out[] = '}';
                    $out[] = '';
                }
                if ($e['package'] !== null) {
                    $out[] = 'package "' . $e['package'] . '" {';
                }
                $package = $e['package'];
            }
            $head = ($e['abstract'] ? 'abstract ' : '') . 'class ' . $e['name']
                . ($e['extends'] !== null ? ' extends ' . $e['extends'] : '')
                . (($e['renamed_from'] ?? null) !== null ? ' {renamed_from:' . $e['renamed_from'] . '}' : '');
            $out[] = $head . ' {';
            foreach ($e['fields'] as $f) {
                $type = $f['type'] === 'enum' ? $f['enum_name'] : $f['type'];
                $markers = '';
                if (in_array($f['name'], $e['title_fields'], true)) {
                    $markers .= ' {title}';
                }
                if (in_array($f['name'], $e['unique_fields'], true)) {
                    $markers .= ' {unique}';
                }
                if (($f['renamed_from'] ?? null) !== null) {
                    $markers .= ' {renamed_from:' . $f['renamed_from'] . '}';
                }
                // the id is never optional ("required" is internally false there)
                $optional = !$f['required'] && empty($f['primary']) ? '?' : '';
                $out[] = "  + {$f['name']}: $type$optional$markers";
            }
            foreach ($e['media'] as $m) {
                $out[] = "  + {$m['name']}: " . PumlParser::MEDIA_TYPE . ($m['required'] ? '' : '?')
                    . (($m['renamed_from'] ?? null) !== null ? ' {renamed_from:' . $m['renamed_from'] . '}' : '');
            }
            $out[] = '}';
            $out[] = '';
        }
        if ($package !== null) {
            $out[] = '}';
            $out[] = '';
        }

        foreach ($model['relations'] as $r) {
            $markers = [];
            if ($r['show_label']) {
                $markers[] = '{label}';
            }
            if ($r['cascade']) {
                $markers[] = '{cascade}';
            }
            if ($r['unique']) {
                $markers[] = '{unique}';
            }
            if ($r['symmetric']) {
                $markers[] = '{symmetric}';
            }
            if (($r['filter_by'] ?? null) !== null && $r['filter_by'] !== '') {
                $markers[] = '{filter_by:' . $r['filter_by'] . '}';
            }
            if (($r['renamed_from'] ?? null) !== null) {
                $markers[] = '{renamed_from:' . $r['renamed_from'] . '}';
            }
            $arrow = $r['kind'] === 'nn' ? '--' : '-->';
            $line = "{$r['from_entity']} \"{$r['from_multiplicity']}\" $arrow \"{$r['to_multiplicity']}\" {$r['to_entity']}";
            if ($r['label'] !== '') {
                $line .= ' : ' . $r['label'];
            }
            $out[] = $line . ($markers ? ' ' . implode(' ', $markers) : '');
        }
        $out[] = '';
        $out[] = '@enduml';
        return implode("\n", $out) . "\n";
    }
}
