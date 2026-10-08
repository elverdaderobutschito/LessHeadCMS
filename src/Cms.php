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
 * Generic CRUD logic based on the stored schema model.
 * Table and column names come exclusively from the model (whitelist),
 * values are always bound as parameters.
 */
final class Cms
{
    private const TITLE_CANDIDATES = ['titel', 'title', 'name', 'bezeichnung', 'label'];

    /** @var PDO */
    private $pdo;
    /** @var array */
    private $model;
    /** @var array<string,array> table => incoming relationships with a minimum count (see minRelations()) */
    private $minRelations = [];
    /** @var Access|null permissions of the logged-in user; null = unrestricted (admin, public API, internal) */
    private $access;
    /** @var array|null logged-in user (Auth::currentUser()); null = public API or internal call */
    private $user;
    /** @var array|null roles/groups of the user for workflow transitions (Workflows::memberships()), only on demand */
    private $memberships = null;

    /**
     * @param Access|null $access roles and permissions (Permissions::access()): denied entities do not exist for this caller
     *        (403, missing in entityNames()), denied fields are missing in _schema and in every row and are silently
     *        discarded when writing (the stored value stays). Denied write actions (create,
     *        update, delete per entity) make create()/update()/delete() reject with 403; reading is not affected by this.
     * @param array|null $user logged-in user: only needed for workflows (who may execute which transition,
     *        see transition(); _schema only lists the transitions with a session)
     *
     * Workflow field (PumlParser: field with 'workflow'): stored and read like an enum, but never changeable via
     * create()/update() - new rows start in the initial state, it is only changed via transition(). It stands outside
     * the field permissions (a field denial on it is ignored); entity denials apply as everywhere.
     */
    public function __construct(PDO $pdo, array $model, ?Access $access = null, ?array $user = null)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->access = $access;
        $this->user = $user;
    }

    /** The stored schema model (for Media: which media fields exist) */
    public function model(): array
    {
        return $this->model;
    }

    /** @return string[] */
    public function entityNames(): array
    {
        $names = array_keys($this->model['entities']);
        if ($this->access === null) {
            return $names;
        }
        return array_values(array_filter($names, function ($table) {
            return !$this->access->entityDenied((string) $table);
        }));
    }

    // ---------------------------------------------------------------- Schema

    public function describe(string $table): array
    {
        $e = $this->entity($table);
        $denied = $this->denied($e);
        $fields = [];
        foreach ($e['fields'] as $f) {
            if (isset($denied[$f['name']])) {
                continue;
            }
            $fk = null;
            if ($f['foreign_key']) {
                $target = $this->model['entities'][$f['foreign_key']['table']];
                $fk = [
                    'table'       => $target['table'],
                    'title_field' => $this->visibleTitle($target),
                    'label'       => $f['foreign_key']['label'],
                    // behaviour when the referenced row is deleted (see delete())
                    'on_delete'   => self::onDelete($f),
                    // 1:1 relationship: every target row referenced at most once (older models without the key -> false)
                    'one_to_one'  => !empty($f['foreign_key']['one_to_one']),
                ];
                // minimum count ("1..*" on the n side): only then in _schema - without it, it stays unchanged
                if (!empty($f['foreign_key']['min_required'])) {
                    $fk['min_required'] = true;
                }
                // {filter_by}: filtered selection in the form (field = field of this entity, target_field = field of the
                // target rows) - likewise only if given in the diagram
                if (!empty($f['foreign_key']['filter_by']) && $this->filterVisible($e, $target, $f['foreign_key']['filter_by'])) {
                    $fk['filter_by'] = $f['foreign_key']['filter_by'];
                }
            }
            $field = [
                'name'        => $f['name'],
                'type'        => $f['type'],
                'required'    => $f['required'],
                'primary'     => $f['primary'],
                'foreign_key' => $fk,
            ];
            if ($f['type'] === 'enum') {
                $field['enum_values'] = $f['enum_values']; // order as in the enum block = order in the dropdown
            }
            if (isset($f['workflow'])) {
                $field['workflow'] = $this->describeWorkflow($f);
            }
            $fields[] = $field;
        }
        $many = [];
        foreach ($e['many_to_many'] as $m) {
            if (isset($denied[$m['name']])) {
                continue;
            }
            $target = $this->model['entities'][$m['table']];
            $many[] = [
                'name'        => $m['name'],
                'table'       => $target['table'],
                'title_field' => $this->visibleTitle($target),
                'label'       => $m['label'],
                // true only with the {label} marker in the diagram: then the UI shows the label instead of the
                // table name. Models stored before this option do not know the key -> false.
                'show_label'  => !empty($m['show_label']),
                // {symmetric} self-reference: the list contains the links of both directions (see fetchRow)
                'symmetric'   => !empty($m['symmetric']),
            ] + (!empty($m['filter_by']) && $this->filterVisible($e, $target, $m['filter_by'])
                ? ['filter_by' => $m['filter_by']] : []); // {filter_by}, see above
        }
        $schema = [
            'entity'       => $e['name'],
            'table'        => $e['table'],
            'title_field'  => $this->visibleTitle($e),
            // sidebar group from the package block of the diagram; null = no package (also for models stored before
            // this option, without the key)
            'package'      => $e['package'] ?? null,
            'fields'       => $fields,
            'many_to_many' => $many,
            // {unique} group: this combination may occur only once (see rejectDuplicate()); empty = no
            // rule, also for models stored before this option, without the key
            'unique_fields' => array_values(array_diff($e['unique_fields'] ?? [], array_keys($denied))),
        ];
        // media fields (type media, list of media from the media library) - only for entities that have some; the _schema
        // of all others stays unchanged
        $media = array_values(array_filter($e['media'] ?? [], function ($m) use ($denied) {
            return !isset($denied[$m['name']]);
        }));
        if ($media) {
            $schema['media'] = array_map(function ($m) {
                return ['name' => $m['name'], 'type' => 'media', 'required' => $m['required']];
            }, $media);
        }
        // Incoming n:1 relationships with a minimum count ("every invoice has at least one line item") - only for entities
        // that have some. name is the identifier that appears in _min_warnings for rows without a related row.
        $min = $this->minRelations($e['table']);
        if ($min) {
            $schema['relations'] = array_map(function ($r) {
                return [
                    'name' => $r['name'], 'table' => $r['table'], 'entity' => $r['entity'], 'column' => $r['column'],
                    'label' => $r['label'], 'min_required' => true,
                ];
            }, $min);
        }
        return $schema;
    }

    /**
     * Workflow of a workflow field for _schema: name, initial state, the states with their label from the
     * workflow file and the main path (flow, for the progress chain in the form). With a session additionally the
     * transitions including allowed= - the UI uses this to hide in advance what
     * the user may not execute; it is still checked on every execution (transition()).
     */
    private function describeWorkflow(array $f): array
    {
        $def = $this->model['workflows'][$f['workflow']] ?? ['states' => [], 'flow' => [], 'initial' => null, 'transitions' => []];
        $out = ['name' => $f['workflow'], 'initial' => $def['initial'], 'states' => $def['states'], 'flow' => $def['flow'] ?? []];
        if ($this->user !== null) {
            $out['transitions'] = array_map(function ($t) {
                return ['from' => $t['from'], 'to' => $t['to'], 'label' => $t['label'], 'allowed' => $t['allowed']];
            }, $def['transitions']);
        }
        return $out;
    }

    /**
     * POST /api/{entity}/{id}/transition: the only way to change the state of a workflow field. Checks completely on every
     * execution (regardless of what the UI offers): entity visible (403 as everywhere), entity
     * has a workflow (otherwise 404), label known (422), transition starts from the current state (409), the user has
     * one of the roles/groups from allowed= or is an admin (403). Then in one transaction: set the state,
     * execute action=set_visibility, complete the open tasks of the record, create a new one for assign=.
     *
     * @param mixed $label value of {"transition": ...}
     * @return array{record:array,transition:array,task:?array}
     */
    public function transition(string $table, int $id, $label): array
    {
        $e = $this->entity($table);
        $field = Workflows::fieldOf($e);
        $def = $field !== null ? ($this->model['workflows'][$field['workflow']] ?? null) : null;
        if ($def === null) {
            throw new ApiException(404, ['error' => 'no_workflow', 'message' => "Für '{$e['name']}' gibt es keinen Workflow"]);
        }
        if ($this->user === null) {
            throw new ApiException(401, ['error' => 'unauthorized', 'message' => 'Anmeldung erforderlich']);
        }
        if (!is_string($label) || trim($label) === '') {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Transition fehlt',
                'errors' => ['transition' => 'Label der Transition erwartet']]);
        }
        $row = $this->fetchRow($e, $id);
        if ($row === null) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datensatz nicht gefunden']);
        }
        $state = $row[$field['name']];
        $named = array_values(array_filter($def['transitions'], function ($t) use ($label) {
            return WorkflowParser::fold($t['label']) === WorkflowParser::fold(trim($label));
        }));
        if (!$named) {
            throw new ApiException(422, ['error' => 'unknown_transition', 'message' => "Die Transition '" . trim($label)
                . "' gibt es im Workflow {$def['name']} nicht", 'errors' => ['transition' => 'unbekannt']]);
        }
        $t = null;
        foreach ($named as $candidate) {
            if ($candidate['from'] === $state) {
                $t = $candidate;
            }
        }
        if ($t === null) {
            throw new ApiException(409, ['error' => 'invalid_state', 'state' => $state, 'message' => "Die Transition "
                . "'{$named[0]['label']}' ist im Zustand '$state' nicht möglich (nur von '"
                . implode("', '", array_column($named, 'from')) . "' aus)"]);
        }
        $this->memberships = $this->memberships ?? Workflows::memberships($this->pdo, $this->user);
        if (!Workflows::permitted($this->memberships, $t)) {
            throw new ApiException(403, ['error' => 'forbidden', 'transition' => $t['label'],
                'message' => "Keine Berechtigung für die Transition '{$t['label']}'"]);
        }
        $set = [$field['name'] => $t['to']];
        $visibility = $this->visibilityField($e['table']);
        foreach ($t['actions'] as $action) {
            if ($action['type'] === 'set_visibility' && $visibility !== null) {
                $set[$visibility] = $action['value'] ? 1 : 0;
            }
        }
        return $this->transaction(function () use ($e, $id, $field, $state, $set, $t) {
            $sql = implode(', ', array_map(function ($c) {
                return SqlGenerator::q($c) . ' = ?';
            }, array_keys($set)));
            // only from the checked state: two simultaneous transitions do not both execute
            $stmt = $this->pdo->prepare('UPDATE ' . SqlGenerator::q($e['table']) . " SET $sql WHERE \"id\" = ? AND "
                . SqlGenerator::q($field['name']) . ' = ?');
            $stmt->execute(array_merge(array_values($set), [$id, $state]));
            if ($stmt->rowCount() !== 1) {
                throw new ApiException(409, ['error' => 'invalid_state', 'message' => 'Der Zustand wurde inzwischen von jemand '
                    . 'anderem geändert. Bitte neu laden.']);
            }
            $task = Workflows::recordTransition($this->pdo, $e['table'], $id, $t, $this->user);
            return [
                'record'     => array_diff_key($this->fetchRow($e, $id), $this->denied($e)),
                'transition' => ['label' => $t['label'], 'from' => $t['from'], 'to' => $t['to']],
                'task'       => $task,
            ];
        });
    }

    /**
     * Incoming n:1 relationships with a minimum count (PumlParser: foreign_key.min_required): all FK columns (those of
     * the own table too) that reference $table and carry "1..*" on the n side.
     *
     * @return array<int,array{name:string,table:string,entity:string,column:string,label:string}> name =
     *         "<table>.<FK column>" (unique, even with several relationships of the same class)
     */
    private function minRelations(string $table): array
    {
        if (!isset($this->minRelations[$table])) {
            $list = [];
            foreach ($this->model['entities'] as $child) {
                // relationships from an entity that is denied for the caller are not reported (the rule itself still applies)
                if ($this->access !== null && $this->access->entityDenied($child['table'])) {
                    continue;
                }
                foreach ($child['fields'] as $f) {
                    if ($f['foreign_key'] && $f['foreign_key']['table'] === $table && !empty($f['foreign_key']['min_required'])) {
                        $list[] = [
                            'name' => $child['table'] . '.' . $f['name'], 'table' => $child['table'], 'entity' => $child['name'],
                            'column' => $f['name'], 'label' => $f['foreign_key']['label'] ?? '',
                        ];
                    }
                }
            }
            $this->minRelations[$table] = $list;
        }
        return $this->minRelations[$table];
    }

    /**
     * Display field(s) of an entity, in the order in which their values are to be combined (see
     * Cms::describe() and the frontend, which joins several fields comma-separated).
     * Explicit {title} markers from the .puml (PumlParser: $entity['title_fields']) take precedence and override the
     * guessing heuristic completely; without any marker the heuristic applies as before (a single field).
     *
     * @return string[]
     */
    /** Like titleField(), for ListQuery (label of target rows when filtering and sorting) */
    public static function titleFieldOf(array $entity): array
    {
        return self::titleField($entity);
    }

    private static function titleField(array $entity): array
    {
        if (!empty($entity['title_fields'])) {
            return $entity['title_fields'];
        }
        $byName = [];
        foreach ($entity['fields'] as $f) {
            if (in_array($f['type'], ['string', 'text', 'richtext'], true) && !$f['foreign_key']) {
                $byName[strtolower($f['name'])] = $f['name'];
            }
        }
        foreach (self::TITLE_CANDIDATES as $candidate) {
            if (isset($byName[$candidate])) {
                return [$byName[$candidate]];
            }
        }
        foreach ($entity['fields'] as $f) {
            if ($f['type'] === 'string' && !$f['foreign_key']) {
                return [$f['name']];
            }
        }
        return ['id'];
    }

    // ---------------------------------------------------------------- Reading

    /**
     * Name of the entity's visibility field or null. Entities with a visibility field publicly return only
     * rows with true; entities without this field stay completely open.
     */
    public function visibilityField(string $table): ?string
    {
        foreach ($this->entity($table)['fields'] as $f) {
            if ($f['type'] === 'visibility') {
                return $f['name'];
            }
        }
        return null;
    }

    /** true if the entity has a visibility field and this record is not (true) published. */
    public function isDraft(string $table, array $row): bool
    {
        $field = $this->visibilityField($table);
        return $field !== null && ($row[$field] ?? null) !== true;
    }

    /**
     * One page of the list: {data, total, page, per_page, total_pages}. Paging, sorting and filtering run in the
     * database (ListQuery); n:n lists, media and minimum count warnings are only loaded for the rows of this page.
     *
     * Protection against oversized responses: per_page is limited (ListQuery::MAX_PER_PAGE). In addition, the rows are
     * read one by one and their raw size is counted; if it exceeds Config::listMaxBytes(), the query aborts with 413 BEFORE
     * the response is built from it - very wide rows (long texts) therefore cannot silently blow up the
     * memory.
     *
     * @param array $query query parameters like $_GET (see ListQuery); invalid ones -> 422
     * @param bool $includeDrafts include drafts (visibility false/NULL) - the caller checks the authorization
     * @param callable|null $entityNames fn(): array<string,string> table => entity name in the caller's display
     *        language (Languages::entityNames()), for searching in labels of FK columns and n:n lists
     */
    public function page(string $table, array $query, bool $includeDrafts = false, ?callable $entityNames = null): array
    {
        $e = $this->entity($table);
        $denied = $this->denied($e);
        $conditions = [];
        $visibility = $this->visibilityField($table);
        if ($visibility !== null && !$includeDrafts) {
            $conditions[] = 'e.' . SqlGenerator::q($visibility) . ' = 1';
        }
        $q = new ListQuery($this->model, $e, $denied, $query, $conditions, function (string $other): ?array {
            return $this->access !== null && $this->access->entityDenied($other) ? null : $this->denied($this->model['entities'][$other]);
        }, $entityNames);
        $from = ' FROM ' . SqlGenerator::q($e['table']) . ' e' . $q->where;

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($q->params);
        $total = (int) $count->fetchColumn();

        // First only the IDs of the page in the requested order, then their rows: this way SQLite does not carry every
        // complete row along while sorting and skipping earlier pages (OFFSET) - with large tables and later
        // pages this makes the difference.
        $idStmt = $this->pdo->prepare("SELECT e.\"id\"$from ORDER BY {$q->orderBy} LIMIT {$q->perPage} OFFSET " . $q->offset());
        $idStmt->execute($q->params);
        $pageIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $this->pdo->query('SELECT * FROM ' . SqlGenerator::q($e['table']) . ' WHERE "id" IN (' . ($pageIds ? implode(', ', $pageIds) : 'NULL') . ')');
        $limit = Config::listMaxBytes();
        $bytes = 0;
        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            foreach ($row as $value) {
                $bytes += is_string($value) ? strlen($value) : 8;
            }
            if ($bytes > $limit) {
                $stmt->closeCursor();
                $fit = max(1, (int) floor(count($rows) * 0.8));
                throw new ApiException(413, ['error' => 'response_too_large', 'message' => 'Die Antwort wäre zu groß: schon '
                    . (count($rows) + 1) . ' von ' . $q->perPage . ' Zeilen dieser Seite überschreiten ' . round($limit / 1048576, 1)
                    . " MB. Bitte per_page verkleinern (z. B. per_page=$fit).", 'per_page' => $q->perPage, 'suggested_per_page' => $fit]);
            }
            $rows[(int) $row['id']] = array_diff_key($this->castRow($e, $row), $denied);
        }
        // back into the order of the page (a row deleted in the meantime is simply missing)
        $ordered = [];
        foreach ($pageIds as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
            }
        }
        $rows = $ordered;
        $ids = array_column($rows, 'id');
        $in = $ids ? implode(', ', array_map('intval', $ids)) : 'NULL';

        foreach ($e['many_to_many'] as $m) {
            if (isset($denied[$m['name']])) {
                continue;
            }
            $own = SqlGenerator::q($m['own_column']);
            $other = SqlGenerator::q($m['other_column']);
            $junction = SqlGenerator::q($m['junction']);
            $sql = "SELECT $own AS a, $other AS b FROM $junction WHERE $own IN ($in)";
            if (!empty($m['symmetric'])) {
                // {symmetric}: every pair is stored once - the row can be on either side
                $sql .= " UNION SELECT $other AS a, $own AS b FROM $junction WHERE $other IN ($in)";
            }
            $map = [];
            foreach ($this->pdo->query($sql . ' ORDER BY b')->fetchAll() as $link) {
                $map[(int) $link['a']][] = (int) $link['b'];
            }
            foreach ($rows as &$row) {
                $row[$m['name']] = $map[$row['id']] ?? [];
            }
            unset($row);
        }
        foreach ($e['media'] ?? [] as $m) {
            if (isset($denied[$m['name']])) {
                continue;
            }
            $map = $this->mediaLists($m, null, $ids);
            foreach ($rows as &$row) {
                $row[$m['name']] = $map[$row['id']] ?? [];
            }
            unset($row);
        }
        // minimum count: ONE query per relationship, only for the rows of this page
        $min = $this->minRelations($e['table']);
        if ($min) {
            $missing = [];
            foreach ($min as $r) {
                $col = SqlGenerator::q($r['column']);
                $found = $this->pdo->query(
                    "SELECT DISTINCT $col FROM " . SqlGenerator::q($r['table']) . " WHERE $col IN ($in)"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_diff($ids, array_map('intval', $found)) as $id) {
                    $missing[(int) $id][] = $r['name'];
                }
            }
            foreach ($rows as &$row) {
                $row['_min_warnings'] = $missing[$row['id']] ?? [];
            }
            unset($row);
        }
        return ['data' => $rows, 'total' => $total, 'page' => $q->page, 'per_page' => $q->perPage,
            'total_pages' => (int) ceil($total / $q->perPage)];
    }

    public function find(string $table, int $id): array
    {
        $e = $this->entity($table);
        $row = $this->fetchRow($e, $id);
        if ($row === null) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datensatz nicht gefunden']);
        }
        return array_diff_key($row, $this->denied($e));
    }

    // ---------------------------------------------------------------- Writing

    /**
     * @param bool $allowEmptyMedia required media fields may be missing (test data generator only: it does not invent files)
     */
    public function create(string $table, array $input, bool $allowEmptyMedia = false): array
    {
        $e = $this->entity($table);
        $this->requireAction($e, 'create');
        $denied = $this->denied($e);
        $input = array_diff_key($input, $denied); // denied fields: silently discard values that were sent along
        // workflow field: always the initial state, a value sent along (e.g. when duplicating) does not count
        $workflow = Workflows::fieldOf($e);
        if ($workflow !== null) {
            $input[$workflow['name']] = $this->model['workflows'][$workflow['workflow']]['initial'] ?? null;
        }
        $errors = [];
        $values = $this->collectValues($e, $input, true, $errors);
        $links = $this->collectLinks($e, $input, $errors);
        $media = $this->collectMedia($e, $input, !$allowEmptyMedia, $errors);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        $this->rejectDuplicate($e, $values, null);

        return $this->transaction(function () use ($e, $values, $links, $media, $denied) {
            if ($values) {
                $cols = implode(', ', array_map([SqlGenerator::class, 'q'], array_keys($values)));
                $marks = implode(', ', array_fill(0, count($values), '?'));
                $stmt = $this->pdo->prepare('INSERT INTO ' . SqlGenerator::q($e['table']) . " ($cols) VALUES ($marks)");
                $stmt->execute(array_values($values));
            } else {
                $this->pdo->exec('INSERT INTO ' . SqlGenerator::q($e['table']) . ' DEFAULT VALUES');
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->syncLinks($e, $id, $links);
            $this->syncMedia($e, $id, $media);
            return array_diff_key($this->fetchRow($e, $id), $denied);
        }, $e, $values);
    }

    public function update(string $table, int $id, array $input): array
    {
        $e = $this->entity($table);
        $this->requireAction($e, 'update');
        $current = $this->fetchRow($e, $id);
        if ($current === null) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datensatz nicht gefunden']);
        }
        $denied = $this->denied($e);
        $input = array_diff_key($input, $denied); // denied fields: the stored value stays
        $errors = [];
        // workflow field: never changeable via PUT (only the unchanged value may come along) - that is what transition() is for
        $workflow = Workflows::fieldOf($e);
        if ($workflow !== null && array_key_exists($workflow['name'], $input)) {
            if ($input[$workflow['name']] !== $current[$workflow['name']]) {
                $errors[$workflow['name']] = 'Der Zustand lässt sich nur über eine Workflow-Transition ändern (POST /api/'
                    . $e['table'] . "/$id/transition).";
            }
            unset($input[$workflow['name']]);
        }
        $values = $this->collectValues($e, $input, false, $errors);
        $links = $this->collectLinks($e, $input, $errors);
        $media = $this->collectMedia($e, $input, false, $errors);
        $errors += $this->selfLinkErrors($e, $id, $links);
        $this->rejectCycles($e, $id, $values, $errors);
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        // PUT is partial: fields of the group that were not sent keep their stored value
        $this->rejectDuplicate($e, $values + $current, $id);

        return $this->transaction(function () use ($e, $id, $values, $links, $media, $denied) {
            if ($values) {
                $set = implode(', ', array_map(function ($c) {
                    return SqlGenerator::q($c) . ' = ?';
                }, array_keys($values)));
                $stmt = $this->pdo->prepare('UPDATE ' . SqlGenerator::q($e['table']) . " SET $set WHERE \"id\" = ?");
                $stmt->execute(array_merge(array_values($values), [$id]));
            }
            $this->syncLinks($e, $id, $links);
            $this->syncMedia($e, $id, $media);
            return array_diff_key($this->fetchRow($e, $id), $denied);
        }, $e, $values + $current);
    }

    /**
     * Deletes a row. Behaviour per FK that references it (PlantUML marker, see PumlParser):
     * - Restrict (default): if there are dependent rows, nothing is deleted -> 409 with the count per table.
     * - {cascade}: dependent rows are deleted along, recursively (also across self-references, to any depth).
     * - Minimum count ("1..*" on the n side): the last remaining row of a parent row that stays cannot
     *   be deleted -> 409 min_required (see minViolations()).
     * First the complete deletion set is determined, then it is checked against restrict references from outside this
     * set, then everything is deleted in one transaction - all or nothing. n:n links disappear
     * as before via ON DELETE CASCADE of the link table and never block.
     *
     * @return array<string,int> rows deleted along per table (without the row itself); empty without a cascade
     */
    public function delete(string $table, int $id): array
    {
        $e = $this->entity($table);
        $this->requireAction($e, 'delete');
        if ($this->fetchIds($e['table'], 'id', [$id]) === []) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Datensatz nicht gefunden']);
        }

        // 1. Deletion set: the row + everything that depends on it via {cascade} FKs (recursively). The set also
        //    serves as the "visited" marker so that legacy cycles in a self-reference terminate as well.
        //    $children records for EVERY row which rows depend on it via {cascade} - including those already
        //    found via another path (e.g. all categories of a shop AND their self-reference);
        //    the deletion order in step 3 follows from this.
        $doomed = [$e['table'] => [$id => true]];
        $levels = [[$e['table'], [$id]]];
        $children = []; // "table:id" => [["table:id", FK column], ...]
        for ($i = 0; $i < count($levels); $i++) {
            [$parent, $ids] = $levels[$i];
            foreach ($this->referencingFields($parent) as [$child, $column, $onDelete]) {
                if ($onDelete !== 'cascade') {
                    continue;
                }
                $new = [];
                foreach ($this->fetchPairs($child, $column, $ids) as [$childId, $parentId]) {
                    $children["$parent:$parentId"][] = ["$child:$childId", $column];
                    if (!isset($doomed[$child][$childId])) {
                        $doomed[$child][$childId] = true;
                        $new[] = $childId;
                    }
                }
                if ($new) {
                    $levels[] = [$child, $new];
                }
            }
        }

        // 2. Restrict: dependent rows that are not deleted along themselves prevent the deletion
        $blockers = [];
        foreach ($doomed as $parent => $set) {
            foreach ($this->referencingFields($parent) as [$child, $column, $onDelete]) {
                if ($onDelete === 'cascade') {
                    continue;
                }
                foreach ($this->fetchIds($child, $column, array_keys($set)) as $childId) {
                    if (!isset($doomed[$child][$childId])) {
                        $blockers[$child][$childId] = true;
                    }
                }
            }
        }
        if ($blockers) {
            $counts = array_map('count', $blockers);
            $parts = [];
            foreach ($counts as $child => $n) {
                $parts[] = "$n abhängige Zeile(n) in '$child'";
            }
            throw new ApiException(409, [
                'error'      => 'in_use',
                'message'    => 'Kann nicht gelöscht werden: ' . implode(', ', $parts) . '.',
                'dependents' => $counts,
            ]);
        }

        // 2b. Minimum count ("1..*" on the n side, e.g. Position "1..*" --> "1" Rechnung): if a row that
        //     stays itself would lose its last related row through this deletion, nothing is deleted. If it is
        //     deleted along (it is the deleted row or depends on it via {cascade}), the rule no longer applies.
        $lacking = $this->minViolations($doomed);
        if ($lacking) {
            $parts = array_map(function ($v) {
                return "'{$v['entity']} #{$v['id']}' benötigt mindestens eine Zeile in '{$v['child_entity']}'";
            }, $lacking);
            throw new ApiException(409, [
                'error'        => 'min_required',
                'message'      => 'Kann nicht gelöscht werden: ' . implode(', ', $parts) . ' (Mindestanzahl).',
                'min_required' => $lacking,
            ]);
        }

        // 3. Delete, row by row strictly "children before parents" (across all tables, see deletionOrder()):
        //    this way the database's ON DELETE CASCADE (SqlGenerator) never finds a child. Otherwise SQLite would
        //    process the cascade recursively itself and abort on deep chains (> 1000 levels) with "too many levels of trigger
        //    recursion" - even if the chain was found all at once via a second path (e.g. shop -> all categories).
        //    defer_foreign_keys defers the restrict check until the COMMIT so that references within the
        //    deletion set do not interfere with the order (applies to this transaction only). If the COMMIT still fails
        //    because of an FK (dependent row created in parallel), everything stays unchanged -> 409 as before.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('PRAGMA defer_foreign_keys = ON');
            [$order, $cycleEdges] = self::deletionOrder("{$e['table']}:$id", $children);
            // Legacy cycles (only possible via direct SQL): cut the edge that closes the ring beforehand - otherwise
            // the database cascade would run once around the whole ring when deleting (with > 1000 rows the
            // recursion error again). A non-existent value instead of NULL so that this also works for a required FK
            // (NOT NULL); the FK check is deferred, and the row is deleted at COMMIT anyway.
            foreach ($cycleEdges as [$key, $column]) {
                $pos = strrpos($key, ':');
                $this->pdo->prepare(
                    'UPDATE ' . SqlGenerator::q(substr($key, 0, $pos)) . ' SET ' . SqlGenerator::q($column) . ' = -1 WHERE "id" = ?'
                )->execute([(int) substr($key, $pos + 1)]);
            }
            $stmts = [];
            foreach ($order as $key) {
                $pos = strrpos($key, ':');
                $t = substr($key, 0, $pos);
                $stmts[$t] = $stmts[$t] ?? $this->pdo->prepare('DELETE FROM ' . SqlGenerator::q($t) . ' WHERE "id" = ?');
                $stmts[$t]->execute([(int) substr($key, $pos + 1)]);
            }
            foreach ($doomed as $t => $set) {
                Workflows::forgetRecords($this->pdo, $t, array_keys($set)); // tasks of the deleted rows
            }
            $this->pdo->commit();
        } catch (\Throwable $ex) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($ex instanceof PDOException && $this->isConstraintError($ex)) {
                throw new ApiException(409, [
                    'error'   => 'in_use',
                    'message' => 'Datensatz wird noch von anderen Datensätzen referenziert',
                ]);
            }
            throw $ex;
        }

        unset($doomed[$e['table']][$id]);
        return array_filter(array_map('count', $doomed));
    }

    // ---------------------------------------------------------------- Internal

    private function entity(string $table): array
    {
        $key = strtolower($table);
        if (!isset($this->model['entities'][$key])) {
            throw new ApiException(404, ['error' => 'unknown_entity', 'message' => "Unbekannte Entität '$table'"]);
        }
        if ($this->access !== null && $this->access->entityDenied($key)) {
            throw new ApiException(403, ['error' => 'forbidden', 'message' => "Keine Berechtigung für '$table'"]);
        }
        return $this->model['entities'][$key];
    }

    /**
     * Action permission (roles and permissions, scope action): 403 if creating, editing or deleting in this entity is
     * denied for the caller. The entity the call is aimed at is checked - rows of other entities deleted along via
     * {cascade} follow the rule in the diagram as before.
     */
    private function requireAction(array $e, string $action): void
    {
        if ($this->access !== null && $this->access->actionDenied($e['table'], $action)) {
            $what = ['create' => 'Anlegen', 'update' => 'Bearbeiten', 'delete' => 'Löschen'][$action];
            throw new ApiException(403, ['error' => 'forbidden', 'action' => $action,
                'message' => "Keine Berechtigung zum $what in '{$e['name']}'"]);
        }
    }

    /** @return array<string,true> API names of the entity denied for the caller (field, FK column, n:n list, media field) */
    private function denied(array $e): array
    {
        $denied = $this->access !== null ? $this->access->deniedFields($e['table']) : [];
        // workflow field: stands outside the field permissions (the rights management rejects a denial on it; an older one,
        // e.g. from before the workflow existed, has no effect)
        $workflow = $denied ? Workflows::fieldOf($e) : null;
        if ($workflow !== null) {
            unset($denied[$workflow['name']]);
        }
        return $denied;
    }

    /** titleField() without denied fields (if none is left: id) */
    private function visibleTitle(array $entity): array
    {
        $title = array_values(array_diff(self::titleField($entity), array_keys($this->denied($entity))));
        return $title ?: ['id'];
    }

    /** Only deliver {filter_by} if the caller sees both fields involved */
    private function filterVisible(array $e, array $target, array $filter): bool
    {
        return !isset($this->denied($e)[$filter['field'] ?? '']) && !isset($this->denied($target)[$filter['target_field'] ?? '']);
    }

    /**
     * Deletion order "children before parents": depth-first search from the row to be deleted along the {cascade} edges,
     * every row comes only after all rows that depend on it (post-order). Iterative instead of recursive so that chains
     * with many thousands of levels do not fail at PHP's call depth either. An edge to a row that is currently on the
     * stack itself closes a legacy cycle; it is skipped and returned so that delete() can cut it before
     * deleting.
     *
     * @param array<string,array<int,array{0:string,1:string}>> $children "table:id" => [[dependent row, FK column], ...]
     * @return array{0:string[],1:array<int,array{0:string,1:string}>} [all reachable rows as "table:id",
     *         children before parents, the root last; cycle-closing edges as [row, FK column]]
     */
    private static function deletionOrder(string $root, array $children): array
    {
        $order = [];
        $cycleEdges = [];
        $onStack = [$root => true];
        $seen = [$root => true];
        $stack = [$root];
        $next = [$root => 0]; // index of the next child to visit per row on the stack
        while ($stack) {
            $node = $stack[count($stack) - 1];
            $kids = $children[$node] ?? [];
            if ($next[$node] < count($kids)) {
                [$kid, $column] = $kids[$next[$node]++];
                if (!isset($seen[$kid])) {
                    $seen[$kid] = true;
                    $onStack[$kid] = true;
                    $next[$kid] = 0;
                    $stack[] = $kid;
                } elseif (isset($onStack[$kid])) {
                    $cycleEdges[] = [$kid, $column];
                }
                continue;
            }
            array_pop($stack);
            unset($onStack[$node]);
            $order[] = $node;
        }
        return [$order, $cycleEdges];
    }

    /**
     * Rows that would fall below their minimum count by deleting $doomed: per relationship with a minimum count, the
     * parent rows of the rows to be deleted that stay themselves and would have no related row left afterwards.
     * Two queries per affected FK column (in chunks), independent of the number of rows.
     *
     * @param array<string,array<int,true>> $doomed table => IDs to delete
     * @return array<int,array{table:string,entity:string,id:int,relation:string,child_entity:string}>
     */
    private function minViolations(array $doomed): array
    {
        $out = [];
        foreach ($doomed as $child => $set) {
            foreach ($this->model['entities'][$child]['fields'] as $f) {
                $fk = $f['foreign_key'];
                if (!$fk || empty($fk['min_required'])) {
                    continue;
                }
                $table = SqlGenerator::q($child);
                $col = SqlGenerator::q($f['name']);
                // parent row => number of its rows that are being deleted
                $leaving = [];
                foreach (array_chunk(array_keys($set), 500) as $chunk) {
                    $marks = implode(', ', array_fill(0, count($chunk), '?'));
                    $stmt = $this->pdo->prepare(
                        "SELECT $col, COUNT(*) FROM $table WHERE \"id\" IN ($marks) AND $col IS NOT NULL GROUP BY $col"
                    );
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$parentId, $n]) {
                        $leaving[(int) $parentId] = ($leaving[(int) $parentId] ?? 0) + (int) $n;
                    }
                }
                // parent rows that are deleted along no longer need a row
                $leaving = array_diff_key($leaving, $doomed[$fk['table']] ?? []);
                foreach (array_chunk(array_keys($leaving), 500) as $chunk) {
                    $marks = implode(', ', array_fill(0, count($chunk), '?'));
                    $stmt = $this->pdo->prepare("SELECT $col, COUNT(*) FROM $table WHERE $col IN ($marks) GROUP BY $col");
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$parentId, $total]) {
                        if ((int) $total - $leaving[(int) $parentId] < 1) {
                            $out[] = [
                                'table' => $fk['table'], 'entity' => $this->model['entities'][$fk['table']]['name'],
                                'id' => (int) $parentId, 'relation' => $child . '.' . $f['name'],
                                'child_entity' => $this->model['entities'][$child]['name'],
                            ];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** 'restrict' or 'cascade'; models from before the {cascade} option do not know the key -> restrict. */
    private static function onDelete(array $field): string
    {
        return ($field['foreign_key']['on_delete'] ?? 'restrict') === 'cascade' ? 'cascade' : 'restrict';
    }

    /**
     * All FK fields (of all entities, including the own one for a self-reference) that reference $table.
     *
     * @return array<int,array{0:string,1:string,2:string}> [dependent table, FK column, on_delete]
     */
    private function referencingFields(string $table): array
    {
        $refs = [];
        foreach ($this->model['entities'] as $child) {
            foreach ($child['fields'] as $f) {
                if ($f['foreign_key'] && $f['foreign_key']['table'] === $table) {
                    $refs[] = [$child['table'], $f['name'], self::onDelete($f)];
                }
            }
        }
        return $refs;
    }

    /**
     * IDs of the rows in $table whose column $column has one of the values from $values (in chunks so that even
     * large sets stay below SQLite's limit for bound parameters).
     *
     * @param int[] $values
     * @return int[]
     */
    private function fetchIds(string $table, string $column, array $values): array
    {
        $ids = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT "id" FROM ' . SqlGenerator::q($table) . ' WHERE ' . SqlGenerator::q($column) . " IN ($marks)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Like fetchIds(), but with the respective value of the column: [[id, $column value], ...] - this tells exactly which
     * row a found row depends on (for the deletion order in delete()).
     *
     * @param int[] $values
     * @return array<int,array{0:int,1:int}>
     */
    private function fetchPairs(string $table, string $column, array $values): array
    {
        $pairs = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT "id", ' . SqlGenerator::q($column) . ' FROM ' . SqlGenerator::q($table)
                . ' WHERE ' . SqlGenerator::q($column) . " IN ($marks)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$childId, $parentId]) {
                $pairs[] = [(int) $childId, (int) $parentId];
            }
        }
        return $pairs;
    }

    private function fetchRow(array $e, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . SqlGenerator::q($e['table']) . ' WHERE "id" = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row = $this->castRow($e, $row);
        foreach ($e['many_to_many'] as $m) {
            $junction = SqlGenerator::q($m['junction']);
            $own = SqlGenerator::q($m['own_column']);
            $other = SqlGenerator::q($m['other_column']);
            if (!empty($m['symmetric'])) {
                // {symmetric}: every pair is stored once (smaller ID in own_column) - the row can be on
                // either side
                $link = $this->pdo->prepare(
                    "SELECT $other FROM $junction WHERE $own = ? UNION SELECT $own FROM $junction WHERE $other = ? ORDER BY 1"
                );
                $link->execute([$id, $id]);
                $row[$m['name']] = array_map('intval', $link->fetchAll(PDO::FETCH_COLUMN));
                continue;
            }
            $link = $this->pdo->prepare("SELECT $other FROM $junction WHERE $own = ? ORDER BY 1");
            $link->execute([$id]);
            $row[$m['name']] = array_map('intval', $link->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ($e['media'] ?? [] as $m) {
            $row[$m['name']] = $this->mediaLists($m, $id)[$id] ?? [];
        }
        $min = $this->minRelations($e['table']);
        if ($min) {
            $row['_min_warnings'] = [];
            foreach ($min as $r) {
                $has = $this->pdo->prepare(
                    'SELECT 1 FROM ' . SqlGenerator::q($r['table']) . ' WHERE ' . SqlGenerator::q($r['column']) . ' = ? LIMIT 1'
                );
                $has->execute([$id]);
                if ($has->fetchColumn() === false) {
                    $row['_min_warnings'][] = $r['name'];
                }
            }
        }
        return $row;
    }

    /**
     * Linked media of a media field in list order (position), as public media objects (Media::present,
     * among others with url) - a consuming frontend therefore needs no access to the (authenticated) media library API.
     *
     * @param int|null $id only this row; null = all
     * @param int[]|null $ids only these rows (list page); null = no restriction
     * @return array<int,array[]> row ID => media
     */
    private function mediaLists(array $m, ?int $id, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $own = SqlGenerator::q($m['own_column']);
        $stmt = $this->pdo->prepare(
            "SELECT j.$own AS own_id, md.* FROM " . SqlGenerator::q($m['junction']) . ' j JOIN "media" md ON md."id" = j."media_id"'
            . ($id !== null ? " WHERE j.$own = ?" : ($ids !== null ? " WHERE j.$own IN (" . implode(', ', array_map('intval', $ids)) . ')' : ''))
            . " ORDER BY j.$own, j.\"position\""
        );
        $stmt->execute($id !== null ? [$id] : []);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['own_id']][] = Media::present($r);
        }
        return $map;
    }

    /**
     * Self-reference (FK references the own table, e.g. category -> parent category): a new parent value
     * must not create a cycle, i.e. it may be neither the row itself nor one of its descendants. Equivalently: the row
     * must not occur among the ancestors of the new parent value. A single recursive query across any number of
     * levels; UNION (instead of UNION ALL) also terminates with already existing legacy cycles. CAST is needed: PDO binds
     * parameters as text, and in this query SQLite does not compare the number 1 with the text '1'. Nothing needs to be
     * checked when creating (a new row has no descendants yet).
     */
    private function rejectCycles(array $e, int $id, array $values, array &$errors): void
    {
        foreach ($e['fields'] as $f) {
            $fk = $f['foreign_key'];
            $name = $f['name'];
            if (!$fk || $fk['table'] !== $e['table'] || !isset($values[$name]) || isset($errors[$name])) {
                continue;
            }
            $table = SqlGenerator::q($e['table']);
            $col = SqlGenerator::q($name);
            $stmt = $this->pdo->prepare(
                "WITH RECURSIVE ancestors(id) AS (
                     SELECT CAST(? AS INTEGER)
                     UNION
                     SELECT t.$col FROM $table t JOIN ancestors a ON t.\"id\" = a.id WHERE t.$col IS NOT NULL
                 )
                 SELECT 1 FROM ancestors WHERE id = CAST(? AS INTEGER) LIMIT 1"
            );
            $stmt->execute([(int) $values[$name], $id]);
            if ($stmt->fetchColumn()) {
                $errors[$name] = 'Würde einen Zyklus erzeugen: Der Datensatz kann nicht sich selbst oder einem seiner Nachfahren untergeordnet werden';
            }
        }
    }

    /**
     * Uniqueness rules of the entity (SqlGenerator::uniqueGroups: {unique} group and, per 1:1 relationship, the FK column):
     * if the combination of values already existed in another row, 409 with the existing row - like "Benutzername
     * bereits vergeben" (Users). If the combination contains NULL, it never counts as a duplicate, as in SQL (and in the
     * UNIQUE constraint). errors marks all fields involved so that the form highlights them.
     *
     * @param array $values column => value of the future row (when creating only those sent, otherwise missing = NULL)
     * @param int|null $id own row when editing (does not count as a duplicate)
     */
    private function rejectDuplicate(array $e, array $values, ?int $id): void
    {
        foreach (SqlGenerator::uniqueGroups($e) as $fields) {
            $where = [];
            $params = [];
            foreach ($fields as $name) {
                $value = $values[$name] ?? null;
                if ($value === null) {
                    continue 2;
                }
                $where[] = SqlGenerator::q($name) . ' = ?';
                $params[] = is_bool($value) ? (int) $value : $value;
            }
            if ($id !== null) {
                $where[] = '"id" <> ?';
                $params[] = $id;
            }
            $stmt = $this->pdo->prepare(
                'SELECT "id" FROM ' . SqlGenerator::q($e['table']) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
            );
            $stmt->execute($params);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                throw new ApiException(409, $this->duplicatePayload($e, $fields, $values, (int) $existing));
            }
        }
    }

    /**
     * 409 response for an already used combination $fields ($existing = null: row unknown, constraint). For a
     * 1:1 column the message names the target row ("Nutzer #3 ist bereits Profil #1 zugeordnet"), otherwise columns and values.
     */
    private function duplicatePayload(array $e, array $fields, array $values, ?int $existing): array
    {
        $oneToOne = null;
        foreach ($e['fields'] as $f) {
            if (count($fields) === 1 && $f['name'] === $fields[0] && !empty($f['foreign_key']['one_to_one'])) {
                $oneToOne = $f;
            }
        }
        if ($oneToOne !== null) {
            $target = $this->model['entities'][$oneToOne['foreign_key']['table']]['name'];
            $ref = $target . ' #' . json_encode($values[$fields[0]] ?? null);
            $message = $existing !== null
                ? "$ref ist bereits {$e['name']} #$existing zugeordnet (1:1-Beziehung: höchstens eine Zuordnung je $target)."
                : "$ref ist bereits einem anderen Datensatz von {$e['name']} zugeordnet (1:1-Beziehung: höchstens eine Zuordnung je $target).";
            $fieldError = $existing !== null ? "Bereits {$e['name']} #$existing zugeordnet" : 'Bereits zugeordnet';
        } else {
            $parts = [];
            foreach ($fields as $name) {
                $v = $values[$name] ?? null;
                $parts[] = $name . ' = ' . (is_string($v) ? "'$v'" : json_encode($v));
            }
            $where = $existing !== null ? " ({$e['name']} #$existing)" : '';
            $message = count($fields) > 1
                ? 'Die Kombination ' . implode(', ', $parts) . " existiert bereits$where."
                : 'Der Wert ' . $parts[0] . " ist bereits vergeben$where.";
            $fieldError = count($fields) > 1 ? 'Kombination existiert bereits' : 'Bereits vergeben';
        }
        // rule with a field denied for the caller: reveal neither its name nor its value
        $visible = array_values(array_diff($fields, array_keys($this->denied($e))));
        if ($visible !== $fields) {
            $message = 'Diese Kombination existiert bereits (die Regel umfasst ein Feld, das für Sie gesperrt ist).';
        }
        return [
            'error'         => 'duplicate',
            'message'       => $message,
            'unique_fields' => $visible,
            'existing_id'   => $existing,
            'errors'        => array_fill_keys($visible, $fieldError),
        ];
    }

    /** Depending on the PHP version, pdo_sqlite returns strings or native types; unified here. */
    private function castRow(array $e, array $row): array
    {
        foreach ($e['fields'] as $f) {
            $name = $f['name'];
            if (!array_key_exists($name, $row) || $row[$name] === null) {
                continue;
            }
            if ($f['type'] === 'int') {
                $row[$name] = (int) $row[$name];
            } elseif ($f['type'] === 'decimal') {
                $row[$name] = (float) $row[$name];
            } elseif ($f['type'] === 'bool' || $f['type'] === 'visibility') {
                $row[$name] = (bool) (int) $row[$name];
            }
        }
        return $row;
    }

    /** @return array<string,mixed> column => value (only fields present in the body) */
    private function collectValues(array $e, array $input, bool $creating, array &$errors): array
    {
        $values = [];
        foreach ($e['fields'] as $f) {
            if ($f['primary']) {
                continue;
            }
            $name = $f['name'];
            if (!array_key_exists($name, $input)) {
                if ($creating && $f['required'] && $f['type'] === 'visibility') {
                    $values[$name] = 0; // default value: draft
                } elseif ($creating && $f['required']) {
                    $errors[$name] = 'Pflichtfeld';
                }
                continue;
            }
            try {
                $value = self::coerce($f, $input[$name]);
            } catch (\InvalidArgumentException $ex) {
                $errors[$name] = $ex->getMessage();
                continue;
            }
            if ($value === null && $f['required']) {
                $errors[$name] = 'Pflichtfeld';
                continue;
            }
            $values[$name] = $value;
        }
        return $values;
    }

    /** @return array<string,int[]> relation name => IDs (only those present in the body) */
    private function collectLinks(array $e, array $input, array &$errors): array
    {
        $links = [];
        foreach ($e['many_to_many'] as $m) {
            $name = $m['name'];
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $ids = $input[$name] ?? [];
            if (!is_array($ids)) {
                $errors[$name] = 'Liste von IDs erwartet';
                continue;
            }
            $clean = [];
            foreach ($ids as $id) {
                if (!is_int($id) && !(is_string($id) && preg_match('/^\d+$/', $id))) {
                    $errors[$name] = 'Liste von IDs erwartet';
                    continue 2;
                }
                $clean[(int) $id] = true;
            }
            $links[$name] = array_keys($clean);
        }
        return $links;
    }

    /**
     * Media fields from the body: per field a list of media IDs in the desired order. Accepted are
     * IDs and - so that a row that was read can be sent back unchanged - media objects with "id". Duplicate
     * IDs count once (first position). Required field (without "?"): at least one medium; when creating it must then
     * be sent along ($requireOnCreate), when editing (PUT, partial) only if the key is in the body.
     *
     * @return array<string,int[]> field name => media IDs (only those present in the body)
     */
    private function collectMedia(array $e, array $input, bool $requireOnCreate, array &$errors): array
    {
        $lists = [];
        foreach ($e['media'] ?? [] as $m) {
            $name = $m['name'];
            if (!array_key_exists($name, $input)) {
                if ($requireOnCreate && $m['required']) {
                    $errors[$name] = 'Pflichtfeld: mindestens ein Medium auswählen';
                }
                continue;
            }
            $items = $input[$name] ?? [];
            if (!is_array($items) || ($items && array_keys($items) !== range(0, count($items) - 1))) {
                $errors[$name] = 'Liste von Medien-IDs erwartet';
                continue;
            }
            $ids = [];
            foreach ($items as $item) {
                $v = is_array($item) ? ($item['id'] ?? null) : $item;
                if (!is_int($v) && !(is_string($v) && preg_match('/^\d+$/', $v))) {
                    $errors[$name] = 'Liste von Medien-IDs erwartet';
                    continue 2;
                }
                $ids[(int) $v] = true;
            }
            $ids = array_keys($ids);
            if (!$ids && $m['required']) {
                $errors[$name] = 'Pflichtfeld: mindestens ein Medium auswählen';
                continue;
            }
            if ($ids) {
                $marks = implode(', ', array_fill(0, count($ids), '?'));
                $stmt = $this->pdo->prepare("SELECT \"id\" FROM \"media\" WHERE \"id\" IN ($marks)");
                $stmt->execute($ids);
                $missing = array_diff($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                if ($missing) {
                    $errors[$name] = 'Medium ' . implode(', ', array_map(function ($i) {
                        return "#$i";
                    }, $missing)) . ' existiert nicht';
                    continue;
                }
            }
            $lists[$name] = $ids;
        }
        return $lists;
    }

    /** Replaces the media list per field sent along (position = order in the body). */
    private function syncMedia(array $e, int $id, array $lists): void
    {
        foreach ($e['media'] ?? [] as $m) {
            if (!isset($lists[$m['name']])) {
                continue;
            }
            $junction = SqlGenerator::q($m['junction']);
            $own = SqlGenerator::q($m['own_column']);
            $this->pdo->prepare("DELETE FROM $junction WHERE $own = ?")->execute([$id]);
            $ins = $this->pdo->prepare("INSERT INTO $junction ($own, \"media_id\", \"position\") VALUES (?, ?, ?)");
            foreach ($lists[$m['name']] as $pos => $mediaId) {
                $ins->execute([$id, $mediaId, $pos]);
            }
        }
    }

    /**
     * n:n self-reference: a row cannot be linked to itself (neither directed nor {symmetric}). This
     * is a data error, not a schema error -> 422 on the list field. The link table additionally enforces this via CHECK.
     *
     * @param array<string,int[]> $links see collectLinks()
     * @return array<string,string> list name => message
     */
    private function selfLinkErrors(array $e, int $id, array $links): array
    {
        $errors = [];
        foreach ($e['many_to_many'] as $m) {
            if ($m['table'] === $e['table'] && in_array($id, $links[$m['name']] ?? [], true)) {
                $errors[$m['name']] = 'Ein Datensatz kann nicht mit sich selbst verknüpft werden';
            }
        }
        return $errors;
    }

    /**
     * Replaces the links of row $id per list sent along. Directed (default, also for a self-reference): only
     * the rows with $id in own_column - the opposite direction ("who follows me") stays untouched. {symmetric}: all pairs
     * with $id on either side; every pair is stored canonically (smaller ID in own_column) exactly once, so "A-B"
     * and "B-A" are the same row and cannot be created twice.
     */
    private function syncLinks(array $e, int $id, array $links): void
    {
        // when creating, this can only be checked here (the ID is only known now); the transaction is then rolled back
        $selfErrors = $this->selfLinkErrors($e, $id, $links);
        if ($selfErrors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $selfErrors]);
        }
        foreach ($e['many_to_many'] as $m) {
            if (!isset($links[$m['name']])) {
                continue;
            }
            $junction = SqlGenerator::q($m['junction']);
            $own = SqlGenerator::q($m['own_column']);
            $other = SqlGenerator::q($m['other_column']);
            $symmetric = !empty($m['symmetric']);
            $this->pdo->prepare("DELETE FROM $junction WHERE $own = ?" . ($symmetric ? " OR $other = ?" : ''))
                ->execute($symmetric ? [$id, $id] : [$id]);
            $ins = $this->pdo->prepare("INSERT INTO $junction ($own, $other) VALUES (?, ?)");
            foreach ($links[$m['name']] as $otherId) {
                $ins->execute($symmetric ? [min($id, $otherId), max($id, $otherId)] : [$id, $otherId]);
            }
        }
    }

    /**
     * Convert a value according to the field rules (storage form: bool as 1/0, numbers as int/float, date/enum/text as
     * string, empty = null). Throws \InvalidArgumentException with the message for the form. Also used by SchemaMigration
     * (backfill values, type change of existing data).
     *
     * @return mixed
     */
    public static function coerce(array $f, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        switch ($f['type']) {
            case 'int':
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) {
                    return (int) $value;
                }
                throw new \InvalidArgumentException('Ganzzahl erwartet');
            case 'decimal':
                // Integers and floating point numbers (decimal point, not comma - JSON/<input type=number> always deliver
                // a point, independent of the browser's display locale).
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }
                if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
                    return (float) $value;
                }
                throw new \InvalidArgumentException('Zahl erwartet');
            case 'bool':
            case 'visibility':
                if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
                    return 1;
                }
                if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
                    return 0;
                }
                throw new \InvalidArgumentException('true oder false erwartet');
            case 'date':
                if (is_string($value)) {
                    $d = \DateTime::createFromFormat('!Y-m-d', $value);
                    if ($d && $d->format('Y-m-d') === $value) {
                        return $value;
                    }
                }
                throw new \InvalidArgumentException('Datum im Format JJJJ-MM-TT erwartet');
            case 'enum':
                // exactly one of the declared values (case-sensitive, as with the CHECK constraint)
                if (is_string($value) && in_array($value, $f['enum_values'], true)) {
                    return $value;
                }
                throw new \InvalidArgumentException(
                    "Ungültiger Wert für '{$f['name']}'. Erlaubt: " . implode(', ', $f['enum_values']) . '.'
                );
            default: // string, text, richtext
                if (is_scalar($value)) {
                    return (string) $value;
                }
                throw new \InvalidArgumentException('Text erwartet');
        }
    }

    private function isConstraintError(PDOException $ex): bool
    {
        return (string) $ex->getCode() === '23000';
    }

    /**
     * @param array|null $e entity whose uniqueness rules may be violated (only for the 409 message)
     * @param array $values written values (only for the 409 message)
     * @return mixed
     */
    private function transaction(callable $fn, ?array $e = null, array $values = [])
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (PDOException $ex) {
            $this->pdo->rollBack();
            // Duplicate created in parallel that rejectDuplicate() could not see yet: the UNIQUE constraint kicks in.
            // SQLite names the columns ("UNIQUE constraint failed: profil.nutzer_id"), which is how the rule is recognized.
            if ($e !== null && preg_match('/UNIQUE constraint failed: (.+)$/', $ex->getMessage(), $m)) {
                $cols = array_map(function ($c) {
                    return substr(trim($c), strrpos(trim($c), '.') + 1);
                }, explode(',', $m[1]));
                foreach (SqlGenerator::uniqueGroups($e) as $group) {
                    if ($group === $cols) {
                        throw new ApiException(409, $this->duplicatePayload($e, $group, $values, null));
                    }
                }
            }
            if ($this->isConstraintError($ex)) {
                throw new ApiException(422, [
                    'error'   => 'constraint',
                    'message' => 'Verweis auf einen nicht vorhandenen Datensatz oder Pflichtfeld fehlt',
                ]);
            }
            throw $ex;
        } catch (\Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }
}
