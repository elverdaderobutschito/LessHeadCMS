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
 * Stable IDs of the schema elements and the stored diagram layout (visual schema editor, phase 2).
 *
 * schema_ids: per element of the active schema (entity, field, media field, relationship, enum) a permanent ID (UUID)
 * that survives renames. An element is identified via its key 'model_key' - that is the identifier of the model JSON
 * derived from the name (PumlParser::modelJson(), e.g. "entity:kurs", "field:kurs.titel",
 * "relation:kurs.dozent_id", "relation:kurs_tag", "media:kurs.galerie", "enum:status"); the remaining columns describe
 * the element in readable form (entity, name, target, label, kind of relationship). Which new key corresponds to a
 * previous one is decided, when applying a migration, exclusively by the matching of SchemaMigration (name or
 * {renamed_from}, target class + label for relationships) - SchemaMigration::stableKeys() translates it into keys,
 * reassign() writes the result in the same transaction as the migration.
 *
 * schema_layout: stored position (x/y, top left corner) per diagram node (entity or enum, node_id = stable
 * ID) including the layout_version at the time of saving. The current layout version and the viewport (scroll position
 * + zoom) live in _meta (layout_version, layout_viewport) because they concern the whole diagram. Layout changes have
 * nothing to do with migrations; only orphaned positions of removed elements are deleted along by reassign() - as are
 * their translations (Languages, translations.ref_id = stable ID).
 *
 * Existing installations (before phase 2) get their IDs when first needed (ensure(): /bootstrap, GET/PUT layout,
 * applying) from the active schema text (_meta.schema_source or the .puml as long as it matches the active schema).
 */
final class SchemaIds
{
    public const IDS_TABLE = 'schema_ids';
    public const LAYOUT_TABLE = 'schema_layout';
    /** Prefix of provisional IDs in the preview (POST /api/_schema/model): element without a stored counterpart */
    public const DRAFT = 'draft:';

