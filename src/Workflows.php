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
 * Workflows (phase 1): workflow files, active workflow file, assignment of groups/roles/users and tasks.
 *
 * Workflow files live in schema/workflows/ (placed there via FTP or created via System -> Workflows), end in .lhwf
 * (LessHead Workflow - a syntax of its own, not PlantUML; .puml stays reserved for the diagrams of the main schema) and
 * are not part of the bootstrap. At most one is active: _meta.active_workflow_file (file name), plus workflow_source (the
 * applied text) and workflow_hash. Work is always done with the applied text from the database, not with the file - a
 * file changed via FTP only takes effect after „Anwenden“ (SchemaMigration, the same path as any schema change, including
 * the backup). The definitions are also part of the model (model['workflows'], see PumlParser); Cms reads them from there.
 *
 * Tasks (system table lh_task - with a prefix so that a class "Tasks" stays available in the diagram; UI: „Meine
 * Aufgaben“, myTasks()): a transition with assign= creates an open task; every
 * further transition on the same record sets the ones open until then to 'done'. The table is created on the first
 * call of the workflow management or on the first transition.
 */
final class Workflows
{
    public const TASKS = 'lh_task';

    private const TASKS_DDL = "CREATE TABLE IF NOT EXISTS lh_task (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  transition_label TEXT NOT NULL,
  assigned_group_id INTEGER,
  assigned_user_id INTEGER,
  created_by INTEGER,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'done'))
)";

    /** Mandatory extension of the workflow files */
    public const EXT = '.lhwf';
    /** Extension from before .lhwf: no longer accepted or offered (see migrateLegacy()) */
    private const OLD_EXT = '.puml';

    private const FILE_NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.lhwf$/';
    private const NAME_MAX = 100;
    /** GET /api/_tasks delivers at most this many tasks */
    private const TASK_LIMIT = 500;

    // ---------------------------------------------------------------- Files

    public static function dir(): string
    {
        return Config::schemaDir() . '/workflows';
    }

    /**
     * Create the directory including its access block. Like schema/, it is already not directly retrievable because of the
     * rewrite rule of the main .htaccess (everything except assets/ and media/ goes through index.php); its own .htaccess
     * is an additional safeguard in case the rewrite rule is missing.
     */
    public static function ensureDir(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        return true;
    }

    /** Plain file name of a workflow file (no path components), otherwise 422 */
    public static function fileName($name): string
    {
        if (is_string($name) && self::hasOldExt($name)) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Workflow-Dateien enden auf ' . self::EXT
                . ' (nicht mehr auf ' . self::OLD_EXT . ').', 'errors' => ['file' => 'Endung ' . self::EXT . ' erwartet',
                'name' => 'Endung ' . self::EXT . ' erwartet']]);
        }
        if (!is_string($name) || !preg_match(self::FILE_NAME, $name) || strlen($name) > self::NAME_MAX) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültiger Dateiname (erlaubt: Buchstaben, '
                . 'Ziffern, _ und -, Endung ' . self::EXT . ').', 'errors' => ['file' => 'Dateiname wie artikel.lhwf erwartet']]);
        }
        return $name;
    }

    private static function hasOldExt(string $name): bool
    {
        return strcasecmp(substr($name, -strlen(self::OLD_EXT)), self::OLD_EXT) === 0;
    }

    /**
     * Switch from the old extension .puml to .lhwf, when System -> Workflows is opened (files()):
     * - The ACTIVE workflow file is switched automatically: the CMS knows for sure that it is a workflow file
     *   (it was applied), and without the switch the active state could no longer be edited. The name in _meta
     *   always changes; the file is renamed if it exists and the new name is free.
     * - All other *.puml in the folder stay untouched and are only reported: whether a .puml placed there via FTP
     *   is a workflow file or a diagram that ended up here by mistake, the CMS cannot know.
     *
     * @return array{renamed:array<int,array{from:string,to:string,file:bool}>,legacy:array<int,string>}
     */
    private static function migrateLegacy(PDO $pdo): array
    {
        $renamed = [];
        $active = self::activeFile($pdo);
        if ($active !== null && self::hasOldExt($active) && !Maintenance::active()) {
            $new = substr($active, 0, -strlen(self::OLD_EXT)) . self::EXT;
            $from = self::dir() . '/' . basename($active);
            $to = self::dir() . '/' . basename($new);
            $moved = is_file($from) && !file_exists($to) && @rename($from, $to);
            Database::setMeta($pdo, 'active_workflow_file', $new);
            $renamed[] = ['from' => $active, 'to' => $new, 'file' => $moved];
        }
        $legacy = [];
        foreach (scandir(self::dir()) ?: [] as $name) {
            if (self::hasOldExt($name) && is_file(self::dir() . '/' . $name)) {
                $legacy[] = $name;
            }
        }
        return ['renamed' => $renamed, 'legacy' => $legacy];
    }

    /**
     * GET /api/_workflow/files: all workflow files (*.lhwf), the active one, and whether the main schema uses workflows;
     * plus 'renamed' (active file just switched from .puml) and 'legacy' (files with the old extension, not offered)
     */
    public static function files(PDO $pdo): array
    {
        $old = is_dir(self::dir()) ? self::migrateLegacy($pdo) : ['renamed' => [], 'legacy' => []];
        $active = self::activeFile($pdo);
        $files = [];
        foreach (glob(self::dir() . '/*' . self::EXT) ?: [] as $path) {
            if (is_file($path)) {
                $files[] = ['name' => basename($path), 'size' => (int) filesize($path),
                    'modified' => date('Y-m-d H:i:s', (int) filemtime($path)), 'active' => basename($path) === $active];
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return [
            'files'  => $files,
            'active' => $active,
            // active, but the file is missing in the folder (e.g. deleted via FTP): the UI offers the applied text
            'active_missing' => $active !== null && !in_array($active, array_column($files, 'name'), true),
            'used'   => self::usedBy(Database::loadModel($pdo) ?? []),
        ] + $old;
    }

    /** GET /api/_workflow/source?file=: text of a file; for the active one additionally whether it matches the applied state */
    public static function source(PDO $pdo, $file): array
    {
        $name = self::fileName($file);
        $path = self::dir() . '/' . $name;
        $active = self::activeFile($pdo) === $name;
        if (!is_file($path)) {
            if (!$active) {
                throw new ApiException(404, ['error' => 'not_found', 'message' => "Die Workflow-Datei '$name' gibt es nicht."]);
            }
            $source = (string) Database::getMeta($pdo, 'workflow_source'); // active file is missing in the folder
            return ['file' => $name, 'source' => $source, 'active' => true, 'file_matches_active' => false, 'missing' => true];
        }
        $source = (string) file_get_contents($path);
        return [
            'file' => $name, 'source' => $source, 'active' => $active,
            'file_matches_active' => !$active
                || hash_equals((string) Database::getMeta($pdo, 'workflow_hash'), hash('sha256', $source)),
        ];
    }

    /**
     * POST /api/_workflow/files: body {name} - new, empty workflow file (".lhwf" is appended); 409 if it exists,
     * 422 for a name with the old extension .puml
     */
    public static function createFile(array $body): array
    {
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name !== '' && !self::hasOldExt($name) && !preg_match('/\.lhwf$/i', $name)) {
            $name .= self::EXT;
        }
        $name = self::fileName(preg_replace('/\.lhwf$/i', self::EXT, $name));
        if (!self::ensureDir() || !is_writable(self::dir())) {
            throw new ApiException(500, ['error' => 'workflow_not_writable', 'message' => 'Der Ordner schema/workflows/ kann '
                . 'nicht angelegt bzw. beschrieben werden - bitte Schreibrechte prüfen.']);
        }
        $handle = @fopen(self::dir() . '/' . $name, 'x'); // atomic: fails if the file already exists
        if ($handle === false) {
            throw new ApiException(409, ['error' => 'conflict', 'message' => "Die Workflow-Datei '$name' gibt es schon.",
                'errors' => ['name' => 'bereits vorhanden']]);
        }
        fclose($handle);
        return ['file' => $name, 'source' => '', 'active' => false, 'file_matches_active' => true];
    }

    // ---------------------------------------------------------------- active state

    public static function activeFile(PDO $pdo): ?string
    {
        return Database::tableExists($pdo, '_meta') ? Database::getMeta($pdo, 'active_workflow_file') : null;
    }

    /** Applied state as the candidate for SchemaMigration: ['file' => name|null, 'source' => text|null] */
    public static function applied(PDO $pdo): array
    {
        $file = self::activeFile($pdo);
        return ['file' => $file, 'source' => $file !== null ? (string) Database::getMeta($pdo, 'workflow_source') : null];
    }

    /**
     * Definitions of the active workflow file (for PumlParser::parse()/modelJson() with the active schema text); null =
     * no workflow file active.
     *
     * @return array<string,array>|null
     */
    public static function active(PDO $pdo): ?array
    {
        static $cache = [];
        $applied = self::applied($pdo);
        if ($applied['source'] === null) {
            return null;
        }
        $key = hash('sha256', $applied['source']);
        if (!isset($cache[$key])) {
            try {
                $cache = [$key => WorkflowParser::parse($applied['source'])];
            } catch (SchemaException $e) {
                $cache = [$key => []]; // applied means checked - just to be safe
            }
        }
        return $cache[$key];
    }

    /** Write or remove the active state in _meta (within the transaction of SchemaMigration) */
    public static function store(PDO $pdo, ?string $file, ?string $source): void
    {
        if ($file === null || $source === null) {
            $pdo->exec("DELETE FROM \"_meta\" WHERE \"key\" IN ('active_workflow_file', 'workflow_source', 'workflow_hash')");
            return;
        }
        Database::setMeta($pdo, 'active_workflow_file', $file);
        Database::setMeta($pdo, 'workflow_source', $source);
        Database::setMeta($pdo, 'workflow_hash', hash('sha256', $source));
    }

    /** @return array<int,array{entity:string,table:string,field:string,workflow:string}> workflow fields of the model */
    public static function usedBy(array $model): array
    {
        $out = [];
        foreach ($model['entities'] ?? [] as $e) {
            foreach ($e['fields'] as $f) {
                if (isset($f['workflow'])) {
                    $out[] = ['entity' => $e['name'], 'table' => $e['table'], 'field' => $f['name'], 'workflow' => $f['workflow']];
                }
            }
        }
        return $out;
    }

    /** Workflow field of an entity of the model (null = none) */
    public static function fieldOf(array $entity): ?array
    {
        foreach ($entity['fields'] as $f) {
            if (isset($f['workflow'])) {
                return $f;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Groups, roles, users

    /**
     * Combined analysis: does every group, role or user named in allowed=/assign= exist? All workflows of the file are
     * checked, including ones not used (yet).
     *
     * @param array<string,array> $workflows
     * @param array $positions WorkflowParser::positions() of the same file: the response then names the lines of the
     *        missing entries (workflow_line = the first one, workflow_lines = all, in the order of the message)
     * @throws ApiException 422 schema_error
     */
    public static function checkPrincipals(PDO $pdo, array $workflows, array $positions = []): void
    {
        $known = ['group' => [], 'role' => [], 'user' => []];
        $has = Database::tableExists($pdo, 'permissions');
        foreach (['group' => $has ? 'SELECT name FROM "groups"' : null, 'role' => $has ? 'SELECT name FROM roles' : null,
            'user' => 'SELECT username FROM users'] as $type => $sql) {
            foreach ($sql !== null ? $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) : [] as $name) {
                $known[$type][WorkflowParser::fold((string) $name)] = true;
            }
        }
        $known['role'][WorkflowParser::fold(Permissions::ADMIN_ROLE)] = true; // built-in, also before the first call of the rights management
        $what = ['group' => 'Gruppe', 'role' => 'Rolle', 'user' => 'Nutzer'];
        $missing = [];
        $lines = [];
        foreach ($workflows as $wfKey => $wf) {
            foreach ($wf['transitions'] as $ti => $t) {
                $refs = array_map(function ($a) {
                    return ['allowed', $a];
                }, $t['allowed']);
                if ($t['assign'] !== null && $t['assign']['type'] !== 'initiator') {
                    $refs[] = ['assign', $t['assign']];
                }
                foreach ($refs as [$key, $ref]) {
                    if (!isset($known[$ref['type']][WorkflowParser::fold($ref['name'])])) {
                        $missing[] = "Workflow '{$wf['name']}', Transition '{$t['label']}' ({$t['from']} -> {$t['to']}): "
                            . "{$what[$ref['type']]} '{$ref['name']}' aus $key= gibt es nicht";
                        $pos = $positions[$wfKey][$ti] ?? [];
                        $lines[] = $pos[$key] ?? $pos['line'] ?? null;
                    }
                }
            }
        }
        if ($missing) {
            $more = count($missing) - 10;
            throw new ApiException(422, ['error' => 'schema_error', 'message' => implode('; ', array_slice($missing, 0, 10))
                . ($more > 0 ? " und $more weitere" : '') . '. Bitte unter System → Rechteverwaltung anlegen oder die Workflow-Datei '
                . 'anpassen.'] + self::linePayload($lines));
        }
    }

    /**
     * Line reference of an error message about the workflow file for the response: workflow_line (the first, decisive line)
     * and workflow_lines (all, without duplicates). Without a known line the response stays as before.
     *
     * @param array<int,int|null> $lines
     */
    public static function linePayload(array $lines): array
    {
        $lines = array_values(array_unique(array_filter($lines, 'is_int')));
        return $lines ? ['workflow_line' => $lines[0], 'workflow_lines' => $lines] : [];
    }

    /**
     * Roles and groups of a user (names), resolved as in Permissions::access(): directly assigned roles, roles
     * of all the user's groups and the groups themselves. Admins may execute every transition.
     *
     * @return array{admin:bool,roles:string[],groups:string[]}
     */
    public static function memberships(PDO $pdo, array $user): array
    {
        $out = ['admin' => $user['role'] === 'admin', 'roles' => [], 'groups' => []];
        if (!Database::tableExists($pdo, 'permissions')) {
            return $out;
        }
        $groups = $pdo->prepare('SELECT g.name FROM "groups" g JOIN user_groups ug ON ug.group_id = g.id WHERE ug.user_id = ? ORDER BY g.name');
        $groups->execute([(int) $user['id']]);
        $out['groups'] = array_map('strval', $groups->fetchAll(PDO::FETCH_COLUMN));
        $roles = $pdo->prepare(
            'SELECT name FROM roles WHERE id IN (
                SELECT role_id FROM user_roles WHERE user_id = :u
                UNION
                SELECT gr.role_id FROM group_roles gr JOIN user_groups ug ON ug.group_id = gr.group_id WHERE ug.user_id = :u)
              ORDER BY name'
        );
        $roles->execute([':u' => (int) $user['id']]);
        $out['roles'] = array_map('strval', $roles->fetchAll(PDO::FETCH_COLUMN));
        return $out;
    }

    /** May the user with these memberships (memberships()) execute the transition? */
    public static function permitted(array $memberships, array $transition): bool
    {
        if ($memberships['admin']) {
            return true;
        }
        $names = ['group' => array_map([WorkflowParser::class, 'fold'], $memberships['groups']),
            'role' => array_map([WorkflowParser::class, 'fold'], $memberships['roles'])];
        foreach ($transition['allowed'] as $a) {
            if (in_array(WorkflowParser::fold($a['name']), $names[$a['type']] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- Tasks

    public static function ensureTasks(PDO $pdo): void
    {
        $pdo->exec(self::TASKS_DDL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS lh_task_record ON lh_task (entity, record_id, status)');
    }

    /**
     * After a transition (in its transaction): complete the open tasks of the record, create a new one for assign=.
     * initiator = whoever started the workflow of this record (creator of its oldest task), otherwise the executing user.
     * A group deleted in the meantime or an unknown user results in a task without an assignment.
     *
     * @return array|null the new task
     */
    public static function recordTransition(PDO $pdo, string $table, int $id, array $transition, array $user): ?array
    {
        self::ensureTasks($pdo);
        $initiator = null;
        if (($transition['assign']['type'] ?? null) === 'initiator') {
            $stmt = $pdo->prepare('SELECT created_by FROM lh_task WHERE entity = ? AND record_id = ? AND created_by IS NOT NULL ORDER BY id LIMIT 1');
            $stmt->execute([$table, $id]);
            $first = $stmt->fetchColumn();
            $initiator = $first !== false ? (int) $first : (int) $user['id'];
        }
        $pdo->prepare("UPDATE lh_task SET status = 'done' WHERE entity = ? AND record_id = ? AND status = 'open'")->execute([$table, $id]);
        $assign = $transition['assign'];
        if ($assign === null) {
            return null;
        }
        $group = null;
        $assignee = $initiator;
        if ($assign['type'] === 'group' && Database::tableExists($pdo, 'permissions')) {
            $group = self::idByName($pdo, 'SELECT id, name FROM "groups"', $assign['name']);
        } elseif ($assign['type'] === 'user') {
            $assignee = self::idByName($pdo, 'SELECT id, username AS name FROM users', $assign['name']);
        }
        $pdo->prepare('INSERT INTO lh_task (entity, record_id, transition_label, assigned_group_id, assigned_user_id, created_by)
                       VALUES (?, ?, ?, ?, ?, ?)')->execute([$table, $id, $transition['label'], $group, $assignee, (int) $user['id']]);
        return self::task($pdo, (int) $pdo->lastInsertId());
    }

    private static function idByName(PDO $pdo, string $sql, string $name): ?int
    {
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            if (WorkflowParser::fold((string) $row['name']) === WorkflowParser::fold($name)) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    private static function task(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM lh_task WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::present($row);
    }

    private static function present(array $r): array
    {
        $int = function ($v): ?int {
            return $v === null ? null : (int) $v;
        };
        return [
            'id' => (int) $r['id'], 'entity' => (string) $r['entity'], 'record_id' => (int) $r['record_id'],
            'transition_label' => (string) $r['transition_label'], 'assigned_group_id' => $int($r['assigned_group_id']),
            'assigned_user_id' => $int($r['assigned_user_id']), 'created_by' => $int($r['created_by']),
            'created_at' => (string) $r['created_at'], 'status' => (string) $r['status'],
        ];
    }

    /** GET /api/_tasks (admin only, all tasks - for supervision): optionally ?entity=, ?record_id=, ?status=open|done; newest first */
    public static function tasks(PDO $pdo, array $query): array
    {
        self::ensureTasks($pdo);
        $where = [];
        $params = [];
        if (is_string($query['entity'] ?? null) && $query['entity'] !== '') {
            $where[] = 'entity = ?';
            $params[] = strtolower($query['entity']);
        }
        if (isset($query['record_id']) && filter_var($query['record_id'], FILTER_VALIDATE_INT) !== false) {
            $where[] = 'record_id = ?';
            $params[] = (int) $query['record_id'];
        }
        if (in_array($query['status'] ?? null, ['open', 'done'], true)) {
            $where[] = 'status = ?';
            $params[] = $query['status'];
        }
        $stmt = $pdo->prepare('SELECT * FROM lh_task' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . self::TASK_LIMIT);
        $stmt->execute($params);
        return ['tasks' => array_map([self::class, 'present'], $stmt->fetchAll())];
    }

    /**
     * GET /api/_tasks/mine („Meine Aufgaben“, any role): tasks assigned to the user directly or via one of their groups
     * - open ones, with ?status=all also completed ones; newest first. Per task additionally created_by_name
     * (login name), assigned_group (name) and via ('user' = directly, 'group' = via the group). 'open' is always the
     * number of open ones (counter in the sidebar); ?count=1 delivers only that. Creates nothing: without the table there are none.
     */
    public static function myTasks(PDO $pdo, array $user, array $query): array
    {
        $countOnly = isset($query['count']) && filter_var($query['count'], FILTER_VALIDATE_BOOLEAN);
        if (!Database::tableExists($pdo, self::TASKS)) {
            return $countOnly ? ['open' => 0] : ['tasks' => [], 'open' => 0];
        }
        $me = (int) $user['id'];
        $groups = [];
        if (Database::tableExists($pdo, 'permissions')) {
            $stmt = $pdo->prepare('SELECT g.id, g.name FROM "groups" g JOIN user_groups ug ON ug.group_id = g.id WHERE ug.user_id = ?');
            $stmt->execute([$me]);
            foreach ($stmt->fetchAll() as $g) {
                $groups[(int) $g['id']] = (string) $g['name'];
            }
        }
        $mine = '(assigned_user_id = ' . $me . ($groups ? ' OR assigned_group_id IN (' . implode(', ', array_keys($groups)) . ')' : '') . ')';
        $open = (int) $pdo->query("SELECT COUNT(*) FROM lh_task WHERE $mine AND status = 'open'")->fetchColumn();
        if ($countOnly) {
            return ['open' => $open];
        }
        $all = ($query['status'] ?? null) === 'all';
        $rows = $pdo->query('SELECT t.*, u.username AS created_by_name FROM lh_task t LEFT JOIN users u ON u.id = t.created_by'
            . " WHERE $mine" . ($all ? '' : " AND t.status = 'open'") . ' ORDER BY t.id DESC LIMIT ' . self::TASK_LIMIT)->fetchAll();
        $tasks = [];
        foreach ($rows as $r) {
            $task = self::present($r);
            $direct = $task['assigned_user_id'] === $me;
            $tasks[] = $task + [
                'created_by_name' => $r['created_by_name'] === null ? null : (string) $r['created_by_name'],
                'via' => $direct ? 'user' : 'group',
                'assigned_group' => $direct ? null : ($groups[$task['assigned_group_id']] ?? null),
            ];
        }
        return ['tasks' => $tasks, 'open' => $open];
    }

    /** Remove tasks of deleted records (Cms::delete(), in its transaction) */
    public static function forgetRecords(PDO $pdo, string $table, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, self::TASKS)) {
            return;
        }
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $pdo->prepare('DELETE FROM lh_task WHERE entity = ? AND record_id IN (' . implode(', ', $chunk) . ')')->execute([$table]);
        }
    }

    /**
     * Tasks after a schema migration (in its transaction): carry renamed entities along, delete tasks of removed
     * entities and of those that no longer have a workflow field.
     *
     * @param array<string,?string> $entityMap new table => previous table|null
     */
    public static function migrateTasks(PDO $pdo, array $entityMap, array $newModel): void
    {
        if (!Database::tableExists($pdo, self::TASKS)) {
            return;
        }
        $target = [];
        foreach ($entityMap as $new => $old) {
            if ($old !== null && self::fieldOf($newModel['entities'][$new]) !== null) {
                $target[$old] = (string) $new;
            }
        }
        $del = $pdo->prepare('DELETE FROM lh_task WHERE entity = ?');
        // rename in two steps (a placeholder with a space cannot be a table name): A -> B with a new A at the same time
        $tmp = $pdo->prepare('UPDATE lh_task SET entity = ? WHERE entity = ?');
        foreach ($pdo->query('SELECT DISTINCT entity FROM lh_task')->fetchAll(PDO::FETCH_COLUMN) as $entity) {
            if (!isset($target[$entity])) {
                $del->execute([$entity]);
            } elseif ($target[$entity] !== $entity) {
                $tmp->execute([' ' . $target[$entity], $entity]);
            }
        }
        $pdo->exec("UPDATE lh_task SET entity = substr(entity, 2) WHERE entity LIKE ' %'");
    }
}
