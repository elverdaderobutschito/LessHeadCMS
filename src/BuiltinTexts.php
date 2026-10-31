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
 * Built-in fixed UI texts (and codes of server messages) from language files - without an import, nothing is copied into
 * the database. Two file sources, the database is the third:
 *
 *  1. shipped: translations/<code>.csv for every code in BUILTIN_LANGUAGES (comes with LessHeadCMS, replaced by an
 *     update). Other files in translations/ are only offered for the import and are ignored here.
 *  2. custom: every <code>.csv in the directory CUSTOM_TRANSLATIONS_DIR from config.php (files of the installation, e.g. of
 *     an agency; not part of the delivery, an update does not touch them). Per key a custom file beats the shipped file
 *     of the same language; a key missing there comes from the shipped file.
 *  3. database (import, System -> Übersetzungen): own translations, they beat both (Languages::publicUiTexts()).
 *
 * The German source texts of i18n-strings.js are the built-in texts of German languages in the same sense (only the
 * frontend knows them); a custom de.csv overrides them per key.
 *
 * A file belongs to a language via its code or name, variants included (match()): en.csv serves "en", "en-GB", "eng",
 * "English" or "Englisch"; a file with a region (en-GB.csv) only serves exactly that code and beats the general one.
 *
 * Only lines of kind ui_text count. Files are read at runtime and cached in data/cache/ by path, modification time and
 * size (not parsed on every request). Faulty lines of a custom file (placeholders other than in the shipped English text,
 * invalid key, too long) are ignored and written to the PHP error log, so a broken file never breaks the UI; the
 * shipped files are checked by backend/tests/messages_test.php instead.
 */
final class BuiltinTexts
{
    /** Languages shipped in translations/ (file <code>.csv). A new one (fr.csv, es.csv) only needs its code here. */
    public const BUILTIN_LANGUAGES = ['en'];

    /**
     * Variants of common languages: codes (ISO 639-1/-2, also with a region: en-GB, en_US) and names (own name, English
     * and German), lower case. A language not listed is recognised by its code only.
     */
    private const ALIASES = [
        'de' => [['de', 'deu', 'ger'], ['deutsch', 'german']],
        'en' => [['en', 'eng'], ['english', 'englisch']],
        'fr' => [['fr', 'fra', 'fre'], ['français', 'francais', 'french', 'französisch', 'franzoesisch']],
        'es' => [['es', 'spa'], ['español', 'espanol', 'spanish', 'spanisch']],
        'it' => [['it', 'ita'], ['italiano', 'italian', 'italienisch']],
        'nl' => [['nl', 'nld', 'dut'], ['nederlands', 'dutch', 'niederländisch', 'niederlaendisch']],
        'pt' => [['pt', 'por'], ['português', 'portugues', 'portuguese', 'portugiesisch']],
        'pl' => [['pl', 'pol'], ['polski', 'polish', 'polnisch']],
    ];

    private const FILE_PATTERN = '/^([A-Za-z0-9][A-Za-z0-9_-]{0,19})\.csv$/i';
    /** a log entry names at most this many faulty lines individually */
    private const LOG_MAX = 20;

    /** @var string[]|null BUILTIN_LANGUAGES replaced by a test (useBuiltinLanguages()) */
    private static $languages = null;
    /** @var array<string,array<string,string>> stamp (path, time, size) => texts, read once per request */
    private static $memo = [];

    /** For tests only: list other shipped languages (null = BUILTIN_LANGUAGES again). */
    public static function useBuiltinLanguages(?array $codes): void
    {
        self::$languages = $codes;
        self::$memo = [];
    }

    /** @return string[] codes of the shipped languages */
    public static function builtinLanguages(): array
    {
        return self::$languages ?? self::BUILTIN_LANGUAGES;
    }

    /**
     * How well a language matches the file code: 2 = same code (en-GB / en_gb), 1 = general file of the language
     * (en.csv for en-US, eng or the name "Englisch"), 0 = not at all.
     */
    public static function match(array $language, string $code): int
    {
        $norm = function (string $c): string {
            return strtolower(str_replace('_', '-', trim($c)));
        };
        $own = $norm((string) $language['code']);
        $file = $norm($code);
        if ($own === $file) {
            return 2;
        }
        if (strpos($file, '-') !== false) {
            return 0; // a file with a region only serves exactly that code
        }
        [$codes, $names] = self::ALIASES[$file] ?? [[$file], []];
        $quote = function (array $list): string {
            return implode('|', array_map(function (string $s): string {
                return preg_quote($s, '/');
            }, $list));
        };
        if (preg_match('/^(' . $quote($codes) . ')(?![a-z])/i', $own) === 1) {
            return 1;
        }
        return $names && preg_match('/^(' . $quote($names) . ')(?!\p{L})/iu', trim((string) $language['name'])) === 1 ? 1 : 0;
    }

    /**
     * Built-in texts of a language from the files: custom file over shipped file, per key.
     *
     * @return array{texts:array<string,string>,sources:array<string,string>} sources: key => 'shipped' | 'custom'
     */
    public static function forLanguage(array $language): array
    {
        $out = ['texts' => [], 'sources' => []];
        foreach (['shipped' => self::shippedFiles(), 'custom' => self::customFiles()] as $source => $files) {
            $best = null;
            $score = 0;
            foreach ($files as $code => $path) {
                $s = self::match($language, (string) $code);
                if ($s > $score) {
                    [$best, $score] = [$path, $s];
                }
            }
            if ($best === null) {
                continue;
            }
            foreach (self::read($best, $source === 'custom') as $key => $text) {
                $out['texts'][$key] = $text;
                $out['sources'][$key] = $source;
            }
        }
        return $out;
    }

