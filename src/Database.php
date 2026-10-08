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

final class Database
{
    /** Opens the SQLite file (creates it if it is missing). */
    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . Config::dbFile(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // text helpers for lists (ListQuery): SQLite knows upper/lower case and collation for ASCII only
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('lhc_fold', [self::class, 'fold'], 1);
            $pdo->sqliteCreateFunction('lhc_sortkey', [self::class, 'sortKey'], 1);
            $pdo->sqliteCreateFunction('lhc_plain', [self::class, 'plainText'], 1);
        }
        return $pdo;
    }

    /**
     * Markdown -> plain text, the way the list view displays richtext cells (the same replacements as plainText() in
     * frontend/src/components/sortFilter.js - change both together): filter and sorting of a richtext column work on the
     * displayed text, not on the Markdown syntax.
     */
    public static function plainText($md): ?string
    {
        if ($md === null) {
            return null;
        }
        $s = (string) $md;
        $kept = []; // characters protected by a backslash are kept as characters
        $s = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~<>])/u', function ($m) use (&$kept) {
            $kept[] = $m[1];
            return "\x01" . (count($kept) - 1) . "\x02";
        }, $s);
        foreach ([
            '/^\s*```.*$/mu' => '',
            '/!\[([^\]]*)\]\([^)]*\)/u' => '$1',
            '/\[([^\]]*)\]\([^)]*\)/u' => '$1',
            '/<[^>]+>/u' => ' ',
            '/^\s*([-*_]\s*){3,}$/mu' => '',
            '/^\s*\|?(\s*:?-+:?\s*\|)+\s*:?-*:?\s*$/mu' => '',
            '/^\s*(>\s?)+/mu' => '',
            '/^\s*#{1,6}\s+/mu' => '',
            '/^\s*([-*+]|\d+[.)])\s+(\[[ xX]\]\s+)?/mu' => '',
            '/(\*\*|__)(.+?)\1/u' => '$2',
            '/~~(.+?)~~/u' => '$1',
            '/\*(.+?)\*/u' => '$1',
            '/(^|[^\w])_(.+?)_(?=[^\w]|$)/u' => '$1$2',
            '/`([^`]*)`/u' => '$1',
            '/\|/u' => ' ',
        ] as $pattern => $replacement) {
            $s = (string) preg_replace($pattern, $replacement, $s);
        }
        $s = preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($kept) {
            return $kept[(int) $m[1]];
        }, $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Lower case, including umlauts and other non-ASCII characters (case-insensitive text filter) */
    public static function fold($value): ?string
    {
        if ($value === null) {
            return null;
        }
        return function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value);
    }

    /**
     * Sort key for text, modelled on the German collation of the UI: case-insensitive, umlauts and accents like their
     * base letter (ß like ss), digit sequences numerically ("Hafen 2" before "Hafen 12"). The key is computed once per
     * row and then compared in binary.
     */
    public static function sortKey($value): ?string
    {
        if ($value === null) {
            return null;
        }
        static $map = null;
        if ($map === null) {
            $map = ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l'];
            foreach (['a' => 'äàáâãå', 'c' => 'çć', 'e' => 'èéêë', 'i' => 'ìíîï', 'n' => 'ñń', 'o' => 'öòóôõ', 's' => 'śš',
                'u' => 'üùúû', 'y' => 'ýÿ', 'z' => 'źżž'] as $base => $chars) {
                foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    $map[$ch] = $base;
                }
            }
        }
        $key = strtr((string) self::fold($value), $map);
        return preg_replace_callback('/\d+/', function ($m) {
            $digits = ltrim($m[0], '0');
            return str_pad($digits, 20, '0', STR_PAD_LEFT);
        }, $key);
    }

    /** Like connect(), but does not create the DB file: null if it is missing. */
    public static function connectIfExists(): ?PDO
    {
        return is_file(Config::dbFile()) ? self::connect() : null;
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    public static function ensureMetaTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS "_meta" ("key" TEXT PRIMARY KEY, "value" TEXT)');
    }

    public static function getMeta(PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT "value" FROM "_meta" WHERE "key" = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function setMeta(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO "_meta" ("key", "value") VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }

    /**
     * Returns the schema model stored at bootstrap, or null if not bootstrapped yet.
     * Does NOT create the DB file.
     */
    public static function loadModel(?PDO $pdo = null): ?array
    {
        if ($pdo === null) {
            if (!is_file(Config::dbFile())) {
                return null;
            }
            $pdo = self::connect();
        }
        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '_meta'")->fetchColumn();
        if (!$exists) {
            return null;
        }
        $json = self::getMeta($pdo, 'schema_json');
        if ($json === null) {
            return null;
        }
        $model = json_decode($json, true);
        return is_array($model) ? $model : null;
    }
}
