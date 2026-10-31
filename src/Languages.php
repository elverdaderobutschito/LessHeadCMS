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
 *  - messages of the pattern library (Patterns, field rules, see Validation): kind = 'pattern', ref_id = name of the
 *    pattern. Translatable like the schema elements (own group in elements(), CSV with kind "pattern" and the name as
 *    ref); the default language may override the message as well. Older tables (CHECK without 'pattern') are rebuilt
 *    once by migrateTranslations().
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
 * (same table, language_id of the default language). Displayed is (labels()): text of the display language, otherwise
 * the override of the default language, otherwise the derived label.
 *
 * Fixed UI texts (and codes of server messages) can be translated for every language, the default language included:
 * there an own text overrides the built-in one. Built-in are the German source texts for a German language and the
 * language files (BuiltinTexts: shipped translations/<code>.csv, custom files from CUSTOM_TRANSLATIONS_DIR) - read at
 * runtime, nothing is copied into the database. Own translations always beat built-in texts; chain see publicUiTexts().
 */
final class Languages
{
    public const LANGUAGES = 'languages';
    public const TRANSLATIONS = 'translations';
    public const PREFS = 'user_language_prefs';

    public const KINDS = ['entity', 'field', 'relation', 'enum', 'enum_value', 'ui_text', 'pattern'];

    /**
     * Messages of the built-in patterns as the translations/en.csv of 2026-10-30 (commit 97bb66e) shipped them as kind
     * 'pattern'. Since then they are system texts with a code (Patterns::builtinMessage()); an imported line would hide
     * that. dropShippedPatternTexts() removes exactly these texts once - word for word, so an adapted text stays.
     */
    private const SHIPPED_PATTERN_TEXTS = [
        'email' => 'Please enter a valid email address (e.g. name@example.org).',
        'url'   => 'Please enter a complete address starting with http:// or https://.',
        'slug'  => 'Please use only lower-case letters, digits and single hyphens (e.g. my-article).',
        'plz'   => 'Please enter a five-digit postcode.',
    ];
    private const SHIPPED_PATTERN_META = 'shipped_pattern_texts_removed';
    /** connection dropShippedPatternTexts() last looked at (one per request; held, so the comparison stays exact) */
    private static $shippedChecked = null;

