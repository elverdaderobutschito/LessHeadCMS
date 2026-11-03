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
 * Backups before a schema change (SchemaMigration::apply()): per apply one pair in data/schema-backups/ with a
 * shared timestamp - the complete database and the .puml active until then:
 *
 *   2026-10-01_14-30-00_cms.sqlite
 *   2026-10-01_14-30-00_kursverwaltung.puml
 *
 * (for two applies in the same second with "-2" etc. appended to the timestamp). The last
 * Settings::SCHEMA_BACKUP_KEEP pairs are kept (default 5); older pairs are deleted together after a successful apply.
 * data/ is blocked via .htaccess, so the backups cannot be retrieved over the web. Restoring: restore()
 * (System -> Schema, „Wiederherstellen“), if need be via FTP (README).
 *
 * Older backups from before the database copies ("20261001-143000_<file>.puml", .puml only) are counted in the
 * list and the rotation.
 */
final class SchemaBackup
{
    /** Confirmation text the admin has to type for restore() */
    public const RESTORE_CONFIRM = 'WIEDERHERSTELLEN';

    private const PATTERN = '/^(\d{4}-\d\d-\d\d_\d\d-\d\d-\d\d(?:-\d+)?)_(.+)$/';
    private const LEGACY_PATTERN = '/^(\d{8})-(\d{6})_(.+\.puml)$/';

    public static function dir(): string
    {
        return Config::dataDir() . '/schema-backups';
    }

