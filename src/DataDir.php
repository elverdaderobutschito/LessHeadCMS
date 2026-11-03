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
 * Storage location of the data directory (Config::dataDir(), DATA_DIR in config.php) - System → Einstellungen → System &
 * Wartung, admins only (GET /api/_datadir, POST /api/_datadir/check|move|cancel|cleanup). Moves the database, its backups
 * and the sessions to another directory, e.g. '../data' outside the webroot. media/ stays where it is (public by design).
 *
 * move() - clearly fail rather than leave half a state; until config.php points to the new location, the previous database
 * stays active and unchanged:
 *   1. check the target (plan(): exists or can be created, writable, within open_basedir, no database there yet; inside or
 *      outside DOCUMENT_ROOT is only a warning) and prepare it (.htaccess as in data/)
 *   2. copy the database with VACUUM INTO (never a file copy: consistent even while others write, and in WAL mode).
 *      Before that a second connection takes the write lock of the active database (BEGIN IMMEDIATE) and keeps it until the
 *      end: nothing can be committed after the snapshot that the copy would miss
 *   3. PRAGMA integrity_check on the copy and the same row count in every table, then it is put in place as cms.sqlite
 *   4. copy the backups (schema-backups/) and the sessions along
 *   5. write DATA_DIR into config.php (writeConfig(): config.php.bak beforehand, only this one define replaced or added,
 *      temporary file + rename, opcache_invalidate()). If config.php is not writable: the line to enter by hand
 *      (status 'pending_config'); the move only counts as finished once config.php points to the new location
 * Then the previous database is locked against writing for good (triggers RAISE(ABORT) on every table except
 * login_attempts and api_keys, committed together with the lock): a request that was still waiting for the lock writes into
 * the previous file, which nobody reads any more - it fails instead. If a step fails (or PHP aborts, see the shutdown
 * function), everything created at the new location is removed again and the previous database stays active.
 *
 * The old files (cms.sqlite incl. -wal/-shm, backups, sessions, config.php.bak) stay until the admin confirms the
 * deletion (cleanup()) - a copy left in the webroot would undo the protection. relocation.json at the new location remembers
 * where they are; while config.php still has to be changed, relocation-pending.json in the old location blocks content
 * writes (Maintenance::guard()) and bootstrap.lock stays (no schema change, restore or bootstrap in between).
 */
final class DataDir
{
    /** Marker at the new location: where the database came from (for cleanup()) and what was copied */
    public const MOVED_FILE = 'relocation.json';
    /** Marker at the old location while config.php still has to be changed by hand */
    public const PENDING_FILE = 'relocation-pending.json';
    /** Tables not locked in the previous database: a lost write there does no harm, and login must keep working */
    private const UNFENCED = ['login_attempts', 'api_keys'];
    private const FENCE_PREFIX = 'lh_relocated_';
    /** Part of the error of a write into the locked previous database (isFenced()) */
    public const FENCE_MARKER = 'LessHeadCMS: database moved';
    private const DB_SUFFIXES = ['', '-wal', '-shm', '-journal'];
    /** In data/ next to index.php (stays there, also after cleanup()): the location of the last move - for assertNotElsewhere() */
    public const LOCATION_FILE = 'location.json';

    /** Where the last move went (data/location.json next to index.php) */
    public static function locationFile(): string
    {
        return Config::root() . '/data/' . self::LOCATION_FILE;
    }

    /**
     * Called when the database file at the configured location is missing, before a new one would be created (bootstrap)
     * or "not installed" would be reported: if data/ next to index.php or the location of the last move (locationFile())
     * holds a cms.sqlite, DATA_DIR was most likely changed by hand - 503 database_elsewhere instead of an empty installation.
     * A genuine new installation (no database anywhere) passes.
     */
    public static function assertNotElsewhere(): void
    {
        $dir = Config::dataDir();
        $candidates = [Config::root() . '/data'];
        $last = self::readJson(self::locationFile());
        if (is_string($last['dir'] ?? null) && $last['dir'] !== '') {
            $candidates[] = $last['dir'];
        }
        foreach ($candidates as $candidate) {
            if (self::real($candidate) === self::real($dir) || !is_file($candidate . '/cms.sqlite')) {
                continue;
            }
            throw ApiException::of(503, 'database_elsewhere', Msg::of('msg_datadir.elsewhere', 'There is no database at the configured '
                . 'location {dir}, but there is one in {found}. To avoid an accidentally empty installation, LessHeadCMS does not create '
                . 'a new one. Was DATA_DIR in config.php changed by hand? Then set DATA_DIR back to the previous location and move the '
                . 'database under System → Settings → System & maintenance - or copy the database (cms.sqlite with schema-backups/) '
                . 'to {dir} yourself.', ['dir' => Config::displayPath($dir, true), 'found' => Config::displayPath(Config::absolute($candidate), true)]));
        }
    }

