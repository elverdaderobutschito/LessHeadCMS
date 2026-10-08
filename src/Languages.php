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
 * Multilingual support, phase 1: languages, translations of the schema labels and the display language per user
 * (system level like users, independent of the PlantUML content schema).
 *
 *  - languages: freely assigned code + name; exactly one entry is_default = 1 - the language the diagram is
 *    written in (purely a label for the language switcher, it changes nothing about the diagram texts). The first
 *    language created becomes the default language; it can be renamed but not deleted.
 *  - translations: translation per language and schema element, addressed via the stable ID from schema_ids (ref_id) -
 *    they therefore survive renames by migration without further action. kind: entity, field (media fields too), relation,
 *    enum, enum_value (ref_id = ID of the enum, value = the value in plain text; individual values have no ID of their own).
 *    If a migration removes an element, SchemaIds deletes the translations along with it (like the layout position);
 *    translations of removed enum values are cleaned up by pruneEnumValues().
 *    Phase 2 (fixed UI texts): kind = 'ui_text', ref_id = fixed key from frontend/src/i18n-strings.js (e.g.
 *    "login.submit"). The German source texts live only there; only the translations are stored here. That is why ref_id
 *    no longer has a foreign key to schema_ids (schema elements are cleared explicitly by SchemaIds::delete() via
 *    deleteRefs() anyway). Tables from phase 1 are rebuilt once by ensureTables() (migrateTranslations()).
 *  - user_language_prefs: chosen display language per user (any role). Without an entry the default language applies.
 *
 * The tables are created on the first write (creating a language, choosing a language); read access treats missing
 * tables as empty. An existing installation therefore does not need another bootstrap, and without multilingual
 * support the database stays as before.
 *
 * Only the display in the editorial UI is translated (entity names, field and relationship labels,
 * enum values); stored values and the public API stay unchanged.
 *
 * Display override of the default language: names in the diagram must be ASCII-safe (veroeffentlicht), the derived
 * label is then „Veroeffentlicht“. Schema elements can therefore also be "translated" for the default language
 * (same table, language_id of the default language) - fixed UI texts cannot, they are freely editable in
 * i18n-strings.js. Displayed is (labels()): text of the display language, otherwise the override of the default
 * language, otherwise the derived label.
 */
final class Languages
{
    public const LANGUAGES = 'languages';
    public const TRANSLATIONS = 'translations';
    public const PREFS = 'user_language_prefs';

    public const KINDS = ['entity', 'field', 'relation', 'enum', 'enum_value', 'ui_text'];

