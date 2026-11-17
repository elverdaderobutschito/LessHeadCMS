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
 * Log of failed checks of the diagram editor's patch (PumlPatcher, 'verify_failed'): the patched text did not match the
 * edited diagram - a fault of the patcher, not one of the known cases that cannot be patched. The editor falls back to
 * the complete export with a warning as before; in addition every such case is kept here so that it can be reproduced:
 * one JSON file per case in data/patcher-failures/ (time, reason, original text, model JSON of the editor). data/ is
 * blocked via .htaccess (or lies outside the webroot), the files cannot be retrieved over the web; System → Schema shows
 * how many there are and offers them as one download. The newest KEEP cases are kept, older ones deleted.
 *
 * Logging never makes the request fail: if the directory cannot be written, the case is simply not logged.
 */
final class PatcherFailures
{
    public const KEEP = 20;

    public static function dir(): string
    {
        return Config::dataDir() . '/patcher-failures';
    }

    /** Logs a case (never throws) */
    public static function record(string $source, $model, Msg $reason): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return;
            }
            $entry = [
                'time'        => date('c'),
                'reason'      => $reason->text(),
                'reason_code' => $reason->codeOnly(),
                'source'      => $source,
                'model'       => $model,
            ];
            $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return;
            }
            // name = time with microseconds, so that the names sort like the cases (several in the same microsecond: + 1)
            [$usec, $sec] = explode(' ', microtime());
            $sec = (int) $sec;
            $usec = (int) round((float) $usec * 1e6);
            do {
                $file = $dir . '/' . date('Y-m-d_H-i-s', $sec) . sprintf('_%06d', $usec % 1000000) . '.json';
                $usec++;
            } while (file_exists($file));
            if (@file_put_contents($file . '.tmp', $json) === false || !@rename($file . '.tmp', $file)) {
                @unlink($file . '.tmp');
                return;
            }
            $files = self::files();
            foreach (array_slice($files, 0, max(0, count($files) - self::KEEP)) as $old) {
                @unlink($old);
            }
        } catch (\Throwable $e) {
            // logging is a diagnosis aid only
        }
    }

    /** @return string[] logged cases, oldest first (the names start with the time) */
    public static function files(): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    public static function count(): int
    {
        return count(self::files());
    }

    /** All logged cases as one JSON file (newest first) */
    public static function download(): Download
    {
        $entries = [];
        foreach (array_reverse(self::files()) as $file) {
            // as objects, so that empty objects ({} in the model JSON) stay objects
            $entry = json_decode((string) @file_get_contents($file));
            if ($entry instanceof \stdClass) {
                $entries[] = (object) (['file' => basename($file)] + get_object_vars($entry));
            }
        }
        $json = (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new Download('patcher-failures.json', 'application/json; charset=utf-8', $json . "\n");
    }
}
