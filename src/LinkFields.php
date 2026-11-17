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
 * Additional columns of a link table: values that belong to ONE link (this medium in this record and field), not to the
 * linked row itself. Today these are the fields of a metadata class (`media(GalerieBild)` with
 * `class GalerieBild <<media_meta>>`, see PumlParser::parseMetaClass()), stored as extra columns of the media link table
 * `<table>_<field>_media` next to `<table>_id`, `media_id` and `position`.
 *
 * The class knows nothing about media: it works on a field list (same structure as the fields of an entity: name, type,
 * sql_type, required, enum_values, rules) and a row/value map. That is the shared part for further link tables with a
 * position and extra columns (n:n with {ordered} and link data, roadmap 6a): column definitions (SqlGenerator), reading
 * with native types, writing via Cms::coerce() and the field rules (Validation) - the same logic as for the fields of an
 * entity, so API, form and migration treat a metadata field exactly like a normal field.
 *
 * Values are addressed in messages as "<list>[<position>].meta.<field>" (e.g. bilder[2].meta.caption), see path().
 */
final class LinkFields
{
    /** Prefix of the column aliases when the extra columns are selected together with the linked row (e.g. media.*) */
    private const ALIAS = '__link_';

    /** Extra fields of a link (media field of the model); empty = plain link table as before */
    public static function of(array $link): array
    {
        return $link['meta']['fields'] ?? [];
    }

    /** Column definitions for CREATE TABLE (type, NOT NULL for required, CHECK for enums - as for entity columns) */
    public static function columnDefinitions(array $fields): array
    {
        return array_map([SqlGenerator::class, 'columnDefinition'], $fields);
    }

    /** ", j."caption" AS "__link_caption", ..." - the extra columns next to other selected columns (empty without fields) */
    public static function selectList(array $fields, string $alias): string
    {
        $out = '';
        foreach ($fields as $f) {
            $out .= ', ' . $alias . '.' . SqlGenerator::q($f['name']) . ' AS ' . SqlGenerator::q(self::ALIAS . $f['name']);
        }
        return $out;
    }

    /**
     * Values of a row selected with selectList() (or, $aliased = false, selected under their own names), with native types
     * like the fields of a record (int, float, bool). Denied fields ($hidden: field name => true) are left out.
     */
    public static function read(array $fields, array $row, array $hidden = [], bool $aliased = true): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (isset($hidden[$f['name']])) {
                continue;
            }
            $key = $aliased ? self::ALIAS . $f['name'] : $f['name'];
            $out[$f['name']] = self::cast($f, $row[$key] ?? null);
        }
        return $out;
    }

    /** Stored value -> API value (pdo_sqlite returns strings or native types depending on the PHP version) */
    public static function cast(array $f, $v)
    {
        if ($v === null) {
            return null;
        }
        if ($f['type'] === 'int') {
            return (int) $v;
        }
        if ($f['type'] === 'decimal') {
            return (float) $v;
        }
        if ($f['type'] === 'bool' || $f['type'] === 'visibility') {
            return (bool) (int) $v;
        }
        return $v;
    }

    /** Storage form of a stored or cast value (bool as 1/0), e.g. for writing back values that were not sent */
    public static function stored(array $f, $v)
    {
        return is_bool($v) ? (int) $v : $v;
    }

    /** Message key of a value: "bilder[2].meta.caption" ($field null: "bilder[2].meta") */
    public static function path(string $list, int $position, ?string $field = null): string
    {
        return "{$list}[{$position}].meta" . ($field !== null ? ".$field" : '');
    }

    /**
     * Values of ONE link after a write: $current (stored values of an existing link, storage form; empty = new link: NULL)
     * with the sent values $input applied - only the keys sent change, the others stay (like PUT on _media/{id}).
     * Sent keys of denied fields ($hidden) are discarded silently (the stored value stays), as for the fields of a record.
     * Checks like for record fields: type (Cms::coerce), required (NOT NULL) and the field rules (Validation) of the
     * complete link - errors under path() in $errors (unknown keys too), rule violations in $violations (Validation format,
     * 'field' = path). $label prefixes every message (e.g. "Medium #7: ...") so that it names the linked row.
     *
     * @return array<string,mixed> field name => value in storage form (all fields, also the denied ones)
     */
    public static function apply(
        array $fields, array $current, array $input, array $hidden, string $list, int $position, callable $label,
        Validation $validation, array &$errors, array &$violations
    ): array {
        $values = [];
        $known = [];
        foreach ($fields as $f) {
            $values[$f['name']] = $current[$f['name']] ?? null;
            $known[$f['name']] = true;
        }
        foreach ($input as $key => $value) {
            if (!isset($known[$key])) {
                $errors[self::path($list, $position, (string) $key)] = $label(Msg::of('msg_records.link_field_unknown',
                    "Unknown metadata field '{field}' (allowed: {allowed})", [
                        'field' => (string) $key, 'allowed' => implode(', ', array_keys(array_diff_key($known, $hidden))),
                    ]));
            }
        }
        $bad = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            if (isset($hidden[$name]) || !array_key_exists($name, $input)) {
                continue;
            }
            try {
                $values[$name] = Cms::coerce($f, $input[$name]);
            } catch (\InvalidArgumentException $ex) {
                $errors[self::path($list, $position, $name)] = $label(ValueException::msgOf($ex));
                $bad[$name] = true;
            }
        }
        foreach ($fields as $f) {
            if ($f['required'] && $values[$f['name']] === null && !isset($bad[$f['name']])) {
                $errors[self::path($list, $position, $f['name'])] = $label(Msg::of('msg_api.required', 'Required field'));
                $bad[$f['name']] = true;
            }
        }
        foreach ($validation->violations(['fields' => $fields], $values, $bad + $hidden) as $v) {
            $violations[] = self::labelled($v, $list, $position, $label);
        }
        return $values;
    }

    /**
     * Rule violation of a link field (Validation format) for the API: field = path(), message/code from $label (e.g.
     * "Medium #7: At most 80 characters ..." - code msg_records.media_meta_error with the rule message nested as 'error');
     * the parameters of the rule (min, max, count, ...) stay next to them.
     */
    public static function labelled(array $v, string $list, int $position, callable $label): array
    {
        $msg = $label(Msg::fromArray(['code' => $v['code'] ?? null, 'message' => $v['message'], 'params' => $v['params'] ?? []]));
        return ['field' => self::path($list, $position, $v['field']), 'message' => $msg->text(), 'code' => $msg->code,
            'params' => (array) $msg->codeOnly()['params'] + (array) ($v['params'] ?? [])] + $v;
    }
}
