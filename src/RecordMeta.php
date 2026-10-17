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
 * Tracking metadata per record: created_at, created_by, updated_at, updated_by in every table of a regular entity
 * (not in link tables and not in the system tables). They are set by the system only (Cms::create(), update(),
 * transition()); values sent along by a client are ignored like an id sent along.
 *
 *  - created_at/updated_at: DATETIME, UTC "YYYY-MM-DD HH:MM:SS" (the format of CURRENT_TIMESTAMP, like lh_task.created_at
 *    and media.uploaded_at). When creating, updated_* equals created_*.
 *  - created_by/updated_by: users.id, without a database constraint (like lh_task.created_by). Users are never deleted,
 *    only deactivated, so the id always resolves; the API shows the current username/name via a join (like
 *    media.uploaded_by), not a snapshot from the time of the change.
 *  - Rows from before this feature: all four NULL ("unbekannt"), nothing is invented.
 *
 * The columns are not part of the schema model (PumlParser): they do not appear in the diagram, in the field permissions
 * or in the translations of the fields - like the id they are always there and never editable. The parser reserves the
 * four names. An entity of an older installation that already has a field of the same name keeps it and simply has no
 * tracking metadata (applies() = false) until the field is renamed.
 *
 * Without a session (public API) created_by/updated_by are missing in _schema and in every row (usernames are
 * not published); created_at/updated_at stay visible.
 */
final class RecordMeta
{
    public const CREATED_AT = 'created_at';
    public const CREATED_BY = 'created_by';
    public const UPDATED_AT = 'updated_at';
    public const UPDATED_BY = 'updated_by';

    /** API and column names in display order */
    public const FIELDS = [self::CREATED_AT, self::CREATED_BY, self::UPDATED_AT, self::UPDATED_BY];
    /** Fields that name a user (missing in the public API) */
    public const USER_FIELDS = [self::CREATED_BY, self::UPDATED_BY];

    private const TYPES = [
        self::CREATED_AT => 'datetime', self::CREATED_BY => 'user', self::UPDATED_AT => 'datetime', self::UPDATED_BY => 'user',
    ];
    private const SQL_TYPES = ['datetime' => 'DATETIME', 'user' => 'INTEGER'];

    /** _meta key: all entity tables have the columns (see ensure()) */
    private const META_KEY = 'record_meta';

    /** Does the entity carry tracking metadata? Not if one of its own fields already uses one of the names (older installation). */
    public static function applies(array $entity): bool
    {
        foreach ($entity['fields'] as $f) {
            if (in_array(strtolower($f['name']), self::FIELDS, true)) {
                return false;
            }
        }
        foreach ($entity['media'] ?? [] as $m) {
            if (in_array(strtolower($m['name']), self::FIELDS, true)) {
                return false;
            }
        }
        return true;
    }

    /** Is $name one of the four names (case-insensitive, like SQLite column names)? */
    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::FIELDS, true);
    }

    /** Pseudo field definitions ['name', 'type' => datetime|user] - for _schema, ListQuery and ColumnPrefs */
    public static function fields(bool $withUsers = true): array
    {
        $out = [];
        foreach (self::FIELDS as $name) {
            if ($withUsers || !in_array($name, self::USER_FIELDS, true)) {
                $out[] = ['name' => $name, 'type' => self::TYPES[$name]];
            }
        }
        return $out;
    }

    /** Column definitions for CREATE TABLE (SqlGenerator) */
    public static function columnDefinitions(): array
    {
        return array_map(function ($name) {
            return SqlGenerator::q($name) . ' ' . self::SQL_TYPES[self::TYPES[$name]];
        }, self::FIELDS);
    }

    /** Current time in the stored format (UTC, like CURRENT_TIMESTAMP) */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Values for INSERT: created_* and updated_* = now/user (null without a user, e.g. an internal call) */
    public static function createValues(?array $user): array
    {
        $now = self::now();
        $by = $user !== null ? (int) $user['id'] : null;
        return [self::CREATED_AT => $now, self::CREATED_BY => $by, self::UPDATED_AT => $now, self::UPDATED_BY => $by];
    }

    /** Values for UPDATE: updated_* = now/user */
    public static function updateValues(?array $user): array
    {
        return [self::UPDATED_AT => self::now(), self::UPDATED_BY => $user !== null ? (int) $user['id'] : null];
    }

    /**
     * Brings the rows into the API form: timestamps as text, created_by/updated_by as {id, username, name} (current
     * values from users, one query for all rows) or null. Without $withUsers the two user fields are removed. A row
     * whose table still lacks the columns (upgrade not done yet) gets null.
     *
     * @param array[] $rows
     * @return array[]
     */
    public static function present(PDO $pdo, array $rows, bool $withUsers): array
    {
        $ids = [];
        if ($withUsers) {
            foreach ($rows as $row) {
                foreach (self::USER_FIELDS as $name) {
                    if (isset($row[$name])) {
                        $ids[(int) $row[$name]] = true;
                    }
                }
            }
        }
        $users = [];
        if ($ids) {
            $stmt = $pdo->query('SELECT id, username, name FROM users WHERE id IN (' . implode(', ', array_keys($ids)) . ')');
            foreach ($stmt->fetchAll() as $u) {
                $users[(int) $u['id']] = ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'name' => (string) $u['name']];
            }
        }
        foreach ($rows as &$row) {
            foreach (self::FIELDS as $name) {
                $value = $row[$name] ?? null;
                if (in_array($name, self::USER_FIELDS, true)) {
                    if (!$withUsers) {
                        unset($row[$name]);
                        continue;
                    }
                    // a user id that no longer exists (only possible via direct SQL): id without a name
                    $value = $value === null ? null : ($users[(int) $value] ?? ['id' => (int) $value, 'username' => '', 'name' => '']);
                } elseif ($value !== null) {
                    $value = (string) $value;
                }
                $row[$name] = $value;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Existing installations: add the four columns to every entity table that lacks them (ALTER TABLE ADD COLUMN,
     * no rebuild, existing rows get NULL). Runs on /bootstrap and lazily on the first API call; afterwards a
     * _meta key saves the check. Not while a schema migration or restore is running (Maintenance) - the next request
     * catches up. Two requests at the same time: the second ALTER fails with "duplicate column", which is fine.
     *
     * @return string[] tables that got columns
     */
    public static function ensure(PDO $pdo, array $model): array
    {
        if (Database::getMeta($pdo, self::META_KEY) === '1' || Maintenance::active()) {
            return [];
        }
        $changed = [];
        foreach ($model['entities'] as $table => $entity) {
            if (!self::applies($entity) || !Database::tableExists($pdo, (string) $table)) {
                continue;
            }
            $have = array_column($pdo->query('PRAGMA table_info(' . SqlGenerator::q((string) $table) . ')')->fetchAll(), 'name');
            foreach (self::FIELDS as $name) {
                if (in_array($name, $have, true)) {
                    continue;
                }
                try {
                    $pdo->exec('ALTER TABLE ' . SqlGenerator::q((string) $table) . ' ADD COLUMN ' . SqlGenerator::q($name) . ' '
                        . self::SQL_TYPES[self::TYPES[$name]]);
                    $changed[$table] = true;
                } catch (\PDOException $e) {
                    if (stripos($e->getMessage(), 'duplicate column') === false) {
                        throw $e;
                    }
                }
            }
        }
        Database::setMeta($pdo, self::META_KEY, '1');
        return array_keys($changed);
    }

    /** New bootstrap: the tables were created with the columns, nothing to check */
    public static function markDone(PDO $pdo): void
    {
        Database::setMeta($pdo, self::META_KEY, '1');
    }
}
