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
 * Follow-ups („Wiedervorlage“): a built-in system function at every record of every entity - not a field of the
 * diagram. "Check the figures in 3 months": a due date, a note (what to do) and who is responsible (users and/or groups,
 * default: whoever creates it). Several per record are possible.
 *
 * Tables (prefix lh_ like lh_task, so the names stay free for classes of the diagram):
 *  - lh_followup: entity_ref = stable ID of the entity (schema_ids.id, like permissions and translations - a renamed
 *    class keeps its follow-ups), record_id, due_date (YYYY-MM-DD), note, interval_months (quick choice +1/+3/+6/+12
 *    months, for "done + follow up again"), created_by/created_at, record_version (newest version of the record when the
 *    follow-up was created, for "changed since"), previous_id (the follow-up this one continues), done_at/done_by/done_note.
 *  - lh_followup_assignee: one row per responsible user or group.
 * They are created on the first write. Deleting a record deletes its follow-ups (Cms::delete()); removing an entity from
 * the schema deletes them via its stable ID (SchemaIds::delete()).
 *
 * Due-ness is computed on every call (no cron - shared hosting): due = due_date <= today (date() of the server, i.e. the
 * time zone of php.ini), still open. Upcoming ones are never counted. A follow-up assigned to a group is done for
 * everyone as soon as one member completes it.
 *
 * Visibility: only for users who may read the record (entity not denied, see Access); never in the public API - all
 * routes need a session, API users (role = api) are rejected. Can be switched off (Settings::FOLLOWUPS_ENABLED): the data
 * stays, only the routes answer 404 and the UI hides the function.
 */
final class Followups
{
    public const TABLE = 'lh_followup';
    public const ASSIGNEES = 'lh_followup_assignee';
    public const NOTE_MAX = 2000;
    public const INTERVALS = [1, 3, 6, 12];
    /** Largest number of follow-ups GET /api/_followups/mine delivers per section (due, upcoming) */
    public const LIST_LIMIT = 500;