    private const IDS_DDL = 'CREATE TABLE IF NOT EXISTS schema_ids (
  id TEXT PRIMARY KEY,
  kind TEXT NOT NULL,
  model_key TEXT NOT NULL UNIQUE,
  entity_name TEXT,
  name TEXT,
  to_entity TEXT,
  label TEXT,
  relation_kind TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)';

    private const LAYOUT_DDL = 'CREATE TABLE IF NOT EXISTS schema_layout (
  node_id TEXT PRIMARY KEY REFERENCES schema_ids(id) ON DELETE CASCADE,
  x REAL NOT NULL,
  y REAL NOT NULL,
  layout_version INTEGER NOT NULL
)';

    /** Limits for stored coordinates and zoom (protection against nonsense, not against large diagrams) */
    private const COORD_LIMIT = 1000000;
    private const ZOOM_MIN = 0.05;
    private const ZOOM_MAX = 10;

    public static function ensureTables(PDO $pdo): void
    {
        $pdo->exec(self::IDS_DDL);
        $pdo->exec(self::LAYOUT_DDL);
    }

    /**
     * Create the tables and give an ID to every element of the active schema that does not have one yet; remove entries for
     * keys that do not exist in the active schema, including their position. Without a readable active schema text (file
     * changed via FTP and never applied) only the tables are created - the IDs then arise on the next apply.
     */
    public static function ensure(PDO $pdo): void
    {
        self::ensureTables($pdo);
        $source = self::activeSource($pdo);
        if ($source === null) {
            return;
        }
        try {
            $model = PumlParser::modelJson($source, Workflows::active($pdo));
        } catch (SchemaException $e) {
            return; // the active schema was bootstrapped, so it is valid - just to be safe
        }
        $known = self::lookup($pdo);
        $keys = [];
        $insert = null;
        foreach (self::elements($model) as $el) {
            $keys[$el['model_key']] = true;
            if (!isset($known[$el['model_key']])) {
                // OR IGNORE: two simultaneous first calls create the same key only once
                $insert = $insert ?? self::insertStatement($pdo, 'INSERT OR IGNORE');
                $insert->execute(self::row(self::uuid(), $el));
            }
        }
        $stale = array_diff_key($known, $keys);
        if ($stale) {
            self::delete($pdo, array_values($stale));
        }
        Languages::pruneEnumValues($pdo, $model);
    }

    /**
     * Text of the active schema: _meta.schema_source (stored at bootstrap/apply since phase 2), otherwise the active
     * .puml if it matches the stored hash (it is then added); null if neither works.
     */
    public static function activeSource(PDO $pdo): ?string
    {
        if (!Database::tableExists($pdo, '_meta')) {
            return null;
        }
        $hash = Database::getMeta($pdo, 'schema_hash');
        $stored = Database::getMeta($pdo, 'schema_source');
        if ($stored !== null && $hash !== null && hash_equals($hash, hash('sha256', $stored))) {
            return $stored;
        }
        $file = Database::getMeta($pdo, 'active_schema');
        if ($hash === null || $file === null) {
            return null;
        }
        $source = @file_get_contents(Config::schemaDir() . '/' . $file);
        if ($source === false || !hash_equals($hash, hash('sha256', $source))) {
            return null;
        }
        Database::setMeta($pdo, 'schema_source', $source);
        return $source;
    }

    /** model_key => id (empty as long as the table does not exist); writes nothing */
    public static function lookup(PDO $pdo): array
    {
        if (!Database::tableExists($pdo, self::IDS_TABLE)) {
            return [];
        }
        return $pdo->query('SELECT model_key, id FROM schema_ids')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * id => ['name', 'label'] of the element in the active schema (columns name/label of schema_ids, updated on every
     * apply; for relationships name is the FK column or link table); writes nothing
     *
     * @return array<string,array{name:?string,label:?string}>
     */
    public static function activeNames(PDO $pdo): array
    {
        if (!Database::tableExists($pdo, self::IDS_TABLE)) {
            return [];
        }
        $out = [];
        foreach ($pdo->query('SELECT id, name, label FROM schema_ids') as $row) {
            $out[$row['id']] = ['name' => $row['name'], 'label' => $row['label']];
        }
        return $out;
    }

    /**
     * Model JSON with stable IDs: every object gets as 'id' the stored ID of its counterpart in the active
     * schema, otherwise a provisional one ("draft:" + key), as 'key' its key and as 'active' the name and label
     * of this counterpart in the active schema (null for a provisional ID). The diagram editor uses this to set
     * {renamed_from:…} when renaming - the migration only matches via the name or this marker (SchemaMigration::identify()),
     * not via the ID. Writes nothing.
     *
     * @param array<string,string[]> $candidates new key => possible previous keys (SchemaMigration::stableKeys())
     * @param array<string,string> $ids model_key => id (lookup())
     * @param array<string,array{name:?string,label:?string}> $names id => name/label in the active schema (activeNames())
     */
    public static function decorate(array $model, array $candidates, array $ids, array $names = []): array
    {
        $resolve = function (array $o) use ($candidates, $ids, $names): array {
            $key = $o['id'];
            $id = self::resolve($candidates[$key] ?? [], $ids);
            return ['id' => $id ?? self::DRAFT . $key, 'key' => $key, 'active' => $id !== null ? ($names[$id] ?? null) : null] + $o;
        };
        foreach ($model['entities'] as &$e) {
            $e = $resolve($e);
            $e['fields'] = array_map($resolve, $e['fields']);
            $e['media'] = array_map($resolve, $e['media']);
        }
        unset($e);
        $model['relations'] = array_map($resolve, $model['relations']);
        $model['enums'] = array_map($resolve, $model['enums']);
        return $model;
    }

    /**
     * When applying a migration (within its transaction): determine the IDs of the new schema. If an element has a
     * counterpart with a stored ID, it stays (also with a changed key), otherwise a new one is created; IDs without
     * a counterpart in the new schema are deleted including their layout position.
     *
     * @param array<string,string[]> $candidates as for decorate()
     * @return array{kept:int,created:int,removed:int}
     */
    public static function reassign(PDO $pdo, array $model, array $candidates): array
    {
        $ids = self::lookup($pdo);
        $rows = [];
        $kept = [];
        foreach (self::elements($model) as $el) {
            $id = self::resolve($candidates[$el['model_key']] ?? [], $ids);
            if ($id === null || isset($kept[$id])) {
                $id = self::uuid(); // new (claimed twice does not occur - and if it did, rather a new one than one ID for two elements)
            } else {
                $kept[$id] = true;
            }
            $rows[] = self::row($id, $el);
        }
        $removed = array_values(array_diff($ids, array_keys($kept)));
        if ($removed) {
            self::delete($pdo, $removed);
        }
        // release the keys first, then set them anew: they can "overtake" each other (class a -> b, new class a)
        $pdo->exec("UPDATE schema_ids SET model_key = '~' || id");
        $update = $pdo->prepare('UPDATE schema_ids SET kind = ?, model_key = ?, entity_name = ?, name = ?, to_entity = ?, label = ?,
                                 relation_kind = ? WHERE id = ?');
        $insert = self::insertStatement($pdo);
        foreach ($rows as $r) {
            if (isset($kept[$r[0]])) {
                $update->execute(array_merge(array_slice($r, 1), [$r[0]]));
            } else {
                $insert->execute($r);
            }
        }
        Languages::pruneEnumValues($pdo, $model); // translations of removed enum values
        return ['kept' => count($kept), 'created' => count($rows) - count($kept), 'removed' => count($removed)];
    }

    // ---------------------------------------------------------------- Layout

    /** GET /api/_schema/layout: {layout_version, positions: {id: {x, y}}, viewport: {x, y, zoom}|null} */
    public static function layout(PDO $pdo): array
    {
        $positions = [];
        foreach ($pdo->query('SELECT node_id, x, y FROM schema_layout ORDER BY node_id') as $r) {
            $positions[$r['node_id']] = ['x' => (float) $r['x'], 'y' => (float) $r['y']];
        }
        $viewport = json_decode((string) Database::getMeta($pdo, 'layout_viewport'), true);
        return [
            'layout_version' => (int) (Database::getMeta($pdo, 'layout_version') ?? 0),
            'positions'      => (object) $positions,
            'viewport'       => is_array($viewport) ? $viewport : null,
        ];
    }

    /**
     * PUT /api/_schema/layout: body {positions?: {id: {x, y}}, viewport?: {x, y, zoom}}. positions replaces all
     * stored positions (a complete layout, e.g. after "Neu anordnen"); if it is missing, they stay. Every call
     * increments layout_version. Only IDs of entities and enums from schema_ids are allowed (no "draft:" IDs).
     */
    public static function saveLayout(PDO $pdo, array $body): array
    {
        $errors = [];
        $positions = null;
        if (array_key_exists('positions', $body)) {
            if (!is_array($body['positions'])) {
                $errors['positions'] = 'Objekt {id: {x, y}} erwartet';
            } else {
                $nodes = $pdo->query("SELECT id FROM schema_ids WHERE kind IN ('entity', 'enum')")->fetchAll(PDO::FETCH_COLUMN);
                $nodes = array_flip($nodes);
                $positions = [];
                foreach ($body['positions'] as $id => $pos) {
                    $id = (string) $id;
                    if (!isset($nodes[$id])) {
                        $errors["positions.$id"] = strpos($id, self::DRAFT) === 0
                            ? 'vorläufige ID (Element noch nicht angewendet) - Position wird nicht gespeichert'
                            : 'unbekannte ID (keine Entität/kein Enum des aktiven Schemas)';
                        continue;
                    }
                    $x = self::number($pos['x'] ?? null, self::COORD_LIMIT);
                    $y = self::number($pos['y'] ?? null, self::COORD_LIMIT);
                    if ($x === null || $y === null) {
                        $errors["positions.$id"] = 'x und y als Zahlen erwartet';
                        continue;
                    }
                    $positions[$id] = [$x, $y];
                }
            }
        }
        $viewport = null;
        if (array_key_exists('viewport', $body) && $body['viewport'] !== null) {
            $v = $body['viewport'];
            $vx = is_array($v) ? self::number($v['x'] ?? null, self::COORD_LIMIT) : null;
            $vy = is_array($v) ? self::number($v['y'] ?? null, self::COORD_LIMIT) : null;
            $zoom = is_array($v) ? self::number($v['zoom'] ?? null, self::ZOOM_MAX) : null;
            if ($vx === null || $vy === null || $zoom === null || $zoom < self::ZOOM_MIN) {
                $errors['viewport'] = '{x, y, zoom} erwartet (zoom ' . self::ZOOM_MIN . ' bis ' . self::ZOOM_MAX . ')';
            } else {
                $viewport = ['x' => $vx, 'y' => $vy, 'zoom' => $zoom];
            }
        }
        if ($positions === null && $viewport === null && !$errors) {
            $errors['positions'] = 'positions und/oder viewport erwartet';
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Layout ungültig', 'errors' => $errors]);
        }
        $pdo->beginTransaction();
        try {
            $version = (int) (Database::getMeta($pdo, 'layout_version') ?? 0) + 1;
            if ($positions !== null) {
                $pdo->exec('DELETE FROM schema_layout');
                $stmt = $pdo->prepare('INSERT INTO schema_layout (node_id, x, y, layout_version) VALUES (?, ?, ?, ?)');
                foreach ($positions as $id => [$x, $y]) {
                    $stmt->execute([$id, $x, $y, $version]);
                }
            }
            if ($viewport !== null) {
                Database::setMeta($pdo, 'layout_viewport', json_encode($viewport));
            }
            Database::setMeta($pdo, 'layout_version', (string) $version);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::layout($pdo);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * All elements of a model JSON as row descriptions: model_key, kind, entity_name (table of the entity or, for
     * relationships, the class with the FK column / the one named first), name, to_entity, label, relation_kind.
     */
    public static function elements(array $model): array
    {
        $table = [];
        foreach ($model['entities'] as $e) {
            $table[$e['name']] = $e['table'];
        }
        $out = [];
        foreach ($model['entities'] as $e) {
            $out[] = ['model_key' => $e['id'], 'kind' => 'entity', 'entity_name' => $e['table'], 'name' => $e['name']];
            foreach ($e['fields'] as $f) {
                $out[] = ['model_key' => $f['id'], 'kind' => 'field', 'entity_name' => $e['table'], 'name' => $f['name']];
            }
            foreach ($e['media'] as $m) {
                $out[] = ['model_key' => $m['id'], 'kind' => 'media', 'entity_name' => $e['table'], 'name' => $m['name']];
            }
        }
        foreach ($model['relations'] as $r) {
            $out[] = ['model_key' => $r['id'], 'kind' => 'relation', 'entity_name' => $table[$r['from_entity']],
                'name' => $r['own_column'] ?? $r['junction'], 'to_entity' => $table[$r['to_entity']], 'label' => $r['label'],
                'relation_kind' => $r['kind']];
        }
        foreach ($model['enums'] as $en) {
            $out[] = ['model_key' => $en['id'], 'kind' => 'enum', 'name' => $en['name']];
        }
        return $out;
    }

    private static function resolve(array $candidates, array $ids): ?string
    {
        foreach ($candidates as $k) {
            if (isset($ids[$k])) {
                return $ids[$k];
            }
        }
        return null;
    }

    private static function row(string $id, array $el): array
    {
        return [$id, $el['kind'], $el['model_key'], $el['entity_name'] ?? null, $el['name'] ?? null, $el['to_entity'] ?? null,
            $el['label'] ?? null, $el['relation_kind'] ?? null];
    }

    private static function insertStatement(PDO $pdo, string $verb = 'INSERT'): \PDOStatement
    {
        return $pdo->prepare("$verb INTO schema_ids (id, kind, model_key, entity_name, name, to_entity, label, relation_kind)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    }

    /** Delete IDs including layout position, translations and permissions (explicitly: foreign keys are off during a migration) */
    private static function delete(PDO $pdo, array $ids): void
    {
        Languages::deleteRefs($pdo, $ids);
        Permissions::deleteRefs($pdo, $ids); // permissions on removed elements (permissions.ref_id = stable ID)
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM schema_layout WHERE node_id IN ($in)")->execute($chunk);
            $pdo->prepare("DELETE FROM schema_ids WHERE id IN ($in)")->execute($chunk);
        }
    }

    /** @param mixed $v */
    private static function number($v, float $limit): ?float
    {
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        $v = (float) $v;
        return is_finite($v) && abs($v) <= $limit ? $v : null;
    }

    /** UUID version 4 */
    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
