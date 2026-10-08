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
 * API keys of the users with role = 'api' (programmatic access, "Authorization: Bearer <key>").
 *
 * An API user has no password and cannot log in; the key is the only way in. Only the SHA-256 hash of a key is stored -
 * the plain text exists solely in the response of generate(), i.e. at the moment it is shown once. The keys are random
 * (192 bits), so a plain fast hash is sufficient and allows the lookup by hash. The key is never written to a log or an
 * error message: nothing in this class passes it on, and it travels in a header, not in the URL.
 *
 * At most one key per user is active: generate() revokes the previous one. Table and column are created on demand
 * (ensure()), so an existing installation needs no new /bootstrap.
 */
final class ApiKeys
{
    public const ROLE = 'api';
    public const PREFIX = 'lh_';
    /** Random bytes of a key (hex encoded in the key: twice as many characters). */
    private const KEY_BYTES = 24;
    /** last_used_at is only rewritten when it is older than this (seconds) - not one write per request. */
    private const LAST_USED_INTERVAL = 300;

    private const DDL = 'CREATE TABLE IF NOT EXISTS api_keys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  key_hash VARCHAR NOT NULL UNIQUE,
  key_preview VARCHAR NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INTEGER,
  last_used_at DATETIME,
  revoked_at DATETIME
)';

    /** @var bool ensure() already ran in this request */
    private static $ensured = false;
    /** @var array{0:string,1:array}|null token and user of the last successful authenticate() in this request */
    private static $resolved = null;

    /** Creates api_keys and users.api_read_only if they are missing. Idempotent; returns true if the table was created. */
    public static function ensure(PDO $pdo): bool
    {
        if (self::$ensured) {
            return false;
        }
        $created = !Database::tableExists($pdo, 'api_keys');
        if ($created) {
            $pdo->exec(self::DDL);
        }
        $hasColumn = false;
        foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $col) {
            $hasColumn = $hasColumn || $col['name'] === 'api_read_only';
        }
        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE users ADD COLUMN api_read_only BOOLEAN NOT NULL DEFAULT 0');
        }
        self::$ensured = true;
        return $created;
    }

    // ---------------------------------------------------------------- Authentication

    /**
     * Key from "Authorization: Bearer <key>": null if the request carries no Bearer header at all, otherwise the
     * (possibly empty) text after "Bearer". Some hosters do not pass the header to PHP as HTTP_AUTHORIZATION; .htaccess
     * copies it into the environment (then also as REDIRECT_HTTP_AUTHORIZATION), apache_request_headers() is the last resort.
     */
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || $header === '') {
            $header = null;
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        $header = (string) $value;
                    }
                }
            }
        }
        if (!is_string($header) || !preg_match('/^Bearer(?:\s+(.*))?$/i', trim($header), $m)) {
            return null;
        }
        return trim($m[1] ?? '');
    }

    /**
     * User of a key, in the form of Auth::currentUser() plus 'auth' => 'api_key' and 'api_read_only'. Unknown, revoked or
     * malformed key, deactivated user, user that is not an API user -> 401 (always the same answer).
     */
    public static function authenticate(PDO $pdo, string $token): array
    {
        if (self::$resolved !== null && hash_equals(self::$resolved[0], $token)) {
            return self::$resolved[1];
        }
        $invalid = new ApiException(401, ['error' => 'invalid_api_key', 'message' => 'API-Schlüssel ungültig oder widerrufen']);
        if (!preg_match('/^' . self::PREFIX . '[0-9a-f]{32,128}$/', $token)
            || !Database::tableExists($pdo, 'users') || !Database::tableExists($pdo, 'api_keys')) {
            throw $invalid;
        }
        self::ensure($pdo);
        $stmt = $pdo->prepare(
            "SELECT k.id AS key_id, u.id, u.username, u.name, u.role, u.active, u.api_read_only,
                    k.last_used_at IS NULL OR k.last_used_at < datetime('now', '-" . self::LAST_USED_INTERVAL . " seconds') AS stale
               FROM api_keys k JOIN users u ON u.id = k.user_id
              WHERE k.key_hash = ? AND k.revoked_at IS NULL"
        );
        $stmt->execute([self::hash($token)]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['active'] !== 1 || $row['role'] !== self::ROLE) {
            throw $invalid;
        }
        if ((int) $row['stale'] === 1) {
            $pdo->prepare("UPDATE api_keys SET last_used_at = datetime('now') WHERE id = ?")->execute([$row['key_id']]);
        }
        $user = [
            'id'                   => (int) $row['id'],
            'username'             => (string) $row['username'],
            'name'                 => (string) $row['name'],
            'role'                 => (string) $row['role'],
            'must_change_password' => false,
            'auth'                 => 'api_key',
            'api_read_only'        => (bool) (int) $row['api_read_only'],
        ];
        self::$resolved = [$token, $user];
        return $user;
    }

    // ---------------------------------------------------------------- Management (admins only, see index.php)

    /**
     * New key for the API user; the previous one stops working at once. The only place a key exists in plain text.
     *
     * @return array info() plus 'key' (plain text, shown once)
     */
    public static function generate(PDO $pdo, int $userId, ?int $createdBy): array
    {
        self::requireApiUser($pdo, $userId);
        $key = self::PREFIX . bin2hex(random_bytes(self::KEY_BYTES));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE api_keys SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL")->execute([$userId]);
            $pdo->prepare('INSERT INTO api_keys (user_id, key_hash, key_preview, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$userId, self::hash($key), substr($key, -6), $createdBy]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::info($pdo, $userId) + ['key' => $key];
    }

    /** Revokes the active key without creating a new one: no access until generate() is called again. */
    public static function revoke(PDO $pdo, int $userId): array
    {
        self::requireApiUser($pdo, $userId);
        $pdo->prepare("UPDATE api_keys SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL")->execute([$userId]);
        return self::info($pdo, $userId);
    }

    /**
     * State of the user's most recent key: status 'active' | 'revoked' | 'none', preview (last 6 characters), created_at,
     * last_used_at, revoked_at, plus the user's read_only switch. Never contains the key or its hash.
     */
    public static function info(PDO $pdo, int $userId): array
    {
        $user = self::requireApiUser($pdo, $userId);
        $stmt = $pdo->prepare(
            'SELECT key_preview, created_at, last_used_at, revoked_at FROM api_keys WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return [
            'user_id'      => $userId,
            'status'       => $row === false ? 'none' : ($row['revoked_at'] === null ? 'active' : 'revoked'),
            'preview'      => $row === false ? null : (string) $row['key_preview'],
            'created_at'   => $row === false ? null : $row['created_at'],
            'last_used_at' => $row === false ? null : $row['last_used_at'],
            'revoked_at'   => $row === false ? null : $row['revoked_at'],
            'read_only'    => (bool) (int) $user['api_read_only'],
        ];
    }

    /** 404 for an unknown user, 422 for a user that is not an API user. */
    private static function requireApiUser(PDO $pdo, int $userId): array
    {
        self::ensure($pdo);
        $stmt = $pdo->prepare('SELECT id, role, api_read_only FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Nutzer nicht gefunden']);
        }
        if ($user['role'] !== self::ROLE) {
            throw new ApiException(422, ['error' => 'not_api_user', 'message' => 'API-Schlüssel gibt es nur für API-Nutzer']);
        }
        return $user;
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
