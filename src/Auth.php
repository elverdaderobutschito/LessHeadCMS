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
 * Users, login and PHP sessions.
 *
 * Sessions deliberately live in data/sessions/ (blocked via data/.htaccess) instead of the hoster's global
 * session.save_path: on shared hosting that one is often not writable or shared with other
 * customers. The session is only started if a session cookie comes along or someone logs in
 * - public GET requests create neither files nor cookies.
 */
final class Auth
{
    public const COOKIE = 'lhcms_session';
    /** Validity of a session in seconds (from login, independent of activity). */
    public const LIFETIME = 28800;
    public const DEFAULT_ADMIN = 'admin';
    public const DEFAULT_PASSWORD = 'password';
    public const MIN_PASSWORD_LENGTH = 8;
    /** bcrypt (PASSWORD_DEFAULT) silently truncates longer passwords. */
    public const MAX_PASSWORD_BYTES = 72;
    /**
     * Complexity rule for every newly set password (creating a user, PUT /api/me/password), see passwordError().
     * Not retroactive: login only checks the hash, older simpler passwords keep working.
     */
    public const PASSWORD_RULE = 'Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH
        . ' Zeichen, eine Ziffer und ein Sonderzeichen (z. B. ! ? # - _) enthalten.';

    /** Hash of a password that is never assigned: evens out the response time for an unknown user name. */
    private const DUMMY_HASH = '$2y$10$zVX75/zigYYjupG4EDSDXuw7idv9LFjo1/Cojol17x0lXI2XQJ.6O';

    /** Rate limit: from this many failed attempts within the time window on, the next login attempt is rejected with 429. */
    public const RATE_LIMIT_MAX = 5;
    public const RATE_LIMIT_WINDOW = 900; // 15 minutes
    /** Entries in login_attempts older than this are deleted on every login call. */
    private const ATTEMPTS_RETENTION = '-1 day';

