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
    /** @var bool call the webhooks after a write (Webhooks::fire()); off for the test data generator */
    private $webhooks = true;
    /** @var Validation|null field rules of the diagram (messages in the language of the user), on demand */
    private $validation = null;

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
     *
     * Field rules ({maxLength: 140}, {pattern: plz}, ..., see Validation): create() and update() check the complete record
     * (update: stored state merged with the change) before saving - 422 with 'errors' and 'violations'. Denied fields are
     * not checked for the caller (they cannot change them, the save leaves them as they are) and never appear in messages.
     * A transition only changes the workflow state (and visibility), which have no rules - it is not blocked by them.
     */
    public function __construct(PDO $pdo, array $model, ?Access $access = null, ?array $user = null)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->access = $access;
        $this->user = $user;
    }

    /**
     * Writes of this instance call no webhooks - for bulk writes like the test data generator, which would otherwise
     * call the global webhooks once per generated record.
     */
    public function withoutWebhooks(): self
    {
        $this->webhooks = false;
        return $this;
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
                // composition (`Ganzes *-- "n" Teil`): this record is a part of the target row - likewise only then
                if (!empty($f['foreign_key']['composition'])) {
                    $fk['composition'] = true;
                }
                // {translations}: the relation to the original of the language versions - likewise only then
                if (!empty($f['foreign_key']['translations'])) {
                    $fk['translations'] = true;
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
            if (array_key_exists('section', $f)) {
                $field['section'] = $f['section']; // only for classes with sections, see below
            }
            // field rules (Validation::describe()) - only for fields that have some, otherwise _schema stays as before
            $rules = $this->validation()->describe($f);
            if ($rules !== null) {
                $field['rules'] = $rules;
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
                ? ['filter_by' => $m['filter_by']] : []) // {filter_by}, see above
                + (array_key_exists('section', $m) ? ['section' => $m['section']] : []);
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
        // named groups {unique: name}: only for entities that have some (the _schema of every other one stays as before);
        // like unique_fields without the fields denied for the caller, a group without a visible field is left out
        $groups = [];
        foreach ($e['unique_groups'] ?? [] as $name => $cols) {
            $visible = array_values(array_diff($cols, array_keys($denied)));
            if ($visible) {
                $groups[] = ['name' => (string) $name, 'fields' => $visible];
            }
        }
        if (!empty($e['unique_groups'])) {
            $schema['unique_groups'] = $groups;
        }
        // manual order (type sort): the field and the FK columns its groups are formed over - only for entities with a sort
        // field the caller sees. The UI offers the arrows with it and hides the field itself (form, columns).
        $sort = Sorting::fieldOf($e);
        if ($sort !== null && !isset($denied[$sort['name']])) {
            $schema['sort'] = ['field' => $sort['name'], 'by' => array_values(array_diff($sort['sort']['by'], array_keys($denied))),
                'shared' => count($sort['sort']['tables']) > 1];
        }
        // media fields (type media, list of media from the media library) - only for entities that have some; the _schema
        // of all others stays unchanged
        $media = array_values(array_filter($e['media'] ?? [], function ($m) use ($denied) {
            return !isset($denied[$m['name']]);
        }));
        if ($media) {
            $schema['media'] = array_map(function ($m) use ($denied) {
                return ['name' => $m['name'], 'type' => 'media', 'required' => $m['required']]
                    + (array_key_exists('section', $m) ? ['section' => $m['section']] : [])
                    + $this->describeMeta($m, $denied);
            }, $media);
        }
        // Display order of all fields, media fields and n:n lists as in the diagram (see PumlParser::finishOrder()); for
        // models stored before this option derived as fields, n:n, media
        $schema['field_order'] = array_values(array_diff(self::fieldOrder($e), array_keys($denied)));
        // Form sections (separator lines with a title) - only for classes that have some, and only sections that still
        // contain a visible entry; 'section' per entry of fields/media/many_to_many (relation fields: null)
        if (!empty($e['sections'])) {
            $used = array_column(array_merge($fields, $many, $schema['media'] ?? []), 'section');
            $schema['sections'] = array_values(array_filter($e['sections'], function ($title) use ($used) {
                return in_array($title, $used, true);
            }));
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
        // Compositions of this whole (sections in its form, GET /api/{entity}/{id}/parts/{name}) - only for entities that are
        // the whole of a composition, and only with the parts the caller may read
        $compositions = $this->describeCompositions($e);
        if ($compositions) {
            $schema['compositions'] = $compositions;
        }
        // {translations}: language versions ("Übersetzung anlegen", GET .../translations, POST .../translate) - only for classes
        // with the marker, and only if the caller sees the relation and the language field
        $tr = Translations::of($e);
        if ($tr !== null && !isset($denied[$tr['relation']]) && !isset($denied[$tr['language_field']])) {
            $schema['translations'] = $tr;
        }
        // tracking metadata (RecordMeta): always present, never editable, outside the field permissions like the id;
        // without a session without created_by/updated_by (usernames are not published)
        if (RecordMeta::applies($e)) {
            $schema['system_fields'] = RecordMeta::fields($this->user !== null);
        }
        return $schema;
    }

    /**
     * _schema 'compositions' of a whole: per group of Compositions::of() {name, label, title (abstract class of a shared
     * order or null), part_entities, fk_column (the same in all parts, else null), fk_columns {table: column}, min_count
     * (1 = a part class has the minimum count "1..*"), min_entities, shared_sort, sort_field (the same in all parts, else
     * null)}. Parts the caller may not read (entity denied, or the FK column denied) are left out, a group without parts too.
     */
    private function describeCompositions(array $e): array
    {
        $out = [];
        foreach (Compositions::of($this->model, $e['table']) as $g) {
            $parts = [];
            foreach ($g['parts'] as $t => $col) {
                if ($this->access !== null && $this->access->entityDenied($t)) {
                    continue;
                }
                if (isset($this->denied($this->model['entities'][$t])[$col])) {
                    continue;
                }
                $parts[$t] = $col;
            }
            if (!$parts) {
                continue;
            }
            $columns = array_values(array_unique($parts));
            $sorts = array_values(array_unique(array_intersect_key($g['sort'], $parts)));
            $min = array_values(array_intersect($g['min'], array_keys($parts)));
            $out[] = [
                'name' => $g['name'], 'label' => $g['label'], 'title' => $g['abstract'],
                'part_entities' => array_keys($parts), 'fk_column' => count($columns) === 1 ? $columns[0] : null, 'fk_columns' => $parts,
                'min_count' => $min ? 1 : 0, 'min_entities' => $min, 'shared_sort' => $g['shared'],
                'sort_field' => count($sorts) === 1 && count(array_intersect_key($g['sort'], $parts)) === count($parts) ? $sorts[0] : null,
            ];
        }
        return $out;
    }

    /**
     * GET /api/{whole}/{id}/parts/{name}: the parts of a composition of the record $id in their order - for a shared order
     * (several part classes, see Compositions) one mixed list over all classes, otherwise the order of the class (sort field
     * grouped by the whole) or the ids. Every entry carries 'entity' (table). Without $expand a short entry {entity, id,
     * title (display text of the {title} fields), visibility (true/false, null without a visibility field), state (workflow
     * state or null)}, with a session also 'move' => {up, down} (is a move possible and allowed - the neighbour of a shared
     * order can be of another class, see move()); with $expand the complete records as GET /api/{entity}/{id} delivers them
     * (media, n:n lists) plus 'entity'; with $expand = 2 every part additionally 'parts' => {<name>: [...]} - its own
     * compositions, expanded the same way (one level more, e.g. the tiles of a tile block; {} for a class without
     * compositions; a composition whose part classes are all denied is missing). Readability as for the single entities:
     * parts of denied classes are missing, drafts only with $includeDrafts (session, checked by the caller) - on both levels.
     * 404 for an unknown composition.
     *
     * @param int $expand 0 = short entries, 1 = complete records, 2 = complete records with their own parts
     * @return array{data:array[],name:string,shared_sort:bool}
     */
    public function parts(string $table, int $id, string $name, int $expand, bool $includeDrafts): array
    {
        $e = $this->entity($table);
        if ($this->fetchRow($e, $id) === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $group = Compositions::find($this->model, $e['table'], $name);
        if ($group === null) {
            throw ApiException::of(404, 'no_composition', Msg::of('msg_records.no_composition', "'{entity}' has no composition '{name}'", [
                'entity' => $e['name'], 'name' => $name,
            ]));
        }
        $order = array_flip(array_keys($group['parts']));
        $entries = [];
        foreach ($group['parts'] as $t => $col) {
            $part = $this->model['entities'][$t];
            if (($this->access !== null && $this->access->entityDenied($t)) || isset($this->denied($part)[$col])) {
                continue;
            }
            $sortName = $group['sort'][$t] ?? null;
            $groupBy = $group['group_by'][$t] ?? [];
            // the order values (and subgroups) straight from the table (the sort field itself may be denied for the caller)
            $values = [];
            if ($sortName !== null) {
                $cols = implode('', array_map(function ($c) {
                    return ', ' . SqlGenerator::q($c);
                }, array_merge([$sortName], $groupBy)));
                foreach ($this->pdo->query('SELECT "id"' . $cols . ' FROM ' . SqlGenerator::q($t) . ' WHERE ' . SqlGenerator::q($col) . ' = ' . $id)
                    ->fetchAll(PDO::FETCH_NUM) as $v) {
                    $values[(int) $v[0]] = [(int) ($v[1] ?? Sorting::UNSET), array_map(function ($x) {
                        return $x === null ? null : (int) $x;
                    }, array_combine($groupBy, array_slice($v, 2)) ?: [])];
                }
            }
            $page = 1;
            do {
                $result = $this->page($t, ['eq' => [$col => (string) $id], 'per_page' => (string) Settings::get($this->pdo, Settings::LIST_MAX_PER_PAGE),
                    'page' => (string) $page, 'sort' => 'id'], $includeDrafts);
                foreach ($result['data'] as $row) {
                    [$value, $sub] = $values[(int) $row['id']] ?? [Sorting::UNSET, []];
                    // subgroup key: nulls (top level) first
                    $subKey = array_map(function ($x) {
                        return $x === null ? -1 : $x;
                    }, array_values($sub));
                    $entries[] = [$value, (int) $row['id'], $order[$t], $t, $row, $subKey, $sub];
                }
                $page++;
            } while ($page <= $result['total_pages']);
        }
        // the order of renumber(): value, id, table (for parts without an order of their own: by id); subgroups first
        usort($entries, function ($a, $b) use ($group) {
            return $group['shared'] ? [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]
                : [$a[2], $a[5], $a[0], $a[1]] <=> [$b[2], $b[5], $b[0], $b[1]];
        });
        // headings of subgroups (e.g. "Leistungen" for the sub-items of that navigation item): the titles of the parents
        $groups = [];
        foreach ($entries as $en) {
            if (!$en[6] || isset($groups[Sorting::key($en[6])])) {
                continue;
            }
            $titles = [];
            foreach ($en[6] as $c => $v) {
                $target = null;
                foreach ($this->model['entities'][$en[3]]['fields'] as $f) {
                    if ($f['name'] === $c && $f['foreign_key']) {
                        $target = $this->model['entities'][$f['foreign_key']['table']];
                    }
                }
                if ($v !== null && $target !== null && $this->readable($target)) {
                    $titles[] = $this->titleText($target, (int) $v);
                }
            }
            $groups[Sorting::key($en[6])] = implode(' › ', array_filter($titles, 'strlen'));
        }
        $data = [];
        foreach ($entries as [, $rowId, , $t, $row, , $sub]) {
            $part = $this->model['entities'][$t];
            if ($expand > 0) {
                $full = ['entity' => $t] + $row;
                if ($expand > 1) {
                    $full['parts'] = (object) $this->nestedParts($t, $rowId, $includeDrafts);
                }
                $data[] = $full;
                continue;
            }
            $visibility = $this->visibilityField($t);
            $workflow = Workflows::fieldOf($part);
            $entry = [
                'entity' => $t, 'id' => $rowId, 'title' => self::titleOfRow($this->visibleTitle($part), $row),
                'visibility' => $visibility !== null && array_key_exists($visibility, $row) ? (bool) $row[$visibility] : null,
                'state' => $workflow !== null ? ($row[$workflow['name']] ?? null) : null,
            ];
            if ($sub) {
                $entry['group'] = Sorting::key($sub);
            }
            if ($this->user !== null) {
                $entry['move'] = ['up' => $this->canMove($part, $rowId, true), 'down' => $this->canMove($part, $rowId, false)];
            }
            $data[] = $entry;
        }
        return ['data' => $data, 'name' => $group['name'], 'shared_sort' => $group['shared']] + ($groups ? ['groups' => $groups] : []);
    }

    /**
     * The compositions of the part $table #$id for parts(…, expand 2): name => complete records (expand 1), in their order.
     * Compositions whose part classes the caller may not read (entity or FK column denied) are left out, as in _schema.
     *
     * @return array<string,array[]>
     */
    private function nestedParts(string $table, int $id, bool $includeDrafts): array
    {
        $out = [];
        foreach (Compositions::of($this->model, $table) as $g) {
            $readable = false;
            foreach ($g['parts'] as $t => $col) {
                if (!($this->access !== null && $this->access->entityDenied($t)) && !isset($this->denied($this->model['entities'][$t])[$col])) {
                    $readable = true;
                }
            }
            if ($readable) {
                $out[$g['name']] = $this->parts($table, $id, $g['name'], 1, $includeDrafts)['data'];
            }
        }
        return $out;
    }

    /**
     * POST /api/{whole}/{id}/parts/{name}/visibility {"visible": true|false, "dry_run": bool}: „Alle veröffentlichen“ /
     * „Alle zurückziehen“ - sets the visibility field of every part of the composition $name of record $id, recursively over
     * nested compositions (the tiles of a tile block), in ONE transaction; every changed row gets a version and its
     * 'updated' webhook. Parts of classes without a visibility field are passed through (their own parts count). Skipped,
     * with reason: 'forbidden' (class not readable, no update right, visibility field denied) and 'workflow' (the class has a
     * workflow - its approval is not bypassed). dry_run: the same answer without writing (count for the confirmation).
     *
     * @return array{visible:bool,dry_run:bool,changed:int,unchanged:int,skipped:int,classes:array[]} classes: per part class
     *         {table, entity, changed: ids, unchanged: ids, skipped: [{id, reason}]}, in the order they were found
     */
    public function setPartsVisibility(string $table, int $id, string $name, array $input): array
    {
        $e = $this->entity($table);
        if ($this->fetchRow($e, $id) === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $group = Compositions::find($this->model, $e['table'], $name);
        if ($group === null) {
            throw ApiException::of(404, 'no_composition', Msg::of('msg_records.no_composition', "'{entity}' has no composition '{name}'", [
                'entity' => $e['name'], 'name' => $name,
            ]));
        }
        $visible = $input['visible'] ?? null;
        if (!is_bool($visible)) {
            throw ApiException::invalid(['visible' => Msg::of('msg_records.visible_expected', 'true or false expected')]);
        }
        $dryRun = !empty($input['dry_run']);
        // all parts below the section, wholes before their parts: [table, id]
        $rows = [];
        $queue = [];
        foreach ($group['parts'] as $t => $col) {
            $queue[] = [(string) $t, $col, $id];
        }
        while ($queue) {
            [$t, $col, $whole] = array_shift($queue);
            $stmt = $this->pdo->prepare('SELECT "id" FROM ' . SqlGenerator::q($t) . ' WHERE ' . SqlGenerator::q($col) . ' = ? ORDER BY "id"');
            $stmt->execute([$whole]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $rows[] = [$t, (int) $rid];
                foreach (Compositions::of($this->model, $t) as $g) {
                    foreach ($g['parts'] as $pt => $pcol) {
                        $queue[] = [(string) $pt, $pcol, (int) $rid];
                    }
                }
            }
        }
        $classes = [];
        $todo = [];
        foreach ($rows as [$t, $rid]) {
            $field = $this->visibilityField($t);
            if ($field === null) {
                continue;
            }
            $pe = $this->model['entities'][$t];
            if (!isset($classes[$t])) {
                $classes[$t] = ['table' => $t, 'entity' => $pe['name'], 'changed' => [], 'unchanged' => [], 'skipped' => []];
            }
            if (($this->access !== null && ($this->access->entityDenied($t) || $this->access->actionDenied($t, 'update')))
                || isset($this->denied($pe)[$field])) {
                $classes[$t]['skipped'][] = ['id' => $rid, 'reason' => 'forbidden'];
                continue;
            }
            if (Workflows::fieldOf($pe) !== null) {
                $classes[$t]['skipped'][] = ['id' => $rid, 'reason' => 'workflow'];
                continue;
            }
            $stmt = $this->pdo->prepare('SELECT ' . SqlGenerator::q($field) . ' FROM ' . SqlGenerator::q($t) . ' WHERE "id" = ?');
            $stmt->execute([$rid]);
            if ((int) $stmt->fetchColumn() === ($visible ? 1 : 0)) {
                $classes[$t]['unchanged'][] = $rid;
                continue;
            }
            $classes[$t]['changed'][] = $rid;
            $todo[] = [$pe, $rid, $field];
        }
        $versions = [];
        if (!$dryRun && $todo) {
            $reason = Revisions::reasonOf($e, $input);
            $versions = $this->transaction(function () use ($todo, $visible, $reason) {
                $versions = [];
                foreach ($todo as [$pe, $rid, $field]) {
                    $set = [$field => $visible ? 1 : 0] + (RecordMeta::applies($pe) ? RecordMeta::updateValues($this->user) : []);
                    $sql = implode(', ', array_map(function ($c) {
                        return SqlGenerator::q($c) . ' = ?';
                    }, array_keys($set)));
                    $this->pdo->prepare('UPDATE ' . SqlGenerator::q($pe['table']) . " SET $sql WHERE \"id\" = ?")
                        ->execute(array_merge(array_values($set), [$rid]));
                    $row = $this->fetchRow($pe, $rid);
                    $versions[] = Revisions::record($this->pdo, $pe, $rid, $row, 'update', $this->user, $this->sortPlace($pe, $row, ['reason' => $reason]));
                }
                return $versions;
            });
            foreach ($todo as $i => [$pe, $rid]) {
                $this->fireWebhooks($pe, $rid, 'updated', $versions[$i]);
            }
        }
        $count = function (string $key) use ($classes): int {
            return array_sum(array_map(function (array $c) use ($key): int {
                return count($c[$key]);
            }, $classes));
        };
        return ['visible' => $visible, 'dry_run' => $dryRun, 'changed' => $count('changed'), 'unchanged' => $count('unchanged'),
            'skipped' => $count('skipped'), 'classes' => array_values($classes)];
    }

    // ---------------------------------------------------------------- Language versions ({translations})

    /** Entity with {translations} and its structure, 404 otherwise (also if the caller cannot see the relation or language field) */
    private function translatable(string $table): array
    {
        $e = $this->entity($table);
        $tr = Translations::of($e);
        $denied = $this->denied($e);
        if ($tr === null || isset($denied[$tr['relation']]) || isset($denied[$tr['language_field']])) {
            throw ApiException::of(404, 'no_translations', Msg::of('msg_records.no_translations', "'{entity}' has no language versions "
                . '({translations})', ['entity' => $e['name'], 'translations' => '{translations}']));
        }
        return [$e, $tr];
    }

    /**
     * The class (the record's own one or a part class below it) the caller may not create in - "Übersetzung anlegen" copies
     * the parts as well; null = allowed
     */
    private function translateDenied(array $e): ?array
    {
        if ($this->access === null) {
            return null;
        }
        foreach (array_merge([$e['table']], RecordCopy::partTables($this->model, $e['table'])) as $t) {
            if ($this->access->entityDenied($t) || $this->access->actionDenied($t, 'create')) {
                return $this->model['entities'][$t];
            }
        }
        return null;
    }

    /**
     * GET /api/{entity}/{id}/translations: the translation group of the record - {original: {id, language, title}|null,
     * versions: [{id, language, title, original, current}] (original first, then by id), missing: [enum values without a
     * version], language_field, relation, values (all enum values), can_translate (the caller may create in the class and in
     * all part classes; with a session only), denied_entity (name of the class that is missing, else null)}. Drafts only with
     * $includeDrafts; a hidden original appears as null.
     */
    public function translations(string $table, int $id, bool $includeDrafts): array
    {
        [$e, $tr] = $this->translatable($table);
        $original = Translations::originalOf($this->pdo, $e, $tr, $id);
        if ($original === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $titles = $this->visibleTitle($e);
        $visibility = $this->visibilityField($e['table']);
        $rows = Translations::rows($this->pdo, $e, $tr, $original, array_merge($titles, $visibility !== null ? [$visibility] : []));
        $versions = [];
        $used = [];
        $originalEntry = null;
        foreach ($rows as $rid => $r) {
            $used[] = $r[$tr['language_field']]; // also hidden drafts: their language is not free
            if (!$includeDrafts && $visibility !== null && (int) $r[$visibility] !== 1 && $rid !== $id) {
                continue;
            }
            $entry = ['id' => $rid, 'language' => $r[$tr['language_field']], 'title' => self::titleOfRow($titles, $r),
                'original' => $rid === $original, 'current' => $rid === $id];
            $versions[] = $entry;
            if ($rid === $original) {
                $originalEntry = ['id' => $rid, 'language' => $entry['language'], 'title' => $entry['title']];
            }
        }
        $denied = $this->user !== null ? $this->translateDenied($e) : null;
        return [
            'original' => $originalEntry, 'versions' => $versions,
            'missing' => array_values(array_diff($tr['values'], $used)),
            'language_field' => $tr['language_field'], 'relation' => $tr['relation'], 'values' => $tr['values'],
            'can_translate' => $this->user !== null && $denied === null, 'denied_entity' => $denied !== null ? $denied['name'] : null,
        ];
    }

    /**
     * POST /api/{entity}/{id}/translate {"language": "<enum value>"}: new language version as a deep copy (RecordCopy) in ONE
     * transaction - the record with all parts of its compositions (recursively), media links with their metadata, n:n links.
     * The copy gets the language, the relation to the ORIGINAL of the group (also when $id is a version itself: the group
     * stays flat), visibility false and the initial workflow state - every copied part with a visibility field on every level
     * is a draft as well (an API reading the parts directly would otherwise deliver them untranslated). n:1/1:1 references to classes with {translations} point
     * at the version in the target language if there is one (otherwise they stay and are reported as untranslated); n:n links
     * with {filter_by:<language field>} to such a class are mapped likewise or dropped. 403 if the caller may not create in
     * the class or one of its part classes, 409 if the language exists in the group, 422 for an unknown value.
     *
     * @return array{id:int,entity:string,language:string,original:int,summary:array,record:array}
     */
    public function translate(string $table, int $id, array $input): array
    {
        [$e, $tr] = $this->translatable($table);
        $this->requireAction($e, 'create');
        $denied = $this->translateDenied($e);
        if ($denied !== null) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_records.translate_forbidden', "No permission to create in '{entity}' - "
                . 'creating a translation copies the parts as well', ['entity' => $denied['name']]), ['action' => 'create', 'entity' => $denied['table']]);
        }
        $language = $input['language'] ?? null;
        if (!is_string($language) || !in_array($language, $tr['values'], true)) {
            throw ApiException::invalid(['language' => Msg::of('msg_records.translate_language', 'Unknown language - allowed: {values}', [
                'values' => implode(', ', $tr['values']),
            ])]);
        }
        $original = Translations::originalOf($this->pdo, $e, $tr, $id);
        if ($original === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $existing = Translations::versionIn($this->pdo, $e, $tr, $original, $language);
        if ($existing !== null) {
            throw ApiException::of(409, 'translation_exists', Msg::of('msg_records.translation_exists', 'The language {language} already '
                . 'exists in this group ({entity} #{id}).', ['language' => $language, 'entity' => $e['name'], 'id' => $existing]),
                ['existing_id' => $existing, 'entity' => $e['table']]);
        }
        $overrides = [$tr['language_field'] => $language, $tr['relation'] => $original];
        $visibility = $this->visibilityField($e['table']);
        if ($visibility !== null) {
            $overrides[$visibility] = 0; // the copy starts as a draft
        }
        $redirect = function (array $target, int $tid) use ($language): array {
            $ttr = Translations::of($target);
            if ($ttr === null) {
                return [$tid, 'same'];
            }
            $version = Translations::versionIn($this->pdo, $target, $ttr, $tid, $language);
            if ($version === $tid) {
                return [$tid, 'same'];
            }
            return $version !== null ? [$version, 'redirected'] : [$tid, 'untranslated'];
        };
        $redirectLink = function (array $from, array $m, int $other) use ($language): array {
            $own = Translations::of($from);
            $filter = $m['filter_by'] ?? null;
            $target = $this->model['entities'][$m['table']];
            $ttr = Translations::of($target);
            if ($own === null || $ttr === null || ($filter['field'] ?? null) !== $own['language_field']) {
                return [$other, 'same'];
            }
            $version = Translations::versionIn($this->pdo, $target, $ttr, $other, $language);
            if ($version === $other) {
                return [$other, 'same'];
            }
            return $version !== null ? [$version, 'redirected'] : [null, 'dropped'];
        };
        $duplicate = function (array $de, array $fields, array $values, int $hit, ?string $group = null): ApiException {
            return new ApiException(409, $this->duplicatePayload($de, $fields, $values, $hit, $group));
        };
        $copier = new RecordCopy($this->pdo, $this->model, $this->user, strtolower($language), $duplicate, $redirect, $redirectLink);
        $reason = Revisions::reasonOf($e, $input);
        $partDraft = function (array $pe): array {
            $field = $this->visibilityField($pe['table']);
            return $field !== null ? [$field => 0] : [];
        };
        [$result, $versions, $record] = $this->transaction(function () use ($copier, $e, $id, $overrides, $reason, $partDraft) {
            $result = $copier->copy($e['table'], $id, $overrides, $partDraft);
            $versions = [];
            foreach ($result['rows'] as [$t, $newId]) {
                $re = $this->model['entities'][$t];
                $row = $this->fetchRow($re, $newId);
                $versions[] = Revisions::record($this->pdo, $re, $newId, $row, 'create', $this->user, $this->sortPlace($re, $row, ['reason' => $reason]));
                $this->recordSymmetricPartners($re, $newId, null, $row);
            }
            return [$result, $versions, $this->visible($e, $this->fetchRow($e, $result['id']), $this->denied($e))];
        }, $e, $overrides);
        foreach ($result['rows'] as $i => [$t, $newId]) {
            $this->fireWebhooks($this->model['entities'][$t], $newId, 'created', $versions[$i]);
        }
        return [
            'id' => $result['id'], 'entity' => $e['table'], 'language' => $language, 'original' => $original,
            'summary' => $this->translateSummary($result), 'record' => $record,
        ];
    }

    /**
     * Summary of a copy for the caller: parts {table: count}, redirected / untranslated / dropped [{entity, field, target,
     * from, to, title}] (title = display text of the target the reference pointed at), renamed [{entity, field, from, to}]
     */
    private function translateSummary(array $result): array
    {
        // drafts: did the record get visibility false, how many parts did (parts without a visibility field are visible at once)
        $draftParts = 0;
        $visibleParts = 0;
        foreach (array_slice($result['rows'], 1) as [$t]) {
            if ($this->visibilityField($t) !== null) {
                $draftParts++;
            } else {
                $visibleParts++;
            }
        }
        $out = ['parts' => (object) $result['parts'], 'renamed' => $result['renamed'], 'draft' => $this->visibilityField($result['rows'][0][0]) !== null,
            'draft_parts' => $draftParts, 'visible_parts' => $visibleParts];
        foreach (['redirected', 'untranslated', 'dropped'] as $kind) {
            $out[$kind] = array_map(function (array $r): array {
                $target = $this->model['entities'][$r['target']];
                return $r + ['target_entity' => $target['name'], 'title' => $this->readable($target) ? $this->titleText($target, $r['from']) : ''];
            }, $result[$kind]);
        }
        return $out;
    }

    /** Display text of a row: the values of the {title} fields, comma-separated, empty ones left out */
    private static function titleOfRow(array $fields, array $row): string
    {
        $titles = [];
        foreach ($fields as $field) {
            $v = $row[$field] ?? null;
            if ($v !== null && $v !== '') {
                $titles[] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            }
        }
        return implode(', ', $titles);
    }

    /** Display text of the row $id of $e (its visible {title} fields), '' if it does not exist */
    private function titleText(array $e, int $id): string
    {
        $fields = $this->visibleTitle($e);
        $stmt = $this->pdo->prepare('SELECT ' . implode(', ', array_map([SqlGenerator::class, 'q'], $fields)) . ' FROM '
            . SqlGenerator::q($e['table']) . ' WHERE "id" = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row === false ? '' : self::titleOfRow($fields, $row);
    }

    /**
     * Would move() of the record succeed (neighbour present, the caller may edit both classes and their sort fields)? For the
     * arrows of the parts section and of the list of a shared order; checks no list filter.
     */
    private function canMove(array $e, int $id, bool $up): bool
    {
        $sort = Sorting::fieldOf($e);
        if ($sort === null || !$this->mayReorder($e, $sort)) {
            return false;
        }
        $row = $this->rawRow($e, $id);
        if (Sorting::shared($sort)) {
            $other = Sorting::sharedNeighbour($this->pdo, $this->model, $sort, $e['table'], $row, $up);
            if ($other === null) {
                return false;
            }
            $ne = $this->model['entities'][$other[0]];
            return $other[0] === $e['table'] || ($this->readable($ne) && $this->mayReorder($ne, Sorting::fieldOf($ne)));
        }
        return Sorting::neighbour($this->pdo, $e, $sort, $row, $up, '', []) !== null;
    }

    /** May the caller change the order of $e (action "update" and the sort field not denied)? */
    private function mayReorder(array $e, ?array $sort): bool
    {
        if ($sort === null) {
            return false;
        }
        if ($this->access !== null && ($this->access->entityDenied($e['table']) || $this->access->actionDenied($e['table'], 'update'))) {
            return false;
        }
        return !isset($this->denied($e)[$sort['name']]);
    }

    /** May the caller read the entity at all? */
    private function readable(array $e): bool
    {
        return $this->access === null || !$this->access->entityDenied($e['table']);
    }

    /**
     * Metadata of a media field for _schema (media(Klasse), see PumlParser::parseMetaClass()): 'meta' => field definitions as
     * for normal fields (name, type, required, primary, foreign_key, enum_values, rules, section), without denied ones;
     * 'meta_class' (name of the metadata class) and, if it has form sections, 'meta_sections'. Without a metadata class
     * nothing - the _schema of such a media field stays exactly as before.
     */
    private function describeMeta(array $m, array $denied): array
    {
        $fields = LinkFields::of($m);
        if (!$fields) {
            return [];
        }
        $hidden = self::metaHidden($denied, $m['name']);
        $meta = [];
        foreach ($fields as $f) {
            if (isset($hidden[$f['name']])) {
                continue;
            }
            $field = ['name' => $f['name'], 'type' => $f['type'], 'required' => $f['required'], 'primary' => false, 'foreign_key' => null];
            if ($f['type'] === 'enum') {
                $field['enum_values'] = $f['enum_values'];
            }
            if (array_key_exists('section', $f)) {
                $field['section'] = $f['section'];
            }
            $rules = $this->validation()->describe($f);
            if ($rules !== null) {
                $field['rules'] = $rules;
            }
            $meta[] = $field;
        }
        $sections = array_values(array_filter($m['meta']['sections'] ?? [], function ($title) use ($meta) {
            return in_array($title, array_column($meta, 'section'), true);
        }));
        return ['meta' => $meta, 'meta_class' => $m['meta']['class']] + ($sections ? ['meta_sections' => $sections] : []);
    }

    /**
     * Denied metadata fields of the media field $media: field name => true. Permissions denies a metadata field as the
     * property "<media field>.meta.<field>" (see Permissions::lockedBy()).
     */
    public static function metaHidden(array $denied, string $media): array
    {
        $out = [];
        $prefix = $media . '.meta.';
        foreach ($denied as $key => $true) {
            if (strpos((string) $key, $prefix) === 0) {
                $out[substr((string) $key, strlen($prefix))] = true;
            }
        }
        return $out;
    }

    /** Row without the properties denied for the caller - also denied metadata fields inside the media lists */
    private function visible(array $e, array $row, array $denied): array
    {
        $row = array_diff_key($row, $denied);
        foreach ($e['media'] ?? [] as $m) {
            $hidden = self::metaHidden($denied, $m['name']);
            if (!$hidden || !isset($row[$m['name']])) {
                continue;
            }
            foreach ($row[$m['name']] as &$medium) {
                if (isset($medium['meta'])) {
                    $medium['meta'] = (object) array_diff_key((array) $medium['meta'], $hidden);
                }
            }
            unset($medium);
        }
        return $row;
    }

    /**
     * field_order of an entity (all names of fields, media and many_to_many in display order); models stored before
     * 2026-10-21 do not know the key - then the previous order fields, n:n, media.
     *
     * @return string[]
     */
    public static function fieldOrder(array $e): array
    {
        return $e['field_order'] ?? array_merge(
            array_column($e['fields'], 'name'),
            array_column($e['many_to_many'], 'name'),
            array_column($e['media'] ?? [], 'name')
        );
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
     * The transition creates a version of the record (Revisions, action transition) with the optional reason.
     *
     * @param mixed $label value of {"transition": ...}
     * @param mixed $reason value of {"_reason": ...} (optional)
     * @return array{record:array,transition:array,task:?array}
     */
    public function transition(string $table, int $id, $label, $reason = null): array
    {
        $e = $this->entity($table);
        $field = Workflows::fieldOf($e);
        $def = $field !== null ? ($this->model['workflows'][$field['workflow']] ?? null) : null;
        if ($def === null) {
            throw ApiException::of(404, 'no_workflow', Msg::of('msg_records.no_workflow', "There is no workflow for '{entity}'", [
                'entity' => $e['name'],
            ]));
        }
        if ($this->user === null) {
            throw ApiException::of(401, 'unauthorized', Msg::of('msg_auth.login_required', 'Login required'));
        }
        if (!is_string($label) || trim($label) === '') {
            throw ApiException::of(422, 'validation', Msg::of('msg_records.transition_missing', 'Transition missing'), ApiException::fields([
                'transition' => Msg::of('msg_records.transition_expected', 'Label of the transition expected'),
            ]));
        }
        $row = $this->fetchRow($e, $id);
        if ($row === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $state = $row[$field['name']];
        $named = array_values(array_filter($def['transitions'], function ($t) use ($label) {
            return WorkflowParser::fold($t['label']) === WorkflowParser::fold(trim($label));
        }));
        if (!$named) {
            throw ApiException::of(422, 'unknown_transition', Msg::of('msg_records.transition_unknown', "The transition '{transition}' "
                . 'does not exist in the workflow {workflow}', ['transition' => trim($label), 'workflow' => $def['name']]), ApiException::fields([
                'transition' => Msg::of('msg_records.unknown', 'unknown'),
            ]));
        }
        $t = null;
        foreach ($named as $candidate) {
            if ($candidate['from'] === $state) {
                $t = $candidate;
            }
        }
        if ($t === null) {
            throw ApiException::of(409, 'invalid_state', Msg::of('msg_records.transition_state', "The transition '{transition}' is not "
                . "possible in the state '{state}' (only from '{from}')", [
                    'transition' => $named[0]['label'], 'state' => $state, 'from' => implode("', '", array_column($named, 'from')),
                ]), ['state' => $state]);
        }
        $reason = Revisions::reason($reason);
        $this->memberships = $this->memberships ?? Workflows::memberships($this->pdo, $this->user);
        if (!Workflows::permitted($this->memberships, $t)) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_records.transition_forbidden', "No permission for the transition '{transition}'", [
                'transition' => $t['label'],
            ]), ['transition' => $t['label']]);
        }
        $set = [$field['name'] => $t['to']];
        if (RecordMeta::applies($e)) {
            $set += RecordMeta::updateValues($this->user); // a transition changes the record
        }
        $visibility = $this->visibilityField($e['table']);
        foreach ($t['actions'] as $action) {
            if ($action['type'] === 'set_visibility' && $visibility !== null) {
                $set[$visibility] = $action['value'] ? 1 : 0;
            }
        }
        $result = $this->transaction(function () use ($e, $id, $field, $state, $set, $t, $reason) {
            $sql = implode(', ', array_map(function ($c) {
                return SqlGenerator::q($c) . ' = ?';
            }, array_keys($set)));
            // only from the checked state: two simultaneous transitions do not both execute
            $stmt = $this->pdo->prepare('UPDATE ' . SqlGenerator::q($e['table']) . " SET $sql WHERE \"id\" = ? AND "
                . SqlGenerator::q($field['name']) . ' = ?');
            $stmt->execute(array_merge(array_values($set), [$id, $state]));
            if ($stmt->rowCount() !== 1) {
                throw ApiException::of(409, 'invalid_state', Msg::of('msg_records.state_changed', 'The state has been changed by someone '
                    . 'else in the meantime. Please reload.'));
            }
            $task = Workflows::recordTransition($this->pdo, $e['table'], $id, $t, $this->user);
            $row = $this->fetchRow($e, $id);
            $version = Revisions::record($this->pdo, $e, $id, $row, 'transition', $this->user, $this->sortPlace($e, $row, ['reason' => $reason, 'transition' => $t['label']]));
            return [[
                'record'     => $this->visible($e, $row, $this->denied($e)),
                'transition' => ['label' => $t['label'], 'from' => $t['from'], 'to' => $t['to']],
                'task'       => $task,
            ], $version];
        });
        // after the commit: action=webhook of the transition ("<entity>.<to>"), global webhooks on update
        $this->fireWebhooks($e, $id, 'updated', $result[1], $t);
        return $result[0];
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
     * Protection against oversized responses: per_page is limited (Settings::LIST_MAX_PER_PAGE). In addition, the rows are
     * read one by one and their raw size is counted; if it exceeds Settings::listMaxBytes(), the query aborts with 413 BEFORE
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
        // ?followup=open: only rows with an open follow-up (Followups; session only, not part of the public API)
        $followup = Followups::listCondition($this->pdo, $this->user, $e['table'], $query['followup'] ?? null);
        if ($followup !== null) {
            $conditions[] = $followup;
        }
        $visibility = $this->visibilityField($table);
        if ($visibility !== null && !$includeDrafts) {
            $conditions[] = 'e.' . SqlGenerator::q($visibility) . ' = 1';
        }
        // public API: the user fields of the tracking metadata do not exist (neither sortable nor filterable)
        $hidden = $this->user === null ? array_fill_keys(RecordMeta::USER_FIELDS, true) : [];
        $q = new ListQuery($this->model, $e, $denied + $hidden, $query, $conditions, function (string $other): ?array {
            return $this->access !== null && $this->access->entityDenied($other) ? null : $this->denied($this->model['entities'][$other]);
        }, $entityNames, Settings::get($this->pdo, Settings::LIST_DEFAULT_PER_PAGE), Settings::get($this->pdo, Settings::LIST_MAX_PER_PAGE));
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
        $limit = Settings::listMaxBytes($this->pdo);
        $bytes = 0;
        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            foreach ($row as $value) {
                $bytes += is_string($value) ? strlen($value) : 8;
            }
            if ($bytes > $limit) {
                $stmt->closeCursor();
                $fit = max(1, (int) floor(count($rows) * 0.8));
                throw ApiException::of(413, 'response_too_large', Msg::of('msg_records.response_too_large', 'The response would be too '
                    . 'large: already {rows} of {per_page} rows of this page exceed {mb} MB. Please reduce per_page (e.g. per_page={fit}).', [
                        'rows' => count($rows) + 1, 'per_page' => $q->perPage, 'mb' => round($limit / 1048576, 1), 'fit' => $fit,
                    ]), ['per_page' => $q->perPage, 'suggested_per_page' => $fit]);
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
        $rows = $this->presentMeta($e, $ordered);
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
            $map = $this->mediaLists($m, null, $ids, self::metaHidden($denied, $m['name']));
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
        $out = ['data' => $rows, 'total' => $total, 'page' => $q->page, 'per_page' => $q->perPage,
            'total_pages' => (int) ceil($total / $q->perPage)];
        // ?arrange=1 (list view): what the arrows and group headings of the manual order need - only while that order
        // applies (no sort parameter) and the caller sees the sort field
        $sort = Sorting::fieldOf($e);
        if (!empty($query['arrange']) && $sort !== null && !isset($denied[$sort['name']])) {
            $out['arrange'] = $q->manual ? $this->arrangement($e, $sort, $q, $from, $out['data']) : null;
        }
        return $out;
    }

    /**
     * Manual order of a list page: marks every row with '_arrange' => ['group' => key, 'up' => bool, 'down' => bool] (is
     * there a row of the same group before/after it in the current list - also beyond the edge of the page) and returns
     * ['groups' => [key => path of the parents, see Sorting::groupPaths()], 'continued' => the first row continues the group
     * of the previous page].
     */
    private function arrangement(array $e, array $sort, ListQuery $q, string $from, array &$rows): array
    {
        // the rows of the page plus one before and one after it, with their groups
        $cols = '';
        foreach ($sort['sort']['by'] as $col) {
            $cols .= ', e.' . SqlGenerator::q($col);
        }
        $before = $q->offset() > 0 ? 1 : 0;
        $stmt = $this->pdo->prepare("SELECT e.\"id\"$cols$from ORDER BY {$q->orderBy} LIMIT " . ($q->perPage + 1 + $before)
            . ' OFFSET ' . ($q->offset() - $before));
        $stmt->execute($q->params);
        $window = [];
        foreach ($stmt->fetchAll() as $r) {
            $window[] = [(int) $r['id'], Sorting::groupOf($sort, $r)];
        }
        $index = [];
        foreach ($window as $i => [$id]) {
            $index[$id] = $i;
        }
        $groups = [];
        $continued = false;
        foreach ($rows as $n => &$row) {
            $i = $index[$row['id']] ?? null;
            if ($i === null) {
                $row['_arrange'] = ['group' => '', 'up' => false, 'down' => false];
                continue;
            }
            $group = $window[$i][1];
            $key = Sorting::key($group);
            $groups[$key] = $group;
            $up = isset($window[$i - 1]) && $window[$i - 1][1] === $group;
            $row['_arrange'] = ['group' => $key, 'up' => $up, 'down' => isset($window[$i + 1]) && $window[$i + 1][1] === $group];
            if (Sorting::shared($sort)) {
                // shared order: the arrows follow the whole group over all classes (move() swaps with the next record of any of
                // them) - also where this list shows no neighbour; off if the neighbour's class may not be edited
                $row['_arrange']['up'] = $this->canMove($e, (int) $row['id'], true);
                $row['_arrange']['down'] = $this->canMove($e, (int) $row['id'], false);
            }
            if ($n === 0 && $up) {
                $continued = true;
            }
        }
        unset($row);
        return ['groups' => (object) Sorting::groupPaths($this->pdo, $e, $sort, array_values($groups)), 'continued' => $continued];
    }

    /**
     * POST /api/{entity}/{id}/move {"direction": "up"|"down", ...}: move a record one place in its manual order (sort
     * field) by swapping the values with its neighbour. The neighbour is determined here, not by the client: the next
     * record of the same group in that direction which meets the list filters sent along (filter, eq, q, followup as for
     * GET /api/{entity}) - so with a filter the record swaps with the next one the list shows. In an order shared by several
     * classes (sort field of an abstract class) the neighbour is the next record of the group of ANY of these classes,
     * without list filters (see moveShared(); until 2026-11 only one of the same class).
     *
     * A move is a change of both records: tracking metadata, a version each (action reorder; consecutive moves are one
     * entry, see Revisions) and the webhooks as for saving. 409 at the edge of the group, 404 without a sort field.
     *
     * @return array{record:array,swapped_with:int,swapped_entity:string}
     */
    public function move(string $table, int $id, array $input): array
    {
        $e = $this->entity($table);
        $sort = Sorting::fieldOf($e);
        if ($sort === null) {
            throw ApiException::of(404, 'no_sort', Msg::of('msg_records.no_sort', "'{entity}' has no manual order (no field of the "
                . 'type sort)', ['entity' => $e['name']]));
        }
        $this->requireAction($e, 'update');
        $denied = $this->denied($e);
        if (isset($denied[$sort['name']])) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_records.update_forbidden', "No permission to edit in '{entity}'", [
                'entity' => $e['name'],
            ]), ['action' => 'update']);
        }
        $direction = $input['direction'] ?? null;
        if ($direction !== 'up' && $direction !== 'down') {
            throw ApiException::of(422, 'validation', Msg::of('msg_records.direction_missing', 'Direction missing'), ApiException::fields([
                'direction' => Msg::of('msg_records.direction_expected', 'up or down expected'),
            ]));
        }
        $query = array_intersect_key($input, array_flip(['filter', 'eq', 'q', 'followup']));
        $conditions = [];
        $followup = Followups::listCondition($this->pdo, $this->user, $e['table'], $query['followup'] ?? null);
        if ($followup !== null) {
            $conditions[] = $followup;
        }
        $hidden = $this->user === null ? array_fill_keys(RecordMeta::USER_FIELDS, true) : [];
        $q = new ListQuery($this->model, $e, $denied + $hidden, $query, $conditions, function (string $other): ?array {
            return $this->access !== null && $this->access->entityDenied($other) ? null : $this->denied($this->model['entities'][$other]);
        });
        $current = $this->fetchRow($e, $id);
        if ($current === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $up = $direction === 'up';
        if (Sorting::shared($sort)) {
            return $this->moveShared($e, $sort, $id, $up, $denied);
        }
        $result = $this->transaction(function () use ($e, $sort, $id, $up, $q, $denied) {
            $name = $sort['name'];
            $own = $this->rawRow($e, $id);
            $other = Sorting::neighbour($this->pdo, $e, $sort, $own, $up, $q->where, $q->params);
            if ($other !== null && $other[1] === (int) $own[$name]) {
                // equal values (rows written past the CMS): number the group first, then look again
                Sorting::renumber($this->pdo, $this->model, $sort, Sorting::groupOf($sort, $own));
                $own = $this->rawRow($e, $id);
                $other = Sorting::neighbour($this->pdo, $e, $sort, $own, $up, $q->where, $q->params);
            }
            if ($other === null) {
                throw ApiException::of(409, 'edge', $up
                    ? Msg::of('msg_records.move_first', 'The record is already the first of its group.')
                    : Msg::of('msg_records.move_last', 'The record is already the last of its group.'));
            }
            $versions = [];
            foreach ([[$id, $other[1]], [$other[0], (int) $own[$name]]] as [$rowId, $value]) {
                $set = [$name => $value] + (RecordMeta::applies($e) ? RecordMeta::updateValues($this->user) : []);
                $sql = implode(', ', array_map(function ($c) {
                    return SqlGenerator::q($c) . ' = ?';
                }, array_keys($set)));
                $this->pdo->prepare('UPDATE ' . SqlGenerator::q($e['table']) . " SET $sql WHERE \"id\" = ?")
                    ->execute(array_merge(array_values($set), [$rowId]));
            }
            // versions only after both rows have their new values: the place stored with them counts the group as it is now
            foreach ([$id, $other[0]] as $rowId) {
                $row = $this->fetchRow($e, $rowId);
                $versions[$rowId] = Revisions::record($this->pdo, $e, $rowId, $row, 'reorder', $this->user, $this->sortPlace($e, $row));
            }
            return [['record' => $this->visible($e, $this->fetchRow($e, $id), $denied), 'swapped_with' => $other[0], 'swapped_entity' => $e['table']], $versions];
        });
        foreach ($result[1] as $rowId => $version) {
            $this->fireWebhooks($e, (int) $rowId, 'updated', $version, null, ['reorder' => true]);
        }
        return $result[0];
    }

    /**
     * move() in an order SHARED by several classes (sort field of an abstract class, e.g. all block types of a page): the
     * neighbour is the next record of the group, whatever its class (Sorting::sharedNeighbour()) - list filters do not apply,
     * they belong to one class. Both records are written in one transaction, also across two tables; each gets its version
     * and its webhook (updated, "reorder": true) with its own entity. The caller needs the right to edit the class of the
     * neighbour too (and its sort field), otherwise 403 and nothing is swapped. The answer names the class of the neighbour
     * ('swapped_entity') - in the list of one class a swap with another class does not change the visible order.
     *
     * @return array{record:array,swapped_with:int,swapped_entity:string}
     */
    private function moveShared(array $e, array $sort, int $id, bool $up, array $denied): array
    {
        $result = $this->transaction(function () use ($e, $sort, $id, $up, $denied) {
            $own = $this->rawRow($e, $id);
            $other = Sorting::sharedNeighbour($this->pdo, $this->model, $sort, $e['table'], $own, $up);
            if ($other !== null && $other[2] === (int) $own[$sort['name']]) {
                // equal values (rows written past the CMS): number the group first, then look again
                Sorting::renumber($this->pdo, $this->model, $sort, Sorting::groupOf($sort, $own));
                $own = $this->rawRow($e, $id);
                $other = Sorting::sharedNeighbour($this->pdo, $this->model, $sort, $e['table'], $own, $up);
            }
            if ($other === null) {
                throw ApiException::of(409, 'edge', $up
                    ? Msg::of('msg_records.move_first', 'The record is already the first of its group.')
                    : Msg::of('msg_records.move_last', 'The record is already the last of its group.'));
            }
            $ne = $this->model['entities'][$other[0]];
            $nsort = Sorting::fieldOf($ne);
            if ($other[0] !== $e['table'] && !($this->readable($ne) && $this->mayReorder($ne, $nsort))) {
                throw ApiException::of(403, 'forbidden', Msg::of('msg_records.move_neighbour_forbidden', "Cannot move: the neighbouring record "
                    . "is a '{neighbour}', and you have no permission to edit in '{neighbour}'.", ['neighbour' => $ne['name']]), [
                        'action' => 'update', 'entity' => $other[0],
                    ]);
            }
            $writes = [[$e, $sort, $id, $other[2]], [$ne, $nsort, $other[1], (int) $own[$sort['name']]]];
            foreach ($writes as [$we, $wf, $rowId, $value]) {
                $set = [$wf['name'] => $value] + (RecordMeta::applies($we) ? RecordMeta::updateValues($this->user) : []);
                $sql = implode(', ', array_map(function ($c) {
                    return SqlGenerator::q($c) . ' = ?';
                }, array_keys($set)));
                $this->pdo->prepare('UPDATE ' . SqlGenerator::q($we['table']) . " SET $sql WHERE \"id\" = ?")
                    ->execute(array_merge(array_values($set), [$rowId]));
            }
            // versions only after both rows have their new values: the place stored with them counts the group as it is now
            $versions = [];
            foreach ($writes as [$we, , $rowId]) {
                $row = $this->fetchRow($we, $rowId);
                $versions[] = [$we, $rowId, Revisions::record($this->pdo, $we, $rowId, $row, 'reorder', $this->user, $this->sortPlace($we, $row))];
            }
            return [[
                'record' => $this->visible($e, $this->fetchRow($e, $id), $denied), 'swapped_with' => $other[1], 'swapped_entity' => $other[0],
            ], $versions];
        });
        foreach ($result[1] as [$we, $rowId, $version]) {
            $this->fireWebhooks($we, (int) $rowId, 'updated', $version, null, ['reorder' => true]);
        }
        return $result[0];
    }

    /** Stored columns of a row as they are (no lists, no presentation); the row exists */
    /**
     * $extra of Revisions::record() plus, for an entity with a sort field, the place of the record in its group right now
     *
     * @param array $row the record after the change (fetchRow)
     */
    private function sortPlace(array $e, array $row, array $extra = []): array
    {
        $sort = Sorting::fieldOf($e);
        return $sort === null ? $extra : $extra + ['sort_place' => Sorting::place($this->pdo, $this->model, $sort, $row)];
    }

    private function rawRow(array $e, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . SqlGenerator::q($e['table']) . ' WHERE "id" = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if ($row === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        return $row;
    }

    public function find(string $table, int $id): array
    {
        $e = $this->entity($table);
        $row = $this->fetchRow($e, $id);
        if ($row === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        return $this->visible($e, $row, $this->denied($e));
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
        $reason = Revisions::reasonOf($e, $input);
        $errors = [];
        $values = $this->collectValues($e, $input, true, $errors);
        $links = $this->collectLinks($e, $input, $errors);
        $mediaViolations = [];
        $media = $this->collectMedia($e, $input, !$allowEmptyMedia, $errors, null, $denied, $mediaViolations);
        $violations = array_merge($this->validation()->violations($e, $values, $errors), $mediaViolations);
        if ($errors || $violations) {
            throw Validation::failure($errors, $violations);
        }
        $this->rejectDuplicate($e, $values, null);

        [$row, $id, $version] = $this->transaction(function () use ($e, $values, $links, $media, $denied, $reason) {
            // tracking metadata: set by the system only (values sent along are not even read, see collectValues())
            $row = $values + (RecordMeta::applies($e) ? RecordMeta::createValues($this->user) : []);
            // manual order: a new record (also a duplicate) goes to the end of its group
            $sort = Sorting::fieldOf($e);
            if ($sort !== null) {
                $row[$sort['name']] = Sorting::next($this->pdo, $this->model, $sort, Sorting::groupOf($sort, $values));
            }
            if ($row) {
                $cols = implode(', ', array_map([SqlGenerator::class, 'q'], array_keys($row)));
                $marks = implode(', ', array_fill(0, count($row), '?'));
                $stmt = $this->pdo->prepare('INSERT INTO ' . SqlGenerator::q($e['table']) . " ($cols) VALUES ($marks)");
                $stmt->execute(array_values($row));
            } else {
                $this->pdo->exec('INSERT INTO ' . SqlGenerator::q($e['table']) . ' DEFAULT VALUES');
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->syncLinks($e, $id, $links);
            $this->syncMedia($e, $id, $media);
            $row = $this->fetchRow($e, $id);
            $version = Revisions::record($this->pdo, $e, $id, $row, 'create', $this->user, $this->sortPlace($e, $row, ['reason' => $reason])); // version 1
            $this->recordSymmetricPartners($e, $id, null, $row);
            return [$this->visible($e, $row, $denied), $id, $version];
        }, $e, $values);
        $this->fireWebhooks($e, $id, 'created', $version);
        return $row;
    }

    /** PUT and PATCH /api/{entity}/{id}: both partial - fields not sent keep their stored value */
    public function update(string $table, int $id, array $input): array
    {
        return $this->updateRecord($this->entity($table), $id, $input, 'update', [])[0];
    }

    /**
     * PUT and restore: change the record and write a version ($action update or restore, $extra e.g. restored_from).
     *
     * @return array{0:array,1:int} record (without denied fields), new version number
     */
    private function updateRecord(array $e, int $id, array $input, string $action, array $extra): array
    {
        $this->requireAction($e, 'update');
        $current = $this->fetchRow($e, $id);
        if ($current === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $denied = $this->denied($e);
        $input = array_diff_key($input, $denied); // denied fields: the stored value stays
        $extra['reason'] = Revisions::reasonOf($e, $input);
        $errors = [];
        // workflow field: never changeable via PUT (only the unchanged value may come along) - that is what transition() is for
        $workflow = Workflows::fieldOf($e);
        if ($workflow !== null && array_key_exists($workflow['name'], $input)) {
            if ($input[$workflow['name']] !== $current[$workflow['name']]) {
                $errors[$workflow['name']] = Msg::of('msg_records.state_via_transition', 'The state can only be changed via a workflow '
                    . 'transition (POST /api/{entity}/{id}/transition).', ['entity' => $e['table'], 'id' => $id]);
            }
            unset($input[$workflow['name']]);
        }
        $values = $this->collectValues($e, $input, false, $errors);
        $links = $this->collectLinks($e, $input, $errors);
        $mediaViolations = [];
        $media = $this->collectMedia($e, $input, false, $errors, $id, $denied, $mediaViolations);
        $errors += $this->selfLinkErrors($e, $id, $links);
        $this->rejectCycles($e, $id, $values, $errors);
        // field rules against the complete record (stored state + change), see Validation
        $violations = array_merge($this->validation()->violations($e, $values + $current, $errors + $denied), $mediaViolations);
        if ($errors || $violations) {
            throw Validation::failure($errors, $violations);
        }
        // PUT is partial: fields of the group that were not sent keep their stored value
        $this->rejectDuplicate($e, $values + $current, $id);

        $result = $this->transaction(function () use ($e, $id, $values, $links, $media, $denied, $action, $extra, $current) {
            // every save counts as a change (also with only n:n/media or unchanged values): updated_at/by
            $row = $values + (RecordMeta::applies($e) ? RecordMeta::updateValues($this->user) : []);
            // manual order: a record that changes its group (another parent) goes to the end of the new group
            $sort = Sorting::fieldOf($e);
            if ($sort !== null) {
                $group = Sorting::groupOf($sort, $values + $current);
                if ($group !== Sorting::groupOf($sort, $current)) {
                    $row[$sort['name']] = Sorting::next($this->pdo, $this->model, $sort, $group);
                }
            }
            if ($row) {
                $set = implode(', ', array_map(function ($c) {
                    return SqlGenerator::q($c) . ' = ?';
                }, array_keys($row)));
                $stmt = $this->pdo->prepare('UPDATE ' . SqlGenerator::q($e['table']) . " SET $set WHERE \"id\" = ?");
                $stmt->execute(array_merge(array_values($row), [$id]));
            }
            $this->syncLinks($e, $id, $links);
            $this->syncMedia($e, $id, $media);
            $row = $this->fetchRow($e, $id);
            $version = Revisions::record($this->pdo, $e, $id, $row, $action, $this->user, $this->sortPlace($e, $row, $extra));
            $this->recordSymmetricPartners($e, $id, $current, $row);
            return [$this->visible($e, $row, $denied), $version];
        }, $e, $values + $current);
        $this->fireWebhooks($e, $id, 'updated', $result[1]); // PUT and restore
        return $result;
    }

    // ---------------------------------------------------------------- Field rules (Validation)

    /**
     * GET /api/{entity}/{id}/_violations: rule violations of the stored record (the form marks the fields right when it is
     * opened, not only when saving). Without denied fields. {violations: [{field, rule, message, params}]}
     */
    public function recordViolations(string $table, int $id): array
    {
        $e = $this->entity($table);
        $row = $this->fetchRow($e, $id);
        if ($row === null) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        $denied = $this->denied($e);
        $violations = $this->validation()->violations($e, $row, $denied);
        foreach ($e['media'] ?? [] as $m) {
            if (!isset($denied[$m['name']])) {
                $violations = array_merge($violations, $this->metaViolations($m, $id, $denied)[$id] ?? []);
            }
        }
        return ['violations' => $violations];
    }

    /**
     * Rule violations of the stored metadata of a media field (media(Klasse)) - per record, field
     * "<media field>[<position>].meta.<field>", the message naming the medium. Only fields with rules, without denied ones.
     *
     * @param int|null $id only this record; null = all
     * @return array<int,array[]> record ID => violations (Validation format)
     */
    private function metaViolations(array $m, ?int $id, array $denied): array
    {
        $hidden = self::metaHidden($denied, $m['name']);
        $ruled = array_values(array_filter(LinkFields::of($m), function ($f) use ($hidden) {
            return !empty($f['rules']) && !isset($hidden[$f['name']]);
        }));
        if (!$ruled) {
            return [];
        }
        $own = SqlGenerator::q($m['own_column']);
        $stmt = $this->pdo->prepare("SELECT $own AS own_id, \"media_id\"" . LinkFields::selectList($ruled, 'j') . ' FROM '
            . SqlGenerator::q($m['junction']) . ' j' . ($id !== null ? " WHERE $own = ?" : '') . " ORDER BY $own, \"position\"");
        $stmt->execute($id !== null ? [$id] : []);
        $out = [];
        $pos = [];
        foreach ($stmt->fetchAll() as $r) {
            $rid = (int) $r['own_id'];
            $p = $pos[$rid] = isset($pos[$rid]) ? $pos[$rid] + 1 : 0;
            $mediaId = (int) $r['media_id'];
            $label = function (Msg $error) use ($mediaId): Msg {
                return Msg::of('msg_records.media_meta_error', 'Medium #{id}: {error}', ['id' => $mediaId, 'error' => $error]);
            };
            foreach ($this->validation()->violations(['fields' => $ruled], LinkFields::read($ruled, $r)) as $v) {
                $out[$rid][] = LinkFields::labelled($v, $m['name'], $p, $label);
            }
        }
        return $out;
    }

    /**
     * GET /api/_violations: all records that violate the current rules (e.g. after a stricter rule or a changed pattern -
     * they are never changed automatically): [{entity, table, id, title, field, rule, message, params}], ordered by
     * entity (diagram order), record and field. Computed anew on every call. Entities and fields denied to the caller are
     * left out completely. At most $limit entries (total counts all).
     *
     * @return array{violations:array,total:int,limit:int,checked:array<string,int>}
     */
    public function ruleViolations(int $limit): array
    {
        $out = [];
        $total = 0;
        $checked = [];
        foreach ($this->entityNames() as $table) {
            $e = $this->model['entities'][$table];
            $denied = $this->denied($e);
            $ruled = array_values(array_filter($e['fields'], function ($f) use ($denied) {
                return !empty($f['rules']) && !isset($denied[$f['name']]);
            }));
            // metadata of media fields (media(Klasse)): violations per record, reported after its own fields
            $metaFound = [];
            foreach ($e['media'] ?? [] as $m) {
                if (!isset($denied[$m['name']])) {
                    foreach ($this->metaViolations($m, null, $denied) as $rid => $list) {
                        $metaFound[$rid] = array_merge($metaFound[$rid] ?? [], $list);
                    }
                }
            }
            if (!$ruled && !$metaFound) {
                continue;
            }
            $title = $this->visibleTitle($e);
            $cols = array_unique(array_merge(['id'], $title, array_column($ruled, 'name')));
            $stmt = $this->pdo->query('SELECT ' . implode(', ', array_map([SqlGenerator::class, 'q'], $cols)) . ' FROM '
                . SqlGenerator::q($table) . ' ORDER BY "id"');
            $checked[$table] = 0;
            while (($row = $stmt->fetch()) !== false) {
                $checked[$table]++;
                $row = $this->castRow($e, $row);
                $found = array_merge($ruled ? $this->validation()->violations(['fields' => $ruled], $row) : [],
                    $metaFound[(int) $row['id']] ?? []);
                $total += count($found);
                foreach ($found as $v) {
                    if (count($out) >= $limit) {
                        break;
                    }
                    $parts = array_filter(array_map(function ($name) use ($row) {
                        return $name === 'id' || $row[$name] === null ? '' : (string) $row[$name];
                    }, $title), 'strlen');
                    $out[] = ['entity' => $e['name'], 'table' => $table, 'id' => (int) $row['id'],
                        'title' => $parts ? implode(' ', $parts) : null] + $v;
                }
            }
        }
        return ['violations' => $out, 'total' => $total, 'limit' => $limit, 'checked' => $checked];
    }

    private function validation(): Validation
    {
        if ($this->validation === null) {
            $this->validation = new Validation($this->pdo, $this->user);
        }
        return $this->validation;
    }

    // ---------------------------------------------------------------- Versions (Revisions)

    /**
     * GET /api/{entity}/{id}/revisions: versions of a record, newest first (Revisions::all()). Needs a session (the
     * history shows drafts and editor names); the same read rights as the record: entity denied -> 403, denied fields are
     * missing in every snapshot and in "changed".
     */
    public function revisions(string $table, int $id): array
    {
        $e = $this->historyEntity($table, $id);
        $denied = $this->denied($e);
        return ['versions' => array_map(function (array $v) use ($e, $denied) {
            return $this->hidePlace($e, $v, $denied);
        }, Revisions::all($this->pdo, $e['table'], $id, $denied))];
    }

    /** sort_place of a version only for whoever may see the sort field (and only while the entity has one) */
    private function hidePlace(array $e, array $version, array $denied): array
    {
        $sort = Sorting::fieldOf($e);
        if ($sort === null || isset($denied[$sort['name']])) {
            $version['sort_place'] = null;
        }
        return $version;
    }

    /** GET /api/{entity}/{id}/revisions/{version}: one version with its snapshot (rights as revisions()) */
    public function revision(string $table, int $id, int $version): array
    {
        $e = $this->historyEntity($table, $id);
        $denied = $this->denied($e);
        $rev = Revisions::one($this->pdo, $e['table'], $id, $version, $denied);
        if ($rev === null) {
            throw ApiException::of(404, 'revision_not_found', Msg::of('msg_records.revision_not_found', 'Version {version} does not exist '
                . '(any more)', ['version' => $version]));
        }
        return $this->hidePlace($e, $rev, $denied);
    }

    /**
     * POST /api/{entity}/{id}/revisions/{version}/restore: take over the state of an older version as a new version
     * (action restore, restored_from = $version) - the history stays as it is. Rights like PUT (update action; denied fields
     * keep their value). created_at/created_by stay, updated_at/updated_by change (RecordMeta).
     *
     * What cannot be taken over is skipped and reported ('skipped': [{field, reason}]) instead of failing the restore:
     * the workflow state (only via transitions), an FK whose target row no longer exists (the current value stays), linked
     * rows or media that no longer exist (left out of the list), and values the current schema rejects (e.g. after a type
     * change or a new required field) - the latter by one retry without the fields the validation names.
     *
     * @return array{record:array,version:int,restored_from:int,skipped:array}
     */
    public function restore(string $table, int $id, int $version, array $input): array
    {
        $e = $this->historyEntity($table, $id);
        $this->requireAction($e, 'update');
        $rev = Revisions::one($this->pdo, $e['table'], $id, $version, []);
        if ($rev === null) {
            throw ApiException::of(404, 'revision_not_found', Msg::of('msg_records.revision_not_found', 'Version {version} does not exist '
                . '(any more)', ['version' => $version]));
        }
        $snapshot = $rev['snapshot'];
        $current = $this->fetchRow($e, $id);
        $denied = $this->denied($e);
        $workflow = Workflows::fieldOf($e);
        $data = [];
        $skipped = [];
        foreach ($e['fields'] as $f) {
            $name = $f['name'];
            if ($f['primary'] || !array_key_exists($name, $snapshot) || isset($denied[$name])) {
                continue;
            }
            $value = $snapshot[$name];
            if ($workflow !== null && $name === $workflow['name']) {
                if ($value !== $current[$name]) {
                    $skipped[] = ['field' => $name] + Msg::of('msg_records.skip_workflow', 'The workflow state only changes via a transition.')
                        ->fields('reason');
                }
                continue;
            }
            $fk = $f['foreign_key'];
            if ($fk && $value !== null && $this->fetchIds($fk['table'], 'id', [(int) $value]) === []) {
                $skipped[] = ['field' => $name] + Msg::of('msg_records.skip_gone', '{entity} {ids} no longer exists.', [
                    'entity' => $this->model['entities'][$fk['table']]['name'], 'ids' => "#$value",
                ])->fields('reason');
                continue;
            }
            $data[$name] = $value;
        }
        foreach ($e['many_to_many'] as $m) {
            if (!array_key_exists($m['name'], $snapshot) || isset($denied[$m['name']])) {
                continue;
            }
            $ids = array_map('intval', (array) $snapshot[$m['name']]);
            $exist = $this->fetchIds($m['table'], 'id', $ids);
            $data[$m['name']] = array_values(array_intersect($ids, $exist));
            if ($gone = array_values(array_diff($ids, $exist))) {
                $skipped[] = ['field' => $m['name']] + Msg::of('msg_records.skip_gone', '{entity} {ids} no longer exists.', [
                    'entity' => $this->model['entities'][$m['table']]['name'], 'ids' => '#' . implode(', #', $gone),
                ])->fields('reason');
            }
        }
        foreach ($e['media'] ?? [] as $m) {
            if (!array_key_exists($m['name'], $snapshot) || isset($denied[$m['name']])) {
                continue;
            }
            // media(Klasse): the snapshot holds {id, meta} - metadata are taken over for the fields the current metadata class
            // still has (denied ones are discarded by collectMedia()); a snapshot without metadata keeps the current ones
            $fields = array_column(LinkFields::of($m), 'name');
            $items = [];
            foreach ((array) $snapshot[$m['name']] as $medium) {
                $mediumId = (int) (is_array($medium) ? ($medium['id'] ?? 0) : $medium);
                $metaValues = is_array($medium) && is_array($medium['meta'] ?? null) ? $medium['meta'] : null;
                $items[$mediumId] = $fields && $metaValues !== null
                    ? ['id' => $mediumId, 'meta' => array_intersect_key($metaValues, array_flip($fields))] : $mediumId;
            }
            $ids = array_keys($items);
            $exist = $this->fetchIds('media', 'id', $ids);
            $data[$m['name']] = array_values(array_intersect_key($items, array_flip($exist)));
            if ($gone = array_values(array_diff($ids, $exist))) {
                $skipped[] = ['field' => $m['name']] + Msg::of('msg_records.skip_media_gone', 'Medium {ids} no longer exists.', [
                    'ids' => '#' . implode(', #', $gone),
                ])->fields('reason');
            }
        }
        $data[Revisions::REASON_KEY] = $input[Revisions::REASON_KEY] ?? null;
        $extra = ['restored_from' => $version];
        try {
            [$row, $new] = $this->updateRecord($e, $id, $data, 'restore', $extra);
        } catch (ApiException $ex) {
            $all = $ex->status === 422 ? (array) ($ex->payload['errors'] ?? []) : [];
            $bad = array_intersect_key($all, $data);
            unset($bad[Revisions::REASON_KEY]);
            // a metadata value the current schema rejects ("bilder[2].meta.caption"): the media list stays, its metadata
            // are not taken over (the current ones stay) - reported under that key
            foreach ($all as $key => $message) {
                if (preg_match('/^(\w+)\[\d+\]\.meta/', (string) $key, $hit) && isset($data[$hit[1]]) && !isset($bad[$hit[1]])) {
                    $bad[$key] = $message;
                    $data[$hit[1]] = array_map(function ($medium) {
                        return is_array($medium) ? $medium['id'] : $medium;
                    }, $data[$hit[1]]);
                }
            }
            if (!$bad) {
                throw $ex;
            }
            $codes = (array) ($ex->payload['error_codes'] ?? []);
            foreach ($bad as $name => $message) {
                $reason = isset($codes[$name]) ? Msg::fromArray(['message' => $message] + (array) $codes[$name])
                    : Msg::of('msg_api.plain', '{text}', ['text' => is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE)]);
                $skipped[] = ['field' => $name] + Msg::of('msg_records.skip_schema', 'Does not fit the current schema: {reason}', [
                    'reason' => $reason,
                ])->fields('reason');
            }
            [$row, $new] = $this->updateRecord($e, $id, array_diff_key($data, $bad), 'restore', $extra);
        }
        return ['record' => $row, 'version' => $new, 'restored_from' => $version, 'skipped' => $skipped];
    }

    /**
     * {symmetric} n:n: rows added to or removed from the list of $id show the change in their own list as well - a version
     * for each of them (action link), see Revisions. $before null = new row.
     */
    private function recordSymmetricPartners(array $e, int $id, ?array $before, array $after): void
    {
        $partners = [];
        foreach ($e['many_to_many'] as $m) {
            if (!empty($m['symmetric'])) {
                $old = $before[$m['name']] ?? [];
                $new = $after[$m['name']] ?? [];
                $partners += array_fill_keys(array_merge(array_diff($old, $new), array_diff($new, $old)), true);
            }
        }
        unset($partners[$id]);
        ksort($partners);
        foreach (array_keys($partners) as $partner) {
            $row = $this->fetchRow($e, (int) $partner);
            if ($row !== null) {
                Revisions::record($this->pdo, $e, (int) $partner, $row, 'link', $this->user, $this->sortPlace($e, $row, ['linked_from' => $id]));
            }
        }
    }

    /** Entity + existing record for the history; needs a session (401) */
    private function historyEntity(string $table, int $id): array
    {
        $e = $this->entity($table);
        if ($this->user === null) {
            throw ApiException::of(401, 'unauthorized', Msg::of('msg_auth.login_required', 'Login required'));
        }
        if ($this->fetchIds($e['table'], 'id', [$id]) === []) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
        }
        return $e;
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
            throw ApiException::of(404, 'not_found', Msg::of('msg_api.record_not_found', 'Record not found'));
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
                $parts[] = Msg::of('msg_records.dependents', "{count} dependent row(s) in '{table}'", ['count' => $n, 'table' => $child]);
            }
            throw ApiException::of(409, 'in_use', Msg::of('msg_records.delete_blocked', 'Cannot be deleted: {reasons}.', [
                'reasons' => Msg::commaList($parts),
            ]), ['dependents' => $counts]);
        }

        // 2b. Minimum count ("1..*" on the n side, e.g. Position "1..*" --> "1" Rechnung): if a row that
        //     stays itself would lose its last related row through this deletion, nothing is deleted. If it is
        //     deleted along (it is the deleted row or depends on it via {cascade}), the rule no longer applies.
        $lacking = $this->minViolations($doomed);
        if ($lacking) {
            $parts = array_map(function ($v) {
                return Msg::of('msg_records.min_row', "'{entity} #{id}' needs at least one row in '{child}'", [
                    'entity' => $v['entity'], 'id' => $v['id'], 'child' => $v['child_entity'],
                ]);
            }, $lacking);
            throw ApiException::of(409, 'min_required', Msg::of('msg_records.delete_min', 'Cannot be deleted: {reasons} (minimum count).', [
                'reasons' => Msg::commaList($parts),
            ]), ['min_required' => $lacking]);
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
                Revisions::forgetRecords($this->pdo, $t, array_keys($set)); // and their versions
                Followups::forgetRecords($this->pdo, $t, array_keys($set)); // and their follow-ups
            }
            $this->pdo->commit();
        } catch (\Throwable $ex) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($ex instanceof PDOException && $this->isConstraintError($ex)) {
                throw ApiException::of(409, 'in_use', Msg::of('msg_records.referenced', 'The record is still referenced by other records'));
            }
            throw $ex;
        }

        unset($doomed[$e['table']][$id]);
        $cascaded = array_filter(array_map('count', $doomed));
        // only the deleted record itself fires; rows deleted along via {cascade} are named as counts per table
        $this->fireWebhooks($e, $id, 'deleted', null, null, $cascaded ? ['cascade_deleted' => $cascaded] : []);
        return $cascaded;
    }

    // ---------------------------------------------------------------- Internal

    /**
     * Webhooks of a write that has been committed (Webhooks::fire()). The hash is formed from the record as the public API
     * delivers it (a Cms without user and permissions) - regardless of who saved it and who fetches it later.
     */
    private function fireWebhooks(array $e, int $id, string $kind, ?int $version, ?array $transition = null, array $extra = []): void
    {
        if (!$this->webhooks) {
            return;
        }
        $public = $kind === 'deleted' ? null : function () use ($e, $id): ?array {
            return (new self($this->pdo, $this->model))->fetchRow($e, $id);
        };
        Webhooks::fire($this->pdo, $e['table'], $id, $kind, $version, $public, $transition, $extra);
    }

    private function entity(string $table): array
    {
        $key = strtolower($table);
        if (!isset($this->model['entities'][$key])) {
            throw ApiException::of(404, 'unknown_entity', Msg::of('msg_records.unknown_entity', "Unknown entity '{entity}'", ['entity' => $table]));
        }
        if ($this->access !== null && $this->access->entityDenied($key)) {
            throw ApiException::of(403, 'forbidden', Msg::of('msg_records.entity_forbidden', "No permission for '{entity}'", ['entity' => $table]));
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
            $params = ['entity' => $e['name']];
            if ($action === 'create') {
                $message = Msg::of('msg_records.create_forbidden', "No permission to create in '{entity}'", $params);
            } elseif ($action === 'update') {
                $message = Msg::of('msg_records.update_forbidden', "No permission to edit in '{entity}'", $params);
            } else {
                $message = Msg::of('msg_records.delete_forbidden', "No permission to delete in '{entity}'", $params);
            }
            throw ApiException::of(403, 'forbidden', $message, ['action' => $action]);
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
        $row = $this->presentMeta($e, [$this->castRow($e, $row)])[0];
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
     * media(Klasse): every media object additionally gets 'meta' (values of this link, see LinkFields) - a key of its own
     * instead of being mixed into the media object, so a metadata field "title" never collides with the global title.
     *
     * @param int|null $id only this row; null = all
     * @param int[]|null $ids only these rows (list page); null = no restriction
     * @param array<string,true> $hidden denied metadata fields (see metaHidden())
     * @return array<int,array[]> row ID => media
     */
    private function mediaLists(array $m, ?int $id, ?array $ids = null, array $hidden = []): array
    {
        if ($ids === []) {
            return [];
        }
        $own = SqlGenerator::q($m['own_column']);
        $meta = LinkFields::of($m);
        $stmt = $this->pdo->prepare(
            "SELECT j.$own AS own_id" . LinkFields::selectList($meta, 'j') . ', md.* FROM ' . SqlGenerator::q($m['junction'])
            . ' j JOIN "media" md ON md."id" = j."media_id"'
            . ($id !== null ? " WHERE j.$own = ?" : ($ids !== null ? " WHERE j.$own IN (" . implode(', ', array_map('intval', $ids)) . ')' : ''))
            . " ORDER BY j.$own, j.\"position\""
        );
        $stmt->execute($id !== null ? [$id] : []);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            // uploaded_at: for the admin UI only (preview dialog of the list); the public API stays as it is
            $map[(int) $r['own_id']][] = Media::present($r) + ($this->user !== null ? ['uploaded_at' => $r['uploaded_at']] : [])
                + ($meta ? ['meta' => (object) LinkFields::read($meta, $r, $hidden)] : []);
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
                $errors[$name] = Msg::of('msg_records.cycle', 'Would create a cycle: the record cannot be placed under itself or one of its descendants');
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
        foreach (SqlGenerator::uniqueRules($e) as $rule) {
            $fields = $rule['columns'];
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
                throw new ApiException(409, $this->duplicatePayload($e, $fields, $values, (int) $existing, $rule['name']));
            }
        }
    }

    /**
     * 409 response for an already used combination $fields ($existing = null: row unknown, constraint). For a
     * 1:1 column the message names the target row ("Nutzer #3 ist bereits Profil #1 zugeordnet"), for a named group
     * {unique: name} ($group) the labels of its fields ("Die Kombination aus Slug, Sprache und Website ist bereits vergeben"),
     * otherwise columns and values. 'group' = name of the group (null = the unnamed {unique} group or a 1:1 column),
     * 'fields' = its columns the caller may see (as 'unique_fields').
     */
    private function duplicatePayload(array $e, array $fields, array $values, ?int $existing, ?string $group = null): array
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
            $params = ['ref' => $ref, 'entity' => $e['name'], 'id' => $existing, 'target' => $target];
            $message = $existing !== null
                ? Msg::of('msg_records.one_to_one', '{ref} is already assigned to {entity} #{id} (1:1 relationship: at most one assignment '
                    . 'per {target}).', $params)
                : Msg::of('msg_records.one_to_one_other', '{ref} is already assigned to another record of {entity} (1:1 relationship: at '
                    . 'most one assignment per {target}).', $params);
            $fieldError = $existing !== null
                ? Msg::of('msg_records.assigned_to', 'Already assigned to {entity} #{id}', $params)
                : Msg::of('msg_records.assigned', 'Already assigned');
        } elseif ($group !== null) {
            $params = ['fields' => Msg::andList(array_map(function (string $name) use ($e) {
                return Msg::of('msg_api.plain', '{text}', ['text' => $this->fieldParam($e, $name)]);
            }, $fields)), 'entity' => $e['name'], 'id' => $existing, 'group' => $group];
            if (count($fields) > 1) {
                $message = $existing !== null
                    ? Msg::of('msg_records.group_taken_at', 'The combination of {fields} is already taken ({entity} #{id}).', $params)
                    : Msg::of('msg_records.group_taken', 'The combination of {fields} is already taken.', $params);
                $fieldError = Msg::of('msg_records.combination_exists_short', 'Combination already exists');
            } else {
                $message = $existing !== null
                    ? Msg::of('msg_records.group_value_taken_at', '{fields} is already taken ({entity} #{id}).', $params)
                    : Msg::of('msg_records.group_value_taken', '{fields} is already taken.', $params);
                $fieldError = Msg::of('msg_records.value_taken_short', 'Already taken');
            }
        } else {
            $parts = [];
            foreach ($fields as $name) {
                $v = $values[$name] ?? null;
                $parts[] = $name . ' = ' . (is_string($v) ? "'$v'" : json_encode($v));
            }
            $params = ['values' => implode(', ', $parts), 'entity' => $e['name'], 'id' => $existing];
            if (count($fields) > 1) {
                $message = $existing !== null
                    ? Msg::of('msg_records.combination_exists_at', 'The combination {values} already exists ({entity} #{id}).', $params)
                    : Msg::of('msg_records.combination_exists', 'The combination {values} already exists.', $params);
                $fieldError = Msg::of('msg_records.combination_exists_short', 'Combination already exists');
            } else {
                $message = $existing !== null
                    ? Msg::of('msg_records.value_taken_at', 'The value {values} is already taken ({entity} #{id}).', $params)
                    : Msg::of('msg_records.value_taken', 'The value {values} is already taken.', $params);
                $fieldError = Msg::of('msg_records.value_taken_short', 'Already taken');
            }
        }
        // rule with a field denied for the caller: reveal neither its name nor its value
        $visible = array_values(array_diff($fields, array_keys($this->denied($e))));
        if ($visible !== $fields) {
            $message = Msg::of('msg_records.combination_hidden', 'This combination already exists (the rule includes a field that is '
                . 'denied for you).');
        }
        return ['error' => 'duplicate'] + $message->fields() + [
            'unique_fields' => $visible,
            'existing_id'   => $existing,
            'group'         => $group,
            'fields'        => $visible,
        ] + ApiException::fields(array_fill_keys($visible, $fieldError));
    }

    /**
     * A field of $e as a message parameter: the UI shows its label in the display language (fieldLabel), the English text the
     * label from the diagram (FK: the relationship label or the target table, as the UI does without a translation)
     */
    private function fieldParam(array $e, string $name): array
    {
        $label = $name;
        foreach ($e['fields'] as $f) {
            if ($f['name'] === $name && $f['foreign_key']) {
                $label = ($f['foreign_key']['label'] ?? '') !== '' ? $f['foreign_key']['label'] : preg_replace('/_id$/', '', $name);
            }
        }
        $label = str_replace('_', ' ', (string) $label);
        return Msg::field($e['table'], $name, mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($label, 1, null, 'UTF-8'));
    }

    /**
     * Tracking metadata in API form (RecordMeta::present): user fields as {id, name} (no usernames), without a session
     * removed. Entities without tracking metadata (field of the same name, older installation) stay unchanged.
     */
    private function presentMeta(array $e, array $rows): array
    {
        return RecordMeta::applies($e) ? RecordMeta::present($this->pdo, $rows, $this->user !== null) : $rows;
    }

    /** Depending on the PHP version, pdo_sqlite returns strings or native types; unified here. */
    private function castRow(array $e, array $row): array
    {
        foreach ($e['fields'] as $f) {
            $name = $f['name'];
            if (!array_key_exists($name, $row) || $row[$name] === null) {
                continue;
            }
            if ($f['type'] === 'int' || $f['type'] === 'sort') {
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
            if ($f['type'] === 'sort') {
                continue; // manual order: set by the system only (create(), move()); a value sent along is not even read
            }
            if (!array_key_exists($name, $input)) {
                if ($creating && $f['required'] && $f['type'] === 'visibility') {
                    $values[$name] = 0; // default value: draft
                } elseif ($creating && $f['required']) {
                    $errors[$name] = Msg::of('msg_api.required', 'Required field');
                }
                continue;
            }
            try {
                $value = self::coerce($f, $input[$name]);
            } catch (\InvalidArgumentException $ex) {
                $errors[$name] = ValueException::msgOf($ex);
                continue;
            }
            if ($value === null && $f['required']) {
                $errors[$name] = Msg::of('msg_api.required', 'Required field');
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
                $errors[$name] = Msg::of('msg_permissions.ids_expected', 'List of IDs expected');
                continue;
            }
            $clean = [];
            foreach ($ids as $id) {
                if (!is_int($id) && !(is_string($id) && preg_match('/^\d+$/', $id))) {
                    $errors[$name] = Msg::of('msg_permissions.ids_expected', 'List of IDs expected');
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
     * media(Klasse) (metadata per link, see LinkFields): an entry can also be {"id": 7, "meta": {...}}. A pure ID (or an
     * object without "meta") keeps the stored metadata of a medium already linked to this record ($id) - a newly linked
     * one starts empty (NULL). With "meta" only the keys sent change. The metadata of the complete list is checked like
     * record fields (type, required, rules); messages under "<field>[<position>].meta.<metadata field>", naming the medium.
     * Errors: an object without "id", "meta" that is not an object, unknown metadata fields, "meta" on a media field
     * without a metadata class. Denied metadata fields ($denied, "<field>.meta.<x>") are discarded silently. For a
     * duplicate ID the first occurrence counts, its metadata too.
     *
     * @param int|null $id record being edited (null = new): whose stored metadata stay
     * @param array $violations rule violations of the metadata are added (Validation format)
     * @return array<string,array{ids:int[],meta:array<int,array>}> field name => media IDs and, per ID, the metadata to
     *         store (only fields present in the body; 'meta' empty without a metadata class)
     */
    private function collectMedia(
        array $e, array $input, bool $requireOnCreate, array &$errors, ?int $id = null, array $denied = [], array &$violations = []
    ): array {
        $lists = [];
        foreach ($e['media'] ?? [] as $m) {
            $name = $m['name'];
            if (!array_key_exists($name, $input)) {
                if ($requireOnCreate && $m['required']) {
                    $errors[$name] = Msg::of('msg_records.media_required', 'Required field: select at least one medium');
                }
                continue;
            }
            $items = $input[$name] ?? [];
            if (!is_array($items) || ($items && array_keys($items) !== range(0, count($items) - 1))) {
                $errors[$name] = Msg::of('msg_records.media_ids_expected', 'List of media IDs expected');
                continue;
            }
            $fields = LinkFields::of($m);
            $ids = [];
            $sent = []; // media ID => sent metadata (first occurrence)
            $positions = []; // media ID => position in the body (for messages)
            $bad = false;
            foreach ($items as $pos => $item) {
                $v = is_array($item) ? ($item['id'] ?? null) : $item;
                if (!is_int($v) && !(is_string($v) && preg_match('/^\d+$/', $v))) {
                    if ($fields && is_array($item) && !array_key_exists('id', $item)) {
                        $errors[$name] = Msg::of('msg_records.media_object_id', "Entry {position} is an object without 'id' (expected: a "
                            . 'media ID or {"id": 7, "meta": {...}})', ['position' => $pos]);
                    } else {
                        $errors[$name] = Msg::of('msg_records.media_ids_expected', 'List of media IDs expected');
                    }
                    continue 2;
                }
                $v = (int) $v;
                if (is_array($item) && array_key_exists('meta', $item)) {
                    if (!$fields) {
                        $errors[LinkFields::path($name, $pos)] = Msg::of('msg_records.media_no_meta', "The media field '{field}' has no "
                            . "metadata class - 'meta' is not possible here.", ['field' => $name]);
                        $bad = true;
                    } elseif (!is_array($item['meta']) || ($item['meta'] && array_keys($item['meta']) === range(0, count($item['meta']) - 1))) {
                        $errors[LinkFields::path($name, $pos)] = Msg::of('msg_records.media_meta_object', "Medium #{id}: 'meta' must be an "
                            . 'object {field: value}.', ['id' => $v]);
                        $bad = true;
                    } else {
                        // the same medium more than once: non-contradictory metadata are merged, the same key with a
                        // different value is a contradiction (422 at the later entry)
                        $conflict = isset($sent[$v]) ? self::metaConflict($sent[$v], $item['meta']) : null;
                        if ($conflict !== null) {
                            $errors["{$name}[{$pos}]"] = Msg::of('msg_records.media_meta_conflict', 'Medium #{id} occurs more than once '
                                . "with different metadata ('{field}': entry {first} and {position}). Please send it only once.", [
                                    'id' => $v, 'field' => $conflict, 'first' => $positions[$v], 'position' => $pos,
                                ]);
                            $bad = true;
                        } else {
                            $sent[$v] = ($sent[$v] ?? []) + $item['meta'];
                        }
                    }
                }
                if (!isset($ids[$v])) {
                    $ids[$v] = true;
                    $positions[$v] = $pos;
                }
            }
            $ids = array_keys($ids);
            if (!$ids && $m['required']) {
                $errors[$name] = Msg::of('msg_records.media_required', 'Required field: select at least one medium');
                continue;
            }
            if ($ids) {
                $marks = implode(', ', array_fill(0, count($ids), '?'));
                $stmt = $this->pdo->prepare("SELECT \"id\" FROM \"media\" WHERE \"id\" IN ($marks)");
                $stmt->execute($ids);
                $missing = array_diff($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                if ($missing) {
                    $errors[$name] = Msg::of('msg_records.media_missing', 'Medium {ids} does not exist', ['ids' => implode(', ', array_map(function ($i) {
                        return "#$i";
                    }, $missing))]);
                    continue;
                }
            }
            if ($bad) {
                continue;
            }
            $meta = [];
            if ($fields) {
                $stored = $id !== null ? $this->storedMeta($m, $id) : [];
                $hidden = self::metaHidden($denied, $name);
                foreach ($ids as $mediaId) {
                    $label = function (Msg $error) use ($mediaId): Msg {
                        return Msg::of('msg_records.media_meta_error', 'Medium #{id}: {error}', ['id' => $mediaId, 'error' => $error]);
                    };
                    $meta[$mediaId] = LinkFields::apply($fields, $stored[$mediaId] ?? [], $sent[$mediaId] ?? [], $hidden, $name,
                        $positions[$mediaId], $label, $this->validation(), $errors, $violations);
                }
            }
            $lists[$name] = ['ids' => $ids, 'meta' => $meta];
        }
        return $lists;
    }

    /**
     * First key that two metadata objects sent for the same medium give different values (null = no contradiction).
     * Compared as sent; numbers by value (7 and 7.0 are the same).
     */
    private static function metaConflict(array $a, array $b): ?string
    {
        foreach ($b as $key => $value) {
            if (!array_key_exists($key, $a)) {
                continue;
            }
            $old = $a[$key];
            $same = (is_int($old) || is_float($old)) && (is_int($value) || is_float($value)) ? (float) $old === (float) $value : $old === $value;
            if (!$same) {
                return (string) $key;
            }
        }
        return null;
    }

    /** Stored metadata of the links of record $id in a media field: media ID => field => value (storage form) */
    private function storedMeta(array $m, int $id): array
    {
        $fields = LinkFields::of($m);
        $cols = implode(', ', array_map(function ($f) {
            return SqlGenerator::q($f['name']);
        }, $fields));
        $stmt = $this->pdo->prepare("SELECT \"media_id\", $cols FROM " . SqlGenerator::q($m['junction']) . ' WHERE '
            . SqlGenerator::q($m['own_column']) . ' = ?');
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $values = [];
            foreach ($fields as $f) {
                $values[$f['name']] = LinkFields::stored($f, LinkFields::cast($f, $r[$f['name']]));
            }
            $out[(int) $r['media_id']] = $values;
        }
        return $out;
    }

    /** Replaces the media list per field sent along (position = order in the body), with the metadata of every link. */
    private function syncMedia(array $e, int $id, array $lists): void
    {
        foreach ($e['media'] ?? [] as $m) {
            if (!isset($lists[$m['name']])) {
                continue;
            }
            $junction = SqlGenerator::q($m['junction']);
            $own = SqlGenerator::q($m['own_column']);
            $fields = LinkFields::of($m);
            $this->pdo->prepare("DELETE FROM $junction WHERE $own = ?")->execute([$id]);
            $cols = '';
            foreach ($fields as $f) {
                $cols .= ', ' . SqlGenerator::q($f['name']);
            }
            $ins = $this->pdo->prepare("INSERT INTO $junction ($own, \"media_id\", \"position\"$cols) VALUES (?, ?, ?"
                . str_repeat(', ?', count($fields)) . ')');
            foreach ($lists[$m['name']]['ids'] as $pos => $mediaId) {
                $values = [$id, $mediaId, $pos];
                foreach ($fields as $f) {
                    $values[] = $lists[$m['name']]['meta'][$mediaId][$f['name']] ?? null;
                }
                $ins->execute($values);
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
                $errors[$m['name']] = Msg::of('msg_records.self_link', 'A record cannot be linked to itself');
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
            throw ApiException::invalid($selfErrors);
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
            case 'sort': // only the schema migration converts values into a sort field (int -> sort)
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) {
                    return (int) $value;
                }
                throw new ValueException(Msg::of('msg_records.int_expected', 'Integer expected'));
            case 'decimal':
                // Integers and floating point numbers (decimal point, not comma - JSON/<input type=number> always deliver
                // a point, independent of the browser's display locale).
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }
                if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
                    return (float) $value;
                }
                throw new ValueException(Msg::of('msg_records.number_expected', 'Number expected'));
            case 'bool':
            case 'visibility':
                if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
                    return 1;
                }
                if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
                    return 0;
                }
                throw new ValueException(Msg::of('msg_api.bool_expected', 'true or false expected'));
            case 'date':
                if (is_string($value)) {
                    $d = \DateTime::createFromFormat('!Y-m-d', $value);
                    if ($d && $d->format('Y-m-d') === $value) {
                        return $value;
                    }
                }
                throw new ValueException(Msg::of('msg_records.date_expected', 'Date in the format YYYY-MM-DD expected'));
            case 'enum':
                // exactly one of the declared values (case-sensitive, as with the CHECK constraint)
                if (is_string($value) && in_array($value, $f['enum_values'], true)) {
                    return $value;
                }
                throw new ValueException(Msg::of('msg_records.enum_invalid', "Invalid value for '{field}'. Allowed: {values}.", [
                    'field' => $f['name'], 'values' => implode(', ', $f['enum_values']),
                ]));
            default: // string, text, richtext
                if (is_scalar($value)) {
                    return (string) $value;
                }
                throw new ValueException(Msg::of('msg_api.text_expected', 'Text expected'));
        }
    }

    private function isConstraintError(PDOException $ex): bool
    {
        return Database::isConstraintViolation($ex);
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
                foreach (SqlGenerator::uniqueRules($e) as $rule) {
                    if ($rule['columns'] === $cols) {
                        throw new ApiException(409, $this->duplicatePayload($e, $cols, $values, null, $rule['name']));
                    }
                }
            }
            if ($this->isConstraintError($ex)) {
                throw ApiException::of(422, 'constraint', Msg::of('msg_records.constraint', 'Reference to a non-existent record or required '
                    . 'field missing'));
            }
            throw $ex;
        } catch (\Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }
}