    /**
     * Create a backup pair. Throws ApiException(500, backup_failed) if something cannot be written - then nothing
     * has been created (half pairs are removed). Must run outside a transaction (VACUUM INTO).
     *
     * @return array{stamp:string,files:string[]}
     */
    public static function create(PDO $pdo, string $schemaFile, ?Msg $action = null): array
    {
        $action = $action ?? Msg::of('msg_backup.action_apply', '"Apply"');
        $failed = function (Msg $message) use ($action): ApiException {
            return self::failed($message, $action);
        };
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw $failed(Msg::of('msg_backup.dir_create', 'The directory {dir} cannot be created.',
                ['dir' => Config::displayPath(self::dir(), true)]));
        }
        if (!is_writable($dir)) {
            throw $failed(Msg::of('msg_backup.dir_writable', 'The directory {dir} is not writable.',
                ['dir' => Config::displayPath(self::dir(), true)]));
        }
        $stamp = date('Y-m-d_H-i-s');
        for ($n = 2; glob($dir . '/' . $stamp . '_*'); $n++) {
            $stamp = date('Y-m-d_H-i-s') . "-$n";
        }
        $db = "$dir/{$stamp}_cms.sqlite";
        $puml = "$dir/{$stamp}_" . basename($schemaFile);
        // VACUUM INTO (SQLite >= 3.27) writes a self-consistent copy; older versions: copy the file (the
        // backup runs under the lock data/bootstrap.lock and before any write transaction of this apply)
        $ok = false;
        try {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($db));
            $ok = is_file($db) && filesize($db) > 0;
        } catch (\PDOException $e) {
            @unlink($db);
        }
        if (!$ok) {
            $ok = @copy(Config::dbFile(), $db) && filesize($db) > 0;
        }
        if (!$ok) {
            @unlink($db);
            throw $failed(Msg::of('msg_backup.db_write', 'The database backup could not be written (check disk space or write '
                . 'permissions in {dir}).', ['dir' => Config::displayPath(self::dir(), true)]));
        }
        if (is_file($schemaFile) && !@copy($schemaFile, $puml)) {
            @unlink($db);
            throw $failed(Msg::of('msg_backup.schema_write', 'The backup of the schema file could not be written.'));
        }
        @chmod($db, 0600);
        return ['stamp' => $stamp, 'files' => array_map('basename', array_filter([$db, is_file($puml) ? $puml : null]))];
    }

    /** Remove all files of a backup (e.g. if the apply fails afterwards - the DB stayed unchanged after all) */
    public static function remove(string $stamp): void
    {
        foreach (self::all() as $b) {
            if ($b['stamp'] === $stamp) {
                foreach ($b['files'] as $f) {
                    @unlink(self::dir() . '/' . $f['name']);
                }
            }
        }
    }

    /** Keep only the newest $keep pairs; delete older ones together. @return string[] deleted timestamps */
    public static function rotate(int $keep): array
    {
        $removed = [];
        foreach (array_slice(self::all(), max(1, $keep)) as $b) {
            foreach ($b['files'] as $f) {
                @unlink(self::dir() . '/' . $f['name']);
            }
            $removed[] = $b['stamp'];
        }
        return $removed;
    }

    /**
     * Existing backups, newest first: [['stamp', 'created' => 'YYYY-MM-DD HH:MM:SS', 'files' => [['name', 'kind'
     * => 'database'|'schema', 'bytes']]]]
     */
    public static function all(): array
    {
        $groups = [];
        foreach (is_dir(self::dir()) ? scandir(self::dir()) : [] as $name) {
            $path = self::dir() . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            if (preg_match(self::PATTERN, $name, $m)) {
                $stamp = $m[1];
                $sort = $stamp;
                $created = substr($stamp, 0, 10) . ' ' . str_replace('-', ':', substr($stamp, 11, 8));
            } elseif (preg_match(self::LEGACY_PATTERN, $name, $m)) {
                $stamp = $m[1] . '-' . $m[2];
                $created = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' '
                    . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
                $sort = str_replace([' ', ':'], ['_', '-'], $created);
            } else {
                continue;
            }
            $groups[$stamp]['stamp'] = $stamp;
            $groups[$stamp]['created'] = $created;
            $groups[$stamp]['sort'] = $sort;
            $groups[$stamp]['files'][] = [
                'name'  => $name,
                'kind'  => substr($name, -7) === '.sqlite' ? 'database' : 'schema',
                'bytes' => (int) filesize($path),
            ];
        }
        usort($groups, function ($a, $b) {
            return strcmp($b['sort'], $a['sort']);
        });
        return array_map(function ($g) {
            unset($g['sort']);
            usort($g['files'], function ($a, $b) {
                return strcmp($a['kind'], $b['kind']); // database before schema
            });
            return $g;
        }, $groups);
    }

    /**
     * Restore the backup pair $stamp as the active cms.sqlite + .puml (POST /api/_schema/restore). Beforehand the
     * current state itself is backed up as a new pair so that an accidental restore can be undone.
     * schema_ids/schema_layout are part of the database and therefore come back automatically.
     *
     * Procedure under bootstrap.lock (in the data directory) and the maintenance flag (Maintenance): back up the current state, check a copy
     * of the backed-up database (integrity_check, active schema present), write the .puml on a trial basis, then
     * replace the database file atomically (rename) - meanwhile this connection holds the write lock of the previous file
     * so that no other request sits on it in the middle of a transaction. If something fails before that, everything stays
     * unchanged.
     *
     * The .puml comes from the backup; if it does not match the schema of the backed-up database (e.g. changed via FTP
     * beforehand), the text stored in the database (_meta.schema_source) is written - this way /bootstrap reports
     * up_to_date afterwards. Older backups without a database cannot be restored (409).
     *
     * @param string|null $currentSchemaFile path of the currently active .puml (null if there is none)
     * @throws ApiException 404 backup_not_found, 409 locked/backup_incomplete/backup_invalid, 500 restore_failed
     */
    public static function restore(PDO $pdo, string $stamp, ?string $currentSchemaFile): array
    {
        $entry = null;
        foreach (self::all() as $b) {
            if ($b['stamp'] === $stamp) {
                $entry = $b;
            }
        }
        if ($entry === null) {
            throw ApiException::of(404, 'backup_not_found', Msg::of('msg_backup.not_found', "A backup '{stamp}' does not exist (any more).", [
                'stamp' => $stamp,
            ]));
        }
        $files = array_column($entry['files'], 'name', 'kind');
        if (!isset($files['database'])) {
            throw ApiException::of(409, 'backup_incomplete', Msg::of('msg_backup.incomplete', 'This backup contains no database (older '
                . 'backup of the .puml only) and cannot be restored automatically.'));
        }
        $lock = Config::lockFile();
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw ApiException::of(409, 'locked', Msg::of('msg_backup.locked', 'A bootstrap or a schema change is running right now '
                . '({lock}). Please wait a moment and try again.', ['lock' => Config::displayPath($lock)]));
        }
        fclose($handle);
        $dbFile = Config::dbFile();
        $tmpDb = $dbFile . '.restore';
        $tmpPuml = null;
        $safety = null;
        $replaced = false;
        Maintenance::begin();
        try {
            [$schemaName, $source] = self::checkBackup(self::dir() . '/' . $files['database'],
                isset($files['schema']) ? self::dir() . '/' . $files['schema'] : null, $tmpDb);
            $safety = self::create($pdo, (string) $currentSchemaFile, Msg::of('msg_backup.action_restore', '"Restore"'));
            $target = Config::schemaDir() . '/' . $schemaName;
            $tmpPuml = $target . '.restore';
            if (@file_put_contents($tmpPuml, $source) === false) {
                throw self::restoreFailed(Msg::of('msg_backup.restore_schema_write', 'The schema file cannot be written ({file} in the '
                    . 'folder schema/) - please check the write permissions.', ['file' => $schemaName]));
            }
            @chmod($tmpDb, is_file($dbFile) ? (fileperms($dbFile) & 0777) : 0644);
            $pdo->exec('BEGIN IMMEDIATE'); // write lock of the previous file until after the swap
            try {
                $replaced = @rename($tmpDb, $dbFile);
            } finally {
                $pdo->exec('ROLLBACK');
            }
            if (!$replaced) {
                throw self::restoreFailed(Msg::of('msg_backup.restore_db_replace', 'The database file {file} could not be '
                    . 'replaced (check the write permissions in {dir}).', ['file' => Config::displayPath($dbFile),
                    'dir' => Config::displayPath(Config::dataDir(), true)]));
            }
            // Bring the workflow file to the state of the restored database (its text is stored there in _meta); if that
            // does not work, the state of the database still applies - System -> Workflows then shows the difference
            try {
                $applied = Workflows::applied(Database::connect());
                if ($applied['file'] !== null && Workflows::ensureDir()) {
                    @file_put_contents(Workflows::dir() . '/' . basename($applied['file']), (string) $applied['source']);
                }
            } catch (\Throwable $e) {
                error_log('[LessHeadCMS] Workflow file after restore: ' . $e->getMessage());
            }
            $warning = null;
            if (!@rename($tmpPuml, $target)) {
                $warning = Msg::of('msg_backup.restore_file_warning', 'The database was restored, but the file {file} could not be '
                    . 'replaced. Please upload the text of the backup via FTP as schema/{file}, otherwise /bootstrap reports '
                    . 'migration_needed.', ['file' => $schemaName]);
            }
        } catch (\Throwable $e) {
            if ($safety !== null && !$replaced) {
                self::remove($safety['stamp']); // nothing has changed
            }
            throw $e;
        } finally {
            @unlink($tmpDb);
            if ($tmpPuml !== null) {
                @unlink($tmpPuml);
            }
            Maintenance::end();
            @unlink($lock);
        }
        return ['status' => 'restored'] + Msg::of('msg_backup.restored', 'Backup of {created} restored.', [
            'created' => $entry['created'],
        ])->fields() + [
            'restored' => ['stamp' => $entry['stamp'], 'created' => $entry['created'], 'schema' => $schemaName],
            'backup'   => ['stamp' => $safety['stamp'], 'files' => $safety['files'], 'dir' => Config::displayPath(self::dir(), true)],
        ] + ($warning !== null ? ['warning' => $warning->text(), 'warning_code' => $warning->codeOnly()] : []);
    }

    /**
     * Copy the backed-up database to $tmpDb and check it. @return array{0:string,1:string} name of the .puml in the folder
     * schema/ and its text (matching the schema of the backed-up database)
     */
    private static function checkBackup(string $db, ?string $puml, string $tmpDb): array
    {
        $invalid = function (Msg $why): ApiException {
            return ApiException::of(409, 'backup_invalid', Msg::of('msg_backup.invalid', 'The backup cannot be used: {reason} Nothing '
                . 'was changed.', ['reason' => $why]));
        };
        if (!@copy($db, $tmpDb) || !filesize($tmpDb)) {
            throw self::restoreFailed(Msg::of('msg_backup.restore_copy', 'The backed-up database could not be copied to {dir} (check '
                . 'disk space or write permissions).', ['dir' => Config::displayPath(Config::dataDir(), true)]));
        }
        try {
            $check = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ok = $check->query('PRAGMA integrity_check')->fetchColumn();
            $meta = Database::tableExists($check, '_meta')
                ? $check->query('SELECT "key", "value" FROM "_meta"')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            $check = null;
        } catch (\PDOException $e) {
            throw $invalid(Msg::of('msg_backup.invalid_sqlite', 'not a readable SQLite database.'));
        }
        if ($ok !== 'ok') {
            throw $invalid(Msg::of('msg_backup.invalid_integrity', 'The database is damaged (integrity_check).'));
        }
        if (empty($meta['schema_json']) || empty($meta['schema_hash'])) {
            throw $invalid(Msg::of('msg_backup.invalid_no_schema', 'The database contains no active schema.'));
        }
        $name = $meta['active_schema'] ?? null;
        if ($name === null && $puml !== null && preg_match(self::PATTERN, basename($puml), $m)) {
            $name = $m[2];
        }
        if (!is_string($name) || $name === '' || basename($name) !== $name || substr($name, -5) !== '.puml') {
            throw $invalid(Msg::of('msg_backup.invalid_name', 'The name of the schema file is unknown.'));
        }
        $source = $puml !== null ? (string) file_get_contents($puml) : null;
        if ($source === null || !hash_equals((string) $meta['schema_hash'], hash('sha256', $source))) {
            if (isset($meta['schema_source']) && hash_equals((string) $meta['schema_hash'], hash('sha256', $meta['schema_source']))) {
                $source = $meta['schema_source'];
            } elseif ($source === null) {
                throw $invalid(Msg::of('msg_backup.invalid_no_file', 'The schema file is missing in the backup.'));
            }
        }
        return [$name, $source];
    }

    private static function restoreFailed(Msg $message): ApiException
    {
        return ApiException::of(500, 'restore_failed', Msg::of('msg_backup.restore_failed', '{reason} Nothing was changed.', ['reason' => $message]));
    }

    /** Overview for System -> Schema: list, retention, database size and possibly a disk space notice */
    public static function overview(PDO $pdo): array
    {
        $keep = Settings::get($pdo, Settings::SCHEMA_BACKUP_KEEP);
        $size = is_file(Config::dbFile()) ? (int) filesize(Config::dbFile()) : 0;
        $warn = Settings::schemaBackupWarnBytes($pdo);
        $mb = function (int $bytes): array {
            return Msg::number($bytes / 1048576, $bytes >= 10 * 1048576 ? 0 : 1);
        };
        $warning = $size > $warn ? Msg::of('msg_backup.size_warning', 'The database is already {size} MB. Before every apply it is '
            . 'backed up completely; with {keep} kept backups these together take about {total} MB of disk space in '
            . '{dir}.', ['size' => $mb($size), 'keep' => $keep, 'total' => $mb($size * $keep), 'dir' => Config::displayPath(self::dir(), true)]) : null;
        return [
            'backups'      => self::all(),
            'keep'         => $keep,
            'dir'          => Config::displayPath(self::dir(), true),
            'db_bytes'     => $size,
            'warning'      => $warning !== null ? $warning->text() : null,
            'warning_code' => $warning !== null ? $warning->codeOnly() : null,
        ];
    }

    private static function failed(Msg $message, Msg $action): ApiException
    {
        return ApiException::of(500, 'backup_failed', Msg::of('msg_backup.failed', '{reason} {action} was aborted, nothing was changed '
            . 'in the database.', ['reason' => $message, 'action' => $action]));
    }
}