    /**
     * English built-in texts (shipped en.csv, adapted by a custom en.csv) - the English step of the fallback chain
     * (Languages::publicUiTexts()). Empty without a readable file: then the English text of the server message is the
     * last resort.
     *
     * @return array<string,string>
     */
    public static function english(): array
    {
        return self::forLanguage(['code' => 'en', 'name' => 'English'])['texts'];
    }

    /** @return array<string,string> code => path of the shipped files that exist */
    private static function shippedFiles(): array
    {
        $out = [];
        foreach (self::builtinLanguages() as $code) {
            $path = Config::translationsDir() . "/$code.csv";
            if (is_file($path)) {
                $out[$code] = $path;
            }
        }
        return $out;
    }

    /** @return array<string,string> code => path of the files in CUSTOM_TRANSLATIONS_DIR (empty without the setting/directory) */
    private static function customFiles(): array
    {
        $dir = Config::customTranslationsDir();
        $out = [];
        $names = $dir !== null && is_dir($dir) ? (@scandir($dir) ?: []) : [];
        foreach ($names as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) && is_file("$dir/$name")) {
                $out[$m[1]] = "$dir/$name";
            }
        }
        return $out;
    }

    /**
     * ui_text lines of a file (key => text), from the cache if path, modification time and size are unchanged. $check:
     * custom file - lines whose placeholders differ from the shipped English text are dropped (and logged).
     *
     * @return array<string,string>
     */
    private static function read(string $path, bool $check): array
    {
        $reference = $check ? Config::translationsDir() . '/en.csv' : null;
        $stamp = self::stamp($path) . ($reference !== null ? '|' . self::stamp($reference) : '');
        if (isset(self::$memo[$stamp])) {
            return self::$memo[$stamp];
        }
        $cache = Config::dataDir() . '/cache/ui-texts-' . md5($path) . '.json';
        $cached = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && is_array($cached['texts'] ?? null)) {
            return self::$memo[$stamp] = $cached['texts'];
        }
        $texts = self::parse($path, $reference !== null && is_file($reference) ? self::read($reference, false) : null);
        // best effort: without a writable data/ the file is simply parsed again on the next request
        if (is_dir(dirname($cache)) || @mkdir(dirname($cache), 0755, true)) {
            $tmp = $cache . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['stamp' => $stamp, 'texts' => (object) $texts], JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($tmp, $cache);
            }
            @unlink($tmp);
        }
        return self::$memo[$stamp] = $texts;
    }

    private static function stamp(string $path): string
    {
        clearstatcache(true, $path);
        return is_file($path) ? $path . '@' . filemtime($path) . ':' . filesize($path) : $path . '@-';
    }

    /**
     * @param array<string,string>|null $reference English texts the placeholders are compared with (custom file), null = no check
     * @return array<string,string>
     */
    private static function parse(string $path, ?array $reference): array
    {
        $name = basename(dirname($path)) . '/' . basename($path);
        $content = is_readable($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            error_log("[LessHeadCMS] $name cannot be read (check the file permissions) - its texts are ignored.");
            return [];
        }
        try {
            $records = TranslationCsv::parse($content);
        } catch (ApiException $e) {
            error_log("[LessHeadCMS] $name ignored: " . $e->getMessage());
            return [];
        }
        $out = [];
        $problems = [];
        foreach ($records as [$line, $rec]) {
            if (strtolower(trim((string) $rec[0])) !== 'ui_text') {
                continue; // schema elements and pattern messages are not built-in texts (they need an import)
            }
            $key = trim((string) ($rec[1] ?? ''));
            $text = Languages::normalizeText((string) ($rec[2] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($reference !== null) {
                $problem = null;
                if (strlen($key) > Languages::UI_KEY_MAX || !preg_match(Languages::UI_KEY_PATTERN, $key)) {
                    $problem = 'not the key of a UI text';
                } elseif (Languages::length($text) > Languages::TEXT_MAX) {
                    $problem = 'longer than ' . Languages::TEXT_MAX . ' characters';
                } elseif (isset($out[$key])) {
                    $problem = 'duplicate key';
                } elseif (isset($reference[$key]) && self::placeholders($reference[$key]) !== self::placeholders($text)) {
                    $expected = self::placeholders($reference[$key]);
                    $problem = 'placeholders differ from en.csv (expected: ' . ($expected === [] ? 'none' : '{' . implode('} {', $expected) . '}') . ')';
                }
                if ($problem !== null) {
                    $problems[] = "line $line ($key): $problem";
                    continue;
                }
            }
            $out[$key] = $text;
        }
        if ($problems) {
            $more = count($problems) > self::LOG_MAX ? sprintf(' … and %d more', count($problems) - self::LOG_MAX) : '';
            error_log("[LessHeadCMS] $name: " . count($problems) . ' line(s) ignored - '
                . implode('; ', array_slice($problems, 0, self::LOG_MAX)) . $more . '.');
        }
        return $out;
    }

    /** @return string[] sorted names of the {placeholders} */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }
}
