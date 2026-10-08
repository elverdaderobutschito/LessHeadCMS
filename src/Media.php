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
 * Global media library (system level like users, independent of the PlantUML diagram): images, videos, audio, documents.
 * Metadata in the system tables media, media_tags, media_tag_links; the files themselves in media/ next to index.php,
 * served directly by Apache (Config::mediaDir()). Entities reference it via fields of type `media` (PumlParser,
 * link tables `<table>_<field>_media`, read/written in Cms).
 *
 * Upload security (the files are publicly reachable):
 *  - only extensions from TYPES, and the MIME type determined from the content via finfo must match the extension (a
 *    "bild.jpg" that is really a script is rejected); SVG additionally without scripts/event handlers
 *  - stored under a random name (32 hex characters + checked extension), the original name stays display
 *    metadata only - no path traversal, no collision, no script file name
 *  - media/.htaccess switches off any script execution (Config::MEDIA_HTACCESS)
 *  - size limit (Config::mediaMaxBytes(), plus the PHP limits) with a comprehensible 422
 *
 * Thumbnails (Thumbnail): for raster images, media/<name>_thumb.<extension> (at most 200 × 200) is additionally created
 * on upload. Column thumb_filename: NULL = not tried yet (existing data from before the feature, or GD was missing),
 * '' = none (not needed or not possible -> the original is the preview), otherwise the file name. Existing images get
 * their thumbnail later when the media library list is called, with a time budget per request (backfillThumbnails()).
 * ensureTables() adds the column via ALTER TABLE on existing installations.
 *
 * Tags can be renamed and deleted (renameTag(), deleteTag()), the file of a medium can be replaced (replaceFile()).
 *
 * The tables are created by /bootstrap (Auth::provision()) or by the first call of the media library API
 * (ensureTables()) - an existing installation therefore does not need another bootstrap.
 */
final class Media
{
    /**
     * Allowed extensions: kind, MIME type under which the file is stored/served, and the MIME types finfo
     * can return for a genuine file of this kind (differs depending on the libmagic version).
     */
    public const TYPES = [
        'jpg'  => ['image', 'image/jpeg', ['image/jpeg', 'image/pjpeg']],
        'jpeg' => ['image', 'image/jpeg', ['image/jpeg', 'image/pjpeg']],
        'png'  => ['image', 'image/png', ['image/png']],
        'gif'  => ['image', 'image/gif', ['image/gif']],
        'webp' => ['image', 'image/webp', ['image/webp']],
        'svg'  => ['image', 'image/svg+xml', ['image/svg+xml', 'image/svg', 'text/xml', 'application/xml', 'text/plain', 'text/html']],
        'mp4'  => ['video', 'video/mp4', ['video/mp4', 'video/x-m4v', 'application/mp4', 'video/quicktime']],
        'webm' => ['video', 'video/webm', ['video/webm', 'audio/webm']],
        'mp3'  => ['audio', 'audio/mpeg', ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/mpeg3']],
        'wav'  => ['audio', 'audio/wav', ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave']],
        'ogg'  => ['audio', 'audio/ogg', ['audio/ogg', 'application/ogg', 'audio/x-ogg', 'video/ogg']],
        'pdf'  => ['document', 'application/pdf', ['application/pdf', 'application/x-pdf']],
        'doc'  => ['document', 'application/msword', ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage']],
        'xls'  => ['document', 'application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/msword']],
        'ppt'  => ['document', 'application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/msword']],
        'docx' => ['document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xlsx' => ['document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'pptx' => ['document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'txt'  => ['document', 'text/plain', ['text/plain']],
        'csv'  => ['document', 'text/csv', ['text/csv', 'text/plain', 'application/csv', 'text/x-csv']],
    ];

    /** Office Open XML (ZIP): folder that must occur in the archive - an arbitrary ZIP is not enough */
    private const OOXML_DIR = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];

    public const KINDS = ['image', 'video', 'audio', 'document'];

    private const DDL = [
        'media' => 'CREATE TABLE IF NOT EXISTS media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename VARCHAR NOT NULL UNIQUE,
  original_name VARCHAR NOT NULL,
  mime_type VARCHAR NOT NULL,
  kind VARCHAR NOT NULL CHECK (kind IN (\'image\', \'video\', \'audio\', \'document\')),
  extension VARCHAR NOT NULL,
  size_bytes INTEGER NOT NULL,
  title VARCHAR,
  alt_text VARCHAR,
  description TEXT,
  width INTEGER,
  height INTEGER,
  thumb_filename VARCHAR,
  uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
)',
        'media_tags' => 'CREATE TABLE IF NOT EXISTS media_tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR NOT NULL UNIQUE COLLATE NOCASE
)',
        'media_tag_links' => 'CREATE TABLE IF NOT EXISTS media_tag_links (
  media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
  tag_id INTEGER NOT NULL REFERENCES media_tags(id) ON DELETE CASCADE,
  PRIMARY KEY (media_id, tag_id)
)',
    ];

    private const MAX_TITLE = 255;
    private const MAX_ALT = 1000;
    private const MAX_DESCRIPTION = 20000;
    private const MAX_TAG = 100;

    /** Later thumbnails per list call: at most this many or this long (shared hosting: execution time limit) */
    private const BACKFILL_MAX = 25;
    private const BACKFILL_SECONDS = 3.0;

    /** @var PDO */
    private $pdo;
    /** @var array */
    private $model;
    /** @var array logged-in user (Auth::requireSession()) */
    private $user;

    public function __construct(PDO $pdo, array $model, array $user)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->user = $user;
        self::ensureTables($pdo);
    }

