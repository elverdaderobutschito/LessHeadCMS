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

/** Thin layer between the Flight routes and the CMS logic. */
final class Http
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    public static function json($data, int $status = 200): void
    {
        \Flight::json($data, $status, true, 'utf-8', self::JSON_FLAGS);
    }

    /**
     * Send a JSON response immediately. Needed in the Flight error handler: Flight::json() only writes to the
     * response buffer, nothing else is sent there afterwards (Flight's own handler also calls send()).
     */
    public static function jsonNow($data, int $status): void
    {
        \Flight::response()
            ->clearBody()
            ->status($status)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->write(json_encode($data, self::JSON_FLAGS))
            ->send();
    }

    /** JSON request body as an array; throws ApiException(400) for invalid JSON. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException(400, ['error' => 'invalid_json', 'message' => 'Request-Body ist kein gültiges JSON-Objekt']);
        }
        return $data;
    }

    /**
     * Runs an API handler and translates errors into JSON responses.
     * The handler receives a Cms instance and returns [status, payload].
     *
     * Middleware: everything except GET/HEAD counts as writing and needs a session or an API key (401) with a changed
     * initial password (403). This applies to every route registered via api() - a new write route is
     * therefore protected by default. Reading (GET) stays public, unless the setting "require_auth_for_read" is switched
     * on (Settings, System → Einstellungen): then reading needs a session or API key as well, also for rows that are
     * published. During a schema migration (Maintenance), writes are rejected with 503.
     *
     * Roles and permissions (Permissions): with a session or API key - reading as well as writing - the Cms works with
     * the permissions of the authenticated user (denied entity 403, denied fields are missing). Without authentication
     * (public API) and for admins everything stays as before.
     */
    public static function api(callable $handler): void
    {
        try {
            $model = Database::loadModel();
            if ($model === null) {
                throw new ApiException(503, [
                    'error'   => 'not_bootstrapped',
                    'message' => 'Datenbank noch nicht initialisiert – bitte /bootstrap?token=... aufrufen',
                ]);
            }
            $pdo = Database::connect();
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'GET' && $method !== 'HEAD') {
                $user = Auth::requireSession($pdo);
                Maintenance::guard(); // 503 while a schema migration is running
            } elseif (Settings::get($pdo, Settings::REQUIRE_AUTH_FOR_READ)) {
                \Flight::response()->header('Cache-Control', 'no-store');
                $user = Auth::requireSession($pdo);
            } else {
                $user = Auth::currentUser($pdo);
            }
            [$status, $payload] = $handler(new Cms($pdo, $model, Permissions::access($pdo, $user, $model), $user), $pdo);
            self::json($payload, $status);
        } catch (ApiException $e) {
            self::apiError($e);
        } catch (\Throwable $e) {
            error_log('[LessHeadCMS] ' . get_class($e) . ': ' . $e->getMessage());
            self::json(['error' => 'internal_error', 'message' => 'Interner Fehler'], 500);
        }
    }

    /**
     * Like api(), but for /api/users: requires a session with role = 'admin' (Auth::requireAdmin()) for EVERY method
     * (GET too) instead of only for write access. The handler receives Users, PDO and the
     * logged-in admin and returns [status, payload]; a Download as payload is sent as a file.
     *
     * @param string|null $systable system area (Permissions::SYSTABLES): then, instead of role = 'admin', a session with
     *        an explicit allow on this area is sufficient; the handler's third parameter is then the logged-in user
     *        (not necessarily an admin). If not given, the route stays reserved for admins.
     */
    public static function adminApi(callable $handler, ?string $systable = null): void
    {
        try {
            \Flight::response()->header('Cache-Control', 'no-store');
            $pdo = Database::connectIfExists();
            if ($pdo === null || !Database::tableExists($pdo, 'users')) {
                throw new ApiException(503, [
                    'error'   => 'not_bootstrapped',
                    'message' => 'Datenbank noch nicht initialisiert – bitte /bootstrap?token=... aufrufen',
                ]);
            }
            if ($systable === null) {
                $admin = Auth::requireAdmin($pdo);
            } else {
                $admin = Auth::requireSession($pdo);
                Permissions::requireSystable($pdo, $admin, $systable);
            }
            [$status, $payload] = $handler(new Users($pdo), $pdo, $admin);
            if ($payload instanceof Download) {
                self::download($payload, $status);
                return;
            }
            self::json($payload, $status);
        } catch (ApiException $e) {
            self::apiError($e);
        } catch (\Throwable $e) {
            error_log('[LessHeadCMS] ' . get_class($e) . ': ' . $e->getMessage());
            self::json(['error' => 'internal_error', 'message' => 'Interner Fehler'], 500);
        }
    }

    /**
     * For the logged-in user's own settings (/api/_prefs/...): requires a session with a changed initial password
     * (Auth::requireSession()) for EVERY method (GET too), any role. The handler receives Cms, PDO and the
     * logged-in user and returns [status, payload]. The Cms works with the user's permissions (see api()).
     *
     * @param string|null $systable system area (Permissions::SYSTABLES) the route additionally requires (403 without
     *        allow; admins always)
     */
    public static function userApi(callable $handler, ?string $systable = null): void
    {
        try {
            \Flight::response()->header('Cache-Control', 'no-store');
            $model = Database::loadModel();
            if ($model === null) {
                throw new ApiException(503, [
                    'error'   => 'not_bootstrapped',
                    'message' => 'Datenbank noch nicht initialisiert – bitte /bootstrap?token=... aufrufen',
                ]);
            }
            $pdo = Database::connect();
            $user = Auth::requireSession($pdo);
            $access = Permissions::access($pdo, $user, $model);
            if ($systable !== null && $access !== null && !$access->systable($systable)) {
                throw new ApiException(403, ['error' => 'forbidden', 'message' => 'Keine Berechtigung für diesen Bereich']);
            }
            Maintenance::guardWrite(); // media library, column selection: writes get 503 while a schema migration is running
            [$status, $payload] = $handler(new Cms($pdo, $model, $access, $user), $pdo, $user);
            self::json($payload, $status);
        } catch (ApiException $e) {
            self::apiError($e);
        } catch (\Throwable $e) {
            error_log('[LessHeadCMS] ' . get_class($e) . ': ' . $e->getMessage());
            self::json(['error' => 'internal_error', 'message' => 'Interner Fehler'], 500);
        }
    }

    /** File response (download): file name made of safe characters only, otherwise "download" */
    private static function download(Download $file, int $status): void
    {
        $name = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $file->filename) ? $file->filename : 'download';
        \Flight::response()
            ->status($status)
            ->header('Content-Type', $file->contentType)
            ->header('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->write($file->body);
    }

    /** Query parameter as a boolean: only true/1/yes/on count as true, everything else (nonsense too) as false. */
    public static function flag(string $name): bool
    {
        $value = $_GET[$name] ?? null;
        return is_string($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private static function apiError(ApiException $e): void
    {
        foreach ($e->headers as $name => $value) {
            \Flight::response()->header($name, $value);
        }
        self::json($e->payload, $e->status);
    }

    /**
     * Handler for /api/login, /api/logout, /api/me, /api/me/password: never protected by the write middleware
     * (otherwise no login would be possible); they check their authorization themselves. Returns [status, payload].
     */
    public static function auth(callable $handler): void
    {
        try {
            // the login state must never be cached by a proxy/cache
            \Flight::response()->header('Cache-Control', 'no-store');
            [$status, $payload] = $handler();
            self::json($payload, $status);
        } catch (ApiException $e) {
            self::apiError($e);
        } catch (\Throwable $e) {
            error_log('[LessHeadCMS] ' . get_class($e) . ': ' . $e->getMessage());
            self::json(['error' => 'internal_error', 'message' => 'Interner Fehler'], 500);
        }
    }
}
