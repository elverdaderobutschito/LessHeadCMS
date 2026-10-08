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
 * Maintenance flag data/migration.flag (content: Unix timestamp) while a schema migration (SchemaMigration::apply())
 * or a restore (SchemaBackup::restore()) rebuilds the database. Meanwhile, content write access (POST/PUT/DELETE on
 * /api/{entity}, media library, column selection, test data) is rejected with 503 (guard()). Reading stays
 * unaffected: until the COMMIT, readers in SQLite see the old state anyway.
 *
 * The flag is removed again in any case (finally). If PHP aborts hard (e.g. max_execution_time), it stays behind:
 * a flag older than MAX_AGE seconds therefore counts as orphaned and is ignored.
 */
final class Maintenance
{
    /** From this age (seconds) on, a flag counts as orphaned */
    public const MAX_AGE = 120;

    public const MESSAGE = 'Wartung: Eine Schema-Migration läuft gerade. Bitte in Kürze erneut versuchen.';

    public static function file(): string
    {
        return Config::dataDir() . '/migration.flag';
    }

    /** Set the flag (overwrites an orphaned one) */
    public static function begin(): void
    {
        @file_put_contents(self::file(), (string) time());
    }

    public static function end(): void
    {
        @unlink(self::file());
    }

    /** Is a migration currently running (flag present and not orphaned)? */
    public static function active(): bool
    {
        $file = self::file();
        if (!is_file($file)) {
            return false;
        }
        $raw = trim((string) @file_get_contents($file));
        $since = ctype_digit($raw) ? (int) $raw : (int) @filemtime($file);
        return $since > 0 && time() - $since < self::MAX_AGE;
    }

    /** @throws ApiException 503 maintenance while active() */
    public static function guard(): void
    {
        if (self::active()) {
            throw new ApiException(503, ['error' => 'maintenance', 'message' => self::MESSAGE], ['Retry-After' => '10']);
        }
    }

    /** guard() only for writing methods (everything except GET/HEAD) */
    public static function guardWrite(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            self::guard();
        }
    }
}