    private const DDL = [
        'CREATE TABLE IF NOT EXISTS lh_followup (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_ref TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  due_date TEXT NOT NULL,
  note TEXT NOT NULL,
  interval_months INTEGER,
  record_version INTEGER,
  previous_id INTEGER,
  created_by INTEGER,
  created_at DATETIME NOT NULL,
  done_at DATETIME,
  done_by INTEGER,
  done_note TEXT
)',
        'CREATE INDEX IF NOT EXISTS lh_followup_record ON lh_followup (entity_ref, record_id)',
        'CREATE INDEX IF NOT EXISTS lh_followup_due ON lh_followup (done_at, due_date)',
        'CREATE TABLE IF NOT EXISTS lh_followup_assignee (
  followup_id INTEGER NOT NULL REFERENCES lh_followup(id) ON DELETE CASCADE,
  user_id INTEGER,
  group_id INTEGER,
  CHECK ((user_id IS NULL) <> (group_id IS NULL))
)',
        'CREATE INDEX IF NOT EXISTS lh_followup_assignee_f ON lh_followup_assignee (followup_id)',
        'CREATE INDEX IF NOT EXISTS lh_followup_assignee_u ON lh_followup_assignee (user_id)',
        'CREATE INDEX IF NOT EXISTS lh_followup_assignee_g ON lh_followup_assignee (group_id)',
    ];

    /** @var PDO */
    private $pdo;
    /** @var Cms */
    private $cms;
    /** @var array */
    private $user;

    /**
     * For the routes: 404 if the function is switched off, 403 for API users. $cms carries the permissions of $user.
     */
    public function __construct(PDO $pdo, Cms $cms, array $user)
    {
        if (!self::enabled($pdo)) {
            throw ApiException::of(404, 'followups_disabled', Msg::of('msg_followups.disabled',
                'Follow-ups are switched off (System → Settings).'));
        }
        if (($user['role'] ?? null) === ApiKeys::ROLE) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_followups.no_api',
                'Follow-ups are not available for API users.'));
        }
        $this->pdo = $pdo;
        $this->cms = $cms;
        $this->user = $user;
    }

    public static function enabled(PDO $pdo): bool
    {
        return (bool) Settings::get($pdo, Settings::FOLLOWUPS_ENABLED);
    }

    public static function ensure(PDO $pdo): void
    {
        foreach (self::DDL as $sql) {
            $pdo->exec($sql);
        }
    }

    /** Today in the stored form (YYYY-MM-DD, time zone of the server) - the reference for "due" */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    // ---------------------------------------------------------------- Reading

    /**
     * GET /api/_followups?entity=&record_id=: all follow-ups of a record (open ones first by due date, then done ones,
     * newest first). 403/404 as reading the record.
     */
    public function forRecord(array $query): array
    {
        [$table, $id] = $this->record($query['entity'] ?? null, $query['record_id'] ?? null);
        $ref = self::refOf($this->pdo, $table);
        if ($ref === null || !Database::tableExists($this->pdo, self::TABLE)) {
            return ['followups' => [], 'today' => self::today()];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM lh_followup WHERE entity_ref = ? AND record_id = ?
            ORDER BY done_at IS NOT NULL, CASE WHEN done_at IS NULL THEN due_date END, done_at DESC, id DESC');
        $stmt->execute([$ref, $id]);
        return ['followups' => $this->present($stmt->fetchAll(), [$ref => $table]), 'today' => self::today()];
    }

    /**
     * GET /api/_followups/mine: follow-ups assigned to the user directly or via one of their groups, open only, on records
     * they may read. 'due' (due_date <= today, oldest due first) and 'upcoming' (later, nearest first).
     */
    public function mine(): array
    {
        $refs = $this->readableRefs();
        $empty = ['due' => [], 'upcoming' => [], 'today' => self::today()];
        if (!$refs || !Database::tableExists($this->pdo, self::TABLE)) {
            return $empty;
        }
        [$where, $params] = $this->mineWhere(array_keys($refs));
        $out = $empty;
        foreach (['due' => '<=', 'upcoming' => '>'] as $key => $op) {
            $stmt = $this->pdo->prepare("SELECT f.* FROM lh_followup f WHERE $where AND f.due_date $op ? ORDER BY f.due_date, f.id LIMIT "
                . self::LIST_LIMIT);
            $stmt->execute(array_merge($params, [self::today()]));
            $out[$key] = $this->present($stmt->fetchAll(), $refs);
        }
        return $out;
    }

    /** Number of due follow-ups of the user (counter "Aufgaben (n)"); 0 if switched off, for API users or without the table */
    public static function dueCount(PDO $pdo, Cms $cms, array $user): int
    {
        if (!self::enabled($pdo) || ($user['role'] ?? null) === ApiKeys::ROLE || !Database::tableExists($pdo, self::TABLE)) {
            return 0;
        }
        $self = new self($pdo, $cms, $user);
        $refs = $self->readableRefs();
        if (!$refs) {
            return 0;
        }
        [$where, $params] = $self->mineWhere(array_keys($refs));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lh_followup f WHERE $where AND f.due_date <= ?");
        $stmt->execute(array_merge($params, [self::today()]));
        return (int) $stmt->fetchColumn();
    }

    /**
     * GET /api/_followups/assignees: who a follow-up can be assigned to - active users with a login (no API users) and all
     * groups; 'me' = the own id (default). Only id + display name: every editor sees this list, so no usernames (they are
     * the login) or other account data.
     */
    public function assignees(): array
    {
        $users = $this->pdo->query("SELECT id, name FROM users WHERE active = 1 AND role <> 'api' ORDER BY name COLLATE NOCASE, id")->fetchAll();
        $groups = Database::tableExists($this->pdo, 'groups') ? $this->pdo->query('SELECT id, name FROM "groups" ORDER BY name COLLATE NOCASE, id')->fetchAll() : [];
        return [
            'users'  => array_map(function ($u) {
                return ['id' => (int) $u['id'], 'name' => (string) $u['name']];
            }, $users),
            'groups' => array_map(function ($g) {
                return ['id' => (int) $g['id'], 'name' => (string) $g['name']];
            }, $groups),
            'me'     => (int) $this->user['id'],
        ];
    }

    // ---------------------------------------------------------------- Writing

    /**
     * POST /api/_followups: body {entity, record_id, due_date, note, interval_months?, users?: [ids], groups?: [ids]}.
     * Without users and groups the creator is responsible. Anyone who may read the record may create one.
     */
    public function create(array $body): array
    {
        [$table, $id] = $this->record($body['entity'] ?? null, $body['record_id'] ?? null);
        $ref = self::refOf($this->pdo, $table, true);
        if ($ref === null) {
            throw ApiException::of(409, 'no_schema_ids', Msg::of('msg_followups.no_schema_ids',
                'The entity has no stable ID yet - please run “Apply” under System → Schema once.'));
        }
        $data = $this->validate($body);
        self::ensure($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $new = $this->insert($ref, $table, $id, $data, null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->one($new, $ref, $table);
    }

    /**
     * POST /api/_followups/{id}/done: body {done_note?, next?: {due_date, note, interval_months?, users?, groups?}}.
     * Completes the follow-up (for everyone responsible); with next a new one is created in the same transaction
     * (previous_id = this one). Allowed for the responsible users (directly or via a group), the creator and admins.
     * 409 if it is already done.
     */
    public function complete(int $followupId, array $body): array
    {
        $row = $this->find($followupId);
        $table = self::tableOf($this->pdo, (string) $row['entity_ref']);
        if ($table === null) {
            throw self::notFound();
        }
        $this->record($table, (int) $row['record_id']);
        if (!$this->mayComplete($row)) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_followups.not_responsible',
                'Only the responsible users, the creator or an administrator can complete this follow-up.'));
        }
        if ($row['done_at'] !== null) {
            throw ApiException::of(409, 'already_done', Msg::of('msg_followups.already_done',
                'This follow-up has already been completed.'));
        }
        [$note, $noteError] = self::text($body['done_note'] ?? null, false);
        if ($noteError !== null) {
            throw ApiException::invalid(['done_note' => $noteError]);
        }
        $next = null;
        if (array_key_exists('next', $body) && $body['next'] !== null) {
            if (!is_array($body['next'])) {
                throw ApiException::invalid(['next' => Msg::of('msg_followups.next_expected', 'Object with due_date and note expected')]);
            }
            $next = $this->validate($body['next'], 'next.');
        }
        $this->pdo->beginTransaction();
        try {
            // done_at IS NULL: two completions at the same time - only the first one counts
            $stmt = $this->pdo->prepare('UPDATE lh_followup SET done_at = ?, done_by = ?, done_note = ? WHERE id = ? AND done_at IS NULL');
            $stmt->execute([RecordMeta::now(), (int) $this->user['id'], $note, $followupId]);
            if ($stmt->rowCount() !== 1) {
                throw ApiException::of(409, 'already_done', Msg::of('msg_followups.already_done',
                    'This follow-up has already been completed.'));
            }
            $newId = $next !== null ? $this->insert((string) $row['entity_ref'], $table, (int) $row['record_id'], $next, $followupId) : null;
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return [
            'followup' => $this->one($followupId, (string) $row['entity_ref'], $table),
            'next'     => $newId !== null ? $this->one($newId, (string) $row['entity_ref'], $table) : null,
        ];
    }

    /**
     * PUT /api/_followups/{id}: change an open follow-up - body {due_date?, note?, interval_months?, users?, groups?}; what
     * is left out keeps its value (users/groups: both left out = unchanged). Only the creator and admins; 409 if done.
     */
    public function update(int $followupId, array $body): array
    {
        [$row, $table] = $this->editable($followupId);
        $current = $this->assigneeIds($followupId);
        $merged = $body + [
            'due_date'        => (string) $row['due_date'],
            'note'            => (string) $row['note'],
            'interval_months' => $row['interval_months'] === null ? null : (int) $row['interval_months'],
        ];
        if (!array_key_exists('users', $body) && !array_key_exists('groups', $body)) {
            $merged['users'] = $current['users'];
            $merged['groups'] = $current['groups'];
        }
        // already assigned ones stay valid even if deactivated/deleted meanwhile - otherwise the note could not be changed
        $data = $this->validate($merged, '', $current);
        $this->pdo->beginTransaction();
        try {
            // done_at IS NULL: completed in the meantime - then nothing changes
            $stmt = $this->pdo->prepare('UPDATE lh_followup SET due_date = ?, note = ?, interval_months = ? WHERE id = ? AND done_at IS NULL');
            $stmt->execute([$data['due_date'], $data['note'], $data['interval_months'], $followupId]);
            if ($stmt->rowCount() !== 1) {
                throw self::locked();
            }
            $this->pdo->prepare('DELETE FROM lh_followup_assignee WHERE followup_id = ?')->execute([$followupId]);
            $this->insertAssignees($followupId, $data);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->one($followupId, (string) $row['entity_ref'], $table);
    }

    /** DELETE /api/_followups/{id}: delete an open follow-up. Only the creator and admins; 409 if done. */
    public function delete(int $followupId): array
    {
        $this->editable($followupId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM lh_followup WHERE id = ? AND done_at IS NULL');
            $stmt->execute([$followupId]);
            if ($stmt->rowCount() !== 1) {
                throw self::locked();
            }
            $this->pdo->prepare('DELETE FROM lh_followup_assignee WHERE followup_id = ?')->execute([$followupId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['deleted' => $followupId];
    }

    // ---------------------------------------------------------------- Record list filter and clean-up

    /**
     * SQL condition (alias e) for the list filter ?followup=open of GET /api/{entity}: only rows with at least one open
     * follow-up. Needs a session (401) and the function switched on (404).
     */
    public static function listCondition(PDO $pdo, ?array $user, string $table, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($user === null) {
            throw ApiException::of(401, 'unauthorized', Msg::of('msg_auth.login_required', 'Login required'));
        }
        if (!self::enabled($pdo)) {
            throw ApiException::of(404, 'followups_disabled', Msg::of('msg_followups.disabled',
                'Follow-ups are switched off (System → Settings).'));
        }
        if ($value !== 'open') {
            throw ApiException::invalid(['followup' => Msg::of('msg_followups.filter_expected', 'followup=open expected')]);
        }
        $ref = self::refOf($pdo, $table);
        if ($ref === null || !Database::tableExists($pdo, self::TABLE)) {
            return '0';
        }
        return 'e."id" IN (SELECT record_id FROM lh_followup WHERE done_at IS NULL AND entity_ref = ' . $pdo->quote($ref) . ')';
    }

    /** Delete the follow-ups of deleted records (Cms::delete(), in its transaction) */
    public static function forgetRecords(PDO $pdo, string $table, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        $ref = self::refOf($pdo, $table);
        if ($ref === null) {
            return;
        }
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $in = implode(', ', $chunk);
            $pdo->prepare("DELETE FROM lh_followup_assignee WHERE followup_id IN (SELECT id FROM lh_followup WHERE entity_ref = ? AND record_id IN ($in))")
                ->execute([$ref]);
            $pdo->prepare("DELETE FROM lh_followup WHERE entity_ref = ? AND record_id IN ($in)")->execute([$ref]);
        }
    }

    /** Delete the follow-ups of removed entities (SchemaIds::delete(), stable IDs; explicitly - foreign keys may be off) */
    public static function deleteRefs(PDO $pdo, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM lh_followup_assignee WHERE followup_id IN (SELECT id FROM lh_followup WHERE entity_ref IN ($in))")
                ->execute($chunk);
            $pdo->prepare("DELETE FROM lh_followup WHERE entity_ref IN ($in)")->execute($chunk);
        }
    }

    // ---------------------------------------------------------------- Internal

    /** Stable ID of an entity (schema_ids, kind entity); $ensure: create missing IDs first (older installations) */
    public static function refOf(PDO $pdo, string $table, bool $ensure = false): ?string
    {
        $map = self::refMap($pdo);
        if (!isset($map[$table]) && $ensure) {
            SchemaIds::ensure($pdo);
            $map = self::refMap($pdo);
        }
        return $map[$table] ?? null;
    }

    private static function tableOf(PDO $pdo, string $ref): ?string
    {
        $table = array_search($ref, self::refMap($pdo), true);
        return $table === false ? null : (string) $table;
    }

    /** @return array<string,string> table => stable ID */
    private static function refMap(PDO $pdo): array
    {
        if (!Database::tableExists($pdo, SchemaIds::IDS_TABLE)) {
            return [];
        }
        return $pdo->query("SELECT entity_name, id FROM schema_ids WHERE kind = 'entity'")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string,string> stable ID => table of the entities the user may read */
    private function readableRefs(): array
    {
        $readable = array_flip($this->cms->entityNames());
        $out = [];
        foreach (self::refMap($this->pdo) as $table => $ref) {
            if (isset($readable[$table])) {
                $out[$ref] = (string) $table;
            }
        }
        return $out;
    }

    /** WHERE (alias f) for "open, assigned to me directly or via one of my groups, on these entities" */
    private function mineWhere(array $refs): array
    {
        $groups = $this->groupIds();
        $who = 'a.user_id = ?' . ($groups ? ' OR a.group_id IN (' . implode(', ', $groups) . ')' : '');
        $in = implode(', ', array_fill(0, count($refs), '?'));
        return ["f.done_at IS NULL AND f.entity_ref IN ($in) AND EXISTS (SELECT 1 FROM lh_followup_assignee a WHERE a.followup_id = f.id AND ($who))",
            array_merge($refs, [(int) $this->user['id']])];
    }

    /** @return int[] */
    private function groupIds(): array
    {
        if (!Database::tableExists($this->pdo, 'user_groups')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT group_id FROM user_groups WHERE user_id = ?');
        $stmt->execute([(int) $this->user['id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Entity + record from the request, checked like reading the record (Cms::find(): unknown entity 404, denied 403,
     * missing record 404). @return array{0:string,1:int}
     */
    private function record($entity, $recordId): array
    {
        $errors = [];
        if (!is_string($entity) || $entity === '') {
            $errors['entity'] = Msg::of('msg_followups.entity_expected', 'Entity expected');
        }
        $id = is_int($recordId) ? $recordId : (is_string($recordId) && ctype_digit($recordId) ? (int) $recordId : null);
        if ($id === null || $id < 1) {
            $errors['record_id'] = Msg::of('msg_api.id_expected', 'ID expected');
        }
        if ($errors) {
            throw ApiException::invalid($errors);
        }
        $this->cms->find((string) $entity, $id);
        return [strtolower((string) $entity), $id];
    }

    private function find(int $id): array
    {
        if (!Database::tableExists($this->pdo, self::TABLE)) {
            throw self::notFound();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM lh_followup WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw self::notFound();
        }
        return $row;
    }

    private static function notFound(): ApiException
    {
        return ApiException::of(404, 'not_found', Msg::of('msg_followups.not_found', 'Follow-up not found'));
    }

    /**
     * The follow-up for changing/deleting: record readable (403/404 like reading it), creator or admin (403), still open
     * (409). @return array{0:array,1:string} row and table
     */
    private function editable(int $followupId): array
    {
        $row = $this->find($followupId);
        $table = self::tableOf($this->pdo, (string) $row['entity_ref']);
        if ($table === null) {
            throw self::notFound();
        }
        $this->record($table, (int) $row['record_id']);
        if (!$this->mayEdit($row)) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_followups.not_creator',
                'Only the creator or an administrator can change or delete this follow-up.'));
        }
        if ($row['done_at'] !== null) {
            throw self::locked();
        }
        return [$row, $table];
    }

    private static function locked(): ApiException
    {
        return ApiException::of(409, 'already_done', Msg::of('msg_followups.done_locked',
            'This follow-up has already been completed and can no longer be changed.'));
    }

    private function mayEdit(array $row): bool
    {
        return $this->user['role'] === 'admin' || (int) $row['created_by'] === (int) $this->user['id'];
    }

    /** @return array{users:int[],groups:int[]} */
    private function assigneeIds(int $followupId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, group_id FROM lh_followup_assignee WHERE followup_id = ? ORDER BY rowid');
        $stmt->execute([$followupId]);
        $out = ['users' => [], 'groups' => []];
        foreach ($stmt->fetchAll() as $a) {
            if ($a['user_id'] !== null) {
                $out['users'][] = (int) $a['user_id'];
            } else {
                $out['groups'][] = (int) $a['group_id'];
            }
        }
        return $out;
    }

    private function mayComplete(array $row): bool
    {
        if ($this->user['role'] === 'admin' || (int) $row['created_by'] === (int) $this->user['id']) {
            return true;
        }
        $groups = $this->groupIds();
        $stmt = $this->pdo->prepare('SELECT 1 FROM lh_followup_assignee WHERE followup_id = ? AND (user_id = ?'
            . ($groups ? ' OR group_id IN (' . implode(', ', $groups) . ')' : '') . ')');
        $stmt->execute([(int) $row['id'], (int) $this->user['id']]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Checks due_date, note, interval_months, users, groups; 422 with all problems ($prefix for the fields of "next").
     * $keep: {users, groups} already assigned (editing) - accepted without the check for active/existing.
     *
     * @return array{due_date:string,note:string,interval_months:?int,users:int[],groups:int[]}
     */
    private function validate(array $body, string $prefix = '', array $keep = ['users' => [], 'groups' => []]): array
    {
        $errors = [];
        $due = $body['due_date'] ?? null;
        if (!is_string($due) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors[$prefix . 'due_date'] = Msg::of('msg_followups.date_expected', 'Date YYYY-MM-DD expected');
        }
        [$note, $noteError] = self::text($body['note'] ?? null, true);
        if ($noteError !== null) {
            $errors[$prefix . 'note'] = $noteError;
        }
        $interval = $body['interval_months'] ?? null;
        if ($interval !== null && (!is_int($interval) || $interval < 1 || $interval > 120)) {
            $errors[$prefix . 'interval_months'] = Msg::of('msg_followups.interval_expected', 'Whole number of months from 1 to 120 expected');
            $interval = null;
        }
        $hasUsers = array_key_exists('users', $body);
        $hasGroups = array_key_exists('groups', $body);
        $users = $this->ids($body['users'] ?? [], $prefix . 'users', $errors);
        $groups = $this->ids($body['groups'] ?? [], $prefix . 'groups', $errors);
        if (!$hasUsers && !$hasGroups) {
            $users = [(int) $this->user['id']]; // default: whoever creates it
        } elseif (!$users && !$groups && !isset($errors[$prefix . 'users']) && !isset($errors[$prefix . 'groups'])) {
            $errors[$prefix . 'users'] = Msg::of('msg_followups.assignee_required', 'At least one responsible user or group expected');
        }
        if ($users) {
            $in = implode(', ', $users);
            $found = $this->pdo->query("SELECT id FROM users WHERE id IN ($in) AND active = 1 AND role <> 'api'")->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_diff($users, array_map('intval', $found), $keep['users']);
            if ($missing) {
                $errors[$prefix . 'users'] = Msg::of('msg_followups.user_missing', 'User {ids} does not exist or is not active',
                    ['ids' => implode(', ', $missing)]);
            }
        }
        if ($groups) {
            $found = Database::tableExists($this->pdo, 'groups')
                ? $this->pdo->query('SELECT id FROM "groups" WHERE id IN (' . implode(', ', $groups) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
            $missing = array_diff($groups, array_map('intval', $found), $keep['groups']);
            if ($missing) {
                $errors[$prefix . 'groups'] = Msg::of('msg_followups.group_missing', 'Group {ids} does not exist',
                    ['ids' => implode(', ', $missing)]);
            }
        }
        if ($errors) {
            throw ApiException::invalid($errors);
        }
        return ['due_date' => (string) $due, 'note' => (string) $note, 'interval_months' => $interval, 'users' => $users, 'groups' => $groups];
    }

    /** @param mixed $value @return int[] */
    private function ids($value, string $field, array &$errors): array
    {
        if (!is_array($value)) {
            $errors[$field] = Msg::of('msg_followups.ids_expected', 'List of IDs expected');
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (!is_int($v) || $v < 1) {
                $errors[$field] = Msg::of('msg_followups.ids_expected', 'List of IDs expected');
                return [];
            }
            $out[$v] = $v;
        }
        return array_values($out);
    }

    /**
     * Trimmed text up to NOTE_MAX characters: [value, null] or [null, problem]; $required: empty is a problem, otherwise
     * empty -> [null, null].
     *
     * @param mixed $value
     * @return array{0:?string,1:?Msg}
     */
    private static function text($value, bool $required): array
    {
        if ($value !== null && !is_string($value)) {
            return [null, Msg::of('msg_api.text_expected', 'Text expected')];
        }
        $value = trim((string) $value);
        if ($value === '') {
            return [null, $required ? Msg::of('msg_followups.note_required', 'Please describe what is to be done') : null];
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > self::NOTE_MAX) {
            return [null, Msg::of('msg_api.max_chars', 'At most {max} characters', ['max' => self::NOTE_MAX])];
        }
        return [$value, null];
    }

    /** INSERT of a follow-up and its assignees (inside the caller's transaction) @return int new id */
    private function insert(string $ref, string $table, int $recordId, array $data, ?int $previous): int
    {
        $this->pdo->prepare('INSERT INTO lh_followup (entity_ref, record_id, due_date, note, interval_months, record_version, previous_id,
                             created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $recordId, $data['due_date'], $data['note'], $data['interval_months'],
                self::latestVersion($this->pdo, $table, $recordId), $previous, (int) $this->user['id'], RecordMeta::now()]);
        $id = (int) $this->pdo->lastInsertId();
        $this->insertAssignees($id, $data);
        return $id;
    }

    private function insertAssignees(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO lh_followup_assignee (followup_id, user_id, group_id) VALUES (?, ?, ?)');
        foreach ($data['users'] as $u) {
            $stmt->execute([$id, $u, null]);
        }
        foreach ($data['groups'] as $g) {
            $stmt->execute([$id, null, $g]);
        }
    }

    private static function latestVersion(PDO $pdo, string $table, int $id): ?int
    {
        if (!Database::tableExists($pdo, Revisions::TABLE)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT MAX(version) FROM lh_revision WHERE entity = ? AND record_id = ?');
        $stmt->execute([$table, $id]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    private function one(int $id, string $ref, string $table): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lh_followup WHERE id = ?');
        $stmt->execute([$id]);
        return $this->present($stmt->fetchAll(), [$ref => $table])[0];
    }

    /**
     * API form: id, entity (table), record_id, due_date, due (due today or earlier and open), note, interval_months,
     * created_at/created_by {id, name}, users [{id, name}], groups [{id, name}], done_at/done_by/done_note, previous_id,
     * mine (assigned to the caller), can_complete, can_edit (change/delete: creator or admin, open), and "changed since":
     * changed (the record was
     * saved after the follow-up was created), compare_from/compare_to (versions for the comparison in the history; null
     * if there is nothing to compare).
     *
     * @param array<string,string> $refs stable ID => table
     */
    private function present(array $rows, array $refs): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $in = implode(', ', $ids);
        $assignees = [];
        foreach ($this->pdo->query("SELECT followup_id, user_id, group_id FROM lh_followup_assignee WHERE followup_id IN ($in)")->fetchAll() as $a) {
            $assignees[(int) $a['followup_id']][] = $a;
        }
        $userIds = [];
        $groupIds = [];
        foreach ($rows as $r) {
            foreach (['created_by', 'done_by'] as $c) {
                if ($r[$c] !== null) {
                    $userIds[(int) $r[$c]] = true;
                }
            }
        }
        foreach ($assignees as $list) {
            foreach ($list as $a) {
                if ($a['user_id'] !== null) {
                    $userIds[(int) $a['user_id']] = true;
                } else {
                    $groupIds[(int) $a['group_id']] = true;
                }
            }
        }
        $users = Users::refs($this->pdo, array_keys($userIds));
        $groups = [];
        if ($groupIds && Database::tableExists($this->pdo, 'groups')) {
            foreach ($this->pdo->query('SELECT id, name FROM "groups" WHERE id IN (' . implode(', ', array_keys($groupIds)) . ')') as $g) {
                $groups[(int) $g['id']] = ['id' => (int) $g['id'], 'name' => (string) $g['name']];
            }
        }
        $userOf = function ($id) use ($users) {
            return $id === null ? null : ($users[(int) $id] ?? ['id' => (int) $id, 'name' => '']);
        };
        $myGroups = array_flip($this->groupIds());
        $me = (int) $this->user['id'];
        $today = self::today();
        $state = $this->recordStates($rows, $refs);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $u = [];
            $g = [];
            $mine = false;
            foreach ($assignees[$id] ?? [] as $a) {
                if ($a['user_id'] !== null) {
                    $u[] = $userOf($a['user_id']);
                    $mine = $mine || (int) $a['user_id'] === $me;
                } else {
                    $g[] = $groups[(int) $a['group_id']] ?? ['id' => (int) $a['group_id'], 'name' => ''];
                    $mine = $mine || isset($myGroups[(int) $a['group_id']]);
                }
            }
            $open = $r['done_at'] === null;
            $table = $refs[$r['entity_ref']] ?? null;
            $s = $state[$table . ':' . $r['record_id']] ?? ['updated_at' => null, 'latest' => null, 'latest_at' => null, 'oldest' => null];
            $changed = ($s['latest_at'] !== null && $s['latest_at'] > $r['created_at'])
                || ($s['updated_at'] !== null && $s['updated_at'] > $r['created_at']);
            $from = $r['record_version'] !== null ? max((int) $r['record_version'], (int) $s['oldest']) : $s['oldest'];
            $compare = $changed && $s['latest'] !== null && $from !== null && $from < $s['latest'];
            $out[] = [
                'id'              => $id,
                'entity'          => $table,
                'record_id'       => (int) $r['record_id'],
                'due_date'        => (string) $r['due_date'],
                'due'             => $open && (string) $r['due_date'] <= $today,
                'note'            => (string) $r['note'],
                'interval_months' => $r['interval_months'] === null ? null : (int) $r['interval_months'],
                'created_at'      => (string) $r['created_at'],
                'created_by'      => $userOf($r['created_by']),
                'users'           => $u,
                'groups'          => $g,
                'done_at'         => $r['done_at'] === null ? null : (string) $r['done_at'],
                'done_by'         => $userOf($r['done_by']),
                'done_note'       => $r['done_note'] === null ? null : (string) $r['done_note'],
                'previous_id'     => $r['previous_id'] === null ? null : (int) $r['previous_id'],
                'mine'            => $mine,
                'can_complete'    => $open && ($mine || $this->user['role'] === 'admin' || (int) $r['created_by'] === $me),
                'can_edit'        => $open && $this->mayEdit($r),
                'changed'         => $changed,
                'compare_from'    => $compare ? (int) $from : null,
                'compare_to'      => $compare ? (int) $s['latest'] : null,
            ];
        }
        return $out;
    }

    /**
     * Per record of the rows ("table:id"): updated_at (RecordMeta, if the table has it), newest/oldest kept version and
     * the time of the newest one - for "changed since".
     */
    private function recordStates(array $rows, array $refs): array
    {
        $byTable = [];
        foreach ($rows as $r) {
            if (isset($refs[$r['entity_ref']])) {
                $byTable[$refs[$r['entity_ref']]][(int) $r['record_id']] = true;
            }
        }
        $model = $this->cms->model();
        $revisions = Database::tableExists($this->pdo, Revisions::TABLE);
        $out = [];
        foreach ($byTable as $table => $set) {
            $in = implode(', ', array_keys($set));
            if (isset($model['entities'][$table]) && RecordMeta::applies($model['entities'][$table])) {
                try {
                    foreach ($this->pdo->query('SELECT "id", ' . SqlGenerator::q(RecordMeta::UPDATED_AT) . ' AS u FROM '
                        . SqlGenerator::q($table) . " WHERE \"id\" IN ($in)") as $row) {
                        $out["$table:{$row['id']}"]['updated_at'] = $row['u'] === null ? null : (string) $row['u'];
                    }
                } catch (\PDOException $e) {
                    // columns not added yet (RecordMeta::ensure() catches up) - then only the versions count
                }
            }
            if ($revisions) {
                $stmt = $this->pdo->prepare("SELECT record_id, MAX(version) AS latest, MIN(version) AS oldest, MAX(created_at) AS at
                    FROM lh_revision WHERE entity = ? AND record_id IN ($in) GROUP BY record_id");
                $stmt->execute([$table]);
                foreach ($stmt->fetchAll() as $v) {
                    $key = "$table:{$v['record_id']}";
                    $out[$key]['latest'] = (int) $v['latest'];
                    $out[$key]['oldest'] = (int) $v['oldest'];
                    $out[$key]['latest_at'] = (string) $v['at'];
                }
            }
        }
        foreach ($out as &$s) {
            $s += ['updated_at' => null, 'latest' => null, 'latest_at' => null, 'oldest' => null];
        }
        unset($s);
        return $out;
    }
}