    /** @return string[] newly created tables */
    public static function ensureTables(PDO $pdo): array
    {
        $created = [];
        foreach (self::DDL as $table => $ddl) {
            if (!Database::tableExists($pdo, $table)) {
                $pdo->exec($ddl);
                $created[] = $table;
            }
        }
        // installations from before the thumbnails: add the column (existing data stays NULL = not tried yet)
        $columns = array_column($pdo->query('PRAGMA table_info(media)')->fetchAll(), 'name');
        if (!in_array('thumb_filename', $columns, true)) {
            $pdo->exec('ALTER TABLE media ADD COLUMN thumb_filename VARCHAR');
        }
        return $created;
    }

    // ---------------------------------------------------------------- Reading

    /**
     * List, newest first. Filters: q (text in title, description, alt text, original name), kind, tag (ID or name).
     *
     * @param array $query $_GET
     */
    public function all(array $query): array
    {
        $where = [];
        $params = [];
        $q = trim((string) (is_string($query['q'] ?? null) ? $query['q'] : ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = "(m.title LIKE ? ESCAPE '\\' OR m.description LIKE ? ESCAPE '\\' OR m.alt_text LIKE ? ESCAPE '\\' "
                . "OR m.original_name LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like, $like);
        }
        $kind = $query['kind'] ?? null;
        if (is_string($kind) && $kind !== '') {
            if (!in_array($kind, self::KINDS, true)) {
                throw new ApiException(422, [
                    'error' => 'validation', 'message' => 'Unbekannte Art (erlaubt: ' . implode(', ', self::KINDS) . ')',
                    'errors' => ['kind' => 'Unbekannte Art'],
                ]);
            }
            $where[] = 'm.kind = ?';
            $params[] = $kind;
        }
        $tag = $query['tag'] ?? null;
        if (is_string($tag) && $tag !== '') {
            $where[] = ctype_digit($tag)
                ? 'm.id IN (SELECT media_id FROM media_tag_links WHERE tag_id = ?)'
                : 'm.id IN (SELECT l.media_id FROM media_tag_links l JOIN media_tags t ON t.id = l.tag_id WHERE t.name = ?)';
            $params[] = ctype_digit($tag) ? (int) $tag : $tag;
        }
        $this->backfillThumbnails();
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.username AS uploader_username, u.name AS uploader_name FROM media m '
            . 'LEFT JOIN users u ON u.id = m.uploaded_by' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY m.id DESC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $tags = $this->tagsByMedia();
        $usage = $this->usageCounts();
        return array_map(function ($row) use ($tags, $usage) {
            return $this->detail($row, $tags[(int) $row['id']] ?? [], $usage[(int) $row['id']] ?? 0);
        }, $rows);
    }

    public function find(int $id): array
    {
        $row = $this->row($id);
        return $this->detail($row, $this->tagsByMedia($id)[$id] ?? [], $this->usageCounts()[$id] ?? 0);
    }

    /** @return array{id:int,name:string,count:int}[] all tags with the number of media, alphabetically */
    public function tags(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.id, t.name, COUNT(l.media_id) AS count FROM media_tags t '
            . 'LEFT JOIN media_tag_links l ON l.tag_id = t.id GROUP BY t.id ORDER BY t.name COLLATE NOCASE'
        )->fetchAll();
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['count']];
        }, $rows);
    }

    /** Create a tag (body {"name": "..."}); if it already exists (case-insensitive), the existing one is returned. */
    public function createTag(array $input): array
    {
        $name = $input['name'] ?? null;
        $error = self::tagNameError($name);
        if ($error !== null) {
            throw new ApiException(422, ['error' => 'validation', 'message' => $error, 'errors' => ['name' => $error]]);
        }
        $id = $this->tagIds([trim($name)])[0];
        return ['id' => $id, 'name' => $this->pdo->query('SELECT name FROM media_tags WHERE id = ' . $id)->fetchColumn()];
    }

    /**
     * Rename a tag (body {"name": "..."}): the links are bound to the ID, all media show the new
     * name afterwards. If another tag already has that name (case-insensitive), 422 - nothing is merged.
     */
    public function renameTag(int $id, array $input): array
    {
        $this->tagRow($id);
        $name = $input['name'] ?? null;
        $error = self::tagNameError($name);
        if ($error === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM media_tags WHERE name = ? AND id <> ?'); // column is COLLATE NOCASE
            $stmt->execute([trim($name), $id]);
            if ($stmt->fetchColumn() !== false) {
                $error = 'Es gibt bereits einen Tag mit diesem Namen';
            }
        }
        if ($error !== null) {
            throw new ApiException(422, ['error' => 'validation', 'message' => $error, 'errors' => ['name' => $error]]);
        }
        $this->pdo->prepare('UPDATE media_tags SET name = ? WHERE id = ?')->execute([trim($name), $id]);
        return $this->tagRow($id);
    }

    /**
     * Delete a tag, including all assignments to media; the media themselves stay. removed_links = number of media that
     * had the tag.
     */
    public function deleteTag(int $id): array
    {
        $tag = $this->tagRow($id);
        $this->pdo->beginTransaction();
        try {
            // explicitly, not just via ON DELETE CASCADE (only takes effect with PRAGMA foreign_keys)
            $this->pdo->prepare('DELETE FROM media_tag_links WHERE tag_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM media_tags WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['deleted' => $id, 'name' => $tag['name'], 'removed_links' => $tag['count']];
    }

    /** @return array{id:int,name:string,count:int} */
    private function tagRow(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, (SELECT COUNT(*) FROM media_tag_links l WHERE l.tag_id = t.id) AS count FROM media_tags t WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Tag nicht gefunden']);
        }
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'count' => (int) $row['count']];
    }

    /**
     * Limits for the UI (upload field, pre-check): effective size limit (minimum of the configuration and the
     * PHP limits) and the allowed extensions per kind.
     */
    public static function limits(): array
    {
        $configured = Config::mediaMaxBytes();
        $upload = self::iniBytes('upload_max_filesize');
        $post = self::iniBytes('post_max_size');
        $effective = $configured;
        foreach ([$upload, $post] as $php) {
            if ($php !== null && $php > 0) {
                $effective = min($effective, $php);
            }
        }
        $extensions = [];
        foreach (self::TYPES as $ext => [$kind]) {
            $extensions[$kind][] = $ext;
        }
        return [
            'max_bytes'               => $effective,
            'thumbnails_available'    => Thumbnail::available(), // GD available? Otherwise the preview always shows the original
            'configured_max_bytes'    => $configured,
            'php_upload_max_filesize' => $upload,
            'php_post_max_size'       => $post,
            'extensions'              => $extensions,
        ];
    }

    // ---------------------------------------------------------------- Writing

    /**
     * Accept and check an uploaded file (field "file") - shared by upload() and replaceFile(): PHP upload errors,
     * size limit, extension from TYPES, content matches the extension (inspect()). Afterwards the file is still in the
     * temporary directory.
     *
     * @return array{tmp:string,size:int,original:string,ext:string,kind:string,mime:string,width:?int,height:?int}
     */
    private static function receive(array $files): array
    {
        $limits = self::limits();
        $file = $files['file'] ?? null;
        if ($file === null) {
            // If the whole request is larger than post_max_size, PHP discards $_POST and $_FILES completely - then name the
            // actual cause here instead of "no file"
            $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax = $limits['php_post_max_size'];
            if ($postMax !== null && $postMax > 0 && $length > $postMax) {
                throw self::tooLarge($limits, $length, 'post_max_size');
            }
            throw self::invalid('file', 'Keine Datei übertragen (Feld "file" im multipart/form-data-Request).');
        }
        if (is_array($file['error'] ?? null)) {
            throw self::invalid('file', 'Bitte genau eine Datei je Upload senden.');
        }
        switch ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw self::tooLarge($limits, null, 'upload_max_filesize');
            case UPLOAD_ERR_PARTIAL:
                throw self::invalid('file', 'Die Datei wurde nur teilweise übertragen. Bitte erneut versuchen.');
            case UPLOAD_ERR_NO_FILE:
                throw self::invalid('file', 'Keine Datei ausgewählt.');
            default:
                // NO_TMP_DIR, CANT_WRITE, EXTENSION: server configuration, not the user
                throw new ApiException(500, [
                    'error'   => 'upload_failed',
                    'message' => 'Der Server konnte die Datei nicht annehmen (PHP-Upload-Fehler ' . (int) $file['error']
                        . ', z. B. temporäres Verzeichnis fehlt oder nicht beschreibbar).',
                ]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw self::invalid('file', 'Keine gültige hochgeladene Datei.');
        }
        $size = (int) filesize($tmp);
        if ($size > $limits['max_bytes']) {
            throw self::tooLarge($limits, $size, null);
        }
        if ($size === 0) {
            throw self::invalid('file', 'Die Datei ist leer.');
        }

        $original = self::cleanOriginalName((string) ($file['name'] ?? ''));
        $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        [$kind, $mime, $width, $height] = self::inspect($tmp, $ext);

        return ['tmp' => $tmp, 'size' => $size, 'original' => $original, 'ext' => $ext, 'kind' => $kind, 'mime' => $mime,
            'width' => $width, 'height' => $height];
    }

    /**
     * Move the checked file to media/ under a new random name and - for raster images - create the
     * thumbnail: [file name, thumb_filename]. If the thumbnail fails, it stays '' (original as
     * preview); without GD it stays NULL (not tried) so that backfillThumbnails() catches up as soon as GD is available.
     *
     * @return array{0:string,1:?string}
     */
    private static function store(string $tmp, string $ext): array
    {
        Config::ensureMediaDir();
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = Config::mediaDir() . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('Hochgeladene Datei kann nicht nach media/ verschoben werden.');
        }
        @chmod($target, 0644);
        $thumb = !Thumbnail::applies($ext) ? '' : (Thumbnail::available() ? Thumbnail::create(Config::mediaDir(), $filename) : null);
        return [$filename, $thumb];
    }

    /** Remove file and thumbnail from media/ (missing files are not an error) */
    private static function removeFiles(string $filename, ?string $thumb): void
    {
        foreach ([$filename, (string) $thumb] as $name) {
            $file = Config::mediaDir() . '/' . $name;
            if ($name !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Upload (multipart/form-data): file, optionally title, alt_text, description, tag_ids[] (existing tags) and/or
     * tags[] (names, missing ones are created). width/height are read from the file, never from the request.
     */
    public function upload(array $post, array $files): array
    {
        $file = self::receive($files);
        ['size' => $size, 'original' => $original, 'ext' => $ext, 'kind' => $kind, 'mime' => $mime, 'width' => $width, 'height' => $height] = $file;

        $errors = [];
        $meta = $this->collectMeta($post, $errors);
        $tagIds = $this->collectTags($post, $errors);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }

        [$filename, $thumb] = self::store($file['tmp'], $ext);
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                'INSERT INTO media (filename, original_name, mime_type, kind, extension, size_bytes, title, alt_text,
                                    description, width, height, thumb_filename, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $filename, $original, $mime, $kind, $ext, $size, $meta['title'] ?? null, $meta['alt_text'] ?? null,
                $meta['description'] ?? null, $width, $height, $thumb, $this->user['id'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->setTags($id, $tagIds ?? []);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            self::removeFiles($filename, $thumb);
            throw $e;
        }
        return $this->find($id);
    }

    /**
     * Replace the file of an existing medium (multipart/form-data, field "file"). The row - and with it id, title,
     * alt text, description, tags and all links to records - stays; filename, original_name,
     * mime_type, kind, extension, size_bytes, width, height and the thumbnail are replaced. The same checks as for the
     * upload (receive()). The new file gets a new random name (also so that browsers and caches do not keep showing the
     * old one under the same URL); the old file including its thumbnail is only removed after the successful UPDATE -
     * if something fails, the medium stays unchanged.
     */
    public function replaceFile(int $id, array $files): array
    {
        $old = $this->row($id);
        $file = self::receive($files);
        [$filename, $thumb] = self::store($file['tmp'], $file['ext']);
        try {
            $this->pdo->prepare(
                'UPDATE media SET filename = ?, original_name = ?, mime_type = ?, kind = ?, extension = ?, size_bytes = ?,
                                  width = ?, height = ?, thumb_filename = ? WHERE id = ?'
            )->execute([
                $filename, $file['original'], $file['mime'], $file['kind'], $file['ext'], $file['size'], $file['width'],
                $file['height'], $thumb, $id,
            ]);
        } catch (\Throwable $e) {
            self::removeFiles($filename, $thumb);
            throw $e;
        }
        self::removeFiles((string) $old['filename'], $old['thumb_filename'] ?? '');
        return $this->find($id);
    }

    /** Change metadata (JSON): title, alt_text, description, tag_ids and/or tags - only keys sent along. */
    public function update(int $id, array $input): array
    {
        $this->row($id);
        $errors = [];
        $meta = $this->collectMeta($input, $errors);
        $tagIds = $this->collectTags($input, $errors);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        $this->pdo->beginTransaction();
        try {
            if ($meta) {
                $set = implode(', ', array_map(function ($c) {
                    return "$c = ?";
                }, array_keys($meta)));
                $this->pdo->prepare("UPDATE media SET $set WHERE id = ?")->execute(array_merge(array_values($meta), [$id]));
            }
            if ($tagIds !== null) {
                $this->setTags($id, $tagIds);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    /**
     * Delete. If the medium is still used by a record field, 409 with the places of use (analogous to the
     * restrict logic in Cms::delete()); otherwise remove the row (tag links go along via CASCADE) and the file.
     */
    public function delete(int $id): array
    {
        $row = $this->row($id);
        $usages = $this->usages($id);
        if ($usages) {
            $parts = [];
            $dependents = [];
            foreach ($usages as $u) {
                $parts[] = count($u['ids']) . "× in {$u['entity_name']}.{$u['field']} (" . implode(', ', array_map(function ($i) {
                    return "#$i";
                }, array_slice($u['ids'], 0, 10))) . (count($u['ids']) > 10 ? ', …' : '') . ')';
                $dependents["{$u['entity']}.{$u['field']}"] = count($u['ids']);
            }
            throw new ApiException(409, [
                'error'      => 'in_use',
                'message'    => 'Kann nicht gelöscht werden, das Medium wird noch verwendet: ' . implode(', ', $parts) . '.',
                'dependents' => $dependents,
                'usages'     => $usages,
            ]);
        }
        try {
            $this->pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            // newly linked in parallel (the FK of the link table kicks in)
            if ((string) $e->getCode() === '23000') {
                throw new ApiException(409, ['error' => 'in_use', 'message' => 'Das Medium wird noch verwendet.']);
            }
            throw $e;
        }
        self::removeFiles((string) $row['filename'], $row['thumb_filename'] ?? '');
        return ['deleted' => $id];
    }

    // ---------------------------------------------------------------- Presentation (also for Cms)

    /**
     * Public representation of a medium as it also appears in records (fields of type media) - without
     * user data. url is absolute (for an external frontend), path relative to the CMS directory. thumbnail_url/-path:
     * thumbnail (at most 200 × 200), null = none (not a raster image, original already small, creation not possible
     * or not caught up yet) - then use the original.
     */
    public static function present(array $row): array
    {
        $thumb = (string) ($row['thumb_filename'] ?? '');
        return [
            'id'            => (int) $row['id'],
            'url'           => self::baseUrl() . '/media/' . $row['filename'],
            'path'          => 'media/' . $row['filename'],
            'filename'      => (string) $row['filename'],
            'original_name' => (string) $row['original_name'],
            'mime_type'     => (string) $row['mime_type'],
            'kind'          => (string) $row['kind'],
            'extension'     => (string) $row['extension'],
            'size_bytes'    => (int) $row['size_bytes'],
            'title'         => $row['title'] !== null ? (string) $row['title'] : null,
            'alt_text'      => $row['alt_text'] !== null ? (string) $row['alt_text'] : null,
            'description'   => $row['description'] !== null ? (string) $row['description'] : null,
            'width'         => $row['width'] !== null ? (int) $row['width'] : null,
            'height'        => $row['height'] !== null ? (int) $row['height'] : null,
            'thumbnail_url'  => $thumb !== '' ? self::baseUrl() . '/media/' . $thumb : null,
            'thumbnail_path' => $thumb !== '' ? 'media/' . $thumb : null,
        ];
    }

    /** Absolute base URL of the CMS (scheme, host, subfolder) without a trailing "/" */
    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'localhost'; // a broken/manipulated Host header does not end up in the URL
        }
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $dir = rtrim($dir, '/.');
        return ($https ? 'https' : 'http') . '://' . $host . $dir;
    }

    // ---------------------------------------------------------------- Internal

    /**
     * Add thumbnails later to existing images (thumb_filename NULL, i.e. from before the thumbnails) -
     * oldest first, per call at most BACKFILL_MAX images or BACKFILL_SECONDS seconds so that even a large
     * stock does not hit an execution time limit on shared hosting; the rest follows on the next calls. Every image
     * tried gets a value ('' on failure too), so it is never tried a second time.
     */
    private function backfillThumbnails(): void
    {
        if (!Thumbnail::available()) {
            return; // without GD do not mark anything as "tried" - it is caught up as soon as GD is available
        }
        $exts = [];
        foreach (array_keys(self::TYPES) as $ext) {
            if (Thumbnail::applies($ext)) {
                $exts[] = $ext;
            }
        }
        $marks = implode(', ', array_fill(0, count($exts), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, filename FROM media WHERE thumb_filename IS NULL AND extension IN ($marks) ORDER BY id LIMIT "
            . self::BACKFILL_MAX
        );
        $stmt->execute($exts);
        $update = $this->pdo->prepare('UPDATE media SET thumb_filename = ? WHERE id = ?');
        $start = microtime(true);
        foreach ($stmt->fetchAll() as $r) {
            if (microtime(true) - $start > self::BACKFILL_SECONDS) {
                break;
            }
            $update->execute([Thumbnail::create(Config::mediaDir(), $r['filename']), $r['id']]);
        }
        // non-raster images (SVG, video, ...) do not need one: mark once as "none"
        $this->pdo->prepare("UPDATE media SET thumb_filename = '' WHERE thumb_filename IS NULL AND extension NOT IN ($marks)")
            ->execute($exts);
    }

    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.username AS uploader_username, u.name AS uploader_name FROM media m '
            . 'LEFT JOIN users u ON u.id = m.uploaded_by WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Medium nicht gefunden']);
        }
        return $row;
    }

    /** Representation for the (authenticated) media library: public fields + tags, uploaded by/at, usages */
    private function detail(array $row, array $tags, int $usage): array
    {
        return self::present($row) + [
            'tags'        => $tags,
            'uploaded_by' => $row['uploaded_by'] !== null ? [
                'id'       => (int) $row['uploaded_by'],
                'username' => (string) ($row['uploader_username'] ?? ''),
                'name'     => (string) ($row['uploader_name'] ?? ''),
            ] : null,
            'uploaded_at' => $row['uploaded_at'],
            'usage_count' => $usage,
        ];
    }

    /** @return array<int,array{id:int,name:string}[]> medium ID => tags (alphabetically) */
    private function tagsByMedia(?int $only = null): array
    {
        $sql = 'SELECT l.media_id, t.id, t.name FROM media_tag_links l JOIN media_tags t ON t.id = l.tag_id'
            . ($only !== null ? ' WHERE l.media_id = ' . $only : '') . ' ORDER BY t.name COLLATE NOCASE';
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $r) {
            $out[(int) $r['media_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** Media fields of all entities of the current model: [[table, entity name, field entry], ...] */
    private function mediaFields(): array
    {
        $out = [];
        foreach ($this->model['entities'] as $table => $e) {
            foreach ($e['media'] ?? [] as $m) {
                $out[] = [$table, $e['name'], $m];
            }
        }
        return $out;
    }

    /** @return array<int,int> medium ID => number of usages (across all media fields) */
    private function usageCounts(): array
    {
        $counts = [];
        foreach ($this->mediaFields() as [, , $m]) {
            $rows = $this->pdo->query(
                'SELECT "media_id", COUNT(*) FROM ' . SqlGenerator::q($m['junction']) . ' GROUP BY "media_id"'
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$mediaId, $n]) {
                $counts[(int) $mediaId] = ($counts[(int) $mediaId] ?? 0) + (int) $n;
            }
        }
        return $counts;
    }

    /** Places of use of a medium: [['entity', 'entity_name', 'field', 'ids' => [...]], ...] */
    private function usages(int $id): array
    {
        $out = [];
        foreach ($this->mediaFields() as [$table, $name, $m]) {
            $stmt = $this->pdo->prepare(
                'SELECT ' . SqlGenerator::q($m['own_column']) . ' FROM ' . SqlGenerator::q($m['junction'])
                . ' WHERE "media_id" = ? ORDER BY 1'
            );
            $stmt->execute([$id]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $out[] = ['entity' => $table, 'entity_name' => $name, 'field' => $m['name'], 'ids' => $ids];
            }
        }
        return $out;
    }

    /**
     * title/alt_text/description from the body (only keys present; empty -> NULL).
     *
     * @return array<string,?string>
     */
    private function collectMeta(array $input, array &$errors): array
    {
        $meta = [];
        foreach (['title' => self::MAX_TITLE, 'alt_text' => self::MAX_ALT, 'description' => self::MAX_DESCRIPTION] as $key => $max) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            if ($v !== null && !is_string($v)) {
                $errors[$key] = 'Text erwartet';
                continue;
            }
            $v = $v === null ? '' : trim($v);
            if (mb_strlen($v, 'UTF-8') > $max) {
                $errors[$key] = "Höchstens $max Zeichen";
                continue;
            }
            $meta[$key] = $v === '' ? null : $v;
        }
        return $meta;
    }

    /**
     * tag_ids (existing tags) and tags (names; missing ones are created when saving) into one ID list. null =
     * none of the keys sent (tags stay unchanged). Multipart sends tag_ids[] as an array or - with
     * only one field - possibly as comma-separated text.
     *
     * @return int[]|null
     */
    private function collectTags(array $input, array &$errors): ?array
    {
        if (!array_key_exists('tag_ids', $input) && !array_key_exists('tags', $input)) {
            return null;
        }
        $ids = [];
        $list = function ($v) {
            if ($v === null || $v === '') {
                return [];
            }
            return is_array($v) ? $v : (is_string($v) ? explode(',', $v) : [$v]);
        };
        foreach ($list($input['tag_ids'] ?? null) as $v) {
            if (!is_int($v) && !(is_string($v) && ctype_digit(trim($v)))) {
                $errors['tag_ids'] = 'Liste von Tag-IDs erwartet';
                return null;
            }
            $ids[(int) $v] = true;
        }
        if ($ids) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT id FROM media_tags WHERE id IN ($marks)");
            $stmt->execute(array_keys($ids));
            $missing = array_diff(array_keys($ids), array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            if ($missing) {
                $errors['tag_ids'] = 'Unbekannte Tag-ID(s): ' . implode(', ', $missing);
                return null;
            }
        }
        $names = [];
        foreach ($list($input['tags'] ?? null) as $v) {
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            $error = self::tagNameError($v);
            if ($error !== null) {
                $errors['tags'] = $error;
                return null;
            }
            $names[] = trim($v);
        }
        foreach ($names ? $this->tagIds($names) : [] as $id) {
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @param mixed $name */
    private static function tagNameError($name): ?string
    {
        if (!is_string($name) || trim($name) === '') {
            return 'Tag-Name erwartet';
        }
        if (mb_strlen(trim($name), 'UTF-8') > self::MAX_TAG) {
            return 'Tag-Name höchstens ' . self::MAX_TAG . ' Zeichen';
        }
        return null;
    }

    /**
     * IDs for tag names, missing tags are created (the comparison is case-insensitive).
     *
     * @param string[] $names
     * @return int[]
     */
    private function tagIds(array $names): array
    {
        $ids = [];
        $find = $this->pdo->prepare('SELECT id FROM media_tags WHERE name = ?'); // column is COLLATE NOCASE
        $insert = $this->pdo->prepare('INSERT INTO media_tags (name) VALUES (?)');
        foreach ($names as $name) {
            $find->execute([$name]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $insert->execute([$name]);
                $id = $this->pdo->lastInsertId();
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /** @param int[] $tagIds */
    private function setTags(int $id, array $tagIds): void
    {
        $this->pdo->prepare('DELETE FROM media_tag_links WHERE media_id = ?')->execute([$id]);
        $ins = $this->pdo->prepare('INSERT OR IGNORE INTO media_tag_links (media_id, tag_id) VALUES (?, ?)');
        foreach ($tagIds as $tagId) {
            $ins->execute([$id, $tagId]);
        }
    }

    /**
     * Original name for display only: without path, without control characters, at most 255 characters. < > " become _ so
     * that the name cannot form markup or the end of an attribute even in a frontend that outputs it unchecked as HTML.
     */
    private static function cleanOriginalName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }
        $name = strtr($name, ['<' => '_', '>' => '_', '"' => '_']);
        $name = trim($name);
        if (mb_strlen($name, 'UTF-8') > 255) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 250 - strlen($ext), 'UTF-8') . '.' . $ext;
        }
        return $name === '' ? 'datei' : $name;
    }

    /**
     * Checks extension and content and reads the dimensions: [kind, stored MIME type, width|null, height|null].
     *
     * @throws ApiException 422 for a disallowed extension or content that does not match the extension
     */
    private static function inspect(string $path, string $ext): array
    {
        if (!isset(self::TYPES[$ext])) {
            $allowed = [];
            foreach (self::TYPES as $e => [$kind]) {
                $allowed[] = $e;
            }
            throw new ApiException(422, [
                'error'   => 'type_not_allowed',
                'message' => ($ext === '' ? 'Dateien ohne Endung sind' : "Dateityp .$ext ist") . ' nicht erlaubt. Erlaubt: '
                    . implode(', ', $allowed) . '.',
                'errors'  => ['file' => 'Dateityp nicht erlaubt'],
            ]);
        }
        [$kind, $mime, $accepted] = self::TYPES[$ext];
        if (!class_exists(\finfo::class)) {
            throw new ApiException(500, [
                'error'   => 'fileinfo_missing',
                'message' => 'Die PHP-Erweiterung fileinfo fehlt - ohne sie kann der Dateiinhalt nicht geprüft werden, '
                    . 'Uploads sind deshalb gesperrt.',
            ]);
        }
        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $ok = in_array($detected, $accepted, true);
        $width = null;
        $height = null;
        if ($ok && $kind === 'image' && $ext !== 'svg') {
            $info = @getimagesize($path);
            $ok = is_array($info) && ($info['mime'] ?? '') === $mime;
            if ($ok) {
                [$width, $height] = [(int) $info[0], (int) $info[1]];
            }
        } elseif ($ok && $ext === 'svg') {
            [$ok, $width, $height] = self::inspectSvg($path);
        } elseif ($ok && isset(self::OOXML_DIR[$ext])) {
            $ok = self::zipContains($path, self::OOXML_DIR[$ext]);
        } elseif ($ok && $ext === 'mp4') {
            [$width, $height] = self::mp4Dimensions($path);
        } elseif ($ok && $ext === 'webm') {
            [$width, $height] = self::webmDimensions($path);
        }
        if (!$ok) {
            throw new ApiException(422, [
                'error'   => 'content_mismatch',
                'message' => "Der Inhalt der Datei passt nicht zur Endung .$ext (erkannt: $detected). Die Datei wurde "
                    . 'abgelehnt.',
                'errors'  => ['file' => 'Inhalt passt nicht zur Dateiendung'],
            ]);
        }
        return [$kind, $mime, $width ?: null, $height ?: null];
    }

    /**
     * SVG: must have an <svg> root element and must not contain scripts, event handlers (on...=), javascript: URLs,
     * <foreignObject> or external entities - the file is served publicly and, when called directly, would run
     * in the origin of the CMS. Dimensions from width/height (pixels) or alternatively the viewBox.
     *
     * @return array{0:bool,1:?int,2:?int}
     */
    private static function inspectSvg(string $path): array
    {
        $src = (string) file_get_contents($path);
        if (!preg_match('/<svg[\s>]/i', $src)) {
            return [false, null, null];
        }
        if (preg_match('/<\s*script|\bon[a-z]+\s*=|javascript\s*:|<\s*foreignObject|<!ENTITY|xlink:href\s*=\s*["\']\s*(?!#|data:image\/)/i', $src)) {
            throw new ApiException(422, [
                'error'   => 'svg_unsafe',
                'message' => 'Die SVG-Datei enthält Skripte, Event-Handler oder externe Verweise und wurde aus Sicherheitsgründen '
                    . 'abgelehnt.',
                'errors'  => ['file' => 'SVG mit aktivem Inhalt'],
            ]);
        }
        $width = null;
        $height = null;
        if (preg_match('/<svg\b[^>]*>/is', $src, $tag)) {
            $attr = function (string $name) use ($tag): ?float {
                return preg_match('/\s' . $name . '\s*=\s*["\']\s*([0-9.]+)\s*(px)?\s*["\']/i', $tag[0], $m) ? (float) $m[1] : null;
            };
            $width = $attr('width');
            $height = $attr('height');
            if (($width === null || $height === null)
                && preg_match('/\sviewBox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']/i', $tag[0], $vb)) {
                [$width, $height] = [(float) $vb[1], (float) $vb[2]];
            }
        }
        return [true, $width !== null ? (int) round($width) : null, $height !== null ? (int) round($height) : null];
    }

    /** Does the ZIP archive contain an entry below $dir? (File names are stored uncompressed in the headers) */
    private static function zipContains(string $path, string $dir): bool
    {
        $src = (string) file_get_contents($path);
        return strncmp($src, "PK\x03\x04", 4) === 0 && strpos($src, $dir) !== false;
    }

    /**
     * MP4/MOV: width/height from the first track header (moov/trak/tkhd) with dimensions (audio tracks have 0x0). Pure
     * reading of the box structure, no additional library. [null, null] if not readable.
     *
     * @return array{0:?int,1:?int}
     */
    private static function mp4Dimensions(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [null, null];
        }
        $size = (int) filesize($path);
        $result = [null, null];
        $walk = function (int $start, int $end, int $depth) use (&$walk, $fh, &$result): void {
            $pos = $start;
            while ($pos + 8 <= $end && $result[0] === null && $depth < 8) {
                fseek($fh, $pos);
                $head = fread($fh, 8);
                if (strlen($head) < 8) {
                    return;
                }
                $len = unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);
                $headerLen = 8;
                if ($len === 1) {
                    $ext = unpack('N2', (string) fread($fh, 8));
                    $len = $ext[1] * 4294967296 + $ext[2];
                    $headerLen = 16;
                } elseif ($len === 0) {
                    $len = $end - $pos;
                }
                if ($len < $headerLen) {
                    return;
                }
                if (in_array($type, ['moov', 'trak'], true)) {
                    $walk($pos + $headerLen, min($end, $pos + $len), $depth + 1);
                } elseif ($type === 'tkhd') {
                    $body = (string) fread($fh, min($len - $headerLen, 128));
                    $version = ord($body[0] ?? "\0");
                    $offset = $version === 1 ? 88 : 76; // width/height (16.16 fixed point) at the end of the tkhd
                    if (strlen($body) >= $offset + 8) {
                        [$w, $h] = array_values(unpack('N2', substr($body, $offset, 8)));
                        if ($w > 0 && $h > 0) {
                            $result = [(int) round($w / 65536), (int) round($h / 65536)];
                        }
                    }
                }
                $pos += $len;
            }
        };
        $walk(0, $size, 0);
        fclose($fh);
        return $result;
    }

    /**
     * WebM (Matroska/EBML): PixelWidth/PixelHeight from Segment/Tracks/TrackEntry/Video. [null, null] if not readable.
     *
     * @return array{0:?int,1:?int}
     */
    private static function webmDimensions(string $path): array
    {
        $data = (string) file_get_contents($path, false, null, 0, 4 * 1024 * 1024); // tracks are at the beginning
        $n = strlen($data);
        // variable-length integer: [value, length]; $keepMarker for IDs (they are compared including the length bit)
        $vint = function (int $pos, bool $keepMarker) use ($data, $n): ?array {
            if ($pos >= $n) {
                return null;
            }
            $first = ord($data[$pos]);
            for ($len = 1; $len <= 8 && !($first & (0x80 >> ($len - 1))); $len++) {
            }
            if ($len > 8 || $pos + $len > $n) {
                return null;
            }
            $value = $keepMarker ? $first : $first & ((0x80 >> ($len - 1)) - 1);
            $allOnes = ($first & ((0x80 >> ($len - 1)) - 1)) === ((0x80 >> ($len - 1)) - 1);
            for ($i = 1; $i < $len; $i++) {
                $byte = ord($data[$pos + $i]);
                $allOnes = $allOnes && $byte === 0xFF;
                $value = ($value << 8) | $byte;
            }
            return [$keepMarker ? $value : ($allOnes ? -1 : $value), $len]; // -1 = unknown size
        };
        $containers = [0x18538067 => true, 0x1654AE6B => true, 0xAE => true, 0xE0 => true]; // Segment, Tracks, TrackEntry, Video
        $width = null;
        $height = null;
        $walk = function (int $pos, int $end, int $depth) use (&$walk, $vint, $containers, $data, &$width, &$height): void {
            while ($pos < $end && $depth < 6 && ($width === null || $height === null)) {
                $id = $vint($pos, true);
                if ($id === null) {
                    return;
                }
                $size = $vint($pos + $id[1], false);
                if ($size === null) {
                    return;
                }
                $body = $pos + $id[1] + $size[1];
                $bodyEnd = $size[0] < 0 ? $end : min($end, $body + $size[0]);
                if (isset($containers[$id[0]])) {
                    $walk($body, $bodyEnd, $depth + 1);
                } elseif ($id[0] === 0xB0 || $id[0] === 0xBA) { // PixelWidth / PixelHeight
                    $v = 0;
                    for ($i = $body; $i < $bodyEnd; $i++) {
                        $v = ($v << 8) | ord($data[$i]);
                    }
                    if ($id[0] === 0xB0) {
                        $width = $v;
                    } else {
                        $height = $v;
                    }
                } elseif ($id[0] === 0x1F43B675) { // Cluster: media data, tracks come before it
                    return;
                }
                if ($size[0] < 0) {
                    return;
                }
                $pos = $bodyEnd;
            }
        };
        // skip the EBML header, then the segment
        $head = $vint(0, true);
        if ($head === null || $head[0] !== 0x1A45DFA3) {
            return [null, null];
        }
        $headSize = $vint($head[1], false);
        if ($headSize === null || $headSize[0] < 0) {
            return [null, null];
        }
        $walk($head[1] + $headSize[1] + $headSize[0], $n, 0);
        return [$width ?: null, $height ?: null];
    }

    /** php.ini size value (e.g. "20M") in bytes; null = not set, 0 = unlimited */
    private static function iniBytes(string $key): ?int
    {
        $v = trim((string) ini_get($key));
        if ($v === '') {
            return null;
        }
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }
        return (int) $n;
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException(422, ['error' => 'validation', 'message' => $message, 'errors' => [$field => $message]]);
    }

    /**
     * 422 "Datei zu groß" with the effective limit. $php: which PHP limit kicked in (then the text says that it is
     * a server setting), null = the configured limit of the CMS.
     */
    private static function tooLarge(array $limits, ?int $size, ?string $php): ApiException
    {
        $mb = function (int $bytes): string {
            return number_format($bytes / 1048576, $bytes % 1048576 === 0 ? 0 : 1, ',', '.') . ' MB';
        };
        $message = 'Die Datei ist zu groß' . ($size !== null ? ' (' . $mb($size) . ')' : '') . '. Erlaubt sind höchstens '
            . $mb($limits['max_bytes']) . ' je Datei.';
        if ($php !== null) {
            $message .= " (Grenze der Server-Einstellung $php; ein höheres Limit muss beim Hoster in der PHP-Konfiguration "
                . 'gesetzt werden.)';
        }
        return new ApiException(422, [
            'error'     => 'too_large',
            'message'   => $message,
            'max_bytes' => $limits['max_bytes'],
            'errors'    => ['file' => 'Datei zu groß'],
        ]);
    }
}