    /** Overview for the admin UI: current location, inside/outside the webroot, a waiting move, an old copy to delete */
    public static function status(): array
    {
        $dir = Config::dataDir();
        $docroot = self::documentRoot();
        $pending = self::readJson($dir . '/' . self::PENDING_FILE);
        $moved = self::readJson($dir . '/' . self::MOVED_FILE);
        $cleanup = null;
        if ($moved !== null && $pending === null && is_string($moved['from'] ?? null)) {
            $left = self::leftovers($moved);
            if ($left) {
                // the session files only as a count (there can be many)
                $sessions = array_filter($left, function ($f) {
                    return strpos(basename($f), 'sess_') === 0;
                });
                $cleanup = [
                    'from'           => Config::displayPath($moved['from'], true),
                    'inside_webroot' => self::inside($moved['from'], $docroot),
                    'files'          => array_values(array_map(function ($f) {
                        return Config::displayPath($f);
                    }, array_diff($left, $sessions))),
                    'sessions'       => count($sessions),
                    'sessions_dir'   => Config::displayPath($moved['from'] . '/sessions', true),
                ];
            }
        }
        return [
            'setting'         => Config::dataDirSetting(),
            'display'         => Config::displayPath($dir, true),
            'path'            => $dir,
            'document_root'   => $docroot,
            'inside_webroot'  => self::inside($dir, $docroot),
            'config_writable' => self::configWritable(),
            'open_basedir'    => self::openBasedir() ?: null,
            'pending'         => $pending === null ? null : [
                'display' => Config::displayPath((string) ($pending['to'] ?? ''), true),
                'line'    => self::configLine((string) ($pending['setting'] ?? '')),
            ],
            'cleanup'         => $cleanup,
        ];
    }

    /**
     * Step 1 without changing anything (POST /api/_datadir/check, and the start of move()). Throws 422 (field "path") if
     * the target cannot be used, 409 if a move is still waiting or an old copy still has to be deleted.
     *
     * @return array{setting:string,path:string,display:string,create:bool,inside_webroot:?bool,config_writable:bool,line:string}
     */
    public static function plan($input): array
    {
        $invalid = function (Msg $why): ApiException {
            return ApiException::invalid(['path' => $why]);
        };
        if (!is_string($input) || trim($input) === '') {
            throw $invalid(Msg::of('msg_datadir.path_missing', 'Please enter a directory.'));
        }
        $setting = trim($input);
        $setting = $setting === '/' ? $setting : rtrim($setting, '/\\');
        if ($setting === '' || strlen($setting) > 500 || preg_match('/[\x00-\x1f\x7f]/', $setting)) {
            throw $invalid(Msg::of('msg_datadir.path_invalid', 'The path contains invalid characters.'));
        }
        $current = Config::dataDir();
        if (self::readJson($current . '/' . self::PENDING_FILE) !== null) {
            throw ApiException::of(409, 'relocation_pending', Msg::of('msg_datadir.pending', 'A move is waiting for the change in '
                . 'config.php. Finish or cancel it first.'));
        }
        $moved = self::readJson($current . '/' . self::MOVED_FILE);
        if ($moved !== null && self::leftovers($moved)) {
            throw ApiException::of(409, 'cleanup_first', Msg::of('msg_datadir.cleanup_first', 'The old copy in {dir} still exists. '
                . 'Please delete it first, then move again.', ['dir' => Config::displayPath((string) $moved['from'], true)]));
        }
        $target = Config::absolute($setting);
        $dir = Config::displayPath($target, true);
        $real = self::real($target);
        $realCurrent = self::real($current);
        if ($real === $realCurrent) {
            throw $invalid(Msg::of('msg_datadir.same', 'This is already the current storage location.'));
        }
        if (strpos($real . '/', $realCurrent . '/') === 0 || strpos($realCurrent . '/', $real . '/') === 0) {
            throw $invalid(Msg::of('msg_datadir.nested', 'The new location must not lie inside the current one (or the other way round).'));
        }
        if (!self::withinOpenBasedir($real)) {
            throw $invalid(Msg::of('msg_datadir.open_basedir', 'The directory {dir} lies outside open_basedir ({open_basedir}) - PHP '
                . 'may not access it there.', ['dir' => $dir, 'open_basedir' => self::openBasedir()]));
        }
        $create = !file_exists($target);
        if (!$create && !is_dir($target)) {
            throw $invalid(Msg::of('msg_datadir.not_dir', '{dir} exists but is not a directory.', ['dir' => rtrim($dir, '/')]));
        }
        if ($create) {
            $parent = dirname($target);
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            if (!is_dir($parent) || !is_writable($parent)) {
                throw $invalid(Msg::of('msg_datadir.cannot_create', 'The directory {dir} does not exist and cannot be created ({parent} '
                    . 'is not writable).', ['dir' => $dir, 'parent' => Config::displayPath($parent, true)]));
            }
        } elseif (!is_writable($target)) {
            throw $invalid(Msg::of('msg_datadir.not_writable', 'The directory {dir} is not writable for PHP.', ['dir' => $dir]));
        }
        foreach (self::DB_SUFFIXES as $suffix) {
            if (file_exists($target . '/cms.sqlite' . $suffix)) {
                throw $invalid(Msg::of('msg_datadir.occupied', 'There is already a database in {dir} ({file}). Choose another '
                    . 'directory or delete it there first.', ['dir' => $dir, 'file' => 'cms.sqlite' . $suffix]));
            }
        }
        $version = (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.27.0', '<')) {
            throw $invalid(Msg::of('msg_datadir.sqlite_old', 'Moving needs SQLite 3.27 or newer (VACUUM INTO); this server has {version}.',
                ['version' => $version]));
        }
        return [
            'setting'         => $setting,
            'path'            => $target,
            'display'         => $dir,
            'create'          => $create,
            'inside_webroot'  => self::inside($real, self::documentRoot()),
            'config_writable' => self::configWritable(),
            'line'            => self::configLine($setting),
        ];
    }

