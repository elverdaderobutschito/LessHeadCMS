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
 * Roles and permissions (phase 1: data model, enforcement, management API without a UI).
 *
 * Basic principle: additive, deny always wins. Normal entities and fields are allowed by default - only deviations
 * are stored. System areas (SYSTABLES) are denied by default and need an explicit allow. None of this applies to
 * users.role = 'admin': admins may always do everything (bypass, only this column is checked - a row in
 * user_roles is not needed for it). Schema editing (/api/_schema/*) and this management API itself stay reserved
 * for admins and are deliberately not a system area.
 *
 * Tables (system level, created on the first call of the management API - an existing installation does not need
 * another bootstrap; without them simply nobody has additional permissions):
 *  - roles: exactly one built-in role "Admin" (is_admin = 1) that cannot be deleted or renamed. It documents the bypass,
 *    but cannot be assigned to anyone and carries no permissions (one only becomes an admin via users.role).
 *  - groups, group_roles, user_roles, user_groups: groups and the m:n assignments.
 *  - permissions: owner_type/owner_id (role, group or user - so also ad-hoc permissions directly on a group or
 *    a user), scope (entity, field, systable, action), effect (allow, deny) and the target: for entity/field/action
 *    ref_id = stable ID from schema_ids, NEVER the name - a renamed denied field therefore stays denied, and
 *    SchemaIds::delete() clears the rows of removed elements via deleteRefs(); for systable the fixed identifier in
 *    systable_name; for action additionally the column action (create, update, delete).
 *
 * scope = field means everything the API delivers as a property of a row: field, media field and relationship (FK column
 * or n:n list).
 *
 * scope = action (action permissions): ref_id is the entity, action the write action. Default as for entities:
 * allowed. deny on create/update/delete rejects POST /api/{entity}, PUT or DELETE /api/{entity}/{id} with 403 (Cms);
 * reading is never affected by this. "Duplizieren" is a POST and therefore depends on create. On an entity that is
 * denied anyway, an action permission has no effect but is permitted - it applies again as soon as the entity denial is gone.
 */
final class Permissions
{
    /** System areas of this permission system (default: denied) */
    public const SYSTABLES = ['users', 'media', 'languages', 'translations', 'testdata', 'violations'];

    public const TABLES = ['roles', 'groups', 'group_roles', 'user_roles', 'user_groups', 'permissions'];

    private const OWNER_TYPES = ['role', 'group', 'user'];
    private const SCOPES = ['entity', 'field', 'systable', 'action'];
    public const ACTIONS = ['create', 'update', 'delete'];
    private const EFFECTS = ['allow', 'deny'];
    public const ADMIN_ROLE = 'Admin';
    private const NAME_MAX = 100;

    // permissions last: if it exists, all of them exist (see access())
    private const DDL = [
        'CREATE TABLE IF NOT EXISTS roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  is_admin INTEGER NOT NULL DEFAULT 0
)',
        'CREATE TABLE IF NOT EXISTS "groups" (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
)',
        'CREATE TABLE IF NOT EXISTS group_roles (
  group_id INTEGER NOT NULL REFERENCES "groups"(id) ON DELETE CASCADE,
  role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, role_id)
)',
        'CREATE TABLE IF NOT EXISTS user_roles (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, role_id)
)',
        'CREATE TABLE IF NOT EXISTS user_groups (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  group_id INTEGER NOT NULL REFERENCES "groups"(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, group_id)
)',
        "CREATE TABLE IF NOT EXISTS permissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_type TEXT NOT NULL CHECK (owner_type IN ('role', 'group', 'user')),
  owner_id INTEGER NOT NULL,
  scope TEXT NOT NULL CHECK (scope IN ('entity', 'field', 'systable', 'action')),
  ref_id TEXT,
  systable_name TEXT,
  effect TEXT NOT NULL CHECK (effect IN ('allow', 'deny')),
  action TEXT CHECK (action IS NULL OR action IN ('create', 'update', 'delete')),
  CHECK ((scope = 'systable') = (systable_name IS NOT NULL)),
  CHECK ((scope = 'systable') = (ref_id IS NULL)),
  CHECK ((scope = 'action') = (action IS NOT NULL))
)",
    ];

    public static function ensureTables(PDO $pdo): void
    {
        if (!Database::tableExists($pdo, 'permissions')) {
            SchemaIds::ensureTables($pdo);
            foreach (self::DDL as $sql) {
                $pdo->exec($sql);
            }
        } elseif (!self::hasActionColumn($pdo)) {
            self::addActionColumn($pdo);
        }
        // OR IGNORE: two simultaneous first calls create the built-in role only once
        $pdo->prepare('INSERT OR IGNORE INTO roles (name, is_admin) VALUES (?, 1)')->execute([self::ADMIN_ROLE]);
    }

    private static function hasActionColumn(PDO $pdo): bool
    {
        $stmt = $pdo->query('PRAGMA table_info(permissions)');
        $columns = array_column($stmt->fetchAll(), 'name');
        $stmt->closeCursor();
        return in_array('action', $columns, true);
    }

    /**
     * Rebuild a table from before the action permissions: the CHECK rule on scope cannot be changed in SQLite,
     * so recreate it and carry over the rows (including IDs). Runs once on the next call of the
     * management API; until then access() reads the old table unchanged (it cannot contain action rows).
     */
    private static function addActionColumn(PDO $pdo): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!self::hasActionColumn($pdo)) { // a simultaneous call was faster
                $pdo->exec('ALTER TABLE permissions RENAME TO permissions_before_action');
                $pdo->exec(self::DDL[count(self::DDL) - 1]);
                $pdo->exec('INSERT INTO permissions (id, owner_type, owner_id, scope, ref_id, systable_name, effect)
                            SELECT id, owner_type, owner_id, scope, ref_id, systable_name, effect FROM permissions_before_action');
                $pdo->exec('DROP TABLE permissions_before_action');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    // ---------------------------------------------------------------- Effective permission

    /**
     * Effective permissions of the user: null = unrestricted (no user = public API without a session, or
     * role = 'admin'). Otherwise all applicable rows from directly assigned roles, roles of all the user's groups,
     * permissions of these groups themselves and permissions of the user themselves - across all sources deny wins.
     *
     * @param array|null $user as from Auth::currentUser()
     * @param array $model stored schema model (Database::loadModel())
     */
    public static function access(PDO $pdo, ?array $user, array $model): ?Access
    {
        if ($user === null || $user['role'] === 'admin') {
            return null;
        }
        if (!Database::tableExists($pdo, 'permissions')) {
            return new Access();
        }
        $stmt = $pdo->prepare(
            'SELECT p.*, s.kind, s.entity_name, s.name
               FROM permissions p LEFT JOIN schema_ids s ON s.id = p.ref_id
              WHERE (p.owner_type = \'user\' AND p.owner_id = :u)
                 OR (p.owner_type = \'group\' AND p.owner_id IN (SELECT group_id FROM user_groups WHERE user_id = :u))
                 OR (p.owner_type = \'role\' AND p.owner_id IN (
                        SELECT role_id FROM user_roles WHERE user_id = :u
                        UNION
                        SELECT gr.role_id FROM group_roles gr JOIN user_groups ug ON ug.group_id = gr.group_id
                         WHERE ug.user_id = :u))'
        );
        $stmt->execute([':u' => (int) $user['id']]);

        $entities = [];
        $fields = [];
        $system = [];
        $actions = [];
        $tablesOf = self::tableResolver($pdo, $model);
        foreach ($stmt->fetchAll() as $r) {
            if ($r['scope'] === 'systable') {
                $system[$r['systable_name']][$r['effect']] = true;
                continue;
            }
            // allow on entity/field/action changes nothing (default is allow, deny wins); target without a schema_ids row: removed
            if ($r['effect'] !== 'deny' || $r['kind'] === null) {
                continue;
            }
            if ($r['scope'] === 'action') {
                // resolved like an entity denial: on an abstract class it applies to all subclasses
                foreach ($r['kind'] === 'entity' ? $tablesOf((string) $r['entity_name']) : [] as $t) {
                    $actions[$t][$r['action']] = true;
                }
                continue;
            }
            foreach (self::lockedBy($r, $model, $tablesOf) as [$t, $prop]) {
                if ($prop === null) {
                    $entities[$t] = true;
                } else {
                    $fields[$t][$prop] = true;
                }
            }
        }
        // References to a denied entity: the n:n list or optional FK column disappears along with it (its selection could
        // not be loaded anyway). A required FK stays visible - otherwise no record could be created any more.
        if ($entities) {
            foreach ($model['entities'] as $table => $e) {
                if (isset($entities[$table])) {
                    continue;
                }
                foreach ($e['fields'] as $f) {
                    if ($f['foreign_key'] && isset($entities[$f['foreign_key']['table']]) && !$f['required']) {
                        $fields[$table][$f['name']] = true;
                    }
                }
                foreach ($e['many_to_many'] as $m) {
                    if (isset($entities[$m['table']])) {
                        $fields[$table][$m['name']] = true;
                    }
                }
            }
        }
        $allowed = [];
        foreach ($system as $name => $effects) {
            if (isset($effects['allow']) && !isset($effects['deny'])) {
                $allowed[$name] = true;
            }
        }
        return new Access($entities, $fields, $allowed, $actions);
    }

    /** May the user use the system area? Admin always, otherwise only with allow and without deny. */
    public static function requireSystable(PDO $pdo, array $user, string $systable): void
    {
        if ($user['role'] === 'admin') {
            return;
        }
        $access = self::access($pdo, $user, ['entities' => []]);
        if ($access === null || !$access->systable($systable)) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_api.forbidden_area', 'No permission for this area'));
        }
    }

    /** GET /api/_permissions/me and /api/_users/{id}/permissions */
    public static function effective(PDO $pdo, array $user, array $model): array
    {
        $access = self::access($pdo, $user, $model);
        if ($access === null) {
            return ['admin' => true, 'denied_entities' => [], 'denied_fields' => (object) [], 'denied_actions' => (object) [],
                'systables' => array_fill_keys(self::SYSTABLES, true)];
        }
        return $access->export();
    }

    /**
     * Explicit denials (deny on entity or field/media field/relationship) in the active schema including their owners - for
     * the schema analysis (SchemaMigration: warning "Berechtigungs-Konflikt"). Resolved as in access(), but across all
     * owners and without the derived denials (references to a denied entity). Owners for whom the denial has no
     * effect (administrators, deleted users) are missing.
     *
     * @return array{entities:array<string,array<string,Msg>>,fields:array<string,array<string,array<string,Msg>>>}
     *         table [=> API name] => [owner key => label like "Role 'Redaktion'" (ownerLabel())]
     */
    public static function denials(PDO $pdo, array $model): array
    {
        $out = ['entities' => [], 'fields' => []];
        if (!Database::tableExists($pdo, 'permissions')) {
            return $out;
        }
        $rows = $pdo->query(
            'SELECT p.owner_type, p.owner_id, p.scope, s.kind, s.entity_name, s.name,
                    r.name AS role_name, g.name AS group_name, u.username, u.role AS user_role
               FROM permissions p JOIN schema_ids s ON s.id = p.ref_id
               LEFT JOIN roles r ON p.owner_type = \'role\' AND r.id = p.owner_id
               LEFT JOIN "groups" g ON p.owner_type = \'group\' AND g.id = p.owner_id
               LEFT JOIN users u ON p.owner_type = \'user\' AND u.id = p.owner_id
              WHERE p.effect = \'deny\' AND p.scope IN (\'entity\', \'field\') ORDER BY p.owner_type, p.owner_id, p.id'
        )->fetchAll();
        $tablesOf = self::tableResolver($pdo, $model);
        foreach ($rows as $r) {
            $owner = $r['owner_type'] === 'role' ? ($r['role_name'] !== null ? self::ownerLabel('role', $r['role_name']) : null)
                : ($r['owner_type'] === 'group' ? ($r['group_name'] !== null ? self::ownerLabel('group', $r['group_name']) : null)
                : ($r['username'] !== null && $r['user_role'] !== 'admin' ? self::ownerLabel('user', $r['username']) : null));
            if ($owner === null) {
                continue;
            }
            foreach (self::lockedBy($r, $model, $tablesOf) as [$t, $prop]) {
                if ($prop === null) {
                    $out['entities'][$t]["{$r['owner_type']}:{$r['owner_id']}"] = $owner;
                } else {
                    $out['fields'][$t][$prop]["{$r['owner_type']}:{$r['owner_id']}"] = $owner;
                }
            }
        }
        return $out;
    }

    /**
     * Who cannot fill a required media field of an entity because the system area 'media' is missing (default: denied)?
     * For the schema analysis (SchemaMigration: "Berechtigungs-Konflikt"). Returns a function: table of the entity in the
     * active schema (null = new entity that nobody can have denied) => labels as in denials().
     *
     * Affected is an active user without role = 'admin' who effectively has no access to 'media' (no allow, or
     * a deny somewhere) and has not denied the entity. Named are:
     *  - groups and roles that do not grant 'media' themselves (a group not via its roles either) and do not deny the
     *    entity - unless they have members and none of them is affected (all get 'media' from elsewhere);
     *  - affected users who belong to none of the named groups/roles (e.g. editors without any role).
     */
    public static function mediaBlocked(PDO $pdo, array $model): callable
    {
        $data = null;
        return function (?string $table) use ($pdo, $model, &$data): array {
            $data = $data ?? self::mediaAccessData($pdo, $model);
            $lacks = function (array $keys) use ($data, $table): bool {
                if ($table !== null && self::locks($data, $keys, $table)) {
                    return false; // does not create anything there anyway
                }
                $allow = false;
                foreach ($keys as $k) {
                    if (isset($data['deny'][$k])) {
                        return true;
                    }
                    $allow = $allow || isset($data['allow'][$k]);
                }
                return !$allow;
            };
            $affected = [];
            foreach ($data['users'] as $uid => $u) {
                if ($lacks($u['keys'])) {
                    $affected[$uid] = true;
                }
            }
            $out = [];
            $explained = [];
            foreach ($data['owners'] as $o) {
                if (!$lacks($o['keys']) || ($o['members'] && !array_intersect_key($o['members'], $affected))) {
                    continue;
                }
                $out[] = $o['label'];
                $explained += $o['members'];
            }
            foreach (array_diff_key($affected, $explained) as $uid => $_) {
                $out[] = self::ownerLabel('user', $data['users'][$uid]['username']);
            }
            return $out;
        };
    }

    /** Does one of the owner keys deny the table? */
    private static function locks(array $data, array $keys, string $table): bool
    {
        foreach ($keys as $k) {
            if (isset($data['locked'][$k][$table])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Basis for mediaBlocked(): per owner key ("role:3") allow/deny on 'media' and denied tables; groups
     * and roles (in this order) with their keys and members; active non-admins with all keys from
     * which their effective permission results (as in access()).
     */
    private static function mediaAccessData(PDO $pdo, array $model): array
    {
        $data = ['allow' => [], 'deny' => [], 'locked' => [], 'owners' => [], 'users' => []];
        foreach ($pdo->query("SELECT id, username FROM users WHERE role <> 'admin' AND active = 1 ORDER BY id")->fetchAll() as $u) {
            $data['users'][(int) $u['id']] = ['username' => $u['username'], 'keys' => ['user:' . $u['id']]];
        }
        if (!Database::tableExists($pdo, 'permissions')) {
            return $data;
        }
        $tablesOf = self::tableResolver($pdo, $model);
        $rows = $pdo->query(
            'SELECT p.owner_type, p.owner_id, p.scope, p.effect, p.systable_name, s.kind, s.entity_name, s.name
               FROM permissions p LEFT JOIN schema_ids s ON s.id = p.ref_id
              WHERE (p.scope = \'systable\' AND p.systable_name = \'media\') OR (p.scope = \'entity\' AND p.effect = \'deny\')'
        )->fetchAll();
        foreach ($rows as $r) {
            $key = "{$r['owner_type']}:{$r['owner_id']}";
            if ($r['scope'] === 'systable') {
                $data[$r['effect']][$key] = true;
                continue;
            }
            foreach ($r['kind'] === null ? [] : self::lockedBy($r, $model, $tablesOf) as [$t]) {
                $data['locked'][$key][$t] = true;
            }
        }
        $groupRoles = self::pairs($pdo, 'SELECT group_id, role_id FROM group_roles ORDER BY role_id');
        $groupUsers = self::pairs($pdo, 'SELECT group_id, user_id FROM user_groups');
        $roleUsers = self::pairs($pdo, 'SELECT role_id, user_id FROM user_roles');
        $members = function (array $ids) use ($data): array {
            return array_intersect_key(array_fill_keys($ids, true), $data['users']);
        };
        $roleMembers = [];
        foreach ($pdo->query('SELECT id, name FROM "groups" ORDER BY id')->fetchAll() as $g) {
            $id = (int) $g['id'];
            $keys = ["group:$id"];
            foreach ($groupRoles[$id] ?? [] as $rid) {
                $keys[] = "role:$rid";
                $roleMembers[$rid] = ($roleMembers[$rid] ?? []) + $members($groupUsers[$id] ?? []);
            }
            foreach ($groupUsers[$id] ?? [] as $uid) {
                if (isset($data['users'][$uid])) {
                    array_push($data['users'][$uid]['keys'], ...$keys);
                }
            }
            $data['owners'][] = ['label' => self::ownerLabel('group', $g['name']), 'keys' => $keys, 'members' => $members($groupUsers[$id] ?? [])];
        }
        foreach ($pdo->query('SELECT id, name FROM roles WHERE is_admin = 0 ORDER BY id')->fetchAll() as $r) {
            $id = (int) $r['id'];
            foreach ($roleUsers[$id] ?? [] as $uid) {
                if (isset($data['users'][$uid])) {
                    $data['users'][$uid]['keys'][] = "role:$id";
                }
            }
            $data['owners'][] = ['label' => self::ownerLabel('role', $r['name']), 'keys' => ["role:$id"],
                'members' => ($roleMembers[$id] ?? []) + $members($roleUsers[$id] ?? [])];
        }
        return $data;
    }

    /**
     * Required media fields of the entities this owner has access to (no denial of its own on the entity). With
     * an explicit denial on the system area 'media' they could not be filled.
     *
     * @return array{0:string[],1:string[]} ["Class.field", ...], [Class, ...]
     */
    private static function requiredMediaFor(PDO $pdo, string $ownerType, int $ownerId): array
    {
        $model = Database::loadModel($pdo);
        if ($model === null) {
            return [[], []];
        }
        $stmt = $pdo->prepare(
            'SELECT p.scope, s.kind, s.entity_name, s.name FROM permissions p JOIN schema_ids s ON s.id = p.ref_id
              WHERE p.owner_type = ? AND p.owner_id = ? AND p.scope = \'entity\' AND p.effect = \'deny\''
        );
        $stmt->execute([$ownerType, $ownerId]);
        $tablesOf = self::tableResolver($pdo, $model);
        $locked = [];
        foreach ($stmt->fetchAll() as $r) {
            foreach (self::lockedBy($r, $model, $tablesOf) as [$t]) {
                $locked[$t] = true;
            }
        }
        $fields = [];
        $entities = [];
        foreach ($model['entities'] as $table => $e) {
            foreach (isset($locked[$table]) ? [] : $e['media'] ?? [] as $m) {
                if ($m['required']) {
                    $fields[] = "{$e['name']}.{$m['name']}";
                    $entities[$e['name']] = true;
                }
            }
        }
        return [$fields, array_keys($entities)];
    }

    /** Table of a class => tables for which a permission on it applies (abstract class: all subclasses) */
    private static function tableResolver(PDO $pdo, array $model): callable
    {
        $descendants = null; // abstract class => tables of its subclasses, only on demand
        return function (string $entity) use ($pdo, $model, &$descendants): array {
            if (isset($model['entities'][$entity])) {
                return [$entity];
            }
            $descendants = $descendants ?? self::descendants($pdo);
            return $descendants[$entity] ?? [];
        };
    }

    /**
     * What a deny row (scope entity/field with kind, entity_name, name from schema_ids) denies.
     *
     * @return array<int,array{0:string,1:?string}> [table, null] = whole entity, [table, API name] = property
     */
    private static function lockedBy(array $r, array $model, callable $tablesOf): array
    {
        $out = [];
        if ($r['scope'] === 'action') {
            return $out; // action permission: denies neither entity nor property
        }
        if ($r['scope'] === 'entity') {
            foreach ($r['kind'] === 'entity' ? $tablesOf((string) $r['entity_name']) : [] as $t) {
                $out[] = [$t, null];
            }
        } elseif ($r['kind'] === 'field' || $r['kind'] === 'media') {
            foreach ($tablesOf((string) $r['entity_name']) as $t) {
                $out[] = [$t, (string) $r['name']];
            }
        } elseif ($r['kind'] === 'relation') {
            $out = self::relationProps($model, (string) $r['entity_name'], (string) $r['name']);
        }
        return $out;
    }

    /**
     * Required relationships (n:1 or 1:1 without ?) of other entities to the entity with this stable ID (abstract class:
     * to one of its subclasses). If the entity were denied, the FK column would stay visible there (see access()), but its
     * selection could not be loaded. Relationships within the denied area (self-reference) do not count.
     *
     * @return string[] "Class.column"
     */
    private static function requiredRelationsTo(PDO $pdo, string $refId): array
    {
        $stmt = $pdo->prepare("SELECT entity_name FROM schema_ids WHERE id = ? AND kind = 'entity'");
        $stmt->execute([$refId]);
        $model = Database::loadModel($pdo);
        $locked = array_flip(self::tableResolver($pdo, $model)((string) $stmt->fetchColumn()));
        $out = [];
        foreach ($model['entities'] as $table => $e) {
            foreach (isset($locked[$table]) ? [] : $e['fields'] as $f) {
                if ($f['foreign_key'] && $f['required'] && isset($locked[$f['foreign_key']['table']])) {
                    $out[] = "{$e['name']}.{$f['name']}";
                }
            }
        }
        return $out;
    }

    /**
     * API names under which a relationship appears in the rows: the FK column of the class or the n:n list(s) with this
     * link table.
     *
     * @return array<int,array{0:string,1:string}> [table, API name]
     */
    private static function relationProps(array $model, string $table, string $name): array
    {
        foreach ($model['entities'][$table]['fields'] ?? [] as $f) {
            if ($f['name'] === $name && $f['foreign_key']) {
                return [[$table, $name]];
            }
        }
        $out = [];
        foreach ($model['entities'] as $t => $e) {
            foreach ($e['many_to_many'] as $m) {
                if ($m['junction'] === $name) {
                    $out[] = [$t, $m['name']];
                }
            }
        }
        return $out;
    }

    /** Table of an (abstract) class => tables of all concrete subclasses (fields are copied when inheriting) */
    private static function descendants(PDO $pdo): array
    {
        $json = self::modelJson($pdo);
        if ($json === null) {
            return [];
        }
        $byName = array_column($json['entities'], null, 'name');
        $out = [];
        foreach ($json['entities'] as $e) {
            if ($e['abstract']) {
                continue;
            }
            for ($c = $e; $c['extends'] !== null && isset($byName[$c['extends']]);) {
                $c = $byName[$c['extends']];
                $out[$c['table']][] = $e['table'];
            }
        }
        return $out;
    }

    private static function modelJson(PDO $pdo): ?array
    {
        $source = SchemaIds::activeSource($pdo);
        if ($source === null) {
            return null;
        }
        try {
            return PumlParser::modelJson($source, Workflows::active($pdo));
        } catch (SchemaException $e) {
            return null;
        }
    }

    /** Delete permissions of removed schema elements (SchemaIds::delete(), also within a migration) */
    public static function deleteRefs(PDO $pdo, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, 'permissions')) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM permissions WHERE ref_id IN ($in)")->execute($chunk);
        }
    }

    // ---------------------------------------------------------------- Targets (schema elements)

    /**
     * Everything a permission can refer to: system areas and the elements of the active schema with their
     * stable ID. 'ref' is the readable key (model_key, e.g. "field:produkt.internal_cost") - POST/PUT accept it
     * instead of ref_id and resolve it into the ID immediately. An action permission (scope action, see 'actions') targets
     * an element with scope entity. 'lockable' = false: deny is rejected (required field or id).
     * In addition, an entity cannot be denied as long as another entity has a required relationship to it (422
     * required_relation, see requiredRelationsTo()).
     */
    public static function targets(PDO $pdo): array
    {
        return ['systables' => self::SYSTABLES, 'actions' => self::ACTIONS, 'elements' => array_values(self::elements($pdo))];
    }

    /** @return array<string,array> stable ID => element */
    private static function elements(PDO $pdo): array
    {
        SchemaIds::ensure($pdo);
        $json = self::modelJson($pdo);
        $model = Database::loadModel($pdo);
        if ($json === null || $model === null) {
            throw ApiException::of(409, 'no_active_schema', Msg::of('msg_permissions.no_schema', 'The active schema is not readable - '
                . 'permissions on entities and fields can only be set after /bootstrap or applying the schema.'));
        }
        $ids = SchemaIds::lookup($pdo);
        $tableOf = array_column($json['entities'], 'table', 'name');
        $out = [];
        $add = function (string $key, string $scope, string $kind, string $entity, string $name, bool $required, bool $primary = false, bool $workflow = false) use ($ids, &$out) {
            if (isset($ids[$key])) {
                $out[$ids[$key]] = ['ref_id' => $ids[$key], 'ref' => $key, 'scope' => $scope, 'kind' => $kind, 'entity' => $entity,
                    'name' => $name, 'required' => $required, 'lockable' => !$required && !$primary]
                    // workflow field: stands outside the field permissions (see validate()); the key is missing for all others
                    + ($workflow ? ['workflow' => true] : []);
            }
        };
        foreach ($json['entities'] as $e) {
            $add($e['id'], 'entity', 'entity', $e['name'], $e['name'], false);
            foreach ($e['fields'] as $f) {
                $add($f['id'], 'field', 'field', $e['name'], $f['name'], (bool) $f['required'], (bool) $f['primary'], !empty($f['workflow']));
            }
            foreach ($e['media'] as $m) {
                $add($m['id'], 'field', 'media', $e['name'], $m['name'], (bool) $m['required']);
            }
        }
        foreach ($json['relations'] as $r) {
            $required = false;
            foreach ($model['entities'][$tableOf[$r['from_entity']]]['fields'] ?? [] as $f) {
                if ($r['own_column'] !== null && $f['name'] === $r['own_column']) {
                    $required = (bool) $f['required'];
                }
            }
            $add($r['id'], 'field', 'relation', $r['from_entity'], (string) ($r['own_column'] ?? $r['junction']), $required);
        }
        return $out;
    }

    // ---------------------------------------------------------------- Roles

    public static function roles(PDO $pdo): array
    {
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'name' => $r['name'], 'is_admin' => (bool) (int) $r['is_admin']];
        }, $pdo->query('SELECT id, name, is_admin FROM roles ORDER BY id')->fetchAll());
    }

    public static function createRole(PDO $pdo, array $body): array
    {
        if (!empty($body['is_admin'])) {
            throw self::oneAdminRole();
        }
        $name = self::validName($body);
        self::unique(function () use ($pdo, $name) {
            $pdo->prepare('INSERT INTO roles (name, is_admin) VALUES (?, 0)')->execute([$name]);
        }, Msg::of('msg_permissions.role_exists', "The role '{name}' already exists", ['name' => $name]));
        return self::role($pdo, (int) $pdo->lastInsertId());
    }

    public static function updateRole(PDO $pdo, int $id, array $body): array
    {
        self::customRole($pdo, $id, true);
        if (!empty($body['is_admin'])) {
            throw self::oneAdminRole();
        }
        $name = self::validName($body);
        self::unique(function () use ($pdo, $name, $id) {
            $pdo->prepare('UPDATE roles SET name = ? WHERE id = ?')->execute([$name, $id]);
        }, Msg::of('msg_permissions.role_exists', "The role '{name}' already exists", ['name' => $name]));
        return self::role($pdo, $id);
    }

    public static function deleteRole(PDO $pdo, int $id): array
    {
        self::customRole($pdo, $id, false);
        self::transaction($pdo, function () use ($pdo, $id) {
            $pdo->prepare("DELETE FROM permissions WHERE owner_type = 'role' AND owner_id = ?")->execute([$id]);
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]); // assignments: ON DELETE CASCADE
        });
        return ['deleted' => $id];
    }

    private static function role(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT id, name, is_admin FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_permissions.role_not_found', 'Role not found'));
        }
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'is_admin' => (bool) (int) $r['is_admin']];
    }

    /** 404 unknown, 409 for the built-in admin role */
    private static function customRole(PDO $pdo, int $id, bool $rename): array
    {
        $role = self::role($pdo, $id);
        if ($role['is_admin']) {
            throw ApiException::of(409, 'builtin_role', $rename
                ? Msg::of('msg_permissions.builtin_rename', "The built-in role '{name}' cannot be renamed", ['name' => $role['name']])
                : Msg::of('msg_permissions.builtin_delete', "The built-in role '{name}' cannot be deleted", ['name' => $role['name']]));
        }
        return $role;
    }

    private static function oneAdminRole(): ApiException
    {
        return ApiException::of(422, 'validation', Msg::of('msg_permissions.one_admin_role', 'There is exactly one built-in admin role'),
            ApiException::fields(['is_admin' => Msg::of('msg_permissions.not_settable', 'cannot be set')]));
    }

    /** "Role 'X'", "Group 'X'", "User 'X'" - owner in messages of the schema analysis */
    public static function ownerLabel(string $type, string $name): Msg
    {
        if ($type === 'role') {
            return Msg::of('msg_permissions.owner_role', "Role '{name}'", ['name' => $name]);
        }
        if ($type === 'group') {
            return Msg::of('msg_permissions.owner_group', "Group '{name}'", ['name' => $name]);
        }
        return Msg::of('msg_permissions.owner_user', "User '{name}'", ['name' => $name]);
    }

    // ---------------------------------------------------------------- Groups

    public static function groups(PDO $pdo): array
    {
        $roles = self::pairs($pdo, 'SELECT group_id, role_id FROM group_roles ORDER BY role_id');
        $users = self::pairs($pdo, 'SELECT group_id, user_id FROM user_groups ORDER BY user_id');
        return array_map(function ($g) use ($roles, $users) {
            $id = (int) $g['id'];
            return ['id' => $id, 'name' => $g['name'], 'role_ids' => $roles[$id] ?? [], 'user_ids' => $users[$id] ?? []];
        }, $pdo->query('SELECT id, name FROM "groups" ORDER BY id')->fetchAll());
    }

    public static function createGroup(PDO $pdo, array $body): array
    {
        $name = self::validName($body);
        self::unique(function () use ($pdo, $name) {
            $pdo->prepare('INSERT INTO "groups" (name) VALUES (?)')->execute([$name]);
        }, Msg::of('msg_permissions.group_exists', "The group '{name}' already exists", ['name' => $name]));
        return self::group($pdo, (int) $pdo->lastInsertId());
    }

    public static function updateGroup(PDO $pdo, int $id, array $body): array
    {
        self::group($pdo, $id);
        $name = self::validName($body);
        self::unique(function () use ($pdo, $name, $id) {
            $pdo->prepare('UPDATE "groups" SET name = ? WHERE id = ?')->execute([$name, $id]);
        }, Msg::of('msg_permissions.group_exists', "The group '{name}' already exists", ['name' => $name]));
        return self::group($pdo, $id);
    }

    public static function deleteGroup(PDO $pdo, int $id): array
    {
        self::group($pdo, $id);
        self::transaction($pdo, function () use ($pdo, $id) {
            $pdo->prepare("DELETE FROM permissions WHERE owner_type = 'group' AND owner_id = ?")->execute([$id]);
            $pdo->prepare('DELETE FROM "groups" WHERE id = ?')->execute([$id]);
        });
        return ['deleted' => $id];
    }

    private static function group(PDO $pdo, int $id): array
    {
        foreach (self::groups($pdo) as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        throw ApiException::of(404, 'not_found', Msg::of('msg_permissions.group_not_found', 'Group not found'));
    }

    // ---------------------------------------------------------------- Assignments

    public static function userAssignments(PDO $pdo, int $userId): array
    {
        self::user($pdo, $userId);
        return [
            'user_id'   => $userId,
            'role_ids'  => self::column($pdo, 'SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id', $userId),
            'group_ids' => self::column($pdo, 'SELECT group_id FROM user_groups WHERE user_id = ? ORDER BY group_id', $userId),
        ];
    }

    /** PUT /api/_users/{id}/roles, body {role_ids: [...]}: replaces the directly assigned roles */
    public static function setUserRoles(PDO $pdo, int $userId, array $body): array
    {
        self::user($pdo, $userId);
        self::replace($pdo, 'user_roles', 'user_id', $userId, 'role_id', self::roleIds($pdo, $body));
        return self::userAssignments($pdo, $userId);
    }

    /** PUT /api/_users/{id}/groups, body {group_ids: [...]} */
    public static function setUserGroups(PDO $pdo, int $userId, array $body): array
    {
        self::user($pdo, $userId);
        $ids = self::idList($body, 'group_ids');
        self::known($pdo, 'SELECT id FROM "groups"', $ids, 'group_ids', 'group');
        self::replace($pdo, 'user_groups', 'user_id', $userId, 'group_id', $ids);
        return self::userAssignments($pdo, $userId);
    }

    /** PUT /api/_groups/{id}/roles, body {role_ids: [...]} */
    public static function setGroupRoles(PDO $pdo, int $groupId, array $body): array
    {
        self::group($pdo, $groupId);
        self::replace($pdo, 'group_roles', 'group_id', $groupId, 'role_id', self::roleIds($pdo, $body));
        return self::group($pdo, $groupId);
    }

    /** role_ids from the body: known roles, never the built-in admin role (one only becomes an admin via users.role) */
    private static function roleIds(PDO $pdo, array $body): array
    {
        $ids = self::idList($body, 'role_ids');
        self::known($pdo, 'SELECT id FROM roles', $ids, 'role_ids', 'role');
        $admin = array_map('intval', $pdo->query('SELECT id FROM roles WHERE is_admin = 1')->fetchAll(PDO::FETCH_COLUMN));
        if (array_intersect($ids, $admin)) {
            throw ApiException::of(422, 'validation', Msg::of('msg_permissions.admin_not_assignable', 'The built-in admin role cannot '
                . "be assigned - administrators are set in the user management (role = 'admin')."), ApiException::fields([
                'role_ids' => Msg::of('msg_permissions.admin_not_assignable_short', 'Admin role cannot be assigned'),
            ]));
        }
        return $ids;
    }

    // ---------------------------------------------------------------- Permissions

    /** @param array $query optional owner_type, owner_id (filter) */
    public static function all(PDO $pdo, array $query = []): array
    {
        $where = [];
        $params = [];
        if (isset($query['owner_type']) && is_string($query['owner_type'])) {
            $where[] = 'p.owner_type = ?';
            $params[] = $query['owner_type'];
        }
        if (isset($query['owner_id']) && is_scalar($query['owner_id'])) {
            $where[] = 'p.owner_id = ?';
            $params[] = (int) $query['owner_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT p.*, s.kind, s.entity_name, s.name, s.model_key FROM permissions p LEFT JOIN schema_ids s ON s.id = p.ref_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.id'
        );
        $stmt->execute($params);
        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    public static function find(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.*, s.kind, s.entity_name, s.name, s.model_key FROM permissions p LEFT JOIN schema_ids s ON s.id = p.ref_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_permissions.not_found', 'Permission not found'));
        }
        return self::present($row);
    }

    /** Body {owner_type, owner_id, scope, ref_id | ref | systable_name, effect}, for scope action additionally action */
    public static function create(PDO $pdo, array $body): array
    {
        $v = self::validate($pdo, $body, null);
        $pdo->prepare('INSERT INTO permissions (owner_type, owner_id, scope, ref_id, systable_name, effect, action) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$v['owner_type'], $v['owner_id'], $v['scope'], $v['ref_id'], $v['systable_name'], $v['effect'], $v['action']]);
        return self::find($pdo, (int) $pdo->lastInsertId());
    }

    /** Like create(), fields not sent keep their value; the whole future row is checked */
    public static function update(PDO $pdo, int $id, array $body): array
    {
        $current = self::find($pdo, $id);
        $merged = $body + array_intersect_key($current, array_flip(['owner_type', 'owner_id', 'scope', 'effect']));
        $scope = $merged['scope'];
        // only carry over the target if the body names none and the scope stays the same
        if ($scope === $current['scope'] && !array_key_exists('ref_id', $body) && !array_key_exists('ref', $body)
            && !array_key_exists('systable_name', $body)) {
            $merged['ref_id'] = $current['ref_id'];
            $merged['systable_name'] = $current['systable_name'];
        }
        if ($scope === $current['scope'] && !array_key_exists('action', $body)) {
            $merged['action'] = $current['action'];
        }
        $v = self::validate($pdo, $merged, $id, $current);
        $pdo->prepare('UPDATE permissions SET owner_type = ?, owner_id = ?, scope = ?, ref_id = ?, systable_name = ?, effect = ?, action = ? WHERE id = ?')
            ->execute([$v['owner_type'], $v['owner_id'], $v['scope'], $v['ref_id'], $v['systable_name'], $v['effect'], $v['action'], $id]);
        return self::find($pdo, $id);
    }

    /**
     * GET /api/_permissions/inherited?owner_type=&owner_id=: permissions an owner receives from OTHER sources - for
     * the origin display in the permission editor ("Bereits gesperrt durch Gruppe 'X'").
     *  - user: directly assigned roles, their groups and the roles of these groups ('via' = name of the group);
     *  - group: only the roles assigned to the group (what else a member has is not known from the group's point of view);
     *  - role: nothing (a role has no superordinate source).
     * Per target (scope + ref_id/systable_name + action) and effect one row with all sources, every source once (directly
     * assigned takes precedence over "via group"). The target itself is compared, as the editor shows it too - a denial on
     * an abstract class therefore appears there, not at its subclasses.
     *
     * @return array<int,array{scope:string,ref_id:?string,systable_name:?string,action:?string,effect:string,sources:array}>
     */
    public static function inherited(PDO $pdo, array $query): array
    {
        $ownerType = $query['owner_type'] ?? null;
        $ownerId = $query['owner_id'] ?? null;
        if (!in_array($ownerType, self::OWNER_TYPES, true) || !is_scalar($ownerId) || !preg_match('/^[0-9]+$/', (string) $ownerId)) {
            throw ApiException::invalid(['owner_type' => Msg::of('msg_permissions.owner_expected', 'role, group or user and owner_id expected')]);
        }
        $ownerId = (int) $ownerId;
        // source "type:id" => [type, id, name, via]; the order here is the order in the display
        $sources = [];
        $add = function (string $type, array $row, ?string $via) use (&$sources) {
            $key = "$type:{$row['id']}";
            $sources[$key] = $sources[$key] ?? ['type' => $type, 'id' => (int) $row['id'], 'name' => $row['name'], 'via' => $via];
        };
        $rows = function (string $sql) use ($pdo, $ownerId): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ownerId]);
            return $stmt->fetchAll();
        };
        if ($ownerType === 'user') {
            self::user($pdo, $ownerId);
            foreach ($rows('SELECT r.id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id') as $r) {
                $add('role', $r, null);
            }
            foreach ($rows('SELECT g.id, g.name FROM user_groups ug JOIN "groups" g ON g.id = ug.group_id WHERE ug.user_id = ? ORDER BY g.id') as $g) {
                $add('group', $g, null);
            }
            foreach ($rows('SELECT r.id, r.name, g.name AS via FROM user_groups ug JOIN "groups" g ON g.id = ug.group_id
                              JOIN group_roles gr ON gr.group_id = g.id JOIN roles r ON r.id = gr.role_id
                             WHERE ug.user_id = ? ORDER BY g.id, r.id') as $r) {
                $add('role', $r, $r['via']);
            }
        } elseif ($ownerType === 'group') {
            self::group($pdo, $ownerId);
            foreach ($rows('SELECT r.id, r.name FROM group_roles gr JOIN roles r ON r.id = gr.role_id WHERE gr.group_id = ? ORDER BY r.id') as $r) {
                $add('role', $r, null);
            }
        } else {
            self::role($pdo, $ownerId);
        }
        $out = [];
        $order = array_flip(array_keys($sources));
        foreach ($sources ? $pdo->query('SELECT * FROM permissions ORDER BY id')->fetchAll() : [] as $p) {
            $source = "{$p['owner_type']}:{$p['owner_id']}";
            if (!isset($sources[$source])) {
                continue;
            }
            $key = "{$p['scope']}|{$p['ref_id']}|{$p['systable_name']}|{$p['action']}|{$p['effect']}";
            $out[$key] = $out[$key] ?? ['scope' => $p['scope'], 'ref_id' => $p['ref_id'], 'systable_name' => $p['systable_name'],
                'action' => $p['action'], 'effect' => $p['effect'], 'sources' => []];
            $out[$key]['sources'][$order[$source]] = $sources[$source];
        }
        return array_values(array_map(function (array $row) {
            ksort($row['sources']);
            $row['sources'] = array_values($row['sources']);
            return $row;
        }, $out));
    }

    public static function delete(PDO $pdo, int $id): array
    {
        self::find($pdo, $id);
        $pdo->prepare('DELETE FROM permissions WHERE id = ?')->execute([$id]);
        return ['deleted' => $id];
    }

    /**
     * Checks a future permissions row completely; throws 422 (input, denial of a required field, entity denial despite
     * an incoming required relationship - the same for role, group and user) or 409 (the same row already
     * exists). Nothing is created as long as something is wrong.
     *
     * The entity denial despite a required relationship is only rejected where it newly arises: if the row was already a
     * denial (entity, deny) on the same entity before, a PUT (e.g. different owner) does not change the denial situation -
     * the conflict is then already known (a migration added the required relationship afterwards, see
     * SchemaMigration: "Berechtigungs-Konflikt"). The same applies to an explicit denial on the system area 'media'
     * as long as the owner has access to an entity with a required media field (422 required_media).
     *
     * @param int|null $id own row when changing
     * @param array|null $current previous row when changing (as from find())
     * @return array{owner_type:string,owner_id:int,scope:string,ref_id:?string,systable_name:?string,effect:string,action:?string}
     */
    private static function validate(PDO $pdo, array $body, ?int $id, ?array $current = null): array
    {
        $errors = [];
        $ownerType = $body['owner_type'] ?? null;
        $ownerId = $body['owner_id'] ?? null;
        $scope = $body['scope'] ?? null;
        $effect = $body['effect'] ?? null;
        if (!in_array($ownerType, self::OWNER_TYPES, true)) {
            $errors['owner_type'] = Msg::of('msg_permissions.owner_type_expected', 'role, group or user expected');
        }
        if (!is_int($ownerId)) {
            $errors['owner_id'] = Msg::of('msg_api.id_expected', 'ID expected');
        }
        if (!in_array($scope, self::SCOPES, true)) {
            $errors['scope'] = Msg::of('msg_permissions.scope_expected', 'entity, field, systable or action expected');
        }
        if (!in_array($effect, self::EFFECTS, true)) {
            $errors['effect'] = Msg::of('msg_permissions.effect_expected', 'allow or deny expected');
        }
        if (!isset($errors['owner_type']) && !isset($errors['owner_id'])) {
            if ($ownerType === 'role') {
                $role = self::role($pdo, $ownerId);
                if ($role['is_admin']) {
                    throw ApiException::of(422, 'validation', Msg::of('msg_permissions.admin_full_access', "The built-in role '{name}' "
                        . 'always has full access - permissions on it would have no effect.', ['name' => $role['name']]), ApiException::fields([
                        'owner_id' => Msg::of('msg_permissions.admin_role', 'Admin role'),
                    ]));
                }
            } elseif ($ownerType === 'group') {
                self::group($pdo, $ownerId);
            } else {
                self::user($pdo, $ownerId);
            }
        }
        $refId = null;
        $systable = null;
        $action = null;
        $message = null;
        $extra = [];
        if ($scope === 'action') {
            $action = $body['action'] ?? null;
            if (!in_array($action, self::ACTIONS, true)) {
                $errors['action'] = Msg::of('msg_permissions.one_of', 'one of: {values}', ['values' => implode(', ', self::ACTIONS)]);
            }
        } elseif (($body['action'] ?? null) !== null) {
            $errors['action'] = Msg::of('msg_permissions.only_action', 'only with scope action');
        }
        if ($scope === 'systable') {
            $systable = $body['systable_name'] ?? null;
            if (!in_array($systable, self::SYSTABLES, true)) {
                $errors['systable_name'] = Msg::of('msg_permissions.one_of', 'one of: {values}', ['values' => implode(', ', self::SYSTABLES)]);
            }
            if (($body['ref_id'] ?? null) !== null || ($body['ref'] ?? null) !== null) {
                $errors['ref_id'] = Msg::of('msg_permissions.only_ref', 'only with scope entity/field/action');
            }
            // explicit denial on 'media' (not the default without allow): only where it newly arises, see above
            if (!$errors && $systable === 'media' && $effect === 'deny'
                && !($current !== null && $current['systable_name'] === 'media' && $current['effect'] === 'deny')) {
                [$fields, $entities] = self::requiredMediaFor($pdo, $ownerType, $ownerId);
                if ($fields) {
                    $message = self::mediaMessage($ownerType, count($fields) === 1, [
                        'fields' => implode("', '", $fields), 'entities' => implode("', '", $entities),
                    ]);
                    $errors['systable_name'] = Msg::of('msg_permissions.required_media_short', 'Required media field');
                    $extra = ['error' => 'required_media', 'fields' => $fields];
                }
            }
        } elseif ($scope === 'entity' || $scope === 'field' || $scope === 'action') {
            if (($body['systable_name'] ?? null) !== null) {
                $errors['systable_name'] = Msg::of('msg_permissions.only_systable', 'only with scope systable');
            }
            $elements = self::elements($pdo);
            $el = null;
            if (is_string($body['ref_id'] ?? null)) {
                $el = $elements[$body['ref_id']] ?? null;
            } elseif (is_string($body['ref'] ?? null)) {
                foreach ($elements as $candidate) {
                    if (strtolower($candidate['ref']) === strtolower(trim($body['ref']))) {
                        $el = $candidate;
                    }
                }
            }
            if ($el === null) {
                $errors['ref_id'] = Msg::of('msg_permissions.ref_expected', 'stable ID (ref_id) or key (ref) of an element of the active '
                    . 'schema expected - see GET /api/_permissions/targets');
            } elseif ($el['scope'] !== ($scope === 'action' ? 'entity' : $scope)) {
                $errors['ref_id'] = $scope === 'field'
                    ? Msg::of('msg_permissions.field_expected', 'Field, media field or relationship expected')
                    : Msg::of('msg_permissions.entity_expected', 'Entity expected');
            } else {
                $refId = $el['ref_id'];
                if ($scope === 'field' && $effect === 'deny' && !empty($el['workflow'])) {
                    // state changes are controlled exclusively by allowed= of the transitions, never by the field denial
                    $message = Msg::of('msg_permissions.workflow_field', "The workflow field '{name}' is outside the field permissions: "
                        . 'who may change the state is set only by allowed= of the transitions in the workflow file.', ['name' => $el['name']]);
                    $errors['ref_id'] = Msg::of('msg_permissions.workflow_field_short', 'Workflow field');
                    $extra = ['error' => 'workflow_field'];
                } elseif ($scope === 'field' && $effect === 'deny' && $el['required']) {
                    $message = Msg::of('msg_permissions.required_field', "The required field '{name}' cannot be denied, since otherwise "
                        . 'no valid record could be created.', ['name' => $el['name']]);
                    $errors['ref_id'] = Msg::of('msg_api.required', 'Required field');
                } elseif ($scope === 'field' && $effect === 'deny' && !$el['lockable']) {
                    $message = Msg::of('msg_permissions.primary_key', "The field '{name}' (primary key) cannot be denied.", ['name' => $el['name']]);
                    $errors['ref_id'] = Msg::of('msg_permissions.primary_key_short', 'Primary key');
                } elseif ($scope === 'entity' && $effect === 'deny'
                    && !($current !== null && $current['scope'] === 'entity' && $current['effect'] === 'deny' && $current['ref_id'] === $refId)
                    && ($relations = self::requiredRelationsTo($pdo, $refId))) {
                    $params = ['name' => $el['name'], 'relations' => implode("', '", $relations)];
                    $message = count($relations) === 1
                        ? Msg::of('msg_permissions.required_relation', "The entity '{name}' cannot be denied: '{relations}' is a required "
                            . 'relationship to this entity.', $params)
                        : Msg::of('msg_permissions.required_relations', "The entity '{name}' cannot be denied: '{relations}' are required "
                            . 'relationships to this entity.', $params);
                    $errors['ref_id'] = Msg::of('msg_permissions.required_relation_short', 'Required relationship');
                    $extra = ['error' => 'required_relation', 'relations' => $relations];
                }
            }
        }
        if ($errors) {
            throw ApiException::of(422, $extra['error'] ?? ($message !== null ? 'required_field' : 'validation'),
                $message ?? Msg::of('msg_api.invalid_input', 'Invalid input'), $extra + ApiException::fields($errors));
        }
        // the same permission (owner + target) only once - allow and deny side by side would be deny anyway
        $stmt = $pdo->prepare('SELECT id FROM permissions WHERE owner_type = ? AND owner_id = ? AND scope = ?
                                AND ref_id IS ? AND systable_name IS ? AND action IS ? AND id IS NOT ?');
        $stmt->execute([$ownerType, $ownerId, $scope, $refId, $systable, $action, $id]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            throw ApiException::of(409, 'duplicate', Msg::of('msg_permissions.duplicate', 'There already is a permission for this owner '
                . 'and target (#{id}) - please change that one.', ['id' => (int) $existing]), ['existing_id' => (int) $existing]);
        }
        return ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'scope' => $scope, 'ref_id' => $refId,
            'systable_name' => $systable, 'effect' => $effect, 'action' => $action];
    }

    private static function present(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'owner_type'    => $r['owner_type'],
            'owner_id'      => (int) $r['owner_id'],
            'scope'         => $r['scope'],
            'ref_id'        => $r['ref_id'],
            'systable_name' => $r['systable_name'],
            'effect'        => $r['effect'],
            'action'        => $r['action'],
            // readable target in the active schema (current name - the binding itself is ref_id)
            'target'        => $r['kind'] !== null
                ? ['ref' => $r['model_key'], 'kind' => $r['kind'], 'table' => $r['entity_name'], 'name' => $r['name']]
                : null,
        ];
    }

    // ---------------------------------------------------------------- Helpers

    private static function user(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_users.not_found', 'User not found'));
        }
    }

    private static function validName(array $body): string
    {
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || Languages::length($name) > self::NAME_MAX) {
            throw ApiException::invalid(['name' => $name === '' ? Msg::of('msg_api.required', 'Required field')
                : Msg::of('msg_permissions.max_chars', 'at most {max} characters', ['max' => self::NAME_MAX])]);
        }
        return $name;
    }

    /** @return int[] unique IDs from $body[$key] (422 if it is not a list of IDs) */
    private static function idList(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        $ok = is_array($list) && array_values($list) === $list;
        foreach ($ok ? $list : [] as $v) {
            $ok = $ok && is_int($v);
        }
        if (!$ok) {
            throw ApiException::invalid([$key => Msg::of('msg_permissions.ids_expected', 'List of IDs expected')]);
        }
        return array_values(array_unique($list));
    }

    /** @param string $what 'role' or 'group' */
    private static function known(PDO $pdo, string $sql, array $ids, string $key, string $what): void
    {
        $missing = array_diff($ids, array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)));
        if ($missing) {
            $params = ['ids' => '#' . implode(', #', $missing)];
            throw ApiException::of(422, 'validation', $what === 'role'
                ? Msg::of('msg_permissions.roles_missing', 'Role {ids} does not exist', $params)
                : Msg::of('msg_permissions.groups_missing', 'Group {ids} does not exist', $params),
                ApiException::fields([$key => Msg::of('msg_permissions.unknown_id', 'unknown ID')]));
        }
    }

    /**
     * 409 text for denying 'media' while required media fields need it
     *
     * @param array{fields:string,entities:string} $params
     */
    private static function mediaMessage(string $ownerType, bool $one, array $params): Msg
    {
        if ($ownerType === 'role') {
            return $one
                ? Msg::of('msg_permissions.media_role_one', "The system area 'media' cannot be denied: '{fields}' is a required media "
                    . "field, and this role has access to '{entities}'.", $params)
                : Msg::of('msg_permissions.media_role_many', "The system area 'media' cannot be denied: '{fields}' are required media "
                    . "fields, and this role has access to '{entities}'.", $params);
        }
        if ($ownerType === 'group') {
            return $one
                ? Msg::of('msg_permissions.media_group_one', "The system area 'media' cannot be denied: '{fields}' is a required media "
                    . "field, and this group has access to '{entities}'.", $params)
                : Msg::of('msg_permissions.media_group_many', "The system area 'media' cannot be denied: '{fields}' are required media "
                    . "fields, and this group has access to '{entities}'.", $params);
        }
        return $one
            ? Msg::of('msg_permissions.media_user_one', "The system area 'media' cannot be denied: '{fields}' is a required media "
                . "field, and this user has access to '{entities}'.", $params)
            : Msg::of('msg_permissions.media_user_many', "The system area 'media' cannot be denied: '{fields}' are required media "
                . "fields, and this user has access to '{entities}'.", $params);
    }

    /** Completely replace the assignments of one side ($table/$ownerCol/$col are fixed identifiers of this class) */
    private static function replace(PDO $pdo, string $table, string $ownerCol, int $ownerId, string $col, array $ids): void
    {
        self::transaction($pdo, function () use ($pdo, $table, $ownerCol, $ownerId, $col, $ids) {
            $pdo->prepare("DELETE FROM $table WHERE $ownerCol = ?")->execute([$ownerId]);
            $ins = $pdo->prepare("INSERT INTO $table ($ownerCol, $col) VALUES (?, ?)");
            foreach ($ids as $id) {
                $ins->execute([$ownerId, $id]);
            }
        });
    }

    /** @return array<int,int[]> first column => values of the second */
    private static function pairs(PDO $pdo, string $sql): array
    {
        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as [$a, $b]) {
            $out[(int) $a][] = (int) $b;
        }
        return $out;
    }

    /** @return int[] */
    private static function column(PDO $pdo, string $sql, int $param): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$param]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function unique(callable $write, Msg $message): void
    {
        try {
            $write();
        } catch (PDOException $ex) {
            if ((string) $ex->getCode() === '23000') {
                throw ApiException::of(409, 'duplicate', $message);
            }
            throw $ex;
        }
    }

    private static function transaction(PDO $pdo, callable $fn): void
    {
        $pdo->beginTransaction();
        try {
            $fn();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
