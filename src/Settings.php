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
 * A further setting is one more entry in DEFINITIONS (plus its switch in the UI); a missing row means the default, so
 * existing installations behave as before.
 */
final class Settings
{
    /** Is a valid login or API key also required for read access (GET)? See Http::api(). */
    public const REQUIRE_AUTH_FOR_READ = 'require_auth_for_read';

    /** name => [type, default]; type 'bool' is the only one so far */
    private const DEFINITIONS = [
        self::REQUIRE_AUTH_FOR_READ => ['bool', false],
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

    /** @return mixed current value, the default if nothing is stored */
    public static function get(PDO $pdo, string $name)
    {
        [, $default] = self::DEFINITIONS[$name];
        if (!Database::tableExists($pdo, '_meta')) {
            return $default;
        }
        $stored = Database::getMeta($pdo, $name);
        return $stored === null ? $default : $stored === '1';
    }

    /**
     * @param array $input only the settings contained in the body are changed; unknown names and wrong types -> 422
     * @return array<string,mixed> all settings afterwards
     */
    public static function update(PDO $pdo, array $input): array
    {
        $errors = [];
        foreach ($input as $name => $value) {
            if (!isset(self::DEFINITIONS[$name])) {
                $errors[(string) $name] = 'Unbekannte Einstellung';
            } elseif (!is_bool($value)) {
                $errors[$name] = 'true oder false erwartet';
            }
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        Database::ensureMetaTable($pdo);
        foreach ($input as $name => $value) {
            Database::setMeta($pdo, $name, $value ? '1' : '0');
        }
        return self::all($pdo);
    }
}
