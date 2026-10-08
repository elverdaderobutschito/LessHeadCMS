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
 * Central paths. Everything writable lives in data/ (lock file + SQLite file), plus the uploaded files of the media
 * library in media/ (deliberately public, see mediaDir()).
 */
final class Config
{
    /** @var string|null */
    private static $root = null;

    public static function init(string $root): void
    {
        self::$root = rtrim($root, '/\\');
    }

    public static function root(): string
    {
        if (self::$root === null) {
            throw new \LogicException('Config::init() wurde nicht aufgerufen.');
        }
        return self::$root;
    }

    public static function schemaDir(): string
    {
        return self::root() . '/schema';
    }

    /**
     * Language files (CSV) for the import under System -> Übersetzungen, filled via FTP (like schema/). Read-only and only
     * reachable through the admin API (TranslationCsv); not public, see translations/.htaccess.
     */
    public static function translationsDir(): string
    {
        return self::root() . '/translations';
    }

    public static function assetsDir(): string
    {
        return self::root() . '/assets';
    }

    public static function dataDir(): string
    {
        return self::root() . '/data';
    }

    public static function lockFile(): string
    {
        return self::dataDir() . '/bootstrap.lock';
    }

    public static function sessionDir(): string
    {
        return self::dataDir() . '/sessions';
    }

    /** Placeholder from config.example.php - counts as "not configured". */
    public const TOKEN_PLACEHOLDER = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

    /**
     * BOOTSTRAP_TOKEN from config.php (define). null if config.php is missing, the token is missing,
     * is shorter than 16 characters or is still the placeholder from config.example.php.
     */
    public static function bootstrapToken(): ?string
    {
        self::loadConfigFile();
        if (!defined('BOOTSTRAP_TOKEN')) {
            return null;
        }
        $token = constant('BOOTSTRAP_TOKEN');
        if (!is_string($token) || strlen($token) < 16 || $token === self::TOKEN_PLACEHOLDER) {
            return null;
        }
        return $token;
    }

    /** Read config.php (optional): defines BOOTSTRAP_TOKEN and possibly MEDIA_MAX_MB. */
    private static function loadConfigFile(): void
    {
        $file = self::root() . '/config.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    /** Default size limit per upload in MB if config.php does not define MEDIA_MAX_MB. */
    public const MEDIA_MAX_MB_DEFAULT = 20;

    /**
     * Size limit per upload in bytes according to the configuration (MEDIA_MAX_MB in config.php, otherwise
     * MEDIA_MAX_MB_DEFAULT). The PHP limits upload_max_filesize/post_max_size may actually be lower, see Media::limits().
     */
    public static function mediaMaxBytes(): int
    {
        self::loadConfigFile();
        $mb = defined('MEDIA_MAX_MB') ? constant('MEDIA_MAX_MB') : self::MEDIA_MAX_MB_DEFAULT;
        if (!is_int($mb) && !is_float($mb) && !(is_string($mb) && is_numeric($mb))) {
            $mb = self::MEDIA_MAX_MB_DEFAULT;
        }
        return max(1, (int) round((float) $mb * 1024 * 1024));
    }

    /** Backup pairs kept before schema changes (SchemaBackup) if config.php does not set SCHEMA_BACKUP_KEEP */
    public const SCHEMA_BACKUP_KEEP_DEFAULT = 5;
    /** From this database size (MB) on, System -> Schema points out the storage needed for the backups */
    public const SCHEMA_BACKUP_WARN_MB_DEFAULT = 50;

    /** Number of backup pairs kept (SCHEMA_BACKUP_KEEP in config.php, at least 1) */
    public static function schemaBackupKeep(): int
    {
        self::loadConfigFile();
        $n = defined('SCHEMA_BACKUP_KEEP') ? constant('SCHEMA_BACKUP_KEEP') : self::SCHEMA_BACKUP_KEEP_DEFAULT;
        return is_numeric($n) ? max(1, (int) $n) : self::SCHEMA_BACKUP_KEEP_DEFAULT;
    }

    /** Threshold for the disk space notice in bytes (SCHEMA_BACKUP_WARN_MB in config.php) */
    public static function schemaBackupWarnBytes(): int
    {
        self::loadConfigFile();
        $mb = defined('SCHEMA_BACKUP_WARN_MB') ? constant('SCHEMA_BACKUP_WARN_MB') : self::SCHEMA_BACKUP_WARN_MB_DEFAULT;
        return (int) round((is_numeric($mb) ? (float) $mb : self::SCHEMA_BACKUP_WARN_MB_DEFAULT) * 1048576);
    }

    /**
     * Upper limit for the raw data of one list page (bytes of the field values, before conversion to JSON) above which
     * GET /api/{entity} answers with 413 instead of exhausting PHP memory (Cms::page()). Default: one tenth of the
     * memory_limit still free (building the JSON and PHP arrays need a multiple of the raw data), at most 16 MB, at least
     * 1 MB. Can be fixed with LIST_MAX_BYTES in config.php.
     */
    public static function listMaxBytes(): int
    {
        self::loadConfigFile();
        if (defined('LIST_MAX_BYTES') && is_numeric(constant('LIST_MAX_BYTES')) && constant('LIST_MAX_BYTES') > 0) {
            return (int) constant('LIST_MAX_BYTES');
        }
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = (int) $limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
        }
        $max = 16 * 1048576;
        if ($bytes <= 0) {
            return $max; // no limit set
        }
        return (int) max(1048576, min($max, ($bytes - memory_get_usage(true)) / 10));
    }