    /**
     * Steps 1-5 (see the class comment). @return array status 'moved' (config.php points to the new location) or
     * 'pending_config' (enter 'line' in config.php by hand), plus 'warning' if the new location lies inside the webroot.
     * $confirmReadOnly must be true if config.php is not writable (the read-only period is then the admin's decision).
     * @throws ApiException 422/409 from plan(), 409 locked/busy, 500 relocation_failed (nothing changed)
     */
    public static function move(PDO $pdo, $input, $confirmReadOnly = null): array
    {
        $plan = self::plan($input);
        // config.php not writable: the CMS stays read-only until the line is entered by hand - only with explicit consent
        if (!$plan['config_writable'] && $confirmReadOnly !== true) {
            throw ApiException::of(422, 'confirmation_required', Msg::of('msg_datadir.readonly_confirm', 'config.php is not writable: after '
                . 'the move the CMS is read-only until the line is entered in config.php. Please confirm this explicitly. Nothing was changed.'));
        }
        $oldDir = Config::dataDir();
        $target = $plan['path'];
        $lock = Config::lockFile();
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw ApiException::of(409, 'locked', Msg::of('msg_datadir.locked', 'A bootstrap, a schema change or a restore is running '
                . 'right now ({lock}). Please wait a moment and try again.', ['lock' => Config::displayPath($lock)]));
        }
        fclose($handle);
        Maintenance::begin();
        // shared with the shutdown function: if PHP ends in the middle (timeout, fatal error), it undoes the same as catch
        $st = (object) ['guard' => null, 'inTx' => false, 'created' => [], 'switched' => false, 'pending' => false, 'finished' => false];
        register_shutdown_function(function () use ($st, $lock) {
            if (!$st->finished) {
                self::rollback($st);
                Maintenance::end();
                @unlink($lock);
            }
        });
        $failed = function (Msg $reason) use ($oldDir): ApiException {
            return ApiException::of(500, 'relocation_failed', Msg::of('msg_datadir.failed', '{reason} Nothing was changed: the '
                . 'database stays active in {dir}.', ['reason' => $reason, 'dir' => Config::displayPath($oldDir, true)]));
        };
        try {
            // 1. prepare the target
            if ($plan['create']) {
                $missing = [];
                for ($d = $target; !file_exists($d) && dirname($d) !== $d; $d = dirname($d)) {
                    $missing[] = $d;
                }
                if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                    throw $failed(Msg::of('msg_datadir.cannot_create_now', 'The directory {dir} could not be created.', ['dir' => $plan['display']]));
                }
                foreach ($missing as $d) {
                    $st->created[] = $d;
                }
            }
            if (!is_file($target . '/.htaccess')) {
                if (@file_put_contents($target . '/.htaccess', Config::DATA_HTACCESS) === false) {
                    throw $failed(Msg::of('msg_datadir.not_writable', 'The directory {dir} is not writable for PHP.', ['dir' => $plan['display']]));
                }
                $st->created[] = $target . '/.htaccess';
            }
            self::testAbort(1);

