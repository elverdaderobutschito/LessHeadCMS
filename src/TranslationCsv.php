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
 * Download translations as CSV and upload them again (System -> Übersetzungen, admins only).
 *
 * Format: UTF-8, header line "kind,ref,text", standard CSV quoting (RFC 4180; read with fgetcsv). One file per
 * language, fixed UI texts and schema elements mixed:
 *
 *   kind,ref,text
 *   ui_text,form.save,Save
 *   entity,Kurs,Course
 *   field,Kurs.titel,Title
 *   relation,Kurs->Autor:Verfasser,Written by
 *   enum,Status,State
 *   enum_value,Status.OFFEN,Open
 *
 * ui_text: ref is the key from i18n-strings.js - the same on every installation, so the file is portable (this is how
 * translations/en.csv ships with the product). Schema elements are bound in the database to the stable ID from
 * schema_ids, which differs per installation; the CSV therefore names them via the readable identifier from
 * Languages::elements() ('ref', from the names of the diagram). On import it is resolved via exactly this list of the
 * active schema - no second matching logic. If a line matches no element (different project, field renamed), it is
 * skipped and listed in the summary with a reason; the rest is imported.
 *
 * Import in two steps with the same body {language_id, content | file}: preview() only computes (new / changed /
 * unchanged / skipped), apply() stores new and changed texts in one transaction. content is the text
 * of an uploaded file, file the name of a file from translations/ (placed there via FTP, see Config::translationsDir()).
 * An import adds and overwrites, it deletes nothing: lines with empty text are skipped.
 */
final class TranslationCsv
{
    private const HEADER = ['kind', 'ref', 'text'];
    /** Upper limit for a CSV file (the bundled en.csv is about 30 KB) */
    private const MAX_BYTES = 2097152;
    private const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.csv$/i';
    /** The summary lists at most this many skipped or changed lines individually (all are counted) */
    private const DETAILS_MAX = 200;

    /** GET /api/_translations/export?lang={id}: all translations of the language as <code>.csv */
    public static function export(PDO $pdo, int $languageId): Download
    {
        $language = Languages::find($pdo, $languageId);
        SchemaIds::ensure($pdo);
        $rows = [];
        // default language: only display overrides of the schema elements (it has no fixed UI texts)
        if (!$language['is_default']) {
            foreach (Languages::uiTexts($pdo, $languageId) as $key => $text) {
                $rows[] = ['ui_text', (string) $key, $text];
            }
        }
        $texts = Languages::texts($pdo, $languageId);
        foreach (Languages::elements($pdo) as $g) {
            foreach ($g['items'] as $item) {
                $key = Languages::itemKey($item['kind'], $item['ref_id'], $item['value']);
                if (isset($texts[$key])) {
                    $rows[] = [$item['kind'], $item['ref'], $texts[$key]];
                }
            }
        }
        $csv = '';
        foreach (array_merge([self::HEADER], $rows) as $row) {
            $csv .= implode(',', array_map([self::class, 'quote'], $row)) . "\n";
        }
        return new Download($language['code'] . '.csv', 'text/csv; charset=utf-8', $csv);
    }

    /** GET /api/_translations/files: the .csv files in translations/ (empty if the directory does not exist) */
    public static function files(): array
    {
        $dir = Config::translationsDir();
        $files = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (preg_match(self::FILE_PATTERN, $name) && is_file("$dir/$name")) {
                $files[] = ['name' => $name, 'size' => (int) filesize("$dir/$name"),
                    'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime("$dir/$name"))];
            }
        }
        return ['directory' => 'translations', 'files' => $files];
    }

    /** POST /api/_translations/import/preview: summary, without saving */
    public static function preview(PDO $pdo, array $body): array
    {
        return self::summary(self::plan($pdo, $body), false);
    }