    /**
     * For tests only: artificial delay (milliseconds, at most 30 s) in SchemaMigration::apply() while the maintenance
     * flag is set, so that a parallel write access can be tested reliably (TEST_MIGRATION_DELAY_MS in config.php; 0 if
     * not given). Never set this on a real installation.
     */
    public static function testMigrationDelayMs(): int
    {
        self::loadConfigFile();
        $ms = defined('TEST_MIGRATION_DELAY_MS') ? constant('TEST_MIGRATION_DELAY_MS') : 0;
        return is_numeric($ms) ? max(0, min(30000, (int) $ms)) : 0;
    }

    /**
     * Uploaded files of the media library. Deliberately NOT located under data/ (everything is blocked there) but next
     * to it, and served directly by Apache (.htaccess: RewriteRule ^media/) so that a consuming frontend can use the URL
     * without a detour through PHP. A separate media/.htaccess prevents any script execution there (see ensureMediaDir()).
     */
    public static function mediaDir(): string
    {
        return self::root() . '/media';
    }

    /** Content of media/.htaccess - identical to backend/media/.htaccess (which is shipped there as well). */
    public const MEDIA_HTACCESS = <<<'HTACCESS'
# Mediathek: hochgeladene Dateien werden direkt von Apache ausgeliefert, aber nie ausgeführt.
# Verteidigung in der Tiefe - die Dateinamen erzeugt ohnehin das CMS (zufällig, nur Endungen aus einer festen Liste).
Options -Indexes -ExecCGI

# Keine Skript-Handler in diesem Verzeichnis (PHP als Apache-Modul bzw. per CGI/FPM-Handler)
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pht .phps .cgi .pl .py .sh .shtml
RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pht .phps

# Ausführbare bzw. serverseitig interpretierte Endungen gar nicht erst ausliefern (auch nicht als Quelltext)
<FilesMatch "(?i)\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|shtml|asp|aspx|jsp|htaccess|htpasswd|ini)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_headers.c>
    # Browser sollen den Typ nicht aus dem Inhalt raten (Content-Type kommt aus der geprüften Endung)
    Header set X-Content-Type-Options "nosniff"
    # SVG kann Skripte enthalten: direkt aufgerufen ohne jede Skriptausführung (in <img> laufen ohnehin keine)
    <FilesMatch "(?i)\.svg$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"
    </FilesMatch>
</IfModule>

HTACCESS;

    /** Creates media/ (incl. .htaccess) if it is missing; throws if it is not writable. */
    public static function ensureMediaDir(): void
    {
        $dir = self::mediaDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Verzeichnis media/ kann nicht angelegt werden.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::MEDIA_HTACCESS);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Verzeichnis media/ ist nicht beschreibbar.');
        }
    }

    public static function dbFile(): string
    {
        return self::dataDir() . '/cms.sqlite';
    }

    /** Creates data/ (incl. access protection for Apache) if it is missing. */
    public static function ensureDataDir(): void
    {
        $dir = self::dataDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Verzeichnis data/ kann nicht angelegt werden.');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
            );
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Verzeichnis data/ ist nicht beschreibbar.');
        }
    }
}
