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
    /** Rule for every newly set password: minimum length and the kinds of characters required, see Auth::passwordError(). */
    public const PASSWORD_MIN_LENGTH = 'password_min_length';
    public const PASSWORD_REQUIRE_DIGIT = 'password_require_digit';
    public const PASSWORD_REQUIRE_SPECIAL = 'password_require_special';
    public const PASSWORD_REQUIRE_MIXED_CASE = 'password_require_mixed_case';
    /** Validity of a session in hours, counted from the login. See Auth::sessionLifetime(). */
    public const SESSION_LIFETIME_HOURS = 'session_lifetime_hours';
    /** Rate limit of the login: failed attempts allowed within the time window, and that window in minutes. See Auth. */
    public const LOGIN_RATE_LIMIT_MAX = 'login_rate_limit_max';
    public const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 'login_rate_limit_window_minutes';
    /** Rows of login_attempts older than this many days are deleted on every login call. */
    public const LOGIN_ATTEMPTS_RETENTION_DAYS = 'login_attempts_retention_days';
    /** Size limit per file in the media library in MB; the PHP limits may be lower, see Media::limits(). */
    public const MEDIA_MAX_MB = 'media_max_mb';
    /** Edge length of the thumbnails in pixels (Thumbnail); applies to images uploaded or replaced from then on. */
    public const MEDIA_THUMBNAIL_PX = 'media_thumbnail_px';
    /** Maximum lengths (characters) of the texts of a medium and of a tag name, see Media. */
    public const MEDIA_TITLE_MAX = 'media_title_max';
    public const MEDIA_ALT_MAX = 'media_alt_max';
    public const MEDIA_DESCRIPTION_MAX = 'media_description_max';
    public const MEDIA_TAG_MAX = 'media_tag_max';
    /** Rows per list page (GET /api/{entity}): without per_page, and the largest per_page allowed. See ListQuery. */
    public const LIST_DEFAULT_PER_PAGE = 'list_default_per_page';
    public const LIST_MAX_PER_PAGE = 'list_max_per_page';
    /** Rows per page of the selection dialog of the UI (SelectModal.jsx). */
    public const LIST_SELECT_PER_PAGE = 'list_select_per_page';
    /** Upper limit for the raw data of one list page in MB; 0 = automatic (Config::listMaxBytes()). See listMaxBytes(). */
    public const LIST_MAX_RESPONSE_MB = 'list_max_response_mb';
    /** GET /api/_tasks and /api/_tasks/mine deliver at most this many tasks, see Workflows. */
    public const TASKS_LIST_LIMIT = 'tasks_list_limit';
    /** Versions kept per record (Revisions); 0 = all. Older ones are deleted on the next save of the record. */
    public const REVISIONS_KEEP = 'revisions_keep';
    /** Backup pairs kept before schema changes, see SchemaBackup. */
    public const SCHEMA_BACKUP_KEEP = 'schema_backup_keep';
    /** From this database size (MB) on, System -> Schema points out the storage needed for the backups. */
    public const SCHEMA_BACKUP_WARN_MB = 'schema_backup_warn_mb';
    /** From this age (seconds) on, the maintenance flag counts as orphaned, see Maintenance. */
    public const MAINTENANCE_TIMEOUT_SECONDS = 'maintenance_timeout_seconds';
    /** last_used_at of an API key is rewritten at most this often (seconds); 0 = on every request. See ApiKeys. */
    public const API_KEY_LAST_USED_SECONDS = 'api_key_last_used_seconds';
    /** Largest number of rows per entity one call of the test data generator creates, see TestData. */
    public const TESTDATA_MAX_COUNT = 'testdata_max_count';
    /** Size limit of a CSV file of the translation import in MB, see TranslationCsv. */
    public const TRANSLATIONS_CSV_MAX_MB = 'translations_csv_max_mb';
    /** Longest wait (seconds) for the calls of the webhooks of one save/transition/deletion, see Webhooks. */
    public const WEBHOOK_TIMEOUT_SECONDS = 'webhook_timeout_seconds';

    /**
     * name => ['bool', default] or ['int', default, min, max]. Default null: the value from config.php (or its own
     * default) applies as long as nothing is stored, see fallback() - so an installation configured there stays as it is.
     * The defaults are the previous fixed values, so an installation without stored settings behaves as before.
     * The window of the rate limit (at most one day) never exceeds the retention of login_attempts (at least one day);
     * the largest page (at least 100) covers what the UI itself asks for (useReferences.js: 100 rows, Pager.jsx).
     */
    private const DEFINITIONS = [
        self::REQUIRE_AUTH_FOR_READ           => ['bool', false],
        self::PASSWORD_MIN_LENGTH             => ['int', Auth::MIN_PASSWORD_LENGTH, 8, 64],
        self::PASSWORD_REQUIRE_DIGIT          => ['bool', true],
        self::PASSWORD_REQUIRE_SPECIAL        => ['bool', true],
        self::PASSWORD_REQUIRE_MIXED_CASE     => ['bool', false],
        self::SESSION_LIFETIME_HOURS          => ['int', Auth::LIFETIME / 3600, 1, 720],
        self::LOGIN_RATE_LIMIT_MAX            => ['int', Auth::RATE_LIMIT_MAX, 3, 100],
        self::LOGIN_RATE_LIMIT_WINDOW_MINUTES => ['int', Auth::RATE_LIMIT_WINDOW / 60, 1, 1440],
        self::LOGIN_ATTEMPTS_RETENTION_DAYS   => ['int', Auth::ATTEMPTS_RETENTION_DAYS, 1, 365],
        self::MEDIA_MAX_MB                    => ['int', null, 1, 2048],
        self::MEDIA_THUMBNAIL_PX              => ['int', Thumbnail::MAX, 50, 1000],
        self::MEDIA_TITLE_MAX                 => ['int', Media::MAX_TITLE, 20, 1000],
        self::MEDIA_ALT_MAX                   => ['int', Media::MAX_ALT, 50, 10000],
        self::MEDIA_DESCRIPTION_MAX           => ['int', Media::MAX_DESCRIPTION, 100, 100000],
        self::MEDIA_TAG_MAX                   => ['int', Media::MAX_TAG, 10, 255],
        self::LIST_DEFAULT_PER_PAGE           => ['int', ListQuery::DEFAULT_PER_PAGE, 5, 500],
        self::LIST_MAX_PER_PAGE               => ['int', ListQuery::MAX_PER_PAGE, 100, 5000],
        self::LIST_SELECT_PER_PAGE            => ['int', 25, 5, 100],
        self::LIST_MAX_RESPONSE_MB            => ['int', 0, 0, 256],
        self::TASKS_LIST_LIMIT                => ['int', Workflows::TASK_LIMIT, 10, 5000],
        self::REVISIONS_KEEP                  => ['int', 5, 0, 10000],
        self::SCHEMA_BACKUP_KEEP              => ['int', null, 1, 100],
        self::SCHEMA_BACKUP_WARN_MB           => ['int', null, 1, 102400],
        self::MAINTENANCE_TIMEOUT_SECONDS     => ['int', Maintenance::MAX_AGE, 30, 3600],
        self::API_KEY_LAST_USED_SECONDS       => ['int', ApiKeys::LAST_USED_INTERVAL, 0, 86400],
        self::TESTDATA_MAX_COUNT              => ['int', TestData::MAX_COUNT, 10, 10000],
        self::TRANSLATIONS_CSV_MAX_MB         => ['int', TranslationCsv::MAX_BYTES / 1048576, 1, 32],
        self::WEBHOOK_TIMEOUT_SECONDS         => ['int', Webhooks::TIMEOUT, 1, 30],
    ];

    /**
     * Settings the UI needs for every logged-in user, not only for admins (rule hint below password fields, page sizes,
     * upper limit of the test data form): part of GET /api/me, see client().
     */
    private const CLIENT = [
        self::PASSWORD_MIN_LENGTH, self::PASSWORD_REQUIRE_DIGIT, self::PASSWORD_REQUIRE_SPECIAL, self::PASSWORD_REQUIRE_MIXED_CASE,
        self::LIST_DEFAULT_PER_PAGE, self::LIST_MAX_PER_PAGE, self::LIST_SELECT_PER_PAGE, self::TESTDATA_MAX_COUNT,
    ];

    /** @var array<string,string>|null stored rows of this request (name => value), see stored() */
    private static $rows = null;

    /** @return array<string,mixed> all settings with their current value */
    public static function all(PDO $pdo): array
    {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $name) {
            $out[$name] = self::get($pdo, $name);
        }
        return $out;
    }

    /** @return array<string,mixed> the settings of CLIENT with their current value */
    public static function client(PDO $pdo): array
    {
        $out = [];
        foreach (self::CLIENT as $name) {
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
            return $stored === '1' || $stored === '0' ? $stored === '1' : $def[1];
        }
        if (self::validInt($stored, $def)) {
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
        return self::hasStored($pdo, self::MEDIA_MAX_MB) ? self::get($pdo, self::MEDIA_MAX_MB) * 1048576 : Config::mediaMaxBytes();
    }

    /** Threshold of the disk space notice in bytes: the setting if one is stored, otherwise exactly what config.php says. */
    public static function schemaBackupWarnBytes(PDO $pdo): int
    {
        return self::hasStored($pdo, self::SCHEMA_BACKUP_WARN_MB)
            ? self::get($pdo, self::SCHEMA_BACKUP_WARN_MB) * 1048576
            : Config::schemaBackupWarnBytes();
    }

    /**
     * Upper limit for the raw data of one list page in bytes: the setting if a size is stored, otherwise (nothing stored
     * or 0 = automatic) Config::listMaxBytes() - LIST_MAX_BYTES from config.php or derived from the memory_limit.
     */
    public static function listMaxBytes(PDO $pdo): int
    {
        $mb = self::get($pdo, self::LIST_MAX_RESPONSE_MB);
        return $mb > 0 ? $mb * 1048576 : Config::listMaxBytes();
    }

    /**
     * @param array $input only the settings contained in the body are changed; unknown names, wrong types and values
     *                     outside the allowed range -> 422. Nothing is stored unless everything is valid.
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
        if (!$errors) {
            // rules across two settings, checked on the state after the change
            $after = $input + self::all($pdo);
            if ($after[self::LIST_DEFAULT_PER_PAGE] > $after[self::LIST_MAX_PER_PAGE]) {
                $errors[isset($input[self::LIST_DEFAULT_PER_PAGE]) ? self::LIST_DEFAULT_PER_PAGE : self::LIST_MAX_PER_PAGE]
                    = 'Die Standard-Seitengröße darf nicht über der größten Seitengröße liegen';
            }
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        Database::ensureMetaTable($pdo);
        foreach ($input as $name => $value) {
            Database::setMeta($pdo, $name, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        self::$rows = null;
        return self::all($pdo);
    }

    /** Is a usable value stored for this number (then it takes precedence over config.php)? */
    private static function hasStored(PDO $pdo, string $name): bool
    {
        return self::validInt(self::stored($pdo, $name), self::DEFINITIONS[$name]);
    }

    private static function validInt(?string $stored, array $def): bool
    {
        return $stored !== null && ctype_digit($stored) && strlen($stored) <= 9 && (int) $stored >= $def[2] && (int) $stored <= $def[3];
    }

    /** Stored value or null; all rows are read once per request (update() discards them). */
    private static function stored(PDO $pdo, string $name): ?string
    {
        if (self::$rows === null) {
            self::$rows = [];
            if (Database::tableExists($pdo, '_meta')) {
                $names = array_keys(self::DEFINITIONS);
                $stmt = $pdo->prepare('SELECT "key", "value" FROM "_meta" WHERE "key" IN (' . implode(', ', array_fill(0, count($names), '?')) . ')');
                $stmt->execute($names);
                foreach ($stmt->fetchAll() as $row) {
                    self::$rows[(string) $row['key']] = (string) $row['value'];
                }
            }
        }
        return self::$rows[$name] ?? null;
    }

    /** Value of a setting without a fixed default: what config.php says (which has its own default), in whole units */
    private static function fallback(string $name): int
    {
        switch ($name) {
            case self::MEDIA_MAX_MB:
                return max(1, (int) round(Config::mediaMaxBytes() / 1048576));
            case self::SCHEMA_BACKUP_WARN_MB:
                return max(1, (int) round(Config::schemaBackupWarnBytes() / 1048576));
            default:
                return Config::schemaBackupKeep();
        }
    }
}