    /** POST /api/_translations/import/apply: store new and changed texts (one transaction), summary */
    public static function apply(PDO $pdo, array $body): array
    {
        $plan = self::plan($pdo, $body);
        Languages::ensureTables($pdo); // rebuild the phase 1 table if necessary before 'ui_text' is written
        $pdo->beginTransaction();
        try {
            // OR REPLACE instead of ON CONFLICT … DO UPDATE: also runs with SQLite before 3.24 (like Languages::save())
            $put = $pdo->prepare('INSERT OR REPLACE INTO translations (language_id, kind, ref_id, value, text, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            foreach ($plan['write'] as [$kind, $refId, $value, $text]) {
                $put->execute([$plan['language']['id'], $kind, $refId, $value, $text]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::summary($plan, true);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * CSV field according to RFC 4180: in quotes only if necessary (comma, semicolon, quote, line break,
     * whitespace at the edges), quotes doubled. fputcsv() would already quote on every space -
     * this way the file stays easy to read and looks like the bundled en.csv.
     */
    private static function quote(string $field): string
    {
        return preg_match('/[",;\r\n]|^\s|\s$/', $field) ? '"' . str_replace('"', '""', $field) . '"' : $field;
    }

    private static function summary(array $plan, bool $applied): array
    {
        return [
            'applied'   => $applied,
            'language'  => $plan['language'],
            'file'      => $plan['file'],
            'rows'      => $plan['rows'],
            'new'       => $plan['new'],
            'changed'   => count($plan['changed']),
            'unchanged' => $plan['unchanged'],
            'skipped'   => count($plan['skipped']),
            // listed individually: at most DETAILS_MAX per list
            'changed_rows' => array_slice($plan['changed'], 0, self::DETAILS_MAX),
            'skipped_rows' => array_slice($plan['skipped'], 0, self::DETAILS_MAX),
        ];
    }

    /**
     * Check the body, read the CSV and classify every line. Writes nothing.
     *
     * @return array{language:array,file:?string,rows:int,new:int,unchanged:int,changed:array,skipped:array,write:array}
     */
    private static function plan(PDO $pdo, array $body): array
    {
        $languageId = $body['language_id'] ?? null;
        if (!is_int($languageId)) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ziel-Sprache fehlt', 'errors' => ['language_id' => 'ID erwartet']]);
        }
        $language = Languages::find($pdo, $languageId);
        [$content, $file] = self::source($body);
        $records = self::parse($content);

        // existing texts of the language, schema elements and UI texts under the same key (Languages::itemKey())
        $existing = Languages::texts($pdo, $languageId);
        foreach (Languages::uiTexts($pdo, $languageId) as $key => $text) {
            $existing[Languages::itemKey('ui_text', (string) $key, null)] = $text;
        }
        $known = null; // identifiers of the schema elements, only on demand (a pure UI text file does not need a schema)
        $plan = ['language' => $language, 'file' => $file, 'rows' => count($records), 'new' => 0, 'unchanged' => 0,
            'changed' => [], 'skipped' => [], 'write' => []];
        $seen = [];
        foreach ($records as [$line, $rec]) {
            $kind = trim((string) ($rec[0] ?? ''));
            $ref = trim((string) ($rec[1] ?? ''));
            $skip = function (string $reason) use (&$plan, $line, $kind, $ref): void {
                $plan['skipped'][] = ['line' => $line, 'kind' => $kind, 'ref' => $ref, 'reason' => $reason];
            };
            if (count($rec) < 3) {
                $skip('Drei Spalten erwartet (kind, ref, text)');
                continue;
            }
            if (!in_array($kind, Languages::KINDS, true)) {
                $skip("Unbekannte Art „{$kind}“ (erlaubt: " . implode(', ', Languages::KINDS) . ')');
                continue;
            }
            if ($kind === 'ui_text') {
                if ($language['is_default']) {
                    $skip('Feste UI-Texte der Standardsprache werden nicht übersetzt (sie stehen in i18n-strings.js)');
                    continue;
                }
                if (strlen($ref) > Languages::UI_KEY_MAX || !preg_match(Languages::UI_KEY_PATTERN, $ref)) {
                    $skip("„{$ref}“ ist kein Schlüssel eines UI-Texts (z. B. „login.submit“)");
                    continue;
                }
                $refId = $ref;
                $value = '';
            } else {
                if ($known === null) {
                    SchemaIds::ensure($pdo);
                    $known = [];
                    foreach (Languages::elements($pdo) as $g) {
                        foreach ($g['items'] as $item) {
                            $known[$item['kind'] . '|' . $item['ref']] = $item;
                        }
                    }
                }
                $item = $known[$kind . '|' . $ref] ?? null;
                if ($item === null) {
                    $skip("Element „{$ref}“ im aktuellen Schema nicht gefunden");
                    continue;
                }
                $refId = $item['ref_id'];
                $value = $kind === 'enum_value' ? (string) $item['value'] : '';
            }
            $text = Languages::normalizeText((string) $rec[2]);
            if ($text === '') {
                $skip('Leerer Text (eine vorhandene Übersetzung bleibt bestehen)');
                continue;
            }
            if (Languages::length($text) > Languages::TEXT_MAX) {
                $skip('Text länger als ' . Languages::TEXT_MAX . ' Zeichen');
                continue;
            }
            $key = Languages::itemKey($kind, $refId, $value);
            if (isset($seen[$key])) {
                $skip("Doppelt – steht schon in Zeile {$seen[$key]}");
                continue;
            }
            $seen[$key] = $line;
            $old = $existing[$key] ?? null;
            if ($old === $text) {
                $plan['unchanged']++;
                continue;
            }
            if ($old === null) {
                $plan['new']++;
            } else {
                $plan['changed'][] = ['line' => $line, 'kind' => $kind, 'ref' => $ref, 'old' => $old, 'new' => $text];
            }
            $plan['write'][] = [$kind, $refId, $value, $text];
        }
        return $plan;
    }

    /** @return array{0:string,1:?string} CSV text and, if applicable, the file name from translations/ */
    private static function source(array $body): array
    {
        $file = $body['file'] ?? null;
        $content = $body['content'] ?? null;
        if (($file === null) === ($content === null) || ($file !== null && !is_string($file)) || ($content !== null && !is_string($content))) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Entweder den Inhalt einer CSV-Datei (content) oder '
                . 'eine Datei aus translations/ (file) angeben', 'errors' => ['content' => 'Text erwartet']]);
        }
        if ($file === null) {
            self::checkSize(strlen($content));
            return [$content, null];
        }
        // only a file name from the directory, no paths
        $path = Config::translationsDir() . '/' . $file;
        if (!preg_match(self::FILE_PATTERN, $file) || !is_file($path)) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datei in translations/ nicht gefunden']);
        }
        self::checkSize((int) filesize($path));
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new ApiException(409, ['error' => 'unreadable', 'message' => "translations/{$file} ist nicht lesbar (Dateirechte prüfen)"]);
        }
        return [$content, $file];
    }

    private static function checkSize(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new ApiException(422, ['error' => 'too_large', 'message' => 'Die CSV-Datei ist zu groß (höchstens '
                . (self::MAX_BYTES / 1048576) . ' MB)']);
        }
    }

    /**
     * CSV text -> data lines [[line number, columns], …] without header line and empty lines. Allowed are a BOM at the
     * beginning and ";" instead of "," as the separator (this is how Excel saves with German settings); 422 if the text is
     * not UTF-8 or the header line is missing.
     *
     * @return array<int,array{0:int,1:array}>
     */
    private static function parse(string $content): array
    {
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        if (preg_match('//u', $content) !== 1) {
            throw new ApiException(422, ['error' => 'invalid_csv', 'message' => 'Die Datei ist nicht UTF-8-kodiert.']);
        }
        $first = (string) strtok(ltrim($content), "\r\n");
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $records = [];
        $header = null;
        $line = 0;
        while (($rec = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($rec === [null] || implode('', array_map('strval', $rec)) === '') {
                continue; // empty line
            }
            if ($header === null) {
                $header = array_map(function ($c) {
                    return strtolower(trim((string) $c));
                }, array_slice($rec, 0, 3));
                if ($header !== self::HEADER) {
                    break;
                }
                continue;
            }
            $records[] = [$line, $rec];
        }
        fclose($h);
        if ($header !== self::HEADER) {
            throw new ApiException(422, ['error' => 'invalid_csv', 'message' => 'Keine Übersetzungs-CSV: Die erste Zeile muss '
                . '„kind,ref,text“ lauten.']);
        }
        return $records;
    }
}