            // 2. copy under the write lock of the active database
            $st->guard = Database::connect();
            $st->guard->setAttribute(PDO::ATTR_TIMEOUT, 10);
            try {
                $st->guard->exec('BEGIN IMMEDIATE');
                $st->inTx = true;
            } catch (\PDOException $e) {
                throw ApiException::of(409, 'busy', Msg::of('msg_datadir.busy', 'The database is busy with another write access. '
                    . 'Please try again in a moment.'));
            }
            if (Config::testMigrationDelayMs() > 0) {
                usleep(Config::testMigrationDelayMs() * 1000); // tests only: writes arrive while the lock is held
            }
            $tmp = $target . '/cms.sqlite.relocate';
            @unlink($tmp);
            $st->created[] = $tmp;
            try {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
            } catch (\PDOException $e) {
                throw $failed(Msg::of('msg_datadir.copy_failed', 'The database could not be copied to {dir} ({error}).',
                    ['dir' => $plan['display'], 'error' => $e->getMessage()]));
            }
            self::testAbort(2);

            // 3. check the copy, then put it in place
            self::verifyCopy($pdo, $tmp, $failed, $plan['display']);
            $dbFile = $target . '/cms.sqlite';
            @chmod($tmp, fileperms(Config::dbFile()) & 0777);
            if (!@rename($tmp, $dbFile)) {
                throw $failed(Msg::of('msg_datadir.place_failed', 'The copy could not be put in place as {file}.',
                    ['file' => Config::displayPath($dbFile)]));
            }
            $st->created[] = $dbFile;
            self::testAbort(3);

            // 4. backups and sessions along
            $copied = ['backups' => [], 'sessions' => []];
            $backups = array_merge(...array_map(function ($b) {
                return array_column($b['files'], 'name');
            }, SchemaBackup::all() ?: [['files' => []]]));
            if ($backups) {
                $copied['backups'] = self::copyFiles(SchemaBackup::dir(), $target . '/schema-backups', $backups, 0755, $st, $failed, false);
            }
            $sessions = array_map('basename', glob(Config::sessionDir() . '/sess_*') ?: []);
            if ($sessions) {
                $copied['sessions'] = self::copyFiles(Config::sessionDir(), $target . '/sessions', $sessions, 0700, $st, $failed, true);
            }
            $marker = $target . '/' . self::MOVED_FILE;
            self::writeJson($marker, ['from' => $oldDir, 'to' => $target, 'setting' => $plan['setting'],
                'at' => gmdate('Y-m-d H:i:s')] + $copied, $failed);
            $st->created[] = $marker;
            self::testAbort(4);

            // the previous database is locked for good together with the switch (committed below, see the class comment)
            self::fence($st->guard, $failed);

