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
use PDOException;

/**
 * User management at system level (independent of the PlantUML content schema).
 * All routes using this class are already protected by Auth::requireAdmin() (see Http::adminApi()).
 */
final class Users
{
    public const ROLES = ['admin', 'redakteur', ApiKeys::ROLE];

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ApiKeys::ensure($pdo); // users.api_read_only on installations from before the API users
    }

    /**
     * Privacy default for every response outside the user management: a user is shown as {id, name} only - the display
     * name, never the username (it is the login) or other account data. An empty name stays '' (the UI shows the neutral
     * "Nutzer #<id>", see userName() in the frontend); an id that no longer exists gets name ''.
     * Used by record metadata, versions, media, follow-ups and tasks.
     *
     * @param array $ids user ids (null/duplicates allowed)
     * @return array<int,array{id:int,name:string}>
     */
    public static function refs(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, function ($v) {
            return $v !== null;
        }))));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['id' => $id, 'name' => ''];
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($pdo->query('SELECT id, name FROM users WHERE id IN (' . implode(', ', $chunk) . ')')->fetchAll() as $u) {
                $out[(int) $u['id']]['name'] = (string) $u['name'];
            }
        }
        return $out;
    }

    /** One user as {id, name} (see refs()); null for null */
    public static function ref(PDO $pdo, $id): ?array
    {
        return $id === null ? null : self::refs($pdo, [$id])[(int) $id];
    }

    /** @return array[] all users, without password_hash */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name, username, email, role, active, must_change_password, api_read_only, created_at
               FROM users ORDER BY id'
        )->fetchAll();
        return array_map([self::class, 'cast'], $rows);
    }

    public function find(int $id): array
    {
        return self::cast($this->fetchRaw($id));
    }

    /**
     * User management via the permission on the system area "users" (not an admin): such users may neither
     * create or change administrators nor make anyone an administrator - otherwise the permission would be a path to
     * full access.
     */
    private static function forbidAdminChange(array $actor, ?string $existingRole, ?string $newRole): void
    {
        if ($actor['role'] !== 'admin' && ($existingRole === 'admin' || $newRole === 'admin')) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_users.admin_only_by_admin',
                'Administrators can only be created or changed by administrators'));
        }
    }

    /**
     * API users (role = 'api') have no password - not on creation and never afterwards. Instead they get their first
     * API key right away; the response then additionally contains 'api_key' (plain text, the only time it is shown).
     *
     * @param array $input {"name","username","email","role","password"}; for role 'api' without "password"
     * @param array|null $actor logged-in user (see forbidAdminChange()); null = admin
     */
    public function create(array $input, ?array $actor = null): array
    {
        if ($actor !== null) {
            self::forbidAdminChange($actor, null, self::str($input['role'] ?? null));
        }
        $errors = [];
        $name = self::str($input['name'] ?? null);
        $username = self::str($input['username'] ?? null);
        $email = self::str($input['email'] ?? null);
        $role = self::str($input['role'] ?? null);
        $password = $input['password'] ?? null;

        if ($name === null || $name === '') {
            $errors['name'] = Msg::of('msg_api.required', 'Required field');
        }
        if ($username === null || $username === '') {
            $errors['username'] = Msg::of('msg_api.required', 'Required field');
        }
        if ($email === null || $email === '') {
            $errors['email'] = Msg::of('msg_api.required', 'Required field');
        }
        if ($role === null || !in_array($role, self::ROLES, true)) {
            $errors['role'] = self::roleError();
        }
        $isApi = $role === ApiKeys::ROLE;
        if (!$isApi) {
            $this->validatePassword($password, $errors);
        } elseif ($password !== null && $password !== '') {
            $errors['password'] = Msg::of('msg_users.api_no_password', 'API users have no password');
        }
        if ($errors) {
            throw ApiException::invalid($errors);
        }

        try {
            // must_change_password is always 1 on creation: the admin passes on the generated password,
            // the new user has to replace it with one of their own on the first login. API users: no password - the
            // empty hash matches nothing, and the login refuses the role anyway.
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (name, username, email, role, password_hash, must_change_password, active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$name, $username, $email, $role, $isApi ? '' : password_hash($password, PASSWORD_DEFAULT), $isApi ? 0 : 1]);
        } catch (PDOException $ex) {
            if (self::isUniqueViolation($ex)) {
                throw ApiException::of(409, 'duplicate', Msg::of('msg_users.username_taken', "Username '{username}' is already taken",
                    ['username' => $username]));
            }
            throw $ex;
        }
        $id = (int) $this->pdo->lastInsertId();
        if ($isApi) {
            $key = ApiKeys::generate($this->pdo, $id, $actor !== null ? (int) $actor['id'] : null);
            return $this->find($id) + ['api_key' => $key['key']];
        }
        return $this->find($id);
    }

    /**
     * @param array $input only the fields of "name","email","role","active","api_read_only" contained in the body are
     *                     changed; no password reset via this route (see PUT /api/me/password). The role cannot be
     *                     changed to or away from 'api' (an API user has no password, a login user no key);
     *                     "api_read_only" only exists for API users and is reserved for admins.
     * @param int $currentUserId ID of the calling (logged-in) admin - for the self-protection below
     * @param array|null $actor logged-in user (see forbidAdminChange()); null = admin
     */
    public function update(int $id, array $input, int $currentUserId, ?array $actor = null): array
    {
        $existing = $this->fetchRaw($id); // throws 404
        if ($actor !== null) {
            self::forbidAdminChange($actor, (string) $existing['role'], self::str($input['role'] ?? null));
        }
        $errors = [];
        $values = [];

        if (array_key_exists('name', $input)) {
            $name = self::str($input['name']);
            if ($name === null || $name === '') {
                $errors['name'] = Msg::of('msg_api.required', 'Required field');
            } else {
                $values['name'] = $name;
            }
        }
        if (array_key_exists('email', $input)) {
            $email = self::str($input['email']);
            if ($email === null || $email === '') {
                $errors['email'] = Msg::of('msg_api.required', 'Required field');
            } else {
                $values['email'] = $email;
            }
        }
        if (array_key_exists('role', $input)) {
            $role = self::str($input['role']);
            if ($role === null || !in_array($role, self::ROLES, true)) {
                $errors['role'] = self::roleError();
            } elseif ($role !== $existing['role'] && ($role === ApiKeys::ROLE || $existing['role'] === ApiKeys::ROLE)) {
                $errors['role'] = Msg::of('msg_users.api_role_switch',
                    'An API user cannot become a login user and vice versa - please create a new user');
            } else {
                $values['role'] = $role;
            }
        }
        if (array_key_exists('api_read_only', $input)) {
            if ($actor !== null && $actor['role'] !== 'admin') {
                throw ApiException::of(403, 'forbidden', Msg::of('msg_users.admins_only', 'Administrators only'));
            }
            if (!is_bool($input['api_read_only'])) {
                $errors['api_read_only'] = Msg::of('msg_api.bool_expected', 'true or false expected');
            } elseif ($existing['role'] !== ApiKeys::ROLE) {
                $errors['api_read_only'] = Msg::of('msg_users.read_only_api', '“Read only” exists only for API users');
            } else {
                $values['api_read_only'] = $input['api_read_only'] ? 1 : 0;
            }
        }
        if (array_key_exists('active', $input)) {
            if (!is_bool($input['active'])) {
                $errors['active'] = Msg::of('msg_api.bool_expected', 'true or false expected');
            } else {
                $values['active'] = $input['active'] ? 1 : 0;
            }
        }
        if ($errors) {
            throw ApiException::invalid($errors);
        }

        // Protection 1: an admin must not deactivate themselves (otherwise the last admin could lock themselves out).
        $newActive = array_key_exists('active', $values) ? $values['active'] : (int) $existing['active'];
        if ($id === $currentUserId && $newActive === 0 && (int) $existing['active'] === 1) {
            throw ApiException::of(422, 'self_deactivation', Msg::of('msg_users.self_deactivation',
                'You cannot deactivate your own account'));
        }

        // Protection 2: at least one active admin must always remain - applies both to active=false
        // and to a role change away from admin (otherwise protection 1 could be bypassed via the role).
        $newRole = $values['role'] ?? $existing['role'];
        $wasActiveAdmin = $existing['role'] === 'admin' && (int) $existing['active'] === 1;
        $staysActiveAdmin = $newRole === 'admin' && $newActive === 1;
        if ($wasActiveAdmin && !$staysActiveAdmin && $this->activeAdminCount($id) === 0) {
            throw ApiException::of(422, 'last_admin', Msg::of('msg_users.last_admin', 'At least one active administrator must remain'));
        }

        if ($values) {
            $set = implode(', ', array_map(function ($c) {
                return SqlGenerator::q($c) . ' = ?';
            }, array_keys($values)));
            $stmt = $this->pdo->prepare('UPDATE users SET ' . $set . ' WHERE id = ?');
            $stmt->execute(array_merge(array_values($values), [$id]));
        }
        return $this->find($id);
    }

    // ---------------------------------------------------------------- Internal

    private function fetchRaw(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, username, email, role, active, must_change_password, api_read_only, created_at
               FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_users.not_found', 'User not found'));
        }
        return $row;
    }

    /** Number of other active admins except $excludeId. */
    private function activeAdminCount(int $excludeId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id != ?");
        $stmt->execute([$excludeId]);
        return (int) $stmt->fetchColumn();
    }

    private function validatePassword($password, array &$errors): void
    {
        if (!is_string($password) || $password === '') {
            $errors['password'] = Msg::of('msg_api.required', 'Required field');
        } elseif (($ruleError = Auth::passwordError($this->pdo, $password)) !== null) {
            $errors['password'] = $ruleError; // same rule as PUT /api/me/password
        }
    }

    private static function roleError(): Msg
    {
        return Msg::of('msg_users.role_invalid', "role must be 'admin', 'redakteur' or 'api'");
    }

    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['active'] = (bool) (int) $row['active'];
        $row['must_change_password'] = (bool) (int) $row['must_change_password'];
        $row['api_read_only'] = (bool) (int) $row['api_read_only'];
        return $row;
    }

    /** @param mixed $value */
    private static function str($value): ?string
    {
        return is_scalar($value) ? trim((string) $value) : null;
    }

    private static function isUniqueViolation(PDOException $ex): bool
    {
        return Database::isConstraintViolation($ex);
    }
}
