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
 * Settings of the installation that are changed in the admin area (System → Einstellungen, GET/PUT /api/_settings)
 * instead of in config.php. Stored as rows of _meta (key = name of the setting), read on every request - a change
 * applies at once, without a new bootstrap.
 *
 * A further setting is one more entry in DEFINITIONS (plus its field in the UI); a missing row means the default, so
 * existing installations behave as before.
 */
final class Settings
{
    /** Is a valid login or API key also required for read access (GET)? See Http::api(). */
    public const REQUIRE_AUTH_FOR_READ = 'require_auth_for_read';
    /** Rate limit of the login: failed attempts allowed within the time window, and that window in minutes. See Auth. */
    public const LOGIN_RATE_LIMIT_MAX = 'login_rate_limit_max';
    public const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 'login_rate_limit_window_minutes';
    /** Size limit per file in the media library in MB; the PHP limits may be lower, see Media::limits(). */
    public const MEDIA_MAX_MB = 'media_max_mb';
    /** Backup pairs kept before schema changes, see SchemaBackup. */
    public const SCHEMA_BACKUP_KEEP = 'schema_backup_keep';

    /**
     * name => ['bool', default] or ['int', default, min, max]. Default null: the value from config.php (or its own
     * default) applies as long as nothing is stored, see fallback() - so an installation configured there stays as it is.
     * The window may not exceed the retention of login_attempts (one day, Auth::ATTEMPTS_RETENTION).
     */
    private const DEFINITIONS = [
        self::REQUIRE_AUTH_FOR_READ           => ['bool', false],
        self::LOGIN_RATE_LIMIT_MAX            => ['int', Auth::RATE_LIMIT_MAX, 3, 100],
        self::LOGIN_RATE_LIMIT_WINDOW_MINUTES => ['int', Auth::RATE_LIMIT_WINDOW / 60, 1, 1440],
        self::MEDIA_MAX_MB                    => ['int', null, 1, 2048],
        self::SCHEMA_BACKUP_KEEP              => ['int', null, 1, 100],
    ];

    /** @return array<string,mixed> all settings with their current value */
    public static function all(PDO $pdo): array
    {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $name) {
            $out[$name] = self::get($pdo, $name);
        }
        return $out;
    }

    /** @return mixed current value, the default if nothing (or nothing valid) is stored */
    public static function get(PDO $pdo, string $name)
    {
        $def = self::DEFINITIONS[$name];
        $stored = self::stored($pdo, $name);
        if ($def[0] === 'bool') {
            return $stored === null ? $def[1] : $stored === '1';
        }
        if ($stored !== null && ctype_digit($stored) && (int) $stored >= $def[2] && (int) $stored <= $def[3]) {
            return (int) $stored;
        }
        return $def[1] ?? self::fallback($name);
    }

    /**
     * Size limit per upload in bytes: the setting if one is stored, otherwise exactly what config.php says
     * (MEDIA_MAX_MB there may also be a fraction of a MB).
     */
    public static function mediaMaxBytes(PDO $pdo): int
    {
        return self::stored($pdo, self::MEDIA_MAX_MB) === null
            ? Config::mediaMaxBytes()
            : self::get($pdo, self::MEDIA_MAX_MB) * 1048576;
    }

    /**
     * @param array $input only the settings contained in the body are changed; unknown names, wrong types and values
     *                     outside the allowed range -> 422
     * @return array<string,mixed> all settings afterwards
     */
    public static function update(PDO $pdo, array $input): array
    {
        $errors = [];
        foreach ($input as $name => $value) {
            $def = self::DEFINITIONS[$name] ?? null;
            if ($def === null) {
                $errors[(string) $name] = 'Unbekannte Einstellung';
            } elseif ($def[0] === 'bool' && !is_bool($value)) {
                $errors[$name] = 'true oder false erwartet';
            } elseif ($def[0] === 'int' && (!is_int($value) || $value < $def[2] || $value > $def[3])) {
                $errors[$name] = "Ganze Zahl von {$def[2]} bis {$def[3]} erwartet";
            }
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        Database::ensureMetaTable($pdo);
        foreach ($input as $name => $value) {
            Database::setMeta($pdo, $name, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        return self::all($pdo);
    }

    private static function stored(PDO $pdo, string $name): ?string
    {
        return Database::tableExists($pdo, '_meta') ? Database::getMeta($pdo, $name) : null;
    }

    /** Value of a setting without a fixed default: what config.php says (which has its own default) */
    private static function fallback(string $name): int
    {
        return $name === self::MEDIA_MAX_MB
            ? max(1, (int) round(Config::mediaMaxBytes() / 1048576))
            : Config::schemaBackupKeep();
    }
}