    /** Key of a fixed UI text: area.name, e.g. "login.submit" or "list.filter_aria" */
    public const UI_KEY_PATTERN = '/^[a-z][a-z0-9_]*(\\.[a-z0-9_]+)+$/';
    public const UI_KEY_MAX = 100;

    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,19}$/';
    private const NAME_MAX = 60;
    public const TEXT_MAX = 500;

    // ref_id: schema_ids.id or, for ui_text, the key of the UI text (for pattern the name) - hence without a foreign key (see above)
    private const TRANSLATIONS_DDL = 'CREATE TABLE IF NOT EXISTS translations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  language_id INTEGER NOT NULL REFERENCES languages(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN (\'entity\', \'field\', \'relation\', \'enum\', \'enum_value\', \'ui_text\', \'pattern\')),
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
     * Rebuild translations from phase 1 (CHECK without 'ui_text', ref_id with a foreign key to schema_ids) and from before the
     * pattern library (CHECK without 'pattern') once to the new definition - SQLite cannot change CHECK and foreign keys via
     * ALTER TABLE. Create a new table,
     * copy the rows, drop the old one, rename; all in one transaction. Nothing references translations, so
     * dropping also works with foreign keys switched on.
     */
    private static function migrateTranslations(PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([self::TRANSLATIONS]);
        $sql = (string) $stmt->fetchColumn();
        $stmt->closeCursor(); // an open query would lock DROP TABLE ("database table is locked")
        if ($sql === '' || strpos($sql, "'pattern'") !== false) {
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
            throw ApiException::of(409, 'default_language', Msg::of('msg_i18n.default_no_delete', 'The default language '
                . '“{name}” cannot be deleted (it describes the texts of the diagram). Renaming is possible.',
                ['name' => $language['name']]));
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
            throw ApiException::of(422, 'validation', Msg::of('msg_api.language_missing', 'Language missing'),
                ApiException::fields(['language_id' => Msg::of('msg_api.id_expected', 'ID expected')]));
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
        // fixed UI texts: an entry overrides the built-in text of the language (see class comment); ui_builtin /
        // ui_builtin_sources: the built-in texts from the language files and where they come from (shipped | custom),
        // german: the German source texts are built in as well
        $builtin = BuiltinTexts::forLanguage($language);
        return ['language' => $language, 'groups' => $groups, 'ui_texts' => (object) self::uiTexts($pdo, $languageId),
            'ui_builtin' => (object) $builtin['texts'], 'ui_builtin_sources' => (object) $builtin['sources'],
            'german' => self::isGerman($language)];
    }

    /**
     * GET /api/_ui_texts?lang=<code>: texts of the UI (phase 2) for the UI, also without login (login page). Language:
     * with a session (also during the mandatory password change) that of the user, otherwise the one requested by code
     * (the browser remembers the last one used), otherwise the default language. The texts are labels of the UI, nothing
     * confidential.
     *
     * Fallback chain, the same for every fixed UI text and every code of a server message (Msg) - the UI resolves it in
     * one place (frontend/src/components/i18n.js). Every step has three layers, the own translation on top:
     * texts (database: import, System -> Übersetzungen) over builtin (language files, BuiltinTexts: custom file over the
     * shipped one) over the German source texts of i18n-strings.js (german, only for a German language).
     *  1. display language
     *  2. default language (fallback[0], only if it is not the display language)
     *  3. English: built-in texts of en (last entry of fallback, only if no step before is German or English - those
     *     already cover every key)
     *  4. server messages: the English text of the response; fixed UI texts: the German source text
     * Without languages the UI is German (german true, no fallback), exactly as before.
     *
     * @param array|null $user logged-in user (Auth::currentUser()) or null
     */
    public static function publicUiTexts(?PDO $pdo, ?array $user, ?string $code): array
    {
        $out = ['language' => null, 'texts' => (object) [], 'german' => true, 'builtin' => (object) [], 'fallback' => []];
        if ($pdo === null) {
            return $out;
        }
        $languages = self::all($pdo);
        $language = null;
        if ($user !== null) {
            $language = self::current($pdo, $user['id']);
        } else {
            foreach ($languages as $l) {
                if ($code !== null && strcasecmp($l['code'], $code) === 0) {
                    $language = $l;
                }
            }
            if ($language === null && $languages && $languages[0]['is_default']) {
                $language = $languages[0];
            }
        }
        if ($language === null) {
            return $out;
        }
        $step = function (array $l) use ($pdo): array {
            return ['texts' => (object) self::uiTexts($pdo, $l['id']), 'german' => self::isGerman($l),
                'builtin' => (object) BuiltinTexts::forLanguage($l)['texts']];
        };
        $out = ['language' => ['code' => $language['code'], 'name' => $language['name'], 'is_default' => $language['is_default']]]
            + $step($language) + ['fallback' => []];
        // English already covered: a German step (source texts for every key) or an English one (its built-in texts are en.csv)
        $covered = $out['german'] || BuiltinTexts::match($language, 'en') > 0;
        if (!$language['is_default']) {
            foreach ($languages as $l) {
                if ($l['is_default']) {
                    $out['fallback'][] = $step($l);
                    $covered = $covered || self::isGerman($l) || BuiltinTexts::match($l, 'en') > 0;
                }
            }
        }
        if (!$covered) {
            $out['fallback'][] = ['texts' => (object) [], 'german' => false, 'builtin' => (object) BuiltinTexts::english()];
        }
        return $out;
    }

    /**
     * Does a language bring the German source texts of the UI (frontend/src/i18n-strings.js)? By its code (de, de-AT,
     * de_CH, deu, ger) or its name (Deutsch, German) - the same recognition as for the language files (BuiltinTexts::match()).
     */
    public static function isGerman(array $language): bool
    {
        return BuiltinTexts::match($language, 'de') > 0;
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
            throw ApiException::of(422, 'validation', Msg::of('msg_api.language_missing', 'Language missing'),
                ApiException::fields(['language_id' => Msg::of('msg_api.id_expected', 'ID expected')]));
        }
        $language = self::find($pdo, $languageId);
        $items = $body['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw ApiException::of(422, 'validation', Msg::of('msg_i18n.no_items', 'No items'),
                ApiException::fields(['items' => Msg::of('msg_i18n.list_expected', 'List expected')]));
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
                $errors["items.$i"] = Msg::of('msg_i18n.item_expected', '{kind, ref_id, value?, text} expected');
                continue;
            }
            if ($kind === 'ui_text') {
                if (strlen($ref) > self::UI_KEY_MAX || !preg_match(self::UI_KEY_PATTERN, $ref) || ($value !== null && $value !== '')) {
                    $errors["items.$i"] = Msg::of('msg_i18n.ui_key_expected', 'Key of a UI text expected (e.g. “login.submit”)');
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
                    $errors["items.$i"] = Msg::of('msg_i18n.element_unknown', 'The element does not exist in the active schema');
                    continue;
                }
            }
            $text = self::normalizeText((string) $text);
            if (self::length($text) > self::TEXT_MAX) {
                $errors["items.$i"] = Msg::of('msg_i18n.at_most_chars', 'at most {max} characters', ['max' => self::TEXT_MAX]);
                continue;
            }
            $rows[] = [$kind, $ref, $kind === 'enum_value' ? (string) $value : '', $text];
        }
        if ($errors) {
            throw ApiException::of(422, 'validation', Msg::of('msg_i18n.items_invalid', '{count} item(s) invalid',
                ['count' => count($errors)]), ApiException::fields($errors));
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
            $pdo->prepare("DELETE FROM translations WHERE kind NOT IN ('ui_text', 'pattern') AND ref_id IN ($in)")->execute($chunk);
        }
    }

    /**
     * Messages of the pattern library in a language (Validation): name => text - own translation, otherwise the override
     * of the default language. $language null (no languages, no user) = only the override of the default language.
     *
     * @return array<string,string>
     */
    public static function patternMessages(PDO $pdo, ?array $language): array
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return [];
        }
        $ids = [self::defaultId($pdo)];
        if ($language !== null && !$language['is_default']) {
            $ids[] = $language['id'];
        }
        self::dropShippedPatternTexts($pdo);
        $out = [];
        $stmt = $pdo->prepare("SELECT ref_id, text FROM translations WHERE language_id = ? AND kind = 'pattern'");
        foreach ($ids as $id) { // the own language last: it wins
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $r) {
                $out[(string) $r['ref_id']] = (string) $r['text'];
            }
        }
        return $out;
    }

    /**
     * Once per installation, on the first read of pattern messages (patternMessages(), texts()): removes the messages of
     * the built-in patterns that an earlier import of the bundled en.csv stored (SHIPPED_PATTERN_TEXTS) - only rows whose
     * text is word for word the shipped one, in any language (the file could be imported into any of them). Every other
     * text was written by the admin and stays a translation that beats the system text. Idempotent (_meta
     * shipped_pattern_texts_removed); with a read-only database nothing happens and the next request tries again.
     * Removed rows are written to the PHP error log.
     */
    private static function dropShippedPatternTexts(PDO $pdo): void
    {
        // inside a transaction of the caller (e.g. save()) not: the next read outside does it
        if (self::$shippedChecked === $pdo || $pdo->inTransaction()) {
            return;
        }
        self::$shippedChecked = $pdo;
        $rows = [];
        try {
            if (!Database::tableExists($pdo, '_meta') || Database::getMeta($pdo, self::SHIPPED_PATTERN_META) !== null) {
                return;
            }
            $where = implode(' OR ', array_fill(0, count(self::SHIPPED_PATTERN_TEXTS), '(t.ref_id = ? AND t.text = ?)'));
            $params = [];
            foreach (self::SHIPPED_PATTERN_TEXTS as $name => $text) {
                array_push($params, $name, $text);
            }
            $stmt = $pdo->prepare("SELECT t.id, t.ref_id, l.code FROM translations t LEFT JOIN languages l ON l.id = t.language_id "
                . "WHERE t.kind = 'pattern' AND ($where) ORDER BY t.id");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM translations WHERE id = ?');
            foreach ($rows as $r) {
                $delete->execute([$r['id']]);
            }
            Database::setMeta($pdo, self::SHIPPED_PATTERN_META, (string) count($rows));
            $pdo->commit();
            if ($rows) {
                error_log('[LessHeadCMS] Removed the messages of the built-in patterns imported from the former en.csv (they '
                    . 'hid the system texts): ' . implode(', ', array_map(function (array $r): string {
                        return "{$r['ref_id']} ({$r['code']})";
                    }, $rows)) . '.');
            }
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::$shippedChecked = null;
            if ($rows) { // nothing to remove: no need to fill the log on every request of a read-only installation
                error_log('[LessHeadCMS] Messages of the built-in patterns from the former en.csv not removed: ' . $e->getMessage());
            }
        }
    }

    /** A renamed pattern (Patterns::update(), only while unused) keeps its translations */
    public static function renamePattern(PDO $pdo, string $old, string $new): void
    {
        if (Database::tableExists($pdo, self::TRANSLATIONS)) {
            $pdo->prepare("UPDATE translations SET ref_id = ? WHERE kind = 'pattern' AND ref_id = ?")->execute([$new, $old]);
        }
    }

    /** Translations of a deleted pattern */
    public static function deletePattern(PDO $pdo, string $name): void
    {
        if (Database::tableExists($pdo, self::TRANSLATIONS)) {
            $pdo->prepare("DELETE FROM translations WHERE kind = 'pattern' AND ref_id = ?")->execute([$name]);
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
            return self::patternGroup($pdo);
        }
        $model = PumlParser::modelJson($source, Workflows::active($pdo));
        $ids = SchemaIds::lookup($pdo);
        $groups = [];
        $seen = [];
        $item = function (string $kind, string $key, string $ref, string $original, Msg $type, ?string $value = null) use ($ids, &$seen): ?array {
            if (!isset($ids[$key])) {
                return null;
            }
            // same identifier twice (two relationships with the same label between the same classes): number them
            $n = $seen[$kind . '|' . $ref] = ($seen[$kind . '|' . $ref] ?? 0) + 1;
            return ['kind' => $kind, 'ref_id' => $ids[$key], 'value' => $value, 'original' => $original, 'type' => $type->text(),
                'type_code' => $type->codeOnly(), 'ref' => $n > 1 ? "$ref#$n" : $ref];
        };
        foreach ($model['entities'] as $e) {
            $items = [];
            if (!$e['abstract']) {
                $items[] = $item('entity', $e['id'], $e['name'], $e['name'], Msg::of('msg_i18n.type_entity', 'Entity'));
            }
            foreach ($e['fields'] as $f) {
                if (!$f['primary']) {
                    $items[] = $item('field', $f['id'], "{$e['name']}.{$f['name']}", $f['name'], $f['type'] === 'enum'
                        ? Msg::of('msg_i18n.type_enum_field', 'Field (Enum {enum})', ['enum' => $f['enum_name']])
                        : Msg::of('msg_i18n.type_field', 'Field ({type})', ['type' => $f['type']]));
                }
            }
            foreach ($e['media'] as $m) {
                $items[] = $item('field', $m['id'], "{$e['name']}.{$m['name']}", $m['name'], Msg::of('msg_i18n.type_media', 'Media field'));
            }
            foreach ($model['relations'] as $r) {
                if ($r['from_entity'] !== $e['name']) {
                    continue;
                }
                $ref = "{$r['from_entity']}->{$r['to_entity']}:";
                if ($r['kind'] !== 'nn') {
                    $original = $r['label'] !== '' ? $r['label'] : (string) preg_replace('/_id$/', '', $r['own_column']);
                    $items[] = $item('relation', $r['id'], $ref . $original, $original,
                        Msg::of('msg_i18n.type_relation', '{kind} relationship → {target}', [
                            'kind' => $r['kind'] === 'one_to_one' ? '1:1' : 'n:1', 'target' => $r['to_entity'],
                        ]));
                } elseif ($r['show_label'] && $r['label'] !== '') {
                    $items[] = $item('relation', $r['id'], $ref . $r['label'], $r['label'], Msg::of('msg_i18n.type_nn',
                        'n:n relationship – {target}', ['target' => $r['to_entity']]));
                }
            }
            $items = array_values(array_filter($items));
            if ($items) {
                $groups[] = ['title' => $e['name'], 'kind' => 'entity', 'abstract' => $e['abstract'], 'items' => $items];
            }
        }
        foreach ($model['enums'] as $en) {
            $items = [$item('enum', $en['id'], $en['name'], $en['name'], Msg::of('msg_i18n.type_enum', 'Enum'))];
            foreach ($en['values'] as $v) {
                $items[] = $item('enum_value', $en['id'], "{$en['name']}.$v", (string) $v, Msg::of('msg_i18n.type_enum_value', 'Enum value'), (string) $v);
            }
            $items = array_values(array_filter($items));
            if ($items) {
                $groups[] = ['title' => $en['name'], 'kind' => 'enum', 'abstract' => false, 'items' => $items];
            }
        }
        return array_merge($groups, self::patternGroup($pdo));
    }

    /**
     * Group of the pattern messages (built-in and own patterns) for elements(): ref_id and ref are the name of the pattern
     * (the same on every installation, so the CSV file is portable for the built-in ones). The original of a built-in
     * pattern is its system text: English plus 'original_code' - the UI shows it in the display language.
     */
    private static function patternGroup(PDO $pdo): array
    {
        $items = [];
        foreach (Patterns::all($pdo) as $p) {
            $items[] = ['kind' => 'pattern', 'ref_id' => $p['name'], 'value' => null, 'original' => $p['message'],
                'type' => "Pattern {$p['name']}", 'ref' => $p['name']]
                + ($p['builtin'] ? ['original_code' => Patterns::builtinMessage($p['name'])->codeOnly()] : []);
        }
        return [['title' => 'Patterns', 'kind' => 'pattern', 'abstract' => false, 'items' => $items]];
    }

    /** existing translations of a language: itemKey() => text */
    public static function texts(PDO $pdo, int $languageId): array
    {
        if (!Database::tableExists($pdo, self::TRANSLATIONS)) {
            return [];
        }
        self::dropShippedPatternTexts($pdo);
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
            throw ApiException::of(404, 'not_found', Msg::of('msg_i18n.language_not_found', 'Language not found'));
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
                $errors[$key] = Msg::of('msg_api.max_chars', 'At most {max} characters', ['max' => self::FORMAT_MAX]);
            } elseif (strpbrk((string) $plain, self::FORMAT_PLACEHOLDERS) === false) {
                $errors[$key] = Msg::of('msg_i18n.format_no_placeholder', 'Format without placeholders – expected e.g. '
                    . '“{example}” (supported: {supported})', ['example' => self::FORMAT_DEFAULTS[$key],
                        'supported' => implode(' ', str_split(self::FORMAT_PLACEHOLDERS))]);
            }
            $out[$key] = $v;
        }
        $dec = is_string($body['decimal_separator'] ?? null) ? trim($body['decimal_separator']) : '';
        if ($dec === '') {
            $dec = self::FORMAT_DEFAULTS['decimal_separator'];
        }
        if (self::length($dec) !== 1 || preg_match('/[0-9+\-]/', $dec)) {
            $errors['decimal_separator'] = Msg::of('msg_i18n.decimal_separator', 'Exactly one character expected (no digit, '
                . 'no sign), e.g. “,” or “.”');
        }
        $tho = is_string($body['thousands_separator'] ?? null) ? $body['thousands_separator'] : '';
        if (self::length($tho) > 1 || preg_match('/[0-9+\-]/', $tho)) {
            $errors['thousands_separator'] = Msg::of('msg_i18n.thousands_separator', 'At most one character expected (no '
                . 'digit, no sign); empty = no grouping');
        } elseif ($tho !== '' && $tho === $dec) {
            $errors['thousands_separator'] = Msg::of('msg_i18n.separators_equal', 'Must differ from the decimal separator');
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
            $errors['code'] = Msg::of('msg_i18n.code_invalid', 'Code of 1–20 characters (letters, digits, - and _), e.g. '
                . '“de” or “en-GB”');
        } else {
            $stmt = $pdo->prepare('SELECT id FROM languages WHERE code = ? COLLATE NOCASE AND id IS NOT ?');
            $stmt->execute([$code, $id]);
            if ($stmt->fetchColumn()) {
                $errors['code'] = Msg::of('msg_i18n.code_taken', 'The code “{code}” is already taken', ['code' => $code]);
            }
        }
        if ($name === '' || self::length($name) > self::NAME_MAX) {
            $errors['name'] = Msg::of('msg_i18n.name_invalid', 'Name of 1–{max} characters expected, e.g. “Deutsch”',
                ['max' => self::NAME_MAX]);
        }
        $formats = self::validFormats($body, $errors);
        if ($errors) {
            throw ApiException::of(422, 'validation', Msg::of('msg_i18n.language_invalid', 'Invalid language'),
                ApiException::fields($errors));
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