    /** Key of a fixed UI text: area.name, e.g. "login.submit" or "list.filter_aria" */
    public const UI_KEY_PATTERN = '/^[a-z][a-z0-9_]*(\\.[a-z0-9_]+)+$/';
    public const UI_KEY_MAX = 100;

    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,19}$/';
    private const NAME_MAX = 60;
    public const TEXT_MAX = 500;

    // ref_id: schema_ids.id or, for ui_text, the key of the UI text - hence without a foreign key (see above)
    private const TRANSLATIONS_DDL = 'CREATE TABLE IF NOT EXISTS translations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  language_id INTEGER NOT NULL REFERENCES languages(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN (\'entity\', \'field\', \'relation\', \'enum\', \'enum_value\', \'ui_text\')),
  ref_id TEXT NOT NULL,
  value TEXT NOT NULL DEFAULT \'\',
  text TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (language_id, kind, ref_id, value)
)';

    /**
     * Display formats per language (the default language too), explicitly set by the admin - no locale code:
     * date_format/datetime_format follow the convention of PHP date() (the UI converts the placeholders from
     * FORMAT_PLACEHOLDERS, see frontend format.js), plus decimal and thousands separator (the latter may be empty
     * = no grouping). The default values correspond to the formerly hard-coded German representation.
     */
    public const FORMAT_DEFAULTS = [
        'date_format' => 'd.m.Y', 'datetime_format' => 'd.m.Y, H:i', 'decimal_separator' => ',', 'thousands_separator' => '.',
    ];
    public const FORMAT_PLACEHOLDERS = 'djmnYyHGhgisAa';
    private const FORMAT_MAX = 40;

    private const DDL = [
        'CREATE TABLE IF NOT EXISTS languages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL,
  is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0, 1)),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_format TEXT NOT NULL DEFAULT \'d.m.Y\',
  datetime_format TEXT NOT NULL DEFAULT \'d.m.Y, H:i\',
  decimal_separator TEXT NOT NULL DEFAULT \',\',
  thousands_separator TEXT NOT NULL DEFAULT \'.\'
)',
        // at most one default language
        'CREATE UNIQUE INDEX IF NOT EXISTS languages_single_default ON languages (is_default) WHERE is_default = 1',
        self::TRANSLATIONS_DDL,
        'CREATE TABLE IF NOT EXISTS user_language_prefs (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  language_id INTEGER NOT NULL REFERENCES languages(id) ON DELETE CASCADE,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)',
    ];

    public static function ensureTables(PDO $pdo): void
    {
        SchemaIds::ensureTables($pdo);
        foreach (self::DDL as $sql) {
            $pdo->exec($sql);
        }
        // languages from before the format columns: add them, existing languages get the default values (= previous,
        // hard-coded representation)
        $columns = array_column($pdo->query('PRAGMA table_info(languages)')->fetchAll(), 'name');
        foreach (self::FORMAT_DEFAULTS as $column => $default) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec("ALTER TABLE languages ADD COLUMN $column TEXT NOT NULL DEFAULT '$default'");
            }
        }
        self::migrateTranslations($pdo);
    }

    /**
     * Rebuild translations from phase 1 (CHECK without 'ui_text', ref_id with a foreign key to schema_ids) once to the new
     * definition - SQLite cannot change CHECK and foreign keys via ALTER TABLE. Create a new table,
     * copy the rows, drop the old one, rename; all in one transaction. Nothing references translations, so
     * dropping also works with foreign keys switched on.
     */
    private static function migrateTranslations(PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([self::TRANSLATIONS]);
        $sql = (string) $stmt->fetchColumn();
        $stmt->closeCursor(); // an open query would lock DROP TABLE ("database table is locked")
        if ($sql === '' || strpos($sql, "'ui_text'") !== false) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS translations', 'CREATE TABLE translations_new', self::TRANSLATIONS_DDL));
            $pdo->exec('INSERT INTO translations_new (id, language_id, kind, ref_id, value, text, updated_at)
                SELECT id, language_id, kind, ref_id, value, text, updated_at FROM translations');
            $pdo->exec('DROP TABLE translations');
            $pdo->exec('ALTER TABLE translations_new RENAME TO translations');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- Languages

    /** All languages, default language first, then in order of creation */
    public static function all(PDO $pdo): array
    {
        if (!Database::tableExists($pdo, self::LANGUAGES)) {
            return [];
        }
        return array_map([self::class, 'publicLanguage'],
            $pdo->query('SELECT * FROM languages ORDER BY is_default DESC, id')->fetchAll());
    }

    /** GET /api/_languages: {languages, current_id} - current_id = chosen or default language (null without languages) */
    public static function overview(PDO $pdo, int $userId): array
    {
        $languages = self::all($pdo);
        $current = self::current($pdo, $userId);
        return ['languages' => $languages, 'current_id' => $current !== null ? $current['id'] : null];
    }

    /** Display language of the user: chosen language, otherwise the default language; null as long as there are no languages */
    public static function current(PDO $pdo, int $userId): ?array
    {
        $languages = self::all($pdo);
        if (!$languages) {
            return null;
        }
        if (Database::tableExists($pdo, self::PREFS)) {
            $stmt = $pdo->prepare('SELECT language_id FROM user_language_prefs WHERE user_id = ?');
            $stmt->execute([$userId]);
            $chosen = (int) $stmt->fetchColumn();
            foreach ($languages as $l) {
                if ($l['id'] === $chosen) {
                    return $l;
                }
            }
        }
        return $languages[0]['is_default'] ? $languages[0] : null;
    }

    /**
     * POST /api/_languages {code, name, date_format?, datetime_format?, decimal_separator?, thousands_separator?}: the first
     * language becomes the default language (initial setup). Missing format values = default values (FORMAT_DEFAULTS).
     */
    public static function create(PDO $pdo, array $body): array
    {
        self::ensureTables($pdo);
        [$code, $name, $formats] = self::validLanguage($pdo, $body + self::FORMAT_DEFAULTS, null);
        $isDefault = (int) $pdo->query('SELECT COUNT(*) FROM languages')->fetchColumn() === 0;
        $pdo->prepare('INSERT INTO languages (code, name, is_default, date_format, datetime_format, decimal_separator, thousands_separator) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)')->execute(array_merge([$code, $name, $isDefault ? 1 : 0], array_values($formats)));
        return self::find($pdo, (int) $pdo->lastInsertId());
    }

    /** PUT /api/_languages/{id} {code?, name?, date_format?, …}: values not sent stay as they are */
    public static function update(PDO $pdo, int $id, array $body): array
    {
        $old = self::find($pdo, $id);
        self::ensureTables($pdo); // installations from before the format columns get them here
        [$code, $name, $formats] = self::validLanguage($pdo, $body + $old, $id);
        $pdo->prepare('UPDATE languages SET code = ?, name = ?, date_format = ?, datetime_format = ?, decimal_separator = ?, '
            . 'thousands_separator = ? WHERE id = ?')->execute(array_merge([$code, $name], array_values($formats), [$id]));
        return self::find($pdo, $id);
    }

    /** DELETE /api/_languages/{id}: including its translations and the users' choice (who then see the default again) */
    public static function delete(PDO $pdo, int $id): array
    {
        $language = self::find($pdo, $id);
        if ($language['is_default']) {
            throw new ApiException(409, ['error' => 'default_language', 'message' => "Die Standardsprache „{$language['name']}“ kann nicht "
                . 'gelöscht werden (sie beschreibt die Texte des Diagramms). Umbenennen ist möglich.']);
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM translations WHERE language_id = ?');
            $stmt->execute([$id]);
            $removed = $stmt->rowCount();
            $pdo->prepare('DELETE FROM user_language_prefs WHERE language_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM languages WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['deleted' => $id, 'translations_removed' => $removed];
    }

    /** PUT /api/_prefs/language {language_id}: display language of the logged-in user (any role) */
    public static function choose(PDO $pdo, int $userId, array $body): array
    {
        $id = $body['language_id'] ?? null;
        if (!is_int($id)) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sprache fehlt', 'errors' => ['language_id' => 'ID erwartet']]);
        }
        self::find($pdo, $id); // 404
        self::ensureTables($pdo);
        $pdo->prepare('INSERT OR REPLACE INTO user_language_prefs (user_id, language_id, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)')
            ->execute([$userId, $id]);
        return self::overview($pdo, $userId);
    }

    // ---------------------------------------------------------------- Translations

    /**
     * GET /api/_translations?language_id=…: all translatable elements of the active schema, grouped by class (then
     * the enums), per element the original text and the existing translation.
     *
     * Group: {title, kind: 'entity'|'enum', abstract, items: [{kind, ref_id, value, original, type, text}]}. original is
     * the text as it appears in the diagram (field/relationship labels are formatted by the UI with its capitalize()):
     *  - concrete class: name; abstract classes only appear with their fields (which apply to all subclasses)
     *  - fields and media fields, without id
     *  - n:1/1:1: label, without a label the column name without _id (the way form and list show it)
     *  - n:n: only with {label} - without it the UI shows the name of the target class, translated via its class
     *  - enum: name and every value
     */
    public static function catalog(PDO $pdo, int $languageId): array
    {
        $language = self::find($pdo, $languageId);
        SchemaIds::ensure($pdo);
        $groups = self::elements($pdo);
        $existing = self::texts($pdo, $languageId);
        // other languages: override of the default language per element (what is displayed without a translation)
        $overrides = $language['is_default'] ? [] : self::texts($pdo, self::defaultId($pdo));
        foreach ($groups as &$g) {
            foreach ($g['items'] as &$item) {
                $key = self::itemKey($item['kind'], $item['ref_id'], $item['value']);
                $item['text'] = $existing[$key] ?? null;
                $item['default_text'] = $overrides[$key] ?? null;
            }
            unset($item);
        }
        unset($g);
        // default language: only the overrides of the schema elements, no fixed UI texts (see class comment)
        return ['language' => $language, 'groups' => $groups,
            'ui_texts' => (object) ($language['is_default'] ? [] : self::uiTexts($pdo, $languageId))];
    }

    /**
     * GET /api/_ui_texts?lang=<code>: translations of the fixed UI texts (phase 2) for the UI, also without login
     * (login page). Language: with a session (also during the mandatory password change) that of the user, otherwise the
     * one requested by code (the browser remembers the last one used), otherwise the default language. texts is empty for
     * the default language and without languages - then the UI shows the German source texts from i18n-strings.js.
     * The texts are labels of the UI, nothing confidential.
     *
     * @param array|null $user logged-in user (Auth::currentUser()) or null
     */
    public static function publicUiTexts(?PDO $pdo, ?array $user, ?string $code): array
    {
        $out = ['language' => null, 'texts' => (object) []];
        if ($pdo === null) {
            return $out;
        }
        $language = null;
        if ($user !== null) {
            $language = self::current($pdo, $user['id']);
        } else {
            $languages = self::all($pdo);
            foreach ($languages as $l) {
                if ($code !== null && strcasecmp($l['code'], $code) === 0) {
                    $language = $l;
                }
            }
            if ($language === null && $languages && $languages[0]['is_default']) {
                $language = $languages[0];
            }
        }
        $out['language'] = $language !== null ? ['code' => $language['code'], 'name' => $language['name'],
            'is_default' => $language['is_default']] : null;
        if ($language !== null && !$language['is_default']) {
            $out['texts'] = (object) self::uiTexts($pdo, $language['id']);
        }
        return $out;
    }

    /** existing translations of fixed UI texts of a language: key => text */
    public static function uiTexts(PDO $pdo, int $languageId): array
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return [];
        }
        $stmt = $pdo->prepare("SELECT ref_id, text FROM translations WHERE language_id = ? AND kind = 'ui_text' ORDER BY ref_id");
        $stmt->execute([$languageId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * PUT /api/_translations {language_id, items: [{kind, ref_id, value?, text}]}: stores the entries (empty text =
     * remove the translation). Only elements of the active schema; all in one transaction. kind 'ui_text': ref_id is the
     * key of the fixed UI text. Only the frontend knows the list of keys (i18n-strings.js); what is checked here
     * is the form of the key. Translations of keys no longer in use do no harm (they are never displayed).
     */
    public static function save(PDO $pdo, array $body): array
    {
        $languageId = $body['language_id'] ?? null;
        if (!is_int($languageId)) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sprache fehlt', 'errors' => ['language_id' => 'ID erwartet']]);
        }
        $language = self::find($pdo, $languageId);
        $items = $body['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Keine Einträge', 'errors' => ['items' => 'Liste erwartet']]);
        }
        $known = null; // schema elements, only on demand (saving UI texts only does not need a schema)
        $errors = [];
        $rows = [];
        foreach ($items as $i => $item) {
            $kind = is_array($item) ? ($item['kind'] ?? null) : null;
            $ref = is_array($item) ? ($item['ref_id'] ?? null) : null;
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            $text = is_array($item) ? ($item['text'] ?? null) : null;
            if (!is_string($kind) || !is_string($ref) || ($value !== null && !is_string($value)) || ($text !== null && !is_string($text))) {
                $errors["items.$i"] = '{kind, ref_id, value?, text} erwartet';
                continue;
            }
            if ($kind === 'ui_text') {
                if ($language['is_default']) {
                    // default language: only display overrides of the schema elements, the UI texts live in i18n-strings.js
                    throw new ApiException(422, ['error' => 'default_language', 'message' => 'Feste UI-Texte der Standardsprache '
                        . 'werden nicht übersetzt - sie stehen in i18n-strings.js.']);
                }
                if (strlen($ref) > self::UI_KEY_MAX || !preg_match(self::UI_KEY_PATTERN, $ref) || ($value !== null && $value !== '')) {
                    $errors["items.$i"] = 'Schlüssel eines UI-Texts erwartet (z. B. „login.submit“)';
                    continue;
                }
            } else {
                if ($known === null) {
                    SchemaIds::ensure($pdo);
                    $known = [];
                    foreach (self::elements($pdo) as $g) {
                        foreach ($g['items'] as $item) {
                            $known[self::itemKey($item['kind'], $item['ref_id'], $item['value'])] = true;
                        }
                    }
                }
                if (!isset($known[self::itemKey($kind, $ref, $value)])) {
                    $errors["items.$i"] = 'Element gibt es im aktiven Schema nicht';
                    continue;
                }
            }
            $text = self::normalizeText((string) $text);
            if (self::length($text) > self::TEXT_MAX) {
                $errors["items.$i"] = 'höchstens ' . self::TEXT_MAX . ' Zeichen';
                continue;
            }
            $rows[] = [$kind, $ref, $kind === 'enum_value' ? (string) $value : '', $text];
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => count($errors) . ' Eintrag/Einträge ungültig', 'errors' => $errors]);
        }
        $saved = 0;
        $removed = 0;
        self::ensureTables($pdo); // rebuild the phase 1 table if necessary before 'ui_text' is written
        $pdo->beginTransaction();
        try {
            // OR REPLACE instead of ON CONFLICT … DO UPDATE: also runs with SQLite before 3.24 (shared hosting)
            $put = $pdo->prepare('INSERT OR REPLACE INTO translations (language_id, kind, ref_id, value, text, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $del = $pdo->prepare('DELETE FROM translations WHERE language_id = ? AND kind = ? AND ref_id = ? AND value = ?');
            foreach ($rows as [$kind, $ref, $value, $text]) {
                if ($text === '') {
                    $del->execute([$languageId, $kind, $ref, $value]);
                    $removed += $del->rowCount();
                } else {
                    $put->execute([$languageId, $kind, $ref, $value, $text]);
                    $saved++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['saved' => $saved, 'removed' => $removed];
    }

    /**
     * GET /api/_i18n: display texts in the language of the logged-in user for the UI. Per element: text of the
     * display language, otherwise the display override of the default language, otherwise nothing (the UI then derives
     * the label as before). Empty (language null or without texts) if there is nothing - then the UI shows everything
     * as before. Per concrete
     * table: entity, fields {field/media field/FK column: text}, many {n:n list: text} (only with {label}),
     * enum_values {field: {value: text}}. Inherited fields take the translation from the declaring class.
     */
    public static function labels(PDO $pdo, int $userId): array
    {
        $language = self::current($pdo, $userId);
        $out = ['language' => $language, 'entities' => (object) []];
        if ($language === null || !Database::tableExists($pdo, self::TRANSLATIONS)) {
            return $out;
        }
        $texts = self::displayTexts($pdo, $language);
        $source = SchemaIds::activeSource($pdo);
        $stored = Database::loadModel($pdo);
        if (!$texts || $source === null || $stored === null) {
            return $out;
        }
        $model = PumlParser::modelJson($source, Workflows::active($pdo));
        $ids = SchemaIds::lookup($pdo);
        $t = function (string $kind, string $key, string $value = '') use ($ids, $texts): ?string {
            return isset($ids[$key]) ? ($texts[self::itemKey($kind, $ids[$key], $value)] ?? null) : null;
        };
        $classes = array_column($model['entities'], null, 'name');
        $enumKey = [];
        foreach ($model['enums'] as $en) {
            $enumKey[$en['name']] = $en['id'];
        }
        $entities = [];
        foreach ($model['entities'] as $e) {
            if ($e['abstract']) {
                continue;
            }
            $map = ['entity' => $t('entity', $e['id']), 'fields' => [], 'many' => [], 'enum_values' => []];
            // own and inherited fields (the subclass overrides nothing - duplicate field names are schema errors)
            for ($c = $e; $c !== null; $c = $c['extends'] !== null ? ($classes[$c['extends']] ?? null) : null) {
                foreach (array_merge($c['fields'], $c['media']) as $f) {
                    if (!empty($f['primary'])) {
                        continue;
                    }
                    if (($text = $t('field', $f['id'])) !== null) {
                        $map['fields'][$f['name']] = $text;
                    }
                    if (($f['type'] ?? null) === 'enum' && isset($enumKey[$f['enum_name']])) {
                        foreach ($stored['enums'][$f['enum_name']] ?? [] as $v) {
                            if (($text = $t('enum_value', $enumKey[$f['enum_name']], (string) $v)) !== null) {
                                $map['enum_values'][$f['name']][$v] = $text;
                            }
                        }
                    }
                }
            }
            foreach ($model['relations'] as $r) {
                if ($r['kind'] !== 'nn' && $r['from_entity'] === $e['name'] && ($text = $t('relation', $r['id'])) !== null) {
                    $map['fields'][$r['own_column']] = $text;
                }
            }
            $entities[$e['table']] = $map;
        }
        // n:n with {label}: assign via the link table to the list of the owning class (only it has the list)
        foreach ($model['relations'] as $r) {
            if ($r['kind'] !== 'nn' || !$r['show_label'] || ($text = $t('relation', $r['id'])) === null) {
                continue;
            }
            foreach ($stored['entities'] as $table => $se) {
                foreach ($se['many_to_many'] as $m) {
                    if ($m['junction'] === $r['junction'] && isset($entities[$table])) {
                        $entities[$table]['many'][$m['name']] = $text;
                    }
                }
            }
        }
        foreach ($entities as &$map) {
            foreach (['fields', 'many', 'enum_values'] as $k) {
                $map[$k] = (object) $map[$k];
            }
        }
        unset($map);
        $out['entities'] = (object) $entities;
        return $out;
    }

    /** Texts of the fallback chain of labels(): own translation before the override of the default language; itemKey() => text */
    private static function displayTexts(PDO $pdo, array $language): array
    {
        // keys are never numeric
        $texts = self::texts($pdo, self::defaultId($pdo));
        if (!$language['is_default']) {
            $texts = array_merge($texts, self::texts($pdo, $language['id']));
        }
        return $texts;
    }

    /**
     * Displayed entity names (table => text) as in labels(), only for entities with a translation or override: in the
     * display language of the user, without a user (public API) in the default language. For ListQuery - the search in the
     * label of a linked row ("<entity> #<id> – …") is meant to match the name the caller sees.
     *
     * @return array<string,string>
     */
    public static function entityNames(PDO $pdo, ?int $userId): array
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return [];
        }
        if ($userId !== null) {
            $language = self::current($pdo, $userId);
        } else {
            $all = self::all($pdo);
            $language = $all && $all[0]['is_default'] ? $all[0] : null;
        }
        $texts = $language !== null ? self::displayTexts($pdo, $language) : [];
        $source = $texts ? SchemaIds::activeSource($pdo) : null;
        if ($source === null) {
            return [];
        }
        $ids = SchemaIds::lookup($pdo);
        $out = [];
        foreach (PumlParser::modelJson($source, Workflows::active($pdo))['entities'] as $e) {
            $text = isset($ids[$e['id']]) ? ($texts[self::itemKey('entity', $ids[$e['id']], null)] ?? null) : null;
            if (!$e['abstract'] && $text !== null) {
                $out[$e['table']] = $text;
            }
        }
        return $out;
    }

    /** Remove translations of enum values that no longer exist in the model (after a migration) */
    public static function pruneEnumValues(PDO $pdo, array $modelJson): void
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return;
        }
        $ids = SchemaIds::lookup($pdo);
        $keep = [];
        foreach ($modelJson['enums'] as $en) {
            if (isset($ids[$en['id']])) {
                $keep[$ids[$en['id']]] = array_map('strval', $en['values']);
            }
        }
        $del = $pdo->prepare("DELETE FROM translations WHERE id = ?");
        foreach ($pdo->query("SELECT id, ref_id, value FROM translations WHERE kind = 'enum_value'")->fetchAll() as $row) {
            if (!in_array($row['value'], $keep[$row['ref_id']] ?? [], true)) {
                $del->execute([$row['id']]);
            }
        }
    }

    /** Delete translations for removed schema IDs (SchemaIds::delete(), also with foreign keys switched off) */
    public static function deleteRefs(PDO $pdo, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, self::TRANSLATIONS)) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM translations WHERE kind <> 'ui_text' AND ref_id IN ($in)")->execute($chunk);
        }
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Translatable elements of the active schema (see catalog()), without existing texts. Besides the
     * stable ID (ref_id, different per installation), every element carries a readable identifier 'ref' built from the
     * names of the diagram - it is the same on every installation with the same diagram and addresses the element in the
     * CSV file (TranslationCsv): entity "Kurs", field/media field "Kurs.titel", relationship "Kurs->Autor:Verfasser" (after
     * the colon the label, without a label the column name without _id), enum "Status", enum value "Status.OFFEN". It is
     * resolved via exactly this list, i.e. via the same name mapping (model key -> SchemaIds::lookup()).
     */
    public static function elements(PDO $pdo): array
    {
        $source = SchemaIds::activeSource($pdo);
        if ($source === null) {
            return [];
        }
        $model = PumlParser::modelJson($source, Workflows::active($pdo));
        $ids = SchemaIds::lookup($pdo);
        $groups = [];
        $seen = [];
        $item = function (string $kind, string $key, string $ref, string $original, string $type, ?string $value = null) use ($ids, &$seen): ?array {
            if (!isset($ids[$key])) {
                return null;
            }
            // same identifier twice (two relationships with the same label between the same classes): number them
            $n = $seen[$kind . '|' . $ref] = ($seen[$kind . '|' . $ref] ?? 0) + 1;
            return ['kind' => $kind, 'ref_id' => $ids[$key], 'value' => $value, 'original' => $original, 'type' => $type,
                'ref' => $n > 1 ? "$ref#$n" : $ref];
        };
        foreach ($model['entities'] as $e) {
            $items = [];
            if (!$e['abstract']) {
                $items[] = $item('entity', $e['id'], $e['name'], $e['name'], 'Entität');
            }
            foreach ($e['fields'] as $f) {
                if (!$f['primary']) {
                    $items[] = $item('field', $f['id'], "{$e['name']}.{$f['name']}", $f['name'], $f['type'] === 'enum' ? "Feld (Enum {$f['enum_name']})" : "Feld ({$f['type']})");
                }
            }
            foreach ($e['media'] as $m) {
                $items[] = $item('field', $m['id'], "{$e['name']}.{$m['name']}", $m['name'], 'Medien-Feld');
            }
            foreach ($model['relations'] as $r) {
                if ($r['from_entity'] !== $e['name']) {
                    continue;
                }
                $ref = "{$r['from_entity']}->{$r['to_entity']}:";
                if ($r['kind'] !== 'nn') {
                    $original = $r['label'] !== '' ? $r['label'] : (string) preg_replace('/_id$/', '', $r['own_column']);
                    $items[] = $item('relation', $r['id'], $ref . $original, $original,
                        ($r['kind'] === 'one_to_one' ? '1:1' : 'n:1') . "-Beziehung → {$r['to_entity']}");
                } elseif ($r['show_label'] && $r['label'] !== '') {
                    $items[] = $item('relation', $r['id'], $ref . $r['label'], $r['label'], "n:n-Beziehung – {$r['to_entity']}");
                }
            }
            $items = array_values(array_filter($items));
            if ($items) {
                $groups[] = ['title' => $e['name'], 'kind' => 'entity', 'abstract' => $e['abstract'], 'items' => $items];
            }
        }
        foreach ($model['enums'] as $en) {
            $items = [$item('enum', $en['id'], $en['name'], $en['name'], 'Enum')];
            foreach ($en['values'] as $v) {
                $items[] = $item('enum_value', $en['id'], "{$en['name']}.$v", (string) $v, 'Enum-Wert', (string) $v);
            }
            $items = array_values(array_filter($items));
            if ($items) {
                $groups[] = ['title' => $en['name'], 'kind' => 'enum', 'abstract' => false, 'items' => $items];
            }
        }
        return $groups;
    }

    /** existing translations of a language: itemKey() => text */
    public static function texts(PDO $pdo, int $languageId): array
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return [];
        }
        $out = [];
        $stmt = $pdo->prepare("SELECT kind, ref_id, value, text FROM translations WHERE language_id = ? AND kind <> 'ui_text'");
        $stmt->execute([$languageId]);
        foreach ($stmt as $r) {
            $out[self::itemKey($r['kind'], $r['ref_id'], $r['kind'] === 'enum_value' ? $r['value'] : null)] = $r['text'];
        }
        return $out;
    }

    /** ID of the default language (0 if - e.g. in the middle of the initial setup - there is none) */
    private static function defaultId(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT id FROM languages WHERE is_default = 1')->fetchColumn();
    }

    public static function itemKey(string $kind, string $ref, ?string $value): string
    {
        return $kind . '|' . $ref . ($kind === 'enum_value' ? '|' . (string) $value : '');
    }

    /** @throws ApiException 404 */
    public static function find(PDO $pdo, int $id): array
    {
        $row = false;
        if (Database::tableExists($pdo, self::LANGUAGES)) {
            $stmt = $pdo->prepare('SELECT * FROM languages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
        }
        if ($row === false) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Sprache nicht gefunden']);
        }
        return self::publicLanguage($row);
    }

    /**
     * Check the format values. An empty date format or decimal separator falls back to the default value (no
     * error); an empty thousands separator is valid (no grouping).
     *
     * @return array{date_format:string,datetime_format:string,decimal_separator:string,thousands_separator:string}
     */
    private static function validFormats(array $body, array &$errors): array
    {
        $out = [];
        foreach (['date_format', 'datetime_format'] as $key) {
            $v = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
            if ($v === '') {
                $v = self::FORMAT_DEFAULTS[$key];
            }
            // at least one convertible placeholder outside of \ escapes, otherwise every date would show the same fixed text
            $plain = preg_replace('/\\\\./su', '', $v);
            if (self::length($v) > self::FORMAT_MAX) {
                $errors[$key] = 'Höchstens ' . self::FORMAT_MAX . ' Zeichen';
            } elseif (strpbrk((string) $plain, self::FORMAT_PLACEHOLDERS) === false) {
                $errors[$key] = 'Format ohne Platzhalter – erwartet z. B. „' . self::FORMAT_DEFAULTS[$key] . '“ (unterstützt: '
                    . implode(' ', str_split(self::FORMAT_PLACEHOLDERS)) . ')';
            }
            $out[$key] = $v;
        }
        $dec = is_string($body['decimal_separator'] ?? null) ? trim($body['decimal_separator']) : '';
        if ($dec === '') {
            $dec = self::FORMAT_DEFAULTS['decimal_separator'];
        }
        if (self::length($dec) !== 1 || preg_match('/[0-9+\-]/', $dec)) {
            $errors['decimal_separator'] = 'Genau ein Zeichen erwartet (keine Ziffer, kein Vorzeichen), z. B. „,“ oder „.“';
        }
        $tho = is_string($body['thousands_separator'] ?? null) ? $body['thousands_separator'] : '';
        if (self::length($tho) > 1 || preg_match('/[0-9+\-]/', $tho)) {
            $errors['thousands_separator'] = 'Höchstens ein Zeichen erwartet (keine Ziffer, kein Vorzeichen); leer = keine Gruppierung';
        } elseif ($tho !== '' && $tho === $dec) {
            $errors['thousands_separator'] = 'Muss sich vom Dezimaltrennzeichen unterscheiden';
        }
        return $out + ['decimal_separator' => $dec, 'thousands_separator' => $tho];
    }

    /** @return array{0:string,1:string,2:array} code, name, formats (see validFormats()) */
    private static function validLanguage(PDO $pdo, array $body, ?int $id): array
    {
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        $name = is_string($body['name'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $body['name']) ?? '') : '';
        $errors = [];
        if (!preg_match(self::CODE_PATTERN, $code)) {
            $errors['code'] = 'Kürzel mit 1–20 Zeichen (Buchstaben, Ziffern, - und _), z. B. „de“ oder „en-GB“';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM languages WHERE code = ? COLLATE NOCASE AND id IS NOT ?');
            $stmt->execute([$code, $id]);
            if ($stmt->fetchColumn()) {
                $errors['code'] = "Das Kürzel „{$code}“ ist schon vergeben";
            }
        }
        if ($name === '' || self::length($name) > self::NAME_MAX) {
            $errors['name'] = 'Name mit 1–' . self::NAME_MAX . ' Zeichen erwartet, z. B. „Deutsch“';
        }
        $formats = self::validFormats($body, $errors);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Sprache ungültig', 'errors' => $errors]);
        }
        return [$code, $name, $formats];
    }

    private static function publicLanguage(array $row): array
    {
        $language = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'is_default' => (bool) (int) $row['is_default']];
        // tables from before the format columns (they are only added on the next write): default values
        foreach (self::FORMAT_DEFAULTS as $key => $default) {
            $language[$key] = isset($row[$key]) ? (string) $row[$key] : $default;
        }
        return $language;
    }

    /** Translation text as stored: whitespace (line breaks too) to a single space, trimmed */
    public static function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
