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
    private const ROLE_ERROR = "role muss 'admin', 'redakteur' oder 'api' sein";

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ApiKeys::ensure($pdo); // users.api_read_only on installations from before the API users
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
            throw new ApiException(403, ['error' => 'forbidden', 'message' => 'Administratoren können nur von Administratoren angelegt oder geändert werden']);
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
            $errors['name'] = 'Pflichtfeld';
        }
        if ($username === null || $username === '') {
            $errors['username'] = 'Pflichtfeld';
        }
        if ($email === null || $email === '') {
            $errors['email'] = 'Pflichtfeld';
        }
        if ($role === null || !in_array($role, self::ROLES, true)) {
            $errors['role'] = self::ROLE_ERROR;
        }
        $isApi = $role === ApiKeys::ROLE;
        if (!$isApi) {
            self::validatePassword($password, $errors);
        } elseif ($password !== null && $password !== '') {
            $errors['password'] = 'API-Nutzer haben kein Passwort';
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
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
                throw new ApiException(409, ['error' => 'duplicate', 'message' => "Benutzername '$username' ist bereits vergeben"]);
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
                $errors['name'] = 'Pflichtfeld';
            } else {
                $values['name'] = $name;
            }
        }
        if (array_key_exists('email', $input)) {
            $email = self::str($input['email']);
            if ($email === null || $email === '') {
                $errors['email'] = 'Pflichtfeld';
            } else {
                $values['email'] = $email;
            }
        }
        if (array_key_exists('role', $input)) {
            $role = self::str($input['role']);
            if ($role === null || !in_array($role, self::ROLES, true)) {
                $errors['role'] = self::ROLE_ERROR;
            } elseif ($role !== $existing['role'] && ($role === ApiKeys::ROLE || $existing['role'] === ApiKeys::ROLE)) {
                $errors['role'] = 'Ein API-Nutzer kann kein Login-Nutzer werden und umgekehrt - bitte einen neuen Nutzer anlegen';
            } else {
                $values['role'] = $role;
            }
        }
        if (array_key_exists('api_read_only', $input)) {
            if ($actor !== null && $actor['role'] !== 'admin') {
                throw new ApiException(403, ['error' => 'forbidden', 'message' => 'Nur für Administratoren']);
            }
            if (!is_bool($input['api_read_only'])) {
                $errors['api_read_only'] = 'true oder false erwartet';
            } elseif ($existing['role'] !== ApiKeys::ROLE) {
                $errors['api_read_only'] = '„Nur lesen“ gibt es nur für API-Nutzer';
            } else {
                $values['api_read_only'] = $input['api_read_only'] ? 1 : 0;
            }
        }
        if (array_key_exists('active', $input)) {
            if (!is_bool($input['active'])) {
                $errors['active'] = 'true oder false erwartet';
            } else {
                $values['active'] = $input['active'] ? 1 : 0;
            }
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }

        // Protection 1: an admin must not deactivate themselves (otherwise the last admin could lock themselves out).
        $newActive = array_key_exists('active', $values) ? $values['active'] : (int) $existing['active'];
        if ($id === $currentUserId && $newActive === 0 && (int) $existing['active'] === 1) {
            throw new ApiException(422, [
                'error'   => 'self_deactivation',
                'message' => 'Der eigene Account kann nicht deaktiviert werden',
            ]);
        }

        // Protection 2: at least one active admin must always remain - applies both to active=false
        // and to a role change away from admin (otherwise protection 1 could be bypassed via the role).
        $newRole = $values['role'] ?? $existing['role'];
        $wasActiveAdmin = $existing['role'] === 'admin' && (int) $existing['active'] === 1;
        $staysActiveAdmin = $newRole === 'admin' && $newActive === 1;
        if ($wasActiveAdmin && !$staysActiveAdmin && $this->activeAdminCount($id) === 0) {
            throw new ApiException(422, [
                'error'   => 'last_admin',
                'message' => 'Es muss mindestens ein aktiver Administrator bestehen bleiben',
            ]);
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
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Nutzer nicht gefunden']);
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

    private static function validatePassword($password, array &$errors): void
    {
        if (!is_string($password) || $password === '') {
            $errors['password'] = 'Pflichtfeld';
        } elseif (($ruleError = Auth::passwordError($password)) !== null) {
            $errors['password'] = $ruleError; // same rule as PUT /api/me/password
        }
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
        return (string) $ex->getCode() === '23000';
    }
}