            // 5. config.php
            if ($plan['config_writable']) {
                self::writeConfig($plan['setting'], $st, $failed);
                $st->switched = true;
            } else {
                $pendingFile = $oldDir . '/' . self::PENDING_FILE;
                self::writeJson($pendingFile, ['to' => $target, 'setting' => $plan['setting'], 'at' => gmdate('Y-m-d H:i:s')], $failed);
                $st->created[] = $pendingFile;
                self::testAbort(5);
            }
            try {
                $st->guard->exec('COMMIT');
                $st->inTx = false;
            } catch (\PDOException $e) {
                if (!$st->switched) {
                    throw $failed(Msg::of('msg_datadir.fence', 'The previous database could not be locked against write access ({error}).',
                        ['error' => $e->getMessage()]));
                }
                error_log('[LessHeadCMS] Data directory moved, but the previous database could not be locked: ' . $e->getMessage());
            }
            $st->pending = !$st->switched;
        } catch (\Throwable $e) {
            self::rollback($st);
            if ($e instanceof ApiException) {
                throw $e;
            }
            error_log('[LessHeadCMS] Moving the data directory: ' . get_class($e) . ': ' . $e->getMessage());
            throw $failed($e instanceof DataDirTestAbort ? $e->msg : Msg::of('msg_datadir.internal', 'Unexpected error (see the PHP error log).'));
        } finally {
            $st->finished = true;
            $st->guard = null;
            Maintenance::end();
            if (!$st->pending) {
                @unlink($lock); // while config.php still has to be changed, the lock stays (see the class comment)
            }
        }

        // remember the new location (also while config.php is pending: as long as the old database exists, nothing is checked)
        if (is_dir(Config::root() . '/data') && @file_put_contents(self::locationFile(), json_encode(['dir' => $target,
                'at' => gmdate('Y-m-d H:i:s')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            error_log('[LessHeadCMS] ' . Config::displayPath(self::locationFile()) . ' could not be written.');
        }

        $warning = $plan['inside_webroot'] ? Msg::of('msg_datadir.inside_webroot', 'The new location lies inside the webroot ({docroot}). '
            . 'It is only protected there as long as the web server blocks it (.htaccess with Apache, a location block with nginx).',
            ['docroot' => (string) self::documentRoot()]) : null;
        $result = $st->pending
            ? ['status' => 'pending_config', 'line' => $plan['line']] + Msg::of('msg_datadir.pending_config', 'config.php is not writable. '
                . 'The copy in {dir} is ready; enter this line in config.php: {line} Until then no changes can be saved.',
                ['dir' => $plan['display'], 'line' => $plan['line']])->fields()
            : ['status' => 'moved'] + Msg::of('msg_datadir.moved', 'The database now lives in {dir}. Please delete the old copy in {old} '
                . 'next.', ['dir' => $plan['display'], 'old' => Config::displayPath($oldDir, true)])->fields();
        return $result + ['display' => $plan['display']]
            + ($warning !== null ? ['warning' => $warning->text(), 'warning_code' => $warning->codeOnly()] : []);
    }

    /** Give up a move that waits for config.php: unlock the previous database, remove the copy, release the lock */
    public static function cancel(): array
    {
        $dir = Config::dataDir();
        $pendingFile = $dir . '/' . self::PENDING_FILE;
        $pending = self::readJson($pendingFile);
        if ($pending === null || !is_string($pending['to'] ?? null)) {
            throw ApiException::of(409, 'no_pending', Msg::of('msg_datadir.no_pending', 'There is no move waiting for config.php.'));
        }
        $target = $pending['to'];
        $guard = Database::connect();
        foreach ($guard->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE '" . self::FENCE_PREFIX . "%'")
                     ->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $guard->exec('DROP TRIGGER IF EXISTS ' . self::quoteId((string) $name));
        }
        $guard = null;
        $moved = self::readJson($target . '/' . self::MOVED_FILE);
        if ($moved !== null && self::real((string) ($moved['from'] ?? '')) === self::real($dir)) {
            foreach (array_merge(['cms.sqlite', 'cms.sqlite.relocate'], array_map(function ($f) {
                return 'schema-backups/' . $f;
            }, $moved['backups'] ?? []), array_map(function ($f) {
                return 'sessions/' . $f;
            }, $moved['sessions'] ?? []), [self::MOVED_FILE]) as $f) {
                @unlink($target . '/' . $f);
            }
            @rmdir($target . '/schema-backups');
            @rmdir($target . '/sessions');
        }
        @unlink($pendingFile);
        @unlink(Config::lockFile());
        return ['status' => 'cancelled'] + Msg::of('msg_datadir.cancelled', 'Move cancelled: the database stays in {dir}, the copy in '
            . '{target} was removed.', ['dir' => Config::displayPath($dir, true), 'target' => Config::displayPath($target, true)])->fields();
    }

    /**
     * Step 6, only after the switch and with explicit confirmation: delete the old database (incl. -wal/-shm), the moved
     * backups and sessions, what else LessHeadCMS left there (cache, lock and maintenance files) and config.php.bak.
     */
    public static function cleanup($confirm): array
    {
        $dir = Config::dataDir();
        $markerFile = $dir . '/' . self::MOVED_FILE;
        $moved = self::readJson($markerFile);
        if ($moved === null || !is_string($moved['from'] ?? null) || self::readJson($dir . '/' . self::PENDING_FILE) !== null) {
            throw ApiException::of(409, 'no_cleanup', Msg::of('msg_datadir.no_cleanup', 'There is no old copy to delete.'));
        }
        if ($confirm !== true) {
            throw ApiException::of(422, 'confirmation_required', Msg::of('msg_datadir.cleanup_confirm', 'Please confirm the deletion '
                . 'explicitly. Nothing was deleted.'));
        }
        $from = $moved['from'];
        $shown = Config::displayPath($from, true);
        // never the active database (e.g. if config.php was set back to the old location by hand)
        if (self::real($from) === self::real($dir) || (is_file($from . '/cms.sqlite')
                && self::real($from . '/cms.sqlite') === self::real(Config::dbFile()))) {
            throw ApiException::of(409, 'cleanup_unsafe', Msg::of('msg_datadir.cleanup_unsafe', 'The old location {dir} is the active '
                . 'one - nothing was deleted.', ['dir' => $shown]));
        }
        $left = self::leftovers($moved);
        $failed = [];
        foreach ($left as $file) {
            if (!@unlink($file) && file_exists($file)) {
                $failed[] = Config::displayPath($file);
            }
        }
        foreach (['schema-backups', 'sessions', 'cache'] as $sub) {
            @rmdir($from . '/' . $sub); // only if empty
        }
        if ($failed) {
            throw ApiException::of(500, 'cleanup_partial', Msg::of('msg_datadir.cleanup_partial', 'Some files could not be deleted: '
                . '{files}. Please delete them via FTP.', ['files' => implode(', ', $failed)]));
        }
        @unlink($markerFile);
        return ['status' => 'cleaned', 'deleted' => count($left)] + Msg::of('msg_datadir.cleaned', 'Old copy in {dir} deleted '
            . '({count} files).', ['dir' => $shown, 'count' => count($left)])->fields();
    }

    /**
     * Did a write fail because it went into the locked previous database? SQLite reports RAISE(ABORT) as a constraint
     * error (SQLSTATE 23000) - this is no duplicate or foreign key problem (Database::isConstraintViolation()), and Http
     * answers it with 503 relocated.
     */
    public static function isFenced(\Throwable $e): bool
    {
        return $e instanceof \PDOException && strpos($e->getMessage(), self::FENCE_MARKER) !== false;
    }

    /** The line for config.php */
    public static function configLine(string $setting): string
    {
        return "define('DATA_DIR', " . var_export($setting, true) . ');';
    }

    /** Value of DATA_DIR in the text of a config.php as written by configLine() (single-quoted), null if there is none */
    public static function settingInConfig(string $source): ?string
    {
        if (!preg_match("/^[ \\t]*define\\(\\s*(['\"])DATA_DIR\\1\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)\\s*;/m", $source, $m)) {
            return null;
        }
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $m[2]);
    }

    /**
     * Text of config.php with DATA_DIR set: an existing define('DATA_DIR', …); line is replaced (every one, if there are
     * several), otherwise the line is added at the end (before a closing ?>). Everything else stays exactly as it is.
     */
    public static function withSetting(string $source, string $setting): string
    {
        $line = self::configLine($setting);
        $pattern = "/^([ \\t]*)define\\(\\s*(['\"])DATA_DIR\\2\\s*,[^\\n]*\\)\\s*;[ \\t]*$/m";
        if (preg_match($pattern, $source)) {
            return (string) preg_replace_callback($pattern, function ($m) use ($line) {
                return $m[1] . $line;
            }, $source);
        }
        $add = "\n// Storage location of the database, its backups and the sessions (System -> Einstellungen -> System & Wartung)\n"
            . $line . "\n";
        if (preg_match('/\?>\s*$/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return rtrim(substr($source, 0, $m[0][1])) . "\n" . $add . substr($source, $m[0][1]);
        }
        return rtrim($source) . "\n" . $add;
    }

    // ------------------------------------------------------------------ steps

    /** Step 3: integrity_check and the same row count in every table as in the active database (locked, so stable) */
    private static function verifyCopy(PDO $pdo, string $copy, callable $failed, string $display): void
    {
        try {
            $check = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $result = (string) $check->query('PRAGMA integrity_check')->fetchColumn();
            if ($result !== 'ok') {
                throw $failed(Msg::of('msg_datadir.integrity', 'The copy in {dir} is damaged (integrity_check: {result}).',
                    ['dir' => $display, 'result' => $result]));
            }
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $rows = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::quoteId((string) $table))->fetchColumn();
                $copied = (int) $check->query('SELECT COUNT(*) FROM ' . self::quoteId((string) $table))->fetchColumn();
                if ($rows !== $copied) {
                    throw $failed(Msg::of('msg_datadir.incomplete', 'The copy is incomplete (table {table}: {copied} instead of {rows} rows).',
                        ['table' => $table, 'copied' => $copied, 'rows' => $rows]));
                }
            }
        } catch (\PDOException $e) {
            throw $failed(Msg::of('msg_datadir.integrity', 'The copy in {dir} is damaged (integrity_check: {result}).',
                ['dir' => $display, 'result' => $e->getMessage()]));
        } finally {
            $check = null;
        }
    }

    /**
     * Step 4: copy $names from $from to $to (created with $mode if missing), sizes checked; $keepTime: keep the
     * modification time (sessions expire by it). Files that vanished meanwhile are skipped. @return string[] copied names
     */
    private static function copyFiles(string $from, string $to, array $names, int $mode, \stdClass $st, callable $failed, bool $keepTime): array
    {
        $shown = Config::displayPath($to, true);
        if (!is_dir($to)) {
            if (!@mkdir($to, $mode) && !is_dir($to)) {
                throw $failed(Msg::of('msg_datadir.cannot_create_now', 'The directory {dir} could not be created.', ['dir' => $shown]));
            }
            $st->created[] = $to;
        }
        $done = [];
        foreach ($names as $name) {
            $src = $from . '/' . $name;
            $dst = $to . '/' . $name;
            if (!is_file($src)) {
                continue;
            }
            if (file_exists($dst)) {
                throw $failed(Msg::of('msg_datadir.file_exists', 'A file {file} already exists in {dir}.', ['file' => $name, 'dir' => $shown]));
            }
            $ok = @copy($src, $dst);
            if ($ok) {
                $st->created[] = $dst;
            }
            clearstatcache();
            if (!$ok || filesize($dst) !== filesize($src)) {
                throw $failed(Msg::of('msg_datadir.file_copy', 'The file {file} could not be copied to {dir}.', ['file' => $name, 'dir' => $shown]));
            }
            @chmod($dst, fileperms($src) & 0777);
            if ($keepTime) {
                @touch($dst, (int) filemtime($src));
            }
            $done[] = $name;
        }
        return $done;
    }

    /** Lock every table of the previous database against writing - inside the transaction of $guard (committed by move()) */
    private static function fence(PDO $guard, callable $failed): void
    {
        $raise = "SELECT RAISE(ABORT, '" . self::FENCE_MARKER . " to another directory (DATA_DIR in config.php), it is read-only')";
        try {
            $tables = $guard->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $i => $table) {
                if (in_array($table, self::UNFENCED, true)) {
                    continue;
                }
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $op) {
                    $guard->exec('CREATE TRIGGER ' . self::quoteId(self::FENCE_PREFIX . $i . '_' . strtolower($op)) . " BEFORE $op ON "
                        . self::quoteId((string) $table) . " BEGIN $raise; END");
                }
            }
        } catch (\PDOException $e) {
            throw $failed(Msg::of('msg_datadir.fence', 'The previous database could not be locked against write access ({error}).',
                ['error' => $e->getMessage()]));
        }
    }

    /**
     * Step 5: config.php.bak, then the new text into a temporary file next to config.php and rename() over it (atomic: a
     * request reads either the old or the new file), opcache_invalidate(), read back. If the read-back fails, the
     * previous text goes back in the same way.
     */
    private static function writeConfig(string $setting, \stdClass $st, callable $failed): void
    {
        $file = Config::configFile();
        $source = @file_get_contents($file);
        if ($source === false) {
            throw $failed(Msg::of('msg_datadir.config_write', 'config.php could not be written - it stays unchanged.'));
        }
        $bak = $file . '.bak';
        if (!file_exists($bak)) {
            $st->created[] = $bak; // a .bak from an earlier move is only overwritten (with the same, still valid text)
        }
        if (!@copy($file, $bak)) {
            throw $failed(Msg::of('msg_datadir.config_backup', 'config.php.bak could not be created - config.php stays unchanged.'));
        }
        @chmod($bak, 0600); // contains the BOOTSTRAP_TOKEN
        $mode = fileperms($file) & 0777;
        $replace = function (string $text) use ($file, $mode, $st): bool {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $text) !== strlen($text)) {
                @unlink($tmp);
                return false;
            }
            @chmod($tmp, $mode);
            $st->created[] = $tmp;
            self::testAbort(5);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            return true;
        };
        if (!$replace(self::withSetting($source, $setting))) {
            throw $failed(Msg::of('msg_datadir.config_write', 'config.php could not be written - it stays unchanged.'));
        }
        clearstatcache();
        if (self::settingInConfig((string) @file_get_contents($file)) !== $setting) {
            $replace($source);
            throw $failed(Msg::of('msg_datadir.config_verify', 'config.php was written, but DATA_DIR could not be read back from it - '
                . 'the previous config.php was put back.'));
        }
    }

    /** Undo everything of a failed move (catch or shutdown) - unless config.php already points to the new location */
    private static function rollback(\stdClass $st): void
    {
        if ($st->inTx && $st->guard !== null) {
            try {
                $st->guard->exec('ROLLBACK'); // also drops the triggers of fence()
            } catch (\Throwable $e) {
                // closing the connection rolls back as well
            }
            $st->inTx = false;
        }
        $st->guard = null;
        if ($st->switched) {
            return;
        }
        // files first, then the directories created, innermost first (rmdir() only removes empty ones)
        $dirs = array_filter($st->created, 'is_dir');
        foreach (array_diff($st->created, $dirs) as $path) {
            @unlink($path);
        }
        usort($dirs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($dirs as $path) {
            @rmdir($path);
        }
        $st->created = [];
    }

    /** Test hook TEST_DATADIR_ABORT (Config::testDataDirAbort()) */
    private static function testAbort(int $step): void
    {
        $abort = Config::testDataDirAbort();
        if ($abort === null || $abort[0] !== $step) {
            return;
        }
        if ($abort[1] === 'exit') {
            exit; // like a hard abort of PHP: only the shutdown function cleans up
        }
        throw new DataDirTestAbort(Msg::of('msg_datadir.test_abort', 'Aborted for the test after step {step}.', ['step' => $step]));
    }

    // ------------------------------------------------------------------ helpers

    /** Files at the old location that cleanup() deletes (only those that still exist) */
    private static function leftovers(array $moved): array
    {
        $from = (string) ($moved['from'] ?? '');
        if ($from === '' || !is_dir($from)) {
            return [];
        }
        $files = [];
        foreach (self::DB_SUFFIXES as $suffix) {
            $files[] = $from . '/cms.sqlite' . $suffix;
        }
        $files[] = $from . '/cms.sqlite.relocate';
        foreach ($moved['backups'] ?? [] as $name) {
            $files[] = $from . '/schema-backups/' . basename((string) $name);
        }
        $files = array_merge($files, glob($from . '/sessions/sess_*') ?: [], glob($from . '/cache/ui-texts-*.json') ?: []);
        foreach (['migration.flag', 'field-order-upgrade.lock', 'bootstrap.lock', self::PENDING_FILE] as $name) {
            $files[] = $from . '/' . $name;
        }
        $files[] = Config::configFile() . '.bak';
        return array_values(array_filter($files, 'is_file'));
    }

    private static function configWritable(): bool
    {
        $file = Config::configFile();
        return is_file($file) && is_writable($file) && is_writable(dirname($file));
    }

    /** realpath() of DOCUMENT_ROOT, null if unknown (CLI, or the path cannot be resolved) */
    private static function documentRoot(): ?string
    {
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $real = $root !== '' ? realpath($root) : false;
        return $real !== false ? str_replace('\\', '/', $real) : null;
    }

    /** Does $path lie inside $docroot (or is it the same)? null if the webroot is unknown */
    private static function inside(string $path, ?string $docroot): ?bool
    {
        if ($docroot === null) {
            return null;
        }
        $real = self::real($path);
        return $real === $docroot || strpos($real . '/', rtrim($docroot, '/') . '/') === 0;
    }

    /** realpath() of the deepest existing part of $path plus the rest - also for a directory that does not exist yet */
    private static function real(string $path): string
    {
        $path = Config::absolute($path);
        $rest = [];
        while (($real = realpath($path)) === false && dirname($path) !== $path) {
            array_unshift($rest, basename($path));
            $path = dirname($path);
        }
        $base = $real !== false ? str_replace('\\', '/', $real) : $path;
        return rtrim($base, '/') . ($rest ? '/' . implode('/', $rest) : '');
    }

    private static function openBasedir(): string
    {
        return trim((string) ini_get('open_basedir'));
    }

    /** As PHP checks open_basedir: the path must start with one of the entries (an entry ending in / is a directory) */
    private static function withinOpenBasedir(string $real): bool
    {
        $setting = self::openBasedir();
        if ($setting === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $setting) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $dirOnly = substr($entry, -1) === '/';
            $resolved = @realpath($entry);
            $base = $resolved !== false ? str_replace('\\', '/', $resolved) . ($dirOnly ? '/' : '') : $entry;
            if (strpos($real . '/', $base) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private static function writeJson(string $file, array $data, callable $failed): void
    {
        if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw $failed(Msg::of('msg_datadir.marker', 'The file {file} could not be written.', ['file' => Config::displayPath($file)]));
        }
    }

    private static function quoteId(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}