    private const ATTEMPTS_DDL = 'CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR NOT NULL,
  ip VARCHAR NOT NULL,
  attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)';

    private const USERS_DDL = 'CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR NOT NULL UNIQUE,
  password_hash VARCHAR NOT NULL,
  must_change_password BOOLEAN NOT NULL DEFAULT 1,
  name VARCHAR NOT NULL DEFAULT \'\',
  email VARCHAR NOT NULL DEFAULT \'\',
  role VARCHAR NOT NULL DEFAULT \'redakteur\',
  active BOOLEAN NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)';

    /** Columns that are added via ALTER TABLE on existing installations (see migrateUsersColumns()). */
    private const USERS_MIGRATION_COLUMNS = [
        'name'   => "ALTER TABLE users ADD COLUMN name VARCHAR NOT NULL DEFAULT ''",
        'email'  => "ALTER TABLE users ADD COLUMN email VARCHAR NOT NULL DEFAULT ''",
        'role'   => "ALTER TABLE users ADD COLUMN role VARCHAR NOT NULL DEFAULT 'redakteur'",
        'active' => 'ALTER TABLE users ADD COLUMN active BOOLEAN NOT NULL DEFAULT 1',
    ];

    // ------------------------------------------------------------ Bootstrap

    /**
     * Creates users (if needed) and - if the table is empty - the admin admin/password with
     * a mandatory password change. Idempotent.
     *
     * @return array<string,mixed> additional fields for the /bootstrap response
     */
    public static function provision(PDO $pdo): array
    {
        $tableCreated = !Database::tableExists($pdo, 'users');
        $pdo->exec(self::USERS_DDL);
        self::migrateUsersColumns($pdo); // existing installations: add missing columns
        $attemptsCreated = !Database::tableExists($pdo, 'login_attempts');
        $pdo->exec(self::ATTEMPTS_DDL);
        // column selection per user; otherwise ColumnPrefs creates it itself on the first save
        $prefsCreated = ColumnPrefs::ensureTable($pdo);
        // media library (also needed for the link tables of the media fields); otherwise Media creates it itself on the first call
        $mediaCreated = (bool) Media::ensureTables($pdo);
        // API keys (role = 'api'); otherwise ApiKeys creates table and column itself on the first use
        $apiKeysCreated = ApiKeys::ensure($pdo);

        $adminCreated = false;
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, must_change_password, name, role, active)
                 VALUES (?, ?, 1, ?, ?, 1)'
            );
            $stmt->execute([self::DEFAULT_ADMIN, password_hash(self::DEFAULT_PASSWORD, PASSWORD_DEFAULT), 'Administrator', 'admin']);
            $adminCreated = true;
        }

        $result = [
            'users_table_created'          => $tableCreated,
            'login_attempts_table_created' => $attemptsCreated,
            'user_column_prefs_table_created' => $prefsCreated,
            'media_tables_created'         => $mediaCreated,
            'api_keys_table_created'       => $apiKeysCreated,
            'admin_created'                => $adminCreated,
        ];
        if ($adminCreated) {
            $result['admin_hint'] = "Benutzer '" . self::DEFAULT_ADMIN . "' angelegt; Startpasswort siehe Dokumentation, "
                . 'Änderung beim ersten Login erzwungen. Bitte sofort anmelden und das Passwort ändern.';
        }
        $result['session_check'] = self::sessionCheck();
        $result['rate_limit_check'] = self::clientCheck();
        return $result;
    }

    /**
     * Adds name/email/role/active to a users table from before the user management existed.
     * Idempotent: checks per column via PRAGMA table_info whether it already exists before ALTER TABLE runs
     * (SQLite has no "ADD COLUMN IF NOT EXISTS"). Existing rows (currently only the admin) get
     * the DEFAULT values and therefore do not break.
     */
    private static function migrateUsersColumns(PDO $pdo): void
    {
        $existing = [];
        foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $col) {
            $existing[$col['name']] = true;
        }
        $roleColumnAdded = !isset($existing['role']);
        foreach (self::USERS_MIGRATION_COLUMNS as $column => $ddl) {
            if (!isset($existing[$column])) {
                $pdo->exec($ddl);
            }
        }
        if ($roleColumnAdded) {
            // Before this version there was no user management, every existing row was effectively an administrator.
            // The DEFAULT 'redakteur' of the new column would lock all existing users out of /api/users after the upgrade
            // (Auth::requireAdmin) - so set it explicitly to 'admin' here instead of the default.
            $pdo->exec("UPDATE users SET role = 'admin'");
        }
    }

    /**
     * Diagnostics for /bootstrap: which client IP does PHP see? The rate limit counts per REMOTE_ADDR. With a proxy/load
     * balancer in front, all visitors share the same (usually private) address - then 5 failed attempts of any visitor lock out everyone.
     */
    public static function clientCheck(): array
    {
        $ip = self::clientIp();
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        return [
            'remote_addr'           => $ip,
            'remote_addr_is_public' => $public,
            'x_forwarded_for'       => is_string($forwarded) ? $forwarded : null,
            'hint'                  => $public && $forwarded === null
                ? null
                : 'REMOTE_ADDR ist keine öffentliche Adresse bzw. X-Forwarded-For ist gesetzt: vermutlich sitzt ein Proxy davor. '
                  . 'Dann teilen sich alle Besucher eine IP im Rate-Limit. Prüfen, ob remote_addr Ihrer echten IP entspricht.',
        ];
    }

    /** Diagnostics for /bootstrap: can PHP create sessions here? */
    public static function sessionCheck(): array
    {
        $dir = Config::sessionDir();
        $writable = false;
        if (self::ensureSessionDir()) {
            $probe = $dir . '/probe_' . bin2hex(random_bytes(4));
            $writable = @file_put_contents($probe, 'x') !== false;
            @unlink($probe);
        }
        return [
            'extension_loaded' => extension_loaded('session'),
            'save_path'        => 'data/sessions',
            'writable'         => $writable,
            'save_handler_ini' => (string) ini_get('session.save_handler'),
            'auto_start_ini'   => (string) ini_get('session.auto_start'),
            'https_detected'   => self::isHttps(),
            'ok'               => extension_loaded('session') && $writable,
        ];
    }

    // ------------------------------------------------------------ Endpoints

    /** @return array{0:int,1:array} */
    public static function login(array $input): array
    {
        $pdo = self::usersPdo();
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;

        // Rate limit BEFORE any password check: once exceeded, nothing is compared at all.
        $pdo->exec(self::ATTEMPTS_DDL); // idempotent; in case /bootstrap has not run for this table yet
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '" . self::ATTEMPTS_RETENTION . "')");
        $name = is_string($username) ? substr($username, 0, 255) : '';
        $ip = self::clientIp();
        $wait = max(self::retryAfter($pdo, 'username', $name), self::retryAfter($pdo, 'ip', $ip));
        if ($wait > 0) {
            throw new ApiException(
                429,
                [
                    'error'               => 'rate_limited',
                    'retry_after_seconds' => $wait,
                    'message'             => 'Zu viele Fehlversuche, bitte später erneut versuchen',
                ],
                ['Retry-After' => (string) $wait]
            );
        }

        $user = null;
        if (is_string($username) && $username !== '') {
            $stmt = $pdo->prepare('SELECT id, username, password_hash, must_change_password, active, role FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch() ?: null;
        }
        // API users have no password and never get a session: refused whatever was entered, without comparing anything.
        if ($user !== null && $user['role'] === ApiKeys::ROLE) {
            throw new ApiException(403, [
                'error'   => 'api_user_no_login',
                'message' => 'API-Nutzer können sich nicht anmelden – der Zugriff ist nur mit dem API-Schlüssel möglich',
            ]);
        }
        // Deactivated users fail the same way as with a wrong password - this does not reveal that the account exists.
        $valid = is_string($password)
            && password_verify($password, $user ? (string) $user['password_hash'] : self::DUMMY_HASH)
            && $user !== null
            && (int) $user['active'] === 1;
        if (!$valid) {
            $pdo->prepare('INSERT INTO login_attempts (username, ip) VALUES (?, ?)')->execute([$name, $ip]);
            throw new ApiException(401, ['error' => 'invalid_credentials', 'message' => 'Benutzername oder Passwort falsch']);
        }

        // success: reset the failed attempts of this user name
        $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$name]);

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        if (!self::ensureSessionDir()) {
            error_log('[LessHeadCMS] data/sessions ist nicht beschreibbar - Login nicht möglich.');
            throw new ApiException(500, ['error' => 'session_unavailable', 'message' => 'Session-Verzeichnis nicht beschreibbar']);
        }
        self::gcSessions();
        self::start(false);
        session_regenerate_id(true); // against session fixation
        $_SESSION = ['user_id' => (int) $user['id']];
        session_write_close();

        return [200, ['status' => 'ok', 'must_change_password' => (bool) (int) $user['must_change_password']]];
    }

    /** @return array{0:int,1:array} */
    public static function logout(): array
    {
        if (self::sessionIdFromCookie() !== null && self::ensureSessionDir()) {
            self::start(false);
            $params = session_get_cookie_params();
            $_SESSION = [];
            session_destroy();
            setcookie(self::COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'secure'   => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return [200, ['status' => 'ok']];
    }

    /** @return array{0:int,1:array} */
    public static function me(): array
    {
        $pdo = Database::connectIfExists();
        $user = $pdo && Database::tableExists($pdo, 'users') ? self::currentUser($pdo) : null;
        if ($user === null) {
            return [200, ['logged_in' => false]];
        }
        return [200, [
            'logged_in'            => true,
            'username'             => $user['username'],
            'name'                 => $user['name'],
            'role'                 => $user['role'],
            'must_change_password' => $user['must_change_password'],
        ]];
    }

    /**
     * Checks a password that is about to be set against the complexity rule. null = fine, otherwise a concrete message:
     * the rule plus what is still missing. Special character = any character that is neither a letter (including umlauts)
     * nor a digit nor whitespace - i.e. all ASCII punctuation/special characters, but also e.g. € or §. Upper/lower case is
     * deliberately not required (see README, Login and sessions).
     *
     * @param string $password
     */
    public static function passwordError(string $password): ?string
    {
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return 'Das Passwort darf höchstens ' . self::MAX_PASSWORD_BYTES . ' Bytes lang sein';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        $missing = [];
        if ($length < self::MIN_PASSWORD_LENGTH) {
            $missing[] = 'mindestens ' . self::MIN_PASSWORD_LENGTH . " Zeichen (bisher $length)";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $missing[] = 'eine Ziffer';
        }
        if (preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1) {
            $missing[] = 'ein Sonderzeichen';
        }
        return $missing ? self::PASSWORD_RULE . ' Es fehlt: ' . implode(', ', $missing) . '.' : null;
    }

    /** Message for a wrong or missing current_password (deliberately the same: does not reveal what exactly was missing) */
    public const CURRENT_PASSWORD_WRONG = 'Aktuelles Passwort ist falsch.';

    /**
     * PUT /api/me/password - always only for the logged-in user (ID from the session).
     *
     * Two cases, distinguished by the user's stored must_change_password (not by a client flag):
     *  - forced first change (must_change_password = 1): only "password"; the current one is the initial password the
     *    admin has just assigned, asking for it would gain nothing.
     *  - voluntary change (must_change_password = 0): additionally "current_password", checked via password_verify().
     *    Wrong or missing -> 422 on the field current_password. Protects a session someone left open from having the
     *    password taken over and the owner locked out.
     *
     * Rate limit for current_password: the same lock as for the login (login_attempts, RATE_LIMIT_MAX failed attempts per
     * user name or IP within the time window, then 429). The session binding alone is not enough - precisely the attacker
     * with someone else's session could otherwise guess as often as they like. The counters are shared with the login:
     * failed attempts here also lock the login of this account (just like failed login attempts), a successful check
     * resets the counter of the user name. An empty field is not a guess and does not count.
     *
     * @return array{0:int,1:array}
     */
    public static function changePassword(array $input): array
    {
        $pdo = self::usersPdo();
        $user = self::currentUser($pdo);
        if ($user === null) {
            throw new ApiException(401, ['error' => 'unauthorized', 'message' => 'Anmeldung erforderlich']);
        }
        if ($user['role'] === ApiKeys::ROLE) {
            throw new ApiException(403, ['error' => 'forbidden', 'message' => 'API-Nutzer haben kein Passwort']);
        }

        $errors = [];
        if (!$user['must_change_password']) {
            $current = $input['current_password'] ?? null;
            $pdo->exec(self::ATTEMPTS_DDL); // idempotent, as for the login
            $name = substr($user['username'], 0, 255);
            $ip = self::clientIp();
            $wait = max(self::retryAfter($pdo, 'username', $name), self::retryAfter($pdo, 'ip', $ip));
            if ($wait > 0) {
                $message = 'Zu viele Fehlversuche, bitte später erneut versuchen';
                throw new ApiException(
                    429,
                    [
                        'error'               => 'rate_limited',
                        'retry_after_seconds' => $wait,
                        'message'             => $message,
                        'errors'              => ['current_password' => $message],
                    ],
                    ['Retry-After' => (string) $wait]
                );
            }
            if (!is_string($current) || $current === '') {
                $errors['current_password'] = self::CURRENT_PASSWORD_WRONG;
            } else {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$user['id']]);
                if (password_verify($current, (string) $stmt->fetchColumn())) {
                    $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$name]);
                } else {
                    $pdo->prepare('INSERT INTO login_attempts (username, ip) VALUES (?, ?)')->execute([$name, $ip]);
                    $errors['current_password'] = self::CURRENT_PASSWORD_WRONG;
                }
            }
        }

        $password = $input['password'] ?? null;
        if (!is_string($password)) {
            $errors['password'] = 'Passwort fehlt';
        } elseif (($ruleError = self::passwordError($password)) !== null) {
            $errors['password'] = $ruleError;
        } elseif ($password === self::DEFAULT_PASSWORD || $password === $user['username']) {
            $errors['password'] = 'Dieses Passwort ist nicht erlaubt - bitte ein anderes wählen';
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => reset($errors), 'errors' => $errors]);
        }

        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

        return [200, ['status' => 'ok', 'must_change_password' => false]];
    }

    // ------------------------------------------------------------ Session check (middleware)

    /**
     * Authenticated user of the request or null: from the API key if the request carries "Authorization: Bearer"
     * (ApiKeys::authenticate(); an invalid key is a 401, never a fallback to the session or to anonymous access),
     * otherwise from the session (read access, does not lock the session).
     * A user who has been deleted or deactivated in the meantime counts as logged out.
     *
     * Both ways return the same form, so everything behind it (Permissions::access(), Users, ...) does not know how
     * the user authenticated. With an API key there are additionally 'auth' => 'api_key' and 'api_read_only'.
     *
     * @return array{id:int,username:string,name:string,role:string,must_change_password:bool}|null
     */
    public static function currentUser(PDO $pdo): ?array
    {
        $token = ApiKeys::bearerToken();
        if ($token !== null) {
            return ApiKeys::authenticate($pdo, $token);
        }
        if (self::sessionIdFromCookie() === null || !self::ensureSessionDir()) {
            return null;
        }
        if (!Database::tableExists($pdo, 'users')) {
            return null;
        }
        self::start(true);
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, username, name, role, active, must_change_password FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        // an API user never has a session (login is refused); should one exist anyway, it does not count
        if ($row === false || (int) $row['active'] !== 1 || $row['role'] === ApiKeys::ROLE) {
            return null;
        }
        return [
            'id'                   => (int) $row['id'],
            'username'             => (string) $row['username'],
            'name'                 => (string) $row['name'],
            'role'                 => (string) $row['role'],
            'must_change_password' => (bool) (int) $row['must_change_password'],
        ];
    }

    /**
     * Protection for writing routes and for drafts: 401 without a session or API key, 403 as long as the initial password
     * still applies. (So the forced password change is enforced on the server side as well, not just in the frontend.)
     *
     * API users with the switch "Nur lesen" (users.api_read_only): everything except GET/HEAD is refused with 403 here,
     * before any permission is looked at - whatever roles and groups grant. Every route that needs an authenticated user
     * goes through this method, so the lock holds for all of them.
     */
    public static function requireSession(PDO $pdo): array
    {
        $user = self::currentUser($pdo);
        if ($user === null) {
            throw new ApiException(401, ['error' => 'unauthorized', 'message' => 'Anmeldung erforderlich']);
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!empty($user['api_read_only']) && $method !== 'GET' && $method !== 'HEAD') {
            throw new ApiException(403, [
                'error'   => 'read_only',
                'message' => 'Dieser API-Schlüssel darf nur lesen',
            ]);
        }
        if ($user['must_change_password']) {
            throw new ApiException(403, [
                'error'   => 'password_change_required',
                'message' => 'Bitte zuerst das Passwort ändern',
            ]);
        }
        return $user;
    }

    /** Protection for /api/users: like requireSession(), additionally role = 'admin' (otherwise 403). */
    public static function requireAdmin(PDO $pdo): array
    {
        $user = self::requireSession($pdo);
        if ($user['role'] !== 'admin') {
            throw new ApiException(403, ['error' => 'forbidden', 'message' => 'Nur für Administratoren']);
        }
        return $user;
    }

    /** May the caller see drafts (visibility = false)? Only with a session or API key AND a changed initial password. */
    public static function canSeeDrafts(PDO $pdo): bool
    {
        $user = self::currentUser($pdo);
        return $user !== null && !$user['must_change_password'];
    }

    // ------------------------------------------------------------ Internal

    private static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? substr($ip, 0, 64) : 'unknown';
    }

    /**
     * Seconds until the lock for this counter (column username OR ip) ends; 0 = not locked.
     * Locked is whoever has at least RATE_LIMIT_MAX failed attempts within the time window. The lock ends as soon as
     * enough old attempts have expired that fewer than RATE_LIMIT_MAX count again (with exactly MAX attempts, that is
     * when the oldest one expires).
     *
     * @param string $column only the constants 'username' or 'ip' (is inserted into SQL)
     */
    private static function retryAfter(PDO $pdo, string $column, string $value): int
    {
        if ($column !== 'username' && $column !== 'ip') {
            throw new \InvalidArgumentException('Ungültige Spalte');
        }
        $stmt = $pdo->prepare(
            "SELECT CAST(strftime('%s', attempted_at) AS INTEGER) AS ts, CAST(strftime('%s', 'now') AS INTEGER) AS now
               FROM login_attempts
              WHERE $column = ? AND attempted_at > datetime('now', '-" . (int) self::RATE_LIMIT_WINDOW . " seconds')
              ORDER BY attempted_at ASC, id ASC"
        );
        $stmt->execute([$value]);
        $rows = $stmt->fetchAll();
        $count = count($rows);
        if ($count < self::RATE_LIMIT_MAX) {
            return 0;
        }
        $decisive = $rows[$count - self::RATE_LIMIT_MAX];
        return max(1, (int) $decisive['ts'] + self::RATE_LIMIT_WINDOW - (int) $decisive['now']);
    }

    private static function usersPdo(): PDO
    {
        $pdo = Database::connectIfExists();
        if ($pdo === null || !Database::tableExists($pdo, 'users')) {
            throw new ApiException(503, [
                'error'   => 'not_bootstrapped',
                'message' => 'Benutzertabelle fehlt - bitte /bootstrap?token=... aufrufen',
            ]);
        }
        return $pdo;
    }

    /** Session ID from the cookie if it looks plausible AND its session file exists. */
    private static function sessionIdFromCookie(): ?string
    {
        $id = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id)) {
            return null;
        }
        return is_file(Config::sessionDir() . '/sess_' . $id) ? $id : null;
    }

    private static function start(bool $readOnly): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @ini_set('session.save_handler', 'files'); // ignore hoster defaults (e.g. memcached)
        $ok = session_start([
            'name'             => self::COOKIE,
            'save_path'        => Config::sessionDir(),
            'use_strict_mode'  => 1,
            'use_cookies'      => 1,
            'use_only_cookies' => 1,
            'cookie_lifetime'  => 0,
            'cookie_path'      => self::cookiePath(),
            'cookie_secure'    => self::isHttps() ? 1 : 0,
            'cookie_httponly'  => 1,
            'cookie_samesite'  => 'Lax',
            'gc_maxlifetime'   => self::LIFETIME,
            'gc_probability'   => 0, // cleanup is done by gcSessions()
            'read_and_close'   => $readOnly,
        ]);
        if (!$ok) {
            throw new \RuntimeException('session_start() ist fehlgeschlagen.');
        }
    }

    /** Cookie only for the folder the CMS lives in (relevant when running in a subfolder). */
    private static function cookiePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return rtrim($dir, '/') . '/';
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function ensureSessionDir(): bool
    {
        $dir = Config::sessionDir();
        if (!is_dir($dir)) {
            Config::ensureDataDir();
            @mkdir($dir, 0700, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** PHP's own GC is switched off on many hosters: delete expired session files here. */
    private static function gcSessions(): void
    {
        $limit = time() - self::LIFETIME;
        foreach (glob(Config::sessionDir() . '/sess_*') ?: [] as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
