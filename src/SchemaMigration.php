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
 * Schema editing of an already populated installation without a DB reset (POST /api/_schema/analyze and /apply,
 * admins only). Compares the active model (_meta.schema_json) with the model from the edited .puml text and builds
 * a plan from it:
 *
 *  - Matching old -> new: classes via the table name or {renamed_from:Alt}; fields via the name or
 *    {renamed_from:alt}; FK columns via (target table after renaming, normalized label) - a renamed target class
 *    therefore renames the FK column along with it, without data loss; n:n via (target, label, self-reference/{symmetric})
 *    on the owning side; media fields via the name or {renamed_from}. Whatever finds no match counts as removed
 *    or new.
 *  - Per table of the new model (entity, n:n link table, media link table): leave unchanged if name,
 *    DDL and columns stay exactly the same - otherwise rebuild (shadow table "__new_<name>" with the target structure,
 *    carry over the data row by row, converted or with a backfill value where needed, drop the old table, rename). This
 *    covers new columns, renames, type/required/{unique}/1:1/on_delete changes uniformly (SQLite's recommended
 *    procedure for ALTERs SQLite cannot do directly). IDs are kept, as is the AUTOINCREMENT state.
 *  - Checks in advance against the existing data: data loss (removed table/column/links with content) ->
 *    confirmation; empty required values (new required field, optional -> required, new required relationship) ->
 *    backfill value; values that cannot be converted (type change, removed enum value) and duplicates of a new
 *    uniqueness rule -> blocker with the affected rows.
 *  - Field rules ({maxLength: 140}, {pattern: plz}, ..., see Validation) are part of the model only (no table changes):
 *    changed rules of an existing field are reported with the number of existing records that would violate them
 *    (severity 'warning', see ruleChanges()) - a notice, never a blocker; records are never changed. A backfill value has
 *    to satisfy the rules of its field.
 *  - Comparison with existing permissions (Permissions::denials()): if a denied field becomes required or a
 *    required relationship to a denied entity arises, the analysis reports a "Berechtigungs-Konflikt" (severity
 *    'permission') with the affected roles/groups/users. Likewise if a media field becomes required or is newly
 *    created as a required field and someone has no access to the system area 'media'. Purely a notice - nothing is
 *    blocked or needs confirmation.
 *
 * Workflows (Workflows, WorkflowParser): the analysis is always combined - main schema text plus ONE workflow file (the
 * candidate: the applied state or, coming from System -> Workflows, the text edited there including its file name). First
 * the workflow file structurally, then the named groups/roles/users against the database, then the main schema with the
 * definitions. A new workflow field automatically gets the initial state in existing rows (no backfill dialog);
 * if a state that still has rows in it is dropped, this blocks like a removed enum value. Changed transitions
 * only change the model. apply() writes the active workflow state (_meta) in the same transaction.
 *
 * The matching old -> new (identify()) also determines the stable IDs of the schema elements (SchemaIds, visual
 * schema editor): stableKeys() translates it into keys of the model JSON; apply() writes the IDs in the same
 * transaction, previewModel() delivers them read-only for an arbitrary text.
 *
 * apply() runs everything in ONE transaction (foreign keys off meanwhile, PRAGMA foreign_key_check at the end);
 * if anything fails, the database stays unchanged. Only after the COMMIT is the .puml file replaced (written on a trial
 * basis beforehand, the old version backed up in data/schema-backups/) so that /bootstrap reports up_to_date afterwards.
 */
final class SchemaMigration
{
    /** A message names at most this many affected rows */
    private const LIST_LIMIT = 20;
    /** An FK backfill delivers at most this many choices */
    private const CHOICE_LIMIT = 500;

    /** @var PDO */
    private $pdo;
    /** @var array active model (normalized) */
    private $old;
    /** @var array{file:?string,source:?string} workflow file that is analysed together with it (source null = none active) */
    private $workflow;
    /** @var array{0:?array}|null parsed definitions of the candidate (see workflows()) */
    private $defs = null;

    /**
     * @param array|null $workflow ['file' => file name|null, 'source' => text|null] - the workflow file that is to be
     *        active after applying; null = the state already applied (Workflows::applied())
     */
    public function __construct(PDO $pdo, array $oldModel, ?array $workflow = null)
    {
        $this->pdo = $pdo;
        $this->old = self::normalize($oldModel);
        $this->workflow = $workflow ?? Workflows::applied($pdo);
    }

    /**
     * Definitions of the candidate's workflow file (null = none active), structurally checked.
     *
     * @throws SchemaException
     */
    private function workflows(): ?array
    {
        if ($this->defs === null) {
            $this->defs = [$this->workflow['source'] !== null ? WorkflowParser::parse($this->workflow['source']) : null];
        }
        return $this->defs[0];
    }

    // ---------------------------------------------------------------- public

    /**
     * Pure analysis, no write operation. Additionally 'rename_suggestions' (see renameSuggestions()): possible
     * renames of new elements, for which the UI only puts the {renamed_from} marker into the text - and
     * 'consumed_markers' (see cleanup()): markers of this text whose rename is already part of the active schema.
     */
    public function analyze(string $source): array
    {
        $plan = $this->plan($source);
        return self::publicPlan($plan) + ['rename_suggestions' => $this->renameSuggestions($plan['identity'], $plan['model'], $source)]
            + ['consumed_markers' => self::publicCleanup($this->cleanup($plan['markers']['consumed'], $source))]
            + $this->workflowSummary($plan['model']);
    }

    /**
     * Overview of the candidate's workflows for the analysis - only if a workflow file is or becomes active (otherwise
     * the response stays exactly as before): 'workflow' => ['file', 'workflows' => [['name', 'entity' (using class
     * or null), 'states' => [names], 'initial', 'transitions' => count]]]
     */
    private function workflowSummary(array $model): array
    {
        $defs = $this->workflows();
        if ($defs === null) {
            return [];
        }
        $used = array_column(Workflows::usedBy($model), 'entity', 'workflow');
        return ['workflow' => ['file' => $this->workflow['file'], 'workflows' => array_values(array_map(function ($d) use ($used) {
            return ['name' => $d['name'], 'entity' => $used[$d['name']] ?? null, 'states' => array_column($d['states'], 'name'),
                'initial' => $d['initial'], 'transitions' => count($d['transitions'])];
        }, $defs))]];
    }

    /**
     * Model JSON of an arbitrary (also unsaved) text with the stable IDs from schema_ids where the matching to the
     * active schema finds a counterpart, otherwise provisional "draft:" IDs (SchemaIds::decorate()). Writes nothing.
     *
     * @throws ApiException 422 schema_error (parser or matching, e.g. {renamed_from} without a target - like analyze())
     */
    public function previewModel(string $source): array
    {
        try {
            $model = PumlParser::modelJson($source, $this->workflows(), Patterns::names($this->pdo));
            $new = PumlParser::parse($source, $this->workflows(), Patterns::names($this->pdo));
        } catch (SchemaException $e) {
            throw ApiException::of(422, 'schema_error', $e->msg);
        }
        return SchemaIds::decorate(
            $model, self::stableKeys($this->identify($new), $new, $model), SchemaIds::lookup($this->pdo),
            SchemaIds::activeNames($this->pdo)
        );
    }

    /**
     * @param array $input ['confirm' => [key, ...], 'backfill' => [key => value]]
     * @param string $schemaFile path of the active .puml (is replaced after the COMMIT and is part of the backup pair)
     * @param bool $writeWorkflow true (System -> Workflows): instead of the .puml of the main schema (which stays
     *        unchanged), the candidate's workflow file is written
     */
    public function apply(string $source, array $input, string $schemaFile, bool $writeWorkflow = false): array
    {
        $plan = $this->plan($source);
        if ($plan['blockers']) {
            throw ApiException::of(409, 'blocked', Msg::of('msg_migration.blocked', 'Apply not possible: {count} conflict(s) with '
                . 'existing data.', ['count' => count($plan['blockers'])]), self::publicPlan($plan));
        }
        $confirmed = is_array($input['confirm'] ?? null) ? $input['confirm'] : [];
        $missing = array_values(array_diff(array_column($plan['confirmations'], 'key'), $confirmed));
        $values = is_array($input['backfill'] ?? null) ? $input['backfill'] : [];
        $errors = [];
        $backfill = [];
        foreach ($plan['backfills'] as $b) {
            if (!array_key_exists($b['key'], $values) || $values[$b['key']] === null || $values[$b['key']] === '') {
                $errors[$b['key']] = self::valueRequired();
                continue;
            }
            try {
                $backfill[$b['key']] = $this->backfillValue($b, $values[$b['key']]);
            } catch (\InvalidArgumentException $e) {
                $errors[$b['key']] = ValueException::msgOf($e);
            }
        }
        if ($missing || $errors) {
            $params = ['confirmations' => count($missing), 'backfills' => count($errors)];
            if ($missing && $errors) {
                $message = Msg::of('msg_migration.missing_both', '{confirmations} confirmation(s) missing. {backfills} backfill value(s) '
                    . 'missing or invalid.', $params);
            } elseif ($missing) {
                $message = Msg::of('msg_migration.missing_confirmations', '{confirmations} confirmation(s) missing.', $params);
            } else {
                $message = Msg::of('msg_migration.missing_backfills', '{backfills} backfill value(s) missing or invalid.', $params);
            }
            throw ApiException::of(422, $missing ? 'confirmation_required' : 'backfill_invalid', $message, [
                'missing_confirmations' => $missing,
            ] + ApiException::fields($errors) + self::publicPlan($plan));
        }

        // write the file on a trial basis beforehand: if schema/ is not writable, this is noticed before any DB change
        [$target, $content, $folder] = [$schemaFile, $source, 'schema/'];
        if ($writeWorkflow) {
            // without a file (switching workflows off) nothing is written
            $target = $this->workflow['file'] !== null ? Workflows::dir() . '/' . $this->workflow['file'] : null;
            $content = (string) $this->workflow['source'];
            $folder = 'schema/workflows/';
            Workflows::ensureDir();
        }
        $tmp = $target !== null ? $target . '.neu' : null;
        if ($tmp !== null && @file_put_contents($tmp, $content) === false) {
            $params = ['file' => basename($target), 'folder' => $folder];
            throw ApiException::of(500, 'schema_not_writable', $writeWorkflow
                ? Msg::of('msg_migration.workflow_not_writable', 'The workflow file cannot be written ({file} in the folder {folder}) - '
                    . 'please check the write permissions. Nothing was changed.', $params)
                : Msg::of('msg_migration.schema_not_writable', 'The schema file cannot be written ({file} in the folder {folder}) - '
                    . 'please check the write permissions. Nothing was changed.', $params));
        }
        try {
            $summary = $this->execute($plan, $backfill, $source, $schemaFile, PumlParser::modelJson($source, $this->workflows()));
        } catch (\Throwable $e) {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            throw $e;
        }
        // only after the COMMIT: new version in place of the old one (which is already in the backup pair or in the database)
        if ($tmp !== null && !@rename($tmp, $target)) {
            $params = ['file' => basename($target), 'path' => $folder . basename($target)];
            $warning = $writeWorkflow
                ? Msg::of('msg_migration.replace_failed_workflow', 'The database was migrated, but the file {file} could not be replaced. '
                    . 'Please upload the new text via FTP as {path} (the API already works with the new workflow).', $params)
                : Msg::of('msg_migration.replace_failed', 'The database was migrated, but the file {file} could not be replaced. Please '
                    . 'upload the new text via FTP as {path}, otherwise /bootstrap reports migration_needed (the API works with the new '
                    . 'schema anyway).', $params);
            $summary['warning'] = $warning->text();
            $summary['warning_code'] = $warning->codeOnly();
        }
        // markers that have done their job with this apply (the UI offers to remove them, see removeConsumedMarkers())
        $summary['consumed_markers'] = (new self($this->pdo, $plan['model'], $this->workflow))->consumedMarkers($source);
        return $summary;
    }

    /**
     * {renamed_from} markers of a text whose rename is already part of the active schema (see markerStates()), with the
     * preview of their removal: ['count', 'markers' => [['kind', 'key', 'text', 'line' (1-based), 'original',
     * 'replacement']], 'skipped' => [['kind', 'key', 'text', 'line'|null, 'original'|null, 'reason']]]. A text that cannot be
     * read or matched has none. Writes nothing.
     */
    public function consumedMarkers(string $source): array
    {
        return self::publicCleanup($this->consumed($source));
    }

    /**
     * Removes the consumed {renamed_from} markers from the active .puml file (the admin confirmed the preview of
     * consumedMarkers()). Only the text changes: the cleaned text has to yield exactly the active schema ("no changes"),
     * otherwise nothing is written. It then takes the usual way of apply() - backup pair, file replaced, the stored text and
     * its hash in _meta follow so that /bootstrap keeps reporting up_to_date; no table is created, rebuilt or dropped.
     *
     * @throws ApiException 409 schema_file_changed (the file is not the applied text), 409 no_consumed_markers,
     *         409 conflict (the cleaned text would change the schema)
     */
    public function removeConsumedMarkers(string $schemaFile): array
    {
        $source = (string) @file_get_contents($schemaFile);
        if (!hash_equals((string) Database::getMeta($this->pdo, 'schema_hash'), hash('sha256', $source))) {
            throw ApiException::of(409, 'schema_file_changed', Msg::of('msg_migration.marker_file_changed', 'The schema file was changed '
                . 'after the last apply. Please check and apply the changes first; markers are only removed from the applied text.'));
        }
        $cleanup = $this->consumed($source);
        if (!$cleanup['markers']) {
            throw ApiException::of(409, 'no_consumed_markers', Msg::of('msg_migration.marker_none', 'There are no rename markers that '
                . 'could be removed.'));
        }
        $plan = $this->plan($cleanup['source']);
        $touched = array_filter($plan['ops'], function ($op) {
            return !$op['keep'];
        });
        if ($plan['changes'] || $plan['blockers'] || $plan['confirmations'] || $plan['backfills'] || $touched || $plan['drops']) {
            throw ApiException::of(409, 'conflict', Msg::of('msg_migration.marker_cleanup_changes', 'Removing the markers would change '
                . 'the schema. Nothing was changed.'));
        }
        $summary = $this->apply($cleanup['source'], [], $schemaFile);
        return ['status' => 'markers_removed'] + Msg::of('msg_migration.markers_removed', '{count} rename marker(s) removed.', [
            'count' => count($cleanup['markers']),
        ])->fields() + ['removed' => count($cleanup['markers'])] + self::publicCleanup($cleanup)
            + array_intersect_key($summary, array_flip(['backup', 'backups_removed', 'warning', 'warning_code']));
    }

    // ---------------------------------------------------------------- Plan

    /**
     * @return array internal: 'model', 'ops' (per table of the new model), 'drops', 'changes', 'confirmations',
     *               'backfills', 'blockers', 'entityMap', 'colMaps'
     */
    private function plan(string $source): array
    {
        // errors related to the workflow file name its line (workflow_line/workflow_lines, see Workflows::linePayload())
        try {
            $defs = $this->workflows();
        } catch (SchemaException $e) {
            throw ApiException::of(422, 'schema_error', $e->msg, Workflows::linePayload([$e->sourceLine]));
        }
        $positions = $defs ? WorkflowParser::positions((string) $this->workflow['source']) : [];
        if ($defs) {
            Workflows::checkPrincipals($this->pdo, $defs, $positions); // groups/roles/users from allowed=/assign= (422 schema_error)
            Webhooks::checkWorkflows($this->pdo, $defs, $positions); // webhooks from action=webhook:<name> (422 schema_error)
        }
        try {
            $new = PumlParser::parse($source, $defs, Patterns::names($this->pdo)); // {pattern: x} must exist
        } catch (SchemaException $e) {
            $w = $e->where; // error of the main schema caused by a transition of the workflow file
            $pos = $w !== null ? ($positions[$w['workflow']][$w['transition']] ?? []) : [];
            throw ApiException::of(422, 'schema_error', $e->msg, Workflows::linePayload($pos ? [$pos[$w['key']] ?? $pos['line'] ?? null] : []));
        }
        $old = $this->old;
        $id = $this->identify($new);
        $p = [
            'model' => $new, 'ops' => [], 'drops' => [], 'changes' => [], 'confirmations' => [], 'backfills' => [],
            'blockers' => [], 'entityMap' => $id['entityMap'], 'colMaps' => [], 'listMaps' => [], 'metaMaps' => [],
            'removedColumns' => $id['removedColumns'], 'identity' => $id,
            'denials' => Permissions::denials($this->pdo, $old), 'mediaBlocked' => Permissions::mediaBlocked($this->pdo, $old),
        ];
        $this->workflowChanges($p, $defs);
        $oldDdl = $this->pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $statements = SqlGenerator::createStatements($new);
        $usedOld = []; // old tables that serve as a source

        // --- entities
        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $p['entityMap'][$t1];
            $e0 = $t0 !== null ? $old['entities'][$t0] : null;
            if ($e0 === null) {
                $p['changes'][] = self::change('entity_added', 'info', Msg::of('msg_migration.entity_added', "New class {class} (table '{table}').", [
                    'class' => $e1['name'], 'table' => $t1,
                ]));
                $p['ops'][] = ['table' => $t1, 'ddl' => $statements[$t1], 'source' => null, 'cols' => [], 'keep' => false, 'kind' => 'entity'];
                continue;
            }
            if ($t0 !== $t1) {
                $p['changes'][] = self::change('entity_renamed', 'info', Msg::of('msg_migration.entity_renamed', "Class {old} renamed to "
                    . "{new} (table '{old_table}' -> '{table}', data is kept).", [
                        'old' => $e0['name'], 'new' => $e1['name'], 'old_table' => $t0, 'table' => $t1,
                    ]));
            }
            $usedOld[$t0] = true;
            $cols = $id['cols'][$t1];
            $p['colMaps'][$t1] = $id['colMaps'][$t1];
            array_push($p['changes'], ...$id['mapChanges'][$t1]);
            $this->checkColumns($t1, $e1, $t0, $e0, $cols, $p);
            // only the order of the columns may differ (fields sorted differently in the diagram, see sameDdl())
            $keep = $t0 === $t1 && self::sameDdl($oldDdl[$t1] ?? null, $statements[$t1]) && self::identity($cols);
            // tracking metadata (RecordMeta): not part of the model, carried over unchanged on a rebuild
            if (RecordMeta::applies($e1)) {
                foreach (RecordMeta::FIELDS as $name) {
                    $cols[$name] = ['from' => RecordMeta::applies($e0) ? $name : null];
                }
            }
            $p['ops'][] = ['table' => $t1, 'ddl' => $statements[$t1], 'source' => $t0, 'cols' => $cols, 'keep' => $keep,
                'kind' => 'entity', 'entity' => $e1];
        }
        foreach ($old['entities'] as $t0 => $e0) {
            if (!in_array($t0, $p['entityMap'], true)) {
                $n = $this->count($t0);
                $params = ['class' => $e0['name'], 'table' => $t0, 'count' => $n];
                $p['changes'][] = self::change('entity_removed', $n ? 'confirm' : 'warning', Msg::of('msg_migration.entity_removed',
                    "Class {class} removed (table '{table}', {count} row(s)).", $params));
                if ($n) {
                    $p['confirmations'][] = ['key' => "drop_table:$t0", 'count' => $n] + Msg::of('msg_migration.confirm_rows',
                        "I confirm that {count} row(s) in '{class}' (table '{table}') will be lost.", $params)->fields('text');
                }
                // the users' column selection for this entity is dropped (see execute())
            }
        }

        // --- n:n link tables
        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $p['entityMap'][$t1];
            foreach ($e1['many_to_many'] as $i => $m1) {
                $what = self::nnWhat($e1['name'], $new['entities'][$m1['table']]['name'], $m1['label']);
                $m0 = $id['nnMatch'][$t1][$i];
                if ($m0 !== null && PumlParser::normalizeLabel($m0['label']) !== PumlParser::normalizeLabel($m1['label'])) {
                    $p['changes'][] = self::change('relation_renamed', 'info', Msg::of('msg_migration.nn_relabelled', "{what}: renamed, "
                        . "previous label '{label}' (links are kept).", ['what' => $what, 'label' => $m0['label']]));
                }
                if ($m0 === null) {
                    $p['changes'][] = self::change('nn_added', 'info', Msg::of('msg_migration.nn_added', 'New {what}.', ['what' => $what]));
                    $p['ops'][] = ['table' => $m1['junction'], 'ddl' => $statements[$m1['junction']], 'source' => null, 'cols' => [],
                        'keep' => false, 'kind' => 'junction'];
                    continue;
                }
                if (($m0['filter_by'] ?? null) !== ($m1['filter_by'] ?? null)) {
                    $p['changes'][] = self::change('filter_by', 'info', Msg::of('msg_migration.what_colon', '{what}: {text}', [
                        'what' => $what, 'text' => self::filterChange($m1['filter_by'] ?? null),
                    ]));
                }
                $usedOld[$m0['junction']] = true;
                $p['listMaps'][$t1][$m1['name']] = $m0['name'];
                $cols = [$m1['own_column'] => ['from' => $m0['own_column']], $m1['other_column'] => ['from' => $m0['other_column']]];
                $symChange = null;
                if ($m0['symmetric'] !== !empty($m1['symmetric'])) {
                    $symChange = $m1['symmetric'] ? 'to_symmetric' : 'to_directed';
                    $p['changes'][] = self::change('nn_symmetric', 'warning', $symChange === 'to_symmetric'
                        ? Msg::of('msg_migration.nn_to_symmetric', '{what}: now {symmetric} - every link applies in both directions from '
                            . 'now on; "A -> B" and "B -> A" are merged into one pair.', ['what' => $what, 'symmetric' => '{symmetric}'])
                        : Msg::of('msg_migration.nn_to_directed', '{what}: now directed - every previous pair is taken over in both '
                            . 'directions (one row becomes two), afterwards the directions are independent.', ['what' => $what]));
                }
                $keep = $symChange === null && $m0['junction'] === $m1['junction']
                    && ($oldDdl[$m1['junction']] ?? null) === $statements[$m1['junction']] && self::identity($cols);
                if (!$keep && $m0['junction'] !== $m1['junction']) {
                    $p['changes'][] = self::change('nn_renamed', 'info', Msg::of('msg_migration.nn_renamed', "{what}: link table '{old}' -> "
                        . "'{new}' (links are kept).", ['what' => $what, 'old' => $m0['junction'], 'new' => $m1['junction']]));
                }
                $p['ops'][] = ['table' => $m1['junction'], 'ddl' => $statements[$m1['junction']], 'source' => $m0['junction'],
                    'cols' => $cols, 'keep' => $keep, 'kind' => 'junction', 'symmetric_change' => $symChange];
            }
            // media fields
            foreach ($e1['media'] as $i => $md1) {
                $md0 = $id['mediaMatch'][$t1][$i];
                $what = Msg::of('msg_migration.media_field', 'Media field {field}', ['field' => "{$e1['name']}.{$md1['name']}"]);
                if ($md0 === null) {
                    $p['changes'][] = self::change('media_added', 'info', Msg::of('msg_migration.media_added', 'New media field {field}.', [
                        'field' => "{$e1['name']}.{$md1['name']}",
                    ]));
                    $p['ops'][] = ['table' => $md1['junction'], 'ddl' => $statements[$md1['junction']], 'source' => null, 'cols' => [],
                        'keep' => false, 'kind' => 'media'];
                    if ($md1['required'] && $t0 !== null && $this->count($t0)) {
                        $p['changes'][] = self::change('media_required', 'warning', Msg::of('msg_migration.media_required', '{what} is required: '
                            . 'the existing {count} row(s) have no media yet - the form asks for one at the next edit.', [
                                'what' => $what, 'count' => $this->count($t0),
                            ]));
                    }
                    if ($md1['required']) {
                        $this->mediaConflict($p, $t0, Msg::of('msg_migration.media_new_required', 'New required media field {field}', [
                            'field' => "{$e1['name']}.{$md1['name']}",
                        ]), $e1['name']);
                    }
                    continue;
                }
                $usedOld[$md0['junction']] = true;
                $p['listMaps'][$t1][$md1['name']] = $md0['name'];
                if ($md0['name'] !== $md1['name']) {
                    $p['changes'][] = self::change('media_renamed', 'info', Msg::of('msg_migration.media_renamed', 'Media field {old} renamed '
                        . 'to {new}.', ['old' => $md0['name'], 'new' => $md1['name']]));
                }
                if ($md1['required'] && !$md0['required']) {
                    $this->permissionConflict($p, $t0, $md0['name'], null, Msg::of('msg_migration.becomes_required_denied', '{what} becomes '
                        . 'required but is denied', ['what' => $what]), $e1['name']);
                    $this->mediaConflict($p, $t0, Msg::of('msg_migration.becomes_required', '{what} becomes required, but', ['what' => $what]), $e1['name']);
                }
                $cols = [$md1['own_column'] => ['from' => $md0['own_column']], 'media_id' => ['from' => 'media_id'], 'position' => ['from' => 'position']];
                $meta = $this->metaColumns($t1, $e1, $md1, $md0, $cols, $p);
                $keep = $md0['junction'] === $md1['junction'] && ($oldDdl[$md1['junction']] ?? null) === $statements[$md1['junction']]
                    && self::identity($cols);
                $p['ops'][] = ['table' => $md1['junction'], 'ddl' => $statements[$md1['junction']], 'source' => $md0['junction'],
                    'cols' => $cols, 'keep' => $keep, 'kind' => 'media', 'meta' => $meta];
            }
        }
        // removed n:n / media fields (links are lost)
        foreach ($old['entities'] as $t0 => $e0) {
            foreach ($e0['many_to_many'] as $m0) {
                if (!isset($usedOld[$m0['junction']])) {
                    $n = $this->count($m0['junction']);
                    $p['changes'][] = self::change('nn_removed', $n ? 'confirm' : 'warning', Msg::of('msg_migration.nn_removed',
                        '{what} removed ({count} link(s)).', [
                            'what' => self::nnWhat($e0['name'], $old['entities'][$m0['table']]['name'], $m0['label']), 'count' => $n,
                        ]));
                    if ($n) {
                        $p['confirmations'][] = ['key' => "drop_links:{$m0['junction']}", 'count' => $n] + Msg::of('msg_migration.confirm_links',
                            "I confirm that {count} link(s) in '{table}' will be lost.", ['count' => $n, 'table' => $m0['junction']])->fields('text');
                    }
                }
            }
            foreach ($e0['media'] as $md0) {
                if (!isset($usedOld[$md0['junction']])) {
                    $n = $this->count($md0['junction']);
                    $params = ['field' => "{$e0['name']}.{$md0['name']}", 'count' => $n];
                    $p['changes'][] = self::change('media_removed', $n ? 'confirm' : 'warning', Msg::of('msg_migration.media_removed',
                        'Media field {field} removed ({count} link(s); the files stay in the media library).', $params));
                    if ($n) {
                        $p['confirmations'][] = ['key' => "drop_links:{$md0['junction']}", 'count' => $n] + Msg::of('msg_migration.confirm_media',
                            'I confirm that {count} media link(s) of {field} will be lost.', $params)->fields('text');
                    }
                }
            }
        }

        $p['sorts'] = $this->sortChanges($p, $id);

        // display order and form sections: only the model changes (no table, see sameDdl())
        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $p['entityMap'][$t1];
            if ($t0 !== null) {
                $this->orderChanges($p, $e1, $old['entities'][$t0], $id['colMaps'][$t1] ?? [], $p['listMaps'][$t1] ?? []);
            }
        }

        // all old model tables that do not stay unchanged are dropped (after copying)
        $keepTables = [];
        foreach ($p['ops'] as $op) {
            if ($op['keep']) {
                $keepTables[$op['table']] = true;
            }
        }
        foreach (self::modelTables($old) as $t) {
            if (!isset($keepTables[$t]) && isset($oldDdl[$t])) {
                $p['drops'][] = $t;
            }
        }
        $rebuilt = array_filter($p['ops'], function ($op) {
            return !$op['keep'] && $op['source'] !== null;
        });
        foreach ($rebuilt as $op) {
            if ($op['source'] === $op['table'] && $op['kind'] === 'entity') {
                $p['changes'][] = self::change('table_rebuilt', 'info', Msg::of('msg_migration.table_rebuilt', "Table '{table}' is rebuilt "
                    . '(structure changed; all rows are taken over).', ['table' => $op['table']]));
            } elseif ($op['source'] === $op['table'] && $op['kind'] === 'media' && !empty($op['meta'])) {
                $p['changes'][] = self::change('table_rebuilt', 'info', Msg::of('msg_migration.link_table_rebuilt', "Link table '{table}' "
                    . 'is rebuilt (metadata columns changed; all links and their order are taken over).', ['table' => $op['table']]));
            }
        }
        // {renamed_from} markers without effect: an outdated one whose old name is taken again blocks (see markerStates())
        $p['markers'] = $this->markerStates($id, $new, $source);
        $lines = explode("\n", $source);
        foreach ($p['markers']['stale'] as $m) {
            $original = $m['line'] !== null ? $lines[$m['line']] : null;
            $params = ['what' => $m['what'], 'marker' => $m['marker'], 'from' => $m['from']];
            if (in_array($m['kind'], ['relation', 'nn'], true)) {
                $params['label'] = $m['from'] === '' ? Msg::of('msg_migration.no_label', '(no label)')
                    : Msg::of('msg_migration.quoted_label', '"{label}"', ['label' => $m['from']]);
                unset($params['from']);
                $text = $m['existed']
                    ? Msg::of('msg_migration.marker_stale_relation_other', '{what} {marker}: the rename has already been applied, and the '
                        . 'label {label} now belongs to another relationship. Please remove the marker.', $params)
                    : Msg::of('msg_migration.marker_stale_relation_new', '{what} {marker}: the rename has already been applied, and the '
                        . 'label {label} now belongs to a new relationship. Please remove the marker.', $params);
            } else {
                $text = Msg::of('msg_migration.marker_stale', "{what} {marker}: the rename has already been applied, and the name '{from}' "
                    . 'now belongs to {owner}. Please remove the marker.', $params + ['owner' => self::markerOwner($m['kind'], $m['existed'])]);
            }
            // 'line'/'original'/'replacement' as for rename_suggestions: the UI only replaces this line and checks again
            $p['blockers'][] = ['key' => 'stale_marker:' . $m['key'], 'count' => 0, 'rows' => [], 'kind' => 'stale_marker']
                + $text->fields('text') + ['line' => $m['line'] !== null ? $m['line'] + 1 : null, 'original' => $original,
                    'replacement' => $original !== null ? RenameMarker::without($original) : null];
        }
        return $p;
    }

    // ---------------------------------------------------------------- {renamed_from} markers without effect

    /**
     * {renamed_from} markers that have no effect (any more) because their element is matched under its own name - the rename
     * has been applied, or the marker never was needed. Result of identify(), no matching of its own. Per marker:
     *  - 'stale': the old name is taken again by another element of the same kind in the new text (class <- class, field <-
     *    field of the same class, media field <- media field, relationship <- relationship of the same kind between the same
     *    classes with the old label, metadata class/field likewise). Applying would work once, but afterwards old and new
     *    name both exist in the active schema and the very same text is rejected as ambiguous ("both exist so far") - so it
     *    blocks right away (plan()). 'existed' = the old name also exists in the active schema already.
     *  - 'consumed': the old name exists neither in the active schema nor in the new text (or it is the element's own
     *    name) - the marker can be removed without any effect (cleanup()).
     * Everything else stays untouched: markers that still rename something, and markers whose old name still exists in
     * the active schema without a counterpart in the text (the matching decides about those).
     * A field marker of an abstract class counts per concrete subclass that has a predecessor: outdated as soon as it is for
     * one of them, consumed only if it is for all. Enums and enum values cannot carry the marker (the parser rejects it).
     *
     * @return array ['stale' => [entry], 'consumed' => [entry]], sorted by line; entry: ['kind' => 'entity'|'field'|'media'|
     *               'relation'|'nn'|'media_meta'|'media_meta_field', 'key', 'what' => Msg, 'marker', 'from', 'existed',
     *               'line' => 0-based line in the text or null (not determinable)]
     */
    private function markerStates(array $id, array $new, string $source): array
    {
        if (stripos($source, '{renamed_from:') === false) {
            return ['stale' => [], 'consumed' => []];
        }
        $old = $this->old;
        $map = PumlParser::sourceMap($source, $this->workflows(), Patterns::names($this->pdo));
        // the parser's line numbers only match the text split at "\n" without lone "\r" line breaks
        $pos = preg_match('/\r(?!\n)/', $source) ? ['classes' => [], 'relations' => []] : $map['positions'];
        $classes = array_column($map['model']['entities'], null, 'name');
        $relLines = [];
        foreach ($map['model']['relations'] as $i => $r) {
            $relLines[$r['id']] = $pos['relations'][$i] ?? null;
        }
        // class (the class itself or a base class) that declares the field, media field or relationship
        $chain = function (string $class) use ($classes): array {
            $out = [];
            for ($c = $classes[$class] ?? null; $c !== null && count($out) < 50; $c = $c['extends'] !== null ? ($classes[$c['extends']] ?? null) : null) {
                $out[] = $c;
            }
            return $out;
        };
        $declaring = function (string $class, string $name, string $list) use ($chain): string {
            foreach ($chain($class) as $c) {
                foreach ($c[$list] as $f) {
                    if (strtolower($f['name']) === strtolower($name)) {
                        return $c['name'];
                    }
                }
            }
            return $class;
        };
        $norm = [PumlParser::class, 'normalizeLabel'];
        $found = [];
        $note = function (string $state, string $kind, string $key, Msg $what, string $from, bool $existed, ?int $line) use (&$found): void {
            $found[$key]['states'][$state] = true;
            if (!isset($found[$key]['entry']) || $state === 'stale') {
                $found[$key]['entry'] = ['kind' => $kind, 'key' => $key, 'what' => $what, 'marker' => '{renamed_from:' . $from . '}',
                    'from' => $from, 'existed' => $existed, 'line' => $line];
            }
        };
        // marker without effect: $same = old name is the own name, $sibling = taken again in the new text, $existed = the old
        // name exists in the active schema
        $state = function (bool $same, bool $sibling, bool $existed): string {
            return $same ? 'consumed' : ($sibling ? 'stale' : ($existed ? 'live' : 'consumed'));
        };

        foreach ($new['entities'] as $t1 => $e1) {
            if (isset($e1['renamed_from'])) {
                $r = strtolower($e1['renamed_from']);
                $existed = isset($old['entities'][$r]);
                $note(isset($old['entities'][$t1]) ? $state($r === $t1, isset($new['entities'][$r]), $existed) : 'live', 'entity',
                    "entity:$t1", Msg::of('msg_migration.option_class', 'Class {class}', ['class' => $e1['name']]), $e1['renamed_from'],
                    $existed, $pos['classes'][$t1]['head'] ?? null);
            }
            $t0 = $id['entityMap'][$t1];
            if ($t0 === null) {
                continue; // a new class: its markers (also inherited ones) rename nothing here and say nothing
            }
            $e0 = $old['entities'][$t0];
            $names = function (array $e): array {
                $out = [];
                foreach ($e['fields'] as $f) {
                    if (!$f['foreign_key']) {
                        $out[strtolower($f['name'])] = true;
                    }
                }
                return $out;
            };
            [$names0, $names1] = [$names($e0), $names($e1)];
            foreach ($e1['fields'] as $f1) {
                $fk = $f1['foreign_key'];
                if ($fk) {
                    if (!isset($fk['renamed_from'])) {
                        continue;
                    }
                    $r = $norm($fk['renamed_from']);
                    $from = $id['cols'][$t1][$f1['name']]['old'] ?? null;
                    $sibling = false;
                    foreach ($e1['fields'] as $g) {
                        $sibling = $sibling || ($g['foreign_key'] && $g['name'] !== $f1['name'] && $g['foreign_key']['table'] === $fk['table']
                            && $norm($g['foreign_key']['label']) === $r);
                    }
                    $existed = false;
                    foreach ($e0['fields'] as $f0) {
                        $existed = $existed || ($f0['foreign_key'] && $this->mapsTo($f0['foreign_key']['table'], $id['entityMap']) === $fk['table']
                            && $norm($f0['foreign_key']['label']) === $r);
                    }
                    $line = null;
                    foreach ($chain($e1['name']) as $c) {
                        $line = $line ?? ($relLines["relation:{$c['table']}.{$f1['name']}"] ?? null);
                    }
                    $matched = $from !== null && $norm($from['foreign_key']['label']) === $norm($fk['label']);
                    $note($matched ? $state($r === $norm($fk['label']), $sibling, $existed) : 'live', 'relation', "relation:$t1.{$f1['name']}",
                        Msg::of('msg_migration.relation_what', 'Relationship {class} -> {target}', [
                            'class' => $e1['name'], 'target' => $new['entities'][$fk['table']]['name'],
                        ]), $fk['renamed_from'], $existed, $line);
                    continue;
                }
                if (!isset($f1['renamed_from'])) {
                    continue;
                }
                [$n, $r] = [strtolower($f1['name']), strtolower($f1['renamed_from'])];
                $decl = $declaring($e1['name'], $f1['name'], 'fields');
                $note(isset($names0[$n]) ? $state($r === $n, isset($names1[$r]), isset($names0[$r])) : 'live', 'field',
                    'field:' . strtolower($decl) . ".$n", Msg::of('msg_migration.marker_field', 'Field {field}', ['field' => "$decl.{$f1['name']}"]),
                    $f1['renamed_from'], isset($names0[$r]), $pos['classes'][strtolower($decl)]['fields'][$n] ?? null);
            }
            $media0 = array_flip(array_map('strtolower', array_column($e0['media'], 'name')));
            $media1 = array_flip(array_map('strtolower', array_column($e1['media'], 'name')));
            foreach ($e1['media'] as $md1) {
                if (!isset($md1['renamed_from'])) {
                    continue;
                }
                [$n, $r] = [strtolower($md1['name']), strtolower($md1['renamed_from'])];
                $decl = $declaring($e1['name'], $md1['name'], 'media');
                $note(isset($media0[$n]) ? $state($r === $n, isset($media1[$r]), isset($media0[$r])) : 'live', 'media',
                    'media:' . strtolower($decl) . ".$n", Msg::of('msg_migration.media_field', 'Media field {field}', ['field' => "$decl.{$md1['name']}"]),
                    $md1['renamed_from'], isset($media0[$r]), $pos['classes'][strtolower($decl)]['fields'][$n] ?? null);
            }
            foreach ($e1['many_to_many'] as $i => $m1) {
                if (!isset($m1['renamed_from'])) {
                    continue;
                }
                $r = $norm($m1['renamed_from']);
                $m0 = $id['nnMatch'][$t1][$i];
                $sibling = false;
                foreach ($e1['many_to_many'] as $j => $m2) {
                    $sibling = $sibling || ($j !== $i && $m2['table'] === $m1['table'] && $norm($m2['label']) === $r);
                }
                $existed = false;
                foreach ($e0['many_to_many'] as $c0) {
                    $existed = $existed || ($this->mapsTo($c0['table'], $id['entityMap']) === $m1['table'] && $norm($c0['label']) === $r);
                }
                $matched = $m0 !== null && $norm($m0['label']) === $norm($m1['label']);
                $note($matched ? $state($r === $norm($m1['label']), $sibling, $existed) : 'live', 'nn', "nn:{$m1['junction']}",
                    self::nnWhat($e1['name'], $new['entities'][$m1['table']]['name'], $m1['label']), $m1['renamed_from'], $existed,
                    $relLines["relation:{$m1['junction']}"] ?? null);
            }
        }
        // metadata classes (<<media_meta>>, no table): class and fields by name against the active schema
        $meta0 = array_change_key_case($old['media_meta'] ?? [], CASE_LOWER);
        $meta1 = array_change_key_case($new['media_meta'] ?? [], CASE_LOWER);
        foreach ($meta1 as $n => $mc) {
            $r = isset($mc['renamed_from']) ? strtolower($mc['renamed_from']) : null;
            if ($r !== null) {
                $note(isset($meta0[$n]) ? $state($r === $n, isset($meta1[$r]), isset($meta0[$r])) : 'live', 'media_meta', "media_meta:$n",
                    Msg::of('msg_migration.marker_meta_class', 'Metadata class {class}', ['class' => $mc['name']]), $mc['renamed_from'],
                    isset($meta0[$r]), $pos['classes'][$n]['head'] ?? null);
            }
            $mc0 = $meta0[$n] ?? ($r !== null ? ($meta0[$r] ?? null) : null);
            if ($mc0 === null) {
                continue;
            }
            $names0 = array_flip(array_map('strtolower', array_column($mc0['fields'], 'name')));
            $names1 = array_flip(array_map('strtolower', array_column($mc['fields'], 'name')));
            foreach ($mc['fields'] as $f) {
                if (!isset($f['renamed_from'])) {
                    continue;
                }
                [$fn, $fr] = [strtolower($f['name']), strtolower($f['renamed_from'])];
                $note(isset($names0[$fn]) ? $state($fr === $fn, isset($names1[$fr]), isset($names0[$fr])) : 'live', 'media_meta_field',
                    "media_meta_field:$n.$fn", Msg::of('msg_migration.marker_meta_field', 'Metadata field {field}', [
                        'field' => "{$mc['name']}.{$f['name']}",
                    ]), $f['renamed_from'], isset($names0[$fr]), $pos['classes'][$n]['fields'][$fn] ?? null);
            }
        }
        $out = ['stale' => [], 'consumed' => []];
        foreach ($found as $f) {
            if (isset($f['states']['stale'])) {
                $out['stale'][] = $f['entry'];
            } elseif (!isset($f['states']['live'])) {
                $out['consumed'][] = $f['entry'];
            }
        }
        foreach ($out as &$list) {
            usort($list, function ($a, $b) {
                return [$a['line'] ?? PHP_INT_MAX, $a['key']] <=> [$b['line'] ?? PHP_INT_MAX, $b['key']];
            });
        }
        unset($list);
        return $out;
    }

    /** "… now belongs to {owner}": the element that carries the old name of an outdated marker */
    private static function markerOwner(string $kind, bool $existed): Msg
    {
        switch ($kind) {
            case 'entity':
                return $existed ? Msg::of('msg_migration.marker_owner_other_class', 'another class')
                    : Msg::of('msg_migration.marker_owner_new_class', 'a new class');
            case 'media':
                return $existed ? Msg::of('msg_migration.marker_owner_other_media', 'another media field')
                    : Msg::of('msg_migration.marker_owner_new_media', 'a new media field');
            case 'media_meta':
                return $existed ? Msg::of('msg_migration.marker_owner_other_meta_class', 'another metadata class')
                    : Msg::of('msg_migration.marker_owner_new_meta_class', 'a new metadata class');
            case 'media_meta_field':
                return $existed ? Msg::of('msg_migration.marker_owner_other_meta_field', 'another metadata field')
                    : Msg::of('msg_migration.marker_owner_new_meta_field', 'a new metadata field');
            default:
                return $existed ? Msg::of('msg_migration.marker_owner_other_field', 'another field')
                    : Msg::of('msg_migration.marker_owner_new_field', 'a new field');
        }
    }

    /** Consumed markers of a text with the preview of their removal (see cleanup()); none if the text cannot be read or matched */
    private function consumed(string $source): array
    {
        try {
            $new = PumlParser::parse($source, $this->workflows(), Patterns::names($this->pdo));
            return $this->cleanup($this->markerStates($this->identify($new), $new, $source)['consumed'], $source);
        } catch (SchemaException | ApiException $e) {
            return ['markers' => [], 'skipped' => [], 'source' => $source];
        }
    }

    /**
     * Removal of consumed markers as a minimal change of the text: per marker only its own line changes, and in it only the
     * marker with the blanks in front of it (RenameMarker::without()) - comments, notes and formatting stay as they are. A
     * marker is left out and named in 'skipped' if its line cannot be determined or changed safely (block comment on the
     * line, a second marker in a trailing comment), or if the text without it would not yield exactly the same model.
     *
     * @param array $consumed entries of markerStates()
     * @return array ['markers' => [...], 'skipped' => [...] (see consumedMarkers()), 'source' => text without the markers]
     */
    private function cleanup(array $consumed, string $source): array
    {
        $out = ['markers' => [], 'skipped' => [], 'source' => $source];
        if (!$consumed) {
            return $out;
        }
        $model = function (string $text): ?array {
            try {
                return self::withoutMarkers(PumlParser::parse($text, $this->workflows(), Patterns::names($this->pdo)));
            } catch (SchemaException $e) {
                return null;
            }
        };
        $base = $model($source);
        $lines = explode("\n", $source);
        $item = function (array $c) use ($lines): array {
            return ['kind' => $c['kind'], 'key' => $c['key']] + Msg::of('msg_migration.marker_consumed', '{what} {marker}', [
                'what' => $c['what'], 'marker' => $c['marker'],
            ])->fields('text') + ['line' => $c['line'] !== null ? $c['line'] + 1 : null, 'original' => $c['line'] !== null ? $lines[$c['line']] : null];
        };
        $skip = function (array $c, Msg $reason) use (&$out, $item): void {
            $out['skipped'][] = $item($c) + ['reason' => $reason->text(), 'reason_code' => $reason->codeOnly()];
        };
        $unsafe = Msg::of('msg_migration.marker_skip_unsafe', 'the text without this marker would not yield the same schema');
        $todo = [];
        foreach ($consumed as $c) {
            $replacement = $c['line'] !== null ? RenameMarker::without($lines[$c['line']]) : null;
            if ($replacement === null) {
                $skip($c, $c['line'] === null
                    ? Msg::of('msg_migration.marker_skip_line', 'its line could not be determined unambiguously')
                    : Msg::of('msg_migration.marker_skip_comment', 'block comment or a second marker on the same line'));
                continue;
            }
            $todo[] = [$c, $replacement];
        }
        // all at once; only if that does not yield the same model, one by one (whatever changes the model is left out)
        $all = $lines;
        foreach ($todo as [$c, $replacement]) {
            $all[$c['line']] = $replacement;
        }
        $together = $base !== null && $model(implode("\n", $all)) === $base;
        $cleaned = $together ? $all : $lines;
        foreach ($todo as [$c, $replacement]) {
            if (!$together) {
                $try = $cleaned;
                $try[$c['line']] = $replacement;
                if ($base === null || $model(implode("\n", $try)) !== $base) {
                    $skip($c, $unsafe);
                    continue;
                }
                $cleaned = $try;
            }
            $out['markers'][] = $item($c) + ['replacement' => $replacement];
        }
        $out['source'] = implode("\n", $cleaned);
        return $out;
    }

    private static function publicCleanup(array $cleanup): array
    {
        return ['count' => count($cleanup['markers']), 'markers' => $cleanup['markers'], 'skipped' => $cleanup['skipped']];
    }

    /** Model without any 'renamed_from' key (what a text means once its markers are gone) */
    private static function withoutMarkers(array $model): array
    {
        unset($model['renamed_from']);
        foreach ($model as $k => $v) {
            if (is_array($v)) {
                $model[$k] = self::withoutMarkers($v);
            }
        }
        return $model;
    }

    /**
     * Metadata columns of a matched media field (media(Klasse), see LinkFields): the link table is treated like the table of an
     * entity whose fields are the fields of the metadata class - the same matching (name or {renamed_from}) and the same
     * checks (checkColumns(): removed columns with values -> confirmation, new required columns / optional -> required ->
     * backfill, type changes, rule changes with the affected records). The messages name the columns as
     * "<Class>.<media field>.meta.<field>", so a change to a metadata class used by several media fields shows up per link
     * table. Adds the column mapping to $cols; the record of a link is its own_column (for listed IDs).
     *
     * @return bool true = the media field has or had a metadata class (the link table may change)
     */
    private function metaColumns(string $t1, array $e1, array $md1, array $md0, array &$cols, array &$p): bool
    {
        $f1 = LinkFields::of($md1);
        $f0 = LinkFields::of($md0);
        if (!$f1 && !$f0) {
            return false;
        }
        $field = "{$e1['name']}.{$md1['name']}";
        $c1 = $md1['meta']['class'] ?? null;
        $c0 = $md0['meta']['class'] ?? null;
        $params = ['field' => $field, 'class' => $c1, 'old' => $c0, 'table' => $md1['junction']];
        if ($c0 === null) {
            $p['changes'][] = self::change('media_meta', 'info', Msg::of('msg_migration.media_meta_added', 'Media field {field}: metadata '
                . "class {class} added (new columns in '{table}'; links and their order are kept).", $params));
        } elseif ($c1 === null) {
            $p['changes'][] = self::change('media_meta', 'info', Msg::of('msg_migration.media_meta_removed', 'Media field {field}: metadata '
                . "class {old} removed (its columns in '{table}' are dropped; links and their order are kept).", $params));
        } elseif (strtolower($c0) !== strtolower($c1)) {
            $p['changes'][] = self::change('media_meta', 'info', Msg::of('msg_migration.media_meta_changed', 'Media field {field}: '
                . "metadata class {old} -> {class} (columns in '{table}' are matched by field name).", $params));
        }
        // {renamed_from} of a shared metadata class only counts where the previous field exists (another media field may get
        // the class only now - for it the field is simply new)
        $names0 = array_map('strtolower', array_column($f0, 'name'));
        $f1 = array_map(function (array $f) use ($names0): array {
            if (isset($f['renamed_from']) && !in_array(strtolower($f['renamed_from']), $names0, true)) {
                unset($f['renamed_from']);
            }
            return $f;
        }, $f1);
        $e1m = ['name' => "$field.meta", 'table' => $md1['junction'], 'fields' => $f1];
        $e0m = ['name' => "$field.meta", 'table' => $md0['junction'], 'fields' => $f0];
        $scratch = ['model' => $p['model'], 'entityMap' => $p['entityMap'], 'changes' => [], 'removedColumns' => []];
        [$meta, $map] = $this->mapColumns($e1m, $e0m, $scratch);
        array_push($p['changes'], ...$scratch['changes']);
        $p['removedColumns'][$md0['junction']] = $scratch['removedColumns'][$md0['junction']] ?? [];
        $this->checkColumns($md1['junction'], $e1m, $md0['junction'], $e0m, $meta, $p, SqlGenerator::q($md0['own_column']));
        $cols += $meta;
        $p['metaMaps'][$t1][$md1['name']] = $map; // old metadata field => new one (versions, see Revisions::migrate())
        return true;
    }

    /**
     * Changes to the workflow file itself (independent of tables): a different active file, new, removed and changed
     * workflows. Pure notices - they make sure that a change to transitions only counts as a change.
     */
    private function workflowChanges(array &$p, ?array $defs): void
    {
        $before = $this->old['workflows'] ?? null;
        $fileBefore = Workflows::activeFile($this->pdo);
        $file = $defs !== null ? $this->workflow['file'] : null;
        if ($fileBefore !== $file) {
            $params = ['before' => $fileBefore, 'file' => $file];
            $p['changes'][] = self::change('workflow_file', 'info', $file === null
                ? Msg::of('msg_migration.workflows_off', 'Workflows switched off (previously active workflow file: {before}).', $params)
                : ($fileBefore === null ? Msg::of('msg_migration.workflow_file_active', 'Workflow file {file} becomes active.', $params)
                    : Msg::of('msg_migration.workflow_file_changed', 'Active workflow file: {before} -> {file}.', $params)));
        }
        $used = array_column(Workflows::usedBy($p['model']), 'entity', 'workflow');
        foreach ($defs ?? [] as $name => $def) {
            $where = isset($used[$name])
                ? Msg::of('msg_migration.workflow_class', '(class {class})', ['class' => $used[$name]])
                : Msg::of('msg_migration.workflow_unused', '(not used by any class yet)');
            if (!isset($before[$name])) {
                $p['changes'][] = self::change('workflow_added', 'info', Msg::of('msg_migration.workflow_added', 'New workflow {name} {where}: '
                    . '{states} states, {transitions} transition(s).', [
                        'name' => $name, 'where' => $where, 'states' => count($def['states']), 'transitions' => count($def['transitions']),
                    ]));
            } elseif ($before[$name] !== $def) {
                $p['changes'][] = self::change('workflow_changed', 'info',
                    array_column($before[$name]['states'], 'name') !== array_column($def['states'], 'name')
                        ? Msg::of('msg_migration.workflow_states', 'Workflow {name} {where} changed (states: {states}).', [
                            'name' => $name, 'where' => $where, 'states' => implode(', ', array_column($def['states'], 'name')),
                        ])
                        : Msg::of('msg_migration.workflow_details', 'Workflow {name} {where} changed (labels, transitions or assignments).', [
                            'name' => $name, 'where' => $where,
                        ]));
            }
        }
        foreach (array_diff_key($before ?? [], $defs ?? []) as $name => $def) {
            $p['changes'][] = self::change('workflow_removed', 'info', Msg::of('msg_migration.workflow_removed', 'Workflow {name} removed.', [
                'name' => $name,
            ]));
        }
    }

    /**
     * Matching old -> new (without database access): classes, columns, n:n relationships and media fields of the new
     * model to their counterparts in the active model (null = new). Basis for plan() and for the stable IDs (SchemaIds) -
     * there is exactly this one matching logic. Order as before in plan(): first all classes including columns, then per
     * class n:n and media (determines which schema error is reported first).
     *
     * @return array 'entityMap' (new table => old|null), 'cols' + 'colMaps' + 'mapChanges' per matched new
     *               table (see mapColumns()), 'removedColumns' (old table => removed fields), 'nnMatch' /
     *               'mediaMatch' (new table => [index => old entry|null])
     */
    private function identify(array $new): array
    {
        $old = $this->old;
        $id = ['entityMap' => $this->mapEntities($new, $old), 'cols' => [], 'colMaps' => [], 'mapChanges' => [],
            'removedColumns' => [], 'nnMatch' => [], 'mediaMatch' => []];
        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $id['entityMap'][$t1];
            if ($t0 === null) {
                continue;
            }
            $scratch = ['model' => $new, 'entityMap' => $id['entityMap'], 'changes' => [], 'removedColumns' => []];
            [$id['cols'][$t1], $id['colMaps'][$t1]] = $this->mapColumns($e1, $old['entities'][$t0], $scratch);
            $id['mapChanges'][$t1] = $scratch['changes'];
            $id['removedColumns'] += $scratch['removedColumns'];
        }
        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $id['entityMap'][$t1];
            $used = [];
            foreach ($e1['many_to_many'] as $i => $m1) {
                // Candidates: previous, still unassigned n:n of this class to the same (possibly renamed) target class; matching
                // via the label or {renamed_from:old label}. {symmetric} may change (conversion in copyRows()).
                $cands = [];
                $oldMany = $t0 !== null ? $old['entities'][$t0]['many_to_many'] : [];
                foreach ($oldMany as $k => $cand) {
                    if (!isset($used[$k]) && $this->mapsTo($cand['table'], $id['entityMap']) === $m1['table']) {
                        $cands[$k] = $cand['label'];
                    }
                }
                $what = self::nnWhat($e1['name'], $new['entities'][$m1['table']]['name'], $m1['label']);
                $siblings = [];
                foreach ($e1['many_to_many'] as $j => $m2) {
                    if ($j !== $i && $m2['table'] === $m1['table'] && !isset($m2['renamed_from'])) {
                        $siblings[] = PumlParser::normalizeLabel($m2['label']);
                    }
                }
                $k = self::matchRelation($cands, $m1['label'], $m1['renamed_from'] ?? null, $what, $siblings);
                if ($k !== null) {
                    $used[$k] = true;
                }
                $id['nnMatch'][$t1][$i] = $k !== null ? $oldMany[$k] : null;
            }
            foreach ($e1['media'] as $i => $md1) {
                $id['mediaMatch'][$t1][$i] = $t0 !== null ? $this->matchMedia($md1, $old['entities'][$t0], $e1) : null;
            }
        }
        return $id;
    }

    /**
     * Rename suggestions (result of identify(), no matching of its own): for every new element without a predecessor for
     * which there are removed elements of the same kind in the same context, the removed elements that come into question:
     *  - concrete class without a predecessor <- removed classes
     *  - field or media field of a matched class <- removed fields or media fields of this class
     *  - FK relationship (n:1/1:1) or n:n <- removed relationship of the same kind between the same (possibly renamed) classes
     * Per option the line in the text with {renamed_from:…} inserted (RenameMarker); the UI only replaces this line
     * and checks again, the actual matching then runs via the marker as always. An element whose line cannot be
     * found unambiguously gets no suggestion. Inherited fields are marked in the declaring (abstract) class,
     * one suggestion per line.
     *
     * @return array [['key', 'kind' => 'entity'|'field'|'media'|'relation'|'nn', 'text', 'line' (1-based), 'original',
     *               'options' => [['value', 'label', 'replacement']]]]
     */
    private function renameSuggestions(array $id, array $new, string $source): array
    {
        $old = $this->old;
        $loc = new RenameMarker($source);
        $map = PumlParser::sourceMap($source, $this->workflows());
        $json = $map['model'];
        $classes = array_column($json['entities'], null, 'name');
        // Line of every relationship exactly as the parser read it: 'relation:<table>.<fk column>' or 'relation:<link table>'
        // => line. A search in the text by classes and label (RenameMarker::relation()) is ambiguous as soon as two
        // relationships between the same classes carry the same label - e.g. A "n" -- "1" B and A "1" -- "n" B (FK on
        // either side), or an n:1 next to an n:n: then no line was found and the suggestion was silently missing.
        // The parser's line numbers match RenameMarker's only without lone "\r" line breaks; then the search stays.
        $relLines = [];
        if (!preg_match('/\r(?!\n)/', $source)) {
            foreach ($json['relations'] as $i => $r) {
                $relLines[$r['id']] = $map['positions']['relations'][$i] ?? null;
            }
        }
        $relLine = function (string $id, string $class, string $target, string $label) use ($relLines, $loc): ?int {
            return array_key_exists($id, $relLines) ? $relLines[$id] : $loc->relation($class, $target, $label);
        };
        // declaring class of a (possibly inherited) field or media field
        $declaring = function (string $class, string $name, string $list) use ($classes): ?string {
            for ($c = $classes[$class] ?? null; $c !== null; $c = $c['extends'] !== null ? ($classes[$c['extends']] ?? null) : null) {
                foreach ($c[$list] as $f) {
                    if (strtolower($f['name']) === strtolower($name)) {
                        return $c['name'];
                    }
                }
            }
            return null;
        };
        $out = [];
        $add = function (string $key, string $kind, Msg $text, ?int $line, array $options) use (&$out, $loc) {
            if ($line === null || !$options || isset($out[$line])) {
                return;
            }
            $out[$line] = ['key' => $key, 'kind' => $kind] + $text->fields('text') + ['line' => $line + 1, 'original' => $loc->line($line),
                'options' => $options];
        };
        // option of a suggestion: label (English text + code/params for the UI), value and replacement line
        $option = function (string $value, Msg $label, string $replacement): array {
            return ['value' => $value] + $label->fields('label') + ['replacement' => $replacement];
        };
        $relLabel = function (string $l): Msg {
            return $l === '' ? Msg::of('msg_migration.no_label', '(no label)') : Msg::of('msg_migration.quoted_label', '"{label}"', ['label' => $l]);
        };

        // classes
        $removed = array_diff(array_keys($old['entities']), array_filter(array_values($id['entityMap'])));
        foreach ($new['entities'] as $t1 => $e1) {
            if ($id['entityMap'][$t1] !== null || !$removed) {
                continue;
            }
            $line = $loc->classHead($e1['name']);
            $add("entity:$t1", 'entity', Msg::of('msg_migration.suggest_entity', 'New class {class}', ['class' => $e1['name']]), $line,
                $line === null ? [] : array_values(array_map(function ($t0) use ($old, $loc, $line, $e1, $option) {
                    $name = $old['entities'][$t0]['name'];
                    return $option($name, Msg::of('msg_migration.option_class', 'Class {class}', ['class' => $name]),
                        $loc->classMarked($line, $e1['name'], "{renamed_from:$name}"));
                }, $removed)));
        }

        foreach ($new['entities'] as $t1 => $e1) {
            $t0 = $id['entityMap'][$t1];
            if ($t0 === null) {
                continue;
            }
            $e0 = $old['entities'][$t0];
            $gone = $id['removedColumns'][$t0] ?? [];
            // fields
            $goneFields = array_values(array_filter($gone, function ($f) {
                return !$f['foreign_key'] && !$f['primary'];
            }));
            foreach ($id['cols'][$t1] as $name => $c) {
                if ($c['from'] !== null || $c['field']['primary'] || $c['field']['foreign_key'] || !$goneFields) {
                    continue;
                }
                $decl = $declaring($e1['name'], $name, 'fields');
                $line = $decl !== null ? $loc->field($decl, $name) : null;
                $params = ['field' => "{$e1['name']}.$name", 'base' => $decl];
                $add("field:$t1." . strtolower($name), 'field', $decl !== $e1['name'] && $decl !== null
                    ? Msg::of('msg_migration.suggest_field_inherited', 'New field {field} (inherited from {base})', $params)
                    : Msg::of('msg_migration.suggest_field', 'New field {field}', $params), $line, $line === null ? [] : array_map(
                    function ($f0) use ($loc, $line, $option) {
                        return $option($f0['name'], Msg::of('msg_migration.option_field', 'Field {field} ({type})', [
                            'field' => $f0['name'], 'type' => $f0['type'],
                        ]), $loc->appended($line, "{renamed_from:{$f0['name']}}"));
                    }, $goneFields));
            }
            // media fields
            $usedMedia = array_column(array_filter($id['mediaMatch'][$t1] ?? []), 'name');
            $goneMedia = array_values(array_filter($e0['media'], function ($m) use ($usedMedia) {
                return !in_array($m['name'], $usedMedia, true);
            }));
            foreach ($e1['media'] as $i => $md1) {
                if ($id['mediaMatch'][$t1][$i] !== null || !$goneMedia) {
                    continue;
                }
                $decl = $declaring($e1['name'], $md1['name'], 'media');
                $line = $decl !== null ? $loc->field($decl, $md1['name']) : null;
                $add("media:$t1." . strtolower($md1['name']), 'media', Msg::of('msg_migration.media_added_short', 'New media field {field}', [
                    'field' => "{$e1['name']}.{$md1['name']}",
                ]), $line, $line === null ? [] : array_map(function ($m0) use ($loc, $line, $option) {
                    return $option($m0['name'], Msg::of('msg_migration.media_field', 'Media field {field}', ['field' => $m0['name']]),
                        $loc->appended($line, "{renamed_from:{$m0['name']}}"));
                }, $goneMedia));
            }
            // FK relationships (n:1, 1:1)
            foreach ($id['cols'][$t1] as $name => $c) {
                $fk = $c['field']['foreign_key'];
                if ($c['from'] !== null || !$fk) {
                    continue;
                }
                $cands = array_values(array_filter($gone, function ($f0) use ($fk, $id) {
                    return $f0['foreign_key'] && $this->mapsTo($f0['foreign_key']['table'], $id['entityMap']) === $fk['table']
                        && strpbrk($f0['foreign_key']['label'], '{}') === false;
                }));
                if (!$cands) {
                    continue;
                }
                $target = $new['entities'][$fk['table']]['name'];
                $line = $relLine("relation:$t1.$name", $e1['name'], $target, $fk['label']);
                $add("relation:$t1.$name", 'relation', Msg::of('msg_migration.suggest_relation', 'New relationship {class} → {target} {label}', [
                    'class' => $e1['name'], 'target' => $target, 'label' => $relLabel($fk['label']),
                ]), $line, $line === null ? [] : array_map(function ($f0) use ($loc, $line, $relLabel, $option) {
                    return $option($f0['foreign_key']['label'], Msg::of('msg_migration.option_relation', 'Relationship {label} (column {column})', [
                        'label' => $relLabel($f0['foreign_key']['label']), 'column' => $f0['name'],
                    ]), $loc->appended($line, "{renamed_from:{$f0['foreign_key']['label']}}"));
                }, $cands));
            }
            // n:n
            $usedNn = array_column(array_filter($id['nnMatch'][$t1] ?? []), 'junction');
            foreach ($e1['many_to_many'] as $i => $m1) {
                if ($id['nnMatch'][$t1][$i] !== null) {
                    continue;
                }
                $cands = array_values(array_filter($e0['many_to_many'], function ($m0) use ($m1, $id, $usedNn) {
                    return !in_array($m0['junction'], $usedNn, true) && $this->mapsTo($m0['table'], $id['entityMap']) === $m1['table']
                        && !$this->nnUsedElsewhere($m0['junction'], $id) && strpbrk($m0['label'], '{}') === false;
                }));
                if (!$cands) {
                    continue;
                }
                $target = $new['entities'][$m1['table']]['name'];
                $line = $relLine("relation:{$m1['junction']}", $e1['name'], $target, $m1['label']);
                $add("nn:{$m1['junction']}", 'nn', Msg::of('msg_migration.suggest_nn', 'New n:n relationship {class} – {target} {label}', [
                    'class' => $e1['name'], 'target' => $target, 'label' => $relLabel($m1['label']),
                ]), $line, $line === null ? [] : array_map(function ($m0) use ($loc, $line, $relLabel, $option) {
                    return $option($m0['label'], Msg::of('msg_migration.option_nn', 'n:n relationship {label}', ['label' => $relLabel($m0['label'])]),
                        $loc->appended($line, "{renamed_from:{$m0['label']}}"));
                }, $cands));
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Is the previous link table already matched to an n:n of any class? */
    private function nnUsedElsewhere(string $junction, array $id): bool
    {
        foreach ($id['nnMatch'] as $matches) {
            foreach ($matches as $m0) {
                if ($m0 !== null && $m0['junction'] === $junction) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Translates the matching (identify()) into keys of the model JSON (see SchemaIds): new key => possible
     * previous keys (empty = new). Concrete classes, fields, FK relationships, n:n and media fields follow the
     * matching (name or {renamed_from}, target class + label). Abstract classes have no table and no
     * {renamed_from}: they keep their ID via the name; their fields and media fields via the matching of a
     * matched concrete subclass (they are columns there), without a subclass via name or {renamed_from}. Enums
     * via the name (enums cannot be renamed).
     *
     * @return array<string,string[]>
     */
    private static function stableKeys(array $id, array $new, array $modelJson): array
    {
        $tableOf = [];
        $parent = [];
        foreach ($modelJson['entities'] as $e) {
            $tableOf[$e['name']] = $e['table'];
        }
        foreach ($modelJson['entities'] as $e) {
            $parent[$e['table']] = $e['extends'] !== null ? $tableOf[$e['extends']] : null;
        }
        // matched concrete class through which the columns of a class run (the class itself or a subclass)
        $via = function (array $e) use ($id, $parent): ?string {
            if (!$e['abstract']) {
                return $id['entityMap'][$e['table']] !== null ? $e['table'] : null;
            }
            foreach ($id['entityMap'] as $t1 => $t0) {
                for ($t = $parent[$t1] ?? null; $t0 !== null && $t !== null; $t = $parent[$t]) {
                    if ($t === $e['table']) {
                        return $t1;
                    }
                }
            }
            return null;
        };
        $out = [];
        foreach ($modelJson['entities'] as $e) {
            $t = $e['table'];
            $t0 = $e['abstract'] ? $t : $id['entityMap'][$t];
            $out[$e['id']] = $t0 !== null ? ["entity:$t0"] : [];
            $d = $t0 !== null ? $via($e) : null;
            foreach ($e['fields'] as $f) {
                if ($d !== null) {
                    $c0 = array_search($f['name'], $id['colMaps'][$d], true);
                    $out[$f['id']] = $c0 !== false ? ["field:$t0." . strtolower((string) $c0)] : [];
                } else {
                    $out[$f['id']] = $t0 !== null ? self::byName('field', $t0, $f) : [];
                }
            }
            foreach ($e['media'] as $m) {
                $out[$m['id']] = [];
                if ($d === null) {
                    $out[$m['id']] = $t0 !== null ? self::byName('media', $t0, $m) : [];
                    continue;
                }
                foreach ($new['entities'][$d]['media'] as $i => $md1) {
                    if ($md1['name'] === $m['name'] && $id['mediaMatch'][$d][$i] !== null) {
                        $out[$m['id']] = ["media:$t0." . strtolower($id['mediaMatch'][$d][$i]['name'])];
                    }
                }
            }
        }
        foreach ($modelJson['relations'] as $r) {
            $owner = $tableOf[$r['from_entity']];
            $t0 = $id['entityMap'][$owner];
            $out[$r['id']] = [];
            if ($t0 === null) {
                continue;
            }
            if ($r['kind'] === 'nn') {
                foreach ($new['entities'][$owner]['many_to_many'] as $i => $m1) {
                    if ($m1['junction'] === $r['junction'] && $id['nnMatch'][$owner][$i] !== null) {
                        $out[$r['id']] = ['relation:' . $id['nnMatch'][$owner][$i]['junction']];
                    }
                }
            } else {
                $c0 = array_search($r['own_column'], $id['colMaps'][$owner], true);
                $out[$r['id']] = $c0 !== false ? ["relation:$t0.$c0"] : [];
            }
        }
        foreach ($modelJson['enums'] as $en) {
            $out[$en['id']] = [$en['id']];
        }
        // metadata classes (<<media_meta>>): no table, so via the name or {renamed_from} of the class; their fields via the
        // name or {renamed_from} within the (possibly renamed) class
        foreach ($modelJson['media_meta'] ?? [] as $mc) {
            $classes = array_values(array_unique(array_filter([strtolower($mc['name']), strtolower((string) $mc['renamed_from'])], 'strlen')));
            $out[$mc['id']] = array_map(function ($c) {
                return "media_meta:$c";
            }, $classes);
            foreach ($mc['fields'] as $f) {
                $out[$f['id']] = [];
                foreach ($classes as $c) {
                    foreach (array_filter([strtolower($f['name']), strtolower((string) ($f['renamed_from'] ?? ''))], 'strlen') as $n) {
                        $out[$f['id']][] = "media_meta_field:$c.$n";
                    }
                }
            }
        }
        return $out;
    }

    /** Field/media field of a class without a column mapping: own name, otherwise {renamed_from} (like mapColumns()) */
    private static function byName(string $kind, string $table, array $f): array
    {
        $keys = ["$kind:$table." . strtolower($f['name'])];
        if (($f['renamed_from'] ?? null) !== null) {
            $keys[] = "$kind:$table." . strtolower($f['renamed_from']);
        }
        return $keys;
    }

    /**
     * New table -> old table (or null = new). {renamed_from} only counts if the new name does not yet exist in the old
     * model (otherwise the marker has already been applied and is ignored).
     *
     * @return array<string,?string>
     */
    private function mapEntities(array $new, array $old): array
    {
        $map = [];
        $claimed = [];
        foreach ($new['entities'] as $t1 => $e1) {
            $src = isset($old['entities'][$t1]) ? $t1 : null;
            if (isset($e1['renamed_from'])) {
                $r = strtolower($e1['renamed_from']);
                if ($src === null) {
                    if (!isset($old['entities'][$r])) {
                        throw self::schemaError(Msg::of('msg_migration.renamed_unknown', "Class {class} {renamed_from:{from}}: there is "
                            . "no class '{from}' in the current schema.", ['class' => $e1['name'], 'from' => $e1['renamed_from']]));
                    }
                    $src = $r;
                } elseif ($r !== $t1 && isset($old['entities'][$r])
                    && !(isset($new['entities'][$r]) && !isset($new['entities'][$r]['renamed_from']))) {
                    // both names exist so far (also: two classes swapping names) - a silently ignored marker would be
                    // just as wrong as a guessed matching. Not if the new text still has a plain class of the old name: then
                    // both classes keep their data by name and the marker is merely outdated (blocker, see markerStates())
                    throw self::schemaError(Msg::of('msg_migration.renamed_both', 'Class {class} {renamed_from:{from}}: both classes '
                        . 'exist so far - unclear which data should apply (swapping names is not supported). Please rename in two steps '
                        . 'or remove the marker.', ['class' => $e1['name'], 'from' => $e1['renamed_from']]));
                }
            }
            if ($src !== null && isset($claimed[$src])) {
                throw self::schemaError(Msg::of('msg_migration.renamed_claimed', "The current class '{old}' is claimed by {first} and "
                    . '{second} (rename and new class of the same name). Please make this unambiguous.', [
                        'old' => $old['entities'][$src]['name'], 'first' => $claimed[$src], 'second' => $e1['name'],
                    ]));
            }
            if ($src !== null) {
                $claimed[$src] = $e1['name'];
            }
            $map[$t1] = $src;
        }
        return $map;
    }

    /**
     * Previous relationship for a new one: candidates [key => old label] (same class, same target class,
     * still unassigned). Without {renamed_from} via the same (normalized) label, with the marker via the old label. As
     * for fields: if the new label already exists, the marker has no effect (already applied) - unless the old one exists
     * as well (ambiguous, error); if it points at nothing, that is a schema error.
     *
     * @param array<int|string,string> $cands
     * @param string[] $siblings normalized labels of the other new relationships of the same kind to the same target that
     *        carry no marker themselves: if one of them has the old label, it keeps the old relationship by its label and
     *        the marker is merely outdated (blocker, see markerStates()) instead of ambiguous
     * @return int|string|null
     */
    private static function matchRelation(array $cands, string $label, ?string $renamedFrom, Msg $what, array $siblings = [])
    {
        $find = function (string $l) use ($cands) {
            foreach ($cands as $k => $cl) {
                if (PumlParser::normalizeLabel($cl) === PumlParser::normalizeLabel($l)) {
                    return $k;
                }
            }
            return null;
        };
        $own = $find($label);
        if ($renamedFrom === null) {
            return $own;
        }
        $old = $find($renamedFrom);
        if ($own !== null) {
            if ($old !== null && $old !== $own && !in_array(PumlParser::normalizeLabel($renamedFrom), $siblings, true)) {
                throw self::schemaError(Msg::of('msg_migration.relation_both', '{what} {marker}: relationships with the old and the new '
                    . 'label both exist so far - unclear which values should apply. Please rename in two steps or remove the marker.', [
                        'what' => $what, 'marker' => '{renamed_from:' . $renamedFrom . '}',
                    ]));
            }
            return $own;
        }
        if ($old === null) {
            $params = ['what' => $what, 'marker' => '{renamed_from:' . $renamedFrom . '}', 'label' => $renamedFrom,
                'available' => $cands ? Msg::commaList(array_map(function ($l) {
                    return $l === '' ? Msg::of('msg_migration.no_label', '(no label)') : Msg::of('msg_migration.single_quoted', "'{label}'", ['label' => $l]);
                }, array_values($cands))) : ''];
            if ($renamedFrom === '') {
                throw self::schemaError($cands
                    ? Msg::of('msg_migration.relation_none_unlabelled_avail', '{what} {marker}: a previous relationship without a label '
                        . 'does not exist (available: {available}).', $params)
                    : Msg::of('msg_migration.relation_none_unlabelled', '{what} {marker}: a previous relationship without a label does '
                        . 'not exist.', $params));
            }
            throw self::schemaError($cands
                ? Msg::of('msg_migration.relation_none_avail', "{what} {marker}: a previous relationship with the label '{label}' does "
                    . 'not exist (available: {available}).', $params)
                : Msg::of('msg_migration.relation_none', "{what} {marker}: a previous relationship with the label '{label}' does not "
                    . 'exist.', $params));
        }
        return $old;
    }

    /** new target table for an old one (null = old table removed) */
    private function mapsTo(string $oldTable, array $entityMap): ?string
    {
        $t = array_search($oldTable, $entityMap, true);
        return $t === false ? null : (string) $t;
    }

    /**
     * Column mapping of an entity: [new column => ['from' => old column|null, 'field' => new field, 'old' => old
     * field|null]] and [old column => new column] (for the users' column selection).
     */
    private function mapColumns(array $e1, array $e0, array &$p): array
    {
        $oldByName = [];
        $oldFks = [];
        foreach ($e0['fields'] as $f) {
            if ($f['foreign_key']) {
                $oldFks[] = $f;
            } else {
                $oldByName[strtolower($f['name'])] = $f;
            }
        }
        $cols = [];
        $used = [];
        foreach ($e1['fields'] as $f1) {
            $from = null;
            if ($f1['primary']) {
                $from = $oldByName['id'] ?? null;
            } elseif ($f1['foreign_key']) {
                // candidates: previous, still unassigned FKs of this class to the same (possibly renamed) target class
                $cands = [];
                foreach ($oldFks as $i => $f0) {
                    if (!isset($used[$f0['name']])
                        && $this->mapsTo($f0['foreign_key']['table'], $p['entityMap']) === $f1['foreign_key']['table']) {
                        $cands[$i] = $f0['foreign_key']['label'];
                    }
                }
                $target = $p['model']['entities'][$f1['foreign_key']['table']]['name'];
                $what = Msg::of('msg_migration.relation_what', 'Relationship {class} -> {target}', ['class' => $e1['name'], 'target' => $target]);
                $siblings = [];
                foreach ($e1['fields'] as $g) {
                    if ($g['foreign_key'] && $g['name'] !== $f1['name'] && $g['foreign_key']['table'] === $f1['foreign_key']['table']
                        && !isset($g['foreign_key']['renamed_from'])) {
                        $siblings[] = PumlParser::normalizeLabel($g['foreign_key']['label']);
                    }
                }
                $k = self::matchRelation($cands, $f1['foreign_key']['label'], $f1['foreign_key']['renamed_from'] ?? null, $what, $siblings);
                $from = $k !== null ? $oldFks[$k] : null;
                if ($from !== null && PumlParser::normalizeLabel($from['foreign_key']['label'])
                    !== PumlParser::normalizeLabel($f1['foreign_key']['label'])) {
                    $p['changes'][] = self::change('relation_renamed', 'info', Msg::of('msg_migration.relation_renamed', "{what} renamed: "
                        . "label '{old}' -> '{new}' (column {old_column} -> {column}, values are kept).", [
                            'what' => $what, 'old' => $from['foreign_key']['label'], 'new' => $f1['foreign_key']['label'],
                            'old_column' => $from['name'], 'column' => $f1['name'],
                        ]));
                }
            } else {
                $own = $oldByName[strtolower($f1['name'])] ?? null;
                if (isset($f1['renamed_from']) && $own === null) {
                    $from = $oldByName[strtolower($f1['renamed_from'])] ?? null;
                    if ($from === null) {
                        throw self::schemaError(Msg::of('msg_migration.field_from_missing', "Field {field} {marker}: a field '{from}' does "
                            . 'not exist in {class} so far.', [
                                'field' => "{$e1['name']}.{$f1['name']}", 'marker' => '{renamed_from:' . $f1['renamed_from'] . '}',
                                'from' => $f1['renamed_from'], 'class' => $e0['name'],
                            ]));
                    }
                    $p['changes'][] = self::change('field_renamed', 'info', Msg::of('msg_migration.field_renamed', 'Field {field} renamed to '
                        . '{name} (values are kept).', ['field' => "{$e1['name']}.{$from['name']}", 'name' => $f1['name']]));
                } else {
                    // (a plain field of the old name in the new text keeps its values by name: outdated marker, markerStates())
                    if ($own !== null && isset($f1['renamed_from']) && strtolower($f1['renamed_from']) !== strtolower($f1['name'])
                        && isset($oldByName[strtolower($f1['renamed_from'])]) && !self::plainField($e1, $f1['renamed_from'])) {
                        throw self::schemaError(Msg::of('msg_migration.field_both', 'Field {field} {marker}: both fields exist so far - '
                            . 'unclear which values should apply (swapping names is not supported). Please rename in two steps or remove '
                            . 'the marker.', ['field' => "{$e1['name']}.{$f1['name']}", 'marker' => '{renamed_from:' . $f1['renamed_from'] . '}']));
                    }
                    $from = $own;
                }
            }
            if ($from !== null && isset($used[$from['name']])) {
                throw self::schemaError(Msg::of('msg_migration.field_claimed', 'The previous field {field} is claimed by two fields.', [
                    'field' => "{$e0['name']}.{$from['name']}",
                ]));
            }
            if ($from !== null) {
                $used[$from['name']] = true;
            }
            $cols[$f1['name']] = ['from' => $from !== null ? $from['name'] : null, 'field' => $f1, 'old' => $from];
        }
        $colMap = [];
        foreach ($cols as $name => $c) {
            if ($c['from'] !== null) {
                $colMap[$c['from']] = $name;
            }
        }
        // removed columns (no longer matched)
        foreach ($e0['fields'] as $f0) {
            if (!isset($used[$f0['name']])) {
                $p['removedColumns'][$e0['table']][] = $f0;
            }
        }
        return [$cols, $colMap];
    }

    /** Does the class have a field (no FK column) of this name that carries no {renamed_from} marker itself? */
    private static function plainField(array $e, string $name): bool
    {
        foreach ($e['fields'] as $f) {
            if (!$f['foreign_key'] && strtolower($f['name']) === strtolower($name) && !isset($f['renamed_from'])) {
                return true;
            }
        }
        return false;
    }

    /** Media field in the old model for a new one (name or {renamed_from}) */
    private function matchMedia(array $md1, array $e0, array $e1): ?array
    {
        $find = function (string $name) use ($e0) {
            foreach ($e0['media'] as $m) {
                if (strtolower($m['name']) === strtolower($name)) {
                    return $m;
                }
            }
            return null;
        };
        $own = $find($md1['name']);
        if ($own !== null || !isset($md1['renamed_from'])) {
            return $own;
        }
        $from = $find($md1['renamed_from']);
        if ($from === null) {
            throw self::schemaError(Msg::of('msg_migration.media_from_missing', "Media field {field} {marker}: a media field '{from}' "
                . 'does not exist in {class} so far.', [
                    'field' => "{$e1['name']}.{$md1['name']}", 'marker' => '{renamed_from:' . $md1['renamed_from'] . '}',
                    'from' => $md1['renamed_from'], 'class' => $e0['name'],
                ]));
        }
        return $from;
    }

    /**
     * Checks per column of a matched entity: removed columns with values (confirmation), new/changed fields
     * (notice), type/enum changes with values that cannot be converted (blocker), empty required values (backfill), new
     * uniqueness with duplicates (blocker). Adds cols[...]['backfill'] and ['convert'].
     */
    private function checkColumns(string $t1, array $e1, string $t0, array $e0, array &$cols, array &$p, string $idCol = '"id"'): void
    {
        $rows = $this->count($t0);
        foreach ($p['removedColumns'][$t0] ?? [] as $f0) {
            $n = $this->nonEmpty($t0, $f0['name']);
            $label = self::fieldLabel($e0['name'], $f0);
            $p['changes'][] = self::change('field_removed', $n ? 'confirm' : 'warning', Msg::of('msg_migration.field_removed',
                '{what} removed ({count} row(s) with a value).', ['what' => $label, 'count' => $n]));
            if ($n) {
                $p['confirmations'][] = ['key' => "drop_column:$t0.{$f0['name']}", 'count' => $n] + Msg::of('msg_migration.confirm_values',
                    "I confirm that the values of {count} row(s) in '{field}' will be lost.", [
                        'count' => $n, 'field' => "{$e0['name']}.{$f0['name']}",
                    ])->fields('text');
            }
        }
        foreach ($cols as $name => &$c) {
            $f1 = $c['field'];
            $f0 = $c['old'];
            if ($f1['primary']) {
                continue;
            }
            $label = self::fieldLabel($e1['name'], $f1);
            if ($f0 === null) {
                $params = ['field' => "{$e1['name']}.$name", 'type' => $f1['type'],
                    'target' => $f1['foreign_key'] ? $p['model']['entities'][$f1['foreign_key']['table']]['name'] : null];
                if ($f1['type'] === PumlParser::SORT_TYPE) {
                    $added = Msg::of('msg_migration.sort_added', 'New sort field {field} (manual order): the existing rows are numbered '
                        . 'per group in the order of their IDs.', $params);
                } elseif ($f1['foreign_key']) {
                    $added = $f1['required']
                        ? Msg::of('msg_migration.relation_added_required', 'New required relationship {field} -> {target}.', $params)
                        : Msg::of('msg_migration.relation_added_optional', 'New optional relationship {field} -> {target}.', $params);
                } else {
                    $added = $f1['required']
                        ? Msg::of('msg_migration.field_added_required', 'New required field {field} ({type}).', $params)
                        : Msg::of('msg_migration.field_added_optional', 'New optional field {field} ({type}).', $params);
                }
                $p['changes'][] = self::change('field_added', 'info', $added);
            }
            // type change / enum values: every existing value must be valid under the new field rules
            $c['convert'] = $f0 !== null && !$f1['foreign_key'] && self::typeSignature($f0) !== self::typeSignature($f1);
            if ($c['convert']) {
                $p['changes'][] = self::change('field_type', 'info', Msg::of('msg_migration.field_type', '{what}: {old} -> {new}.', [
                    'what' => $label, 'old' => self::typeLabel($f0), 'new' => self::typeLabel($f1),
                ]));
                $bad = [];
                $total = 0;
                $stmt = $this->pdo->query("SELECT $idCol AS id, " . SqlGenerator::q($f0['name']) . ' AS v FROM ' . SqlGenerator::q($t0)
                    . ' WHERE ' . SqlGenerator::q($f0['name']) . " IS NOT NULL ORDER BY $idCol");
                foreach ($stmt as $r) {
                    try {
                        Cms::coerce($f1, self::raw($f1, $r['v']));
                    } catch (\InvalidArgumentException $e) {
                        $total++;
                        if (count($bad) < self::LIST_LIMIT) {
                            $problem = ValueException::msgOf($e);
                            $bad[] = ['id' => (int) $r['id'], 'value' => $r['v'], 'problem' => $problem->text(),
                                'problem_code' => $problem->codeOnly()];
                        }
                    }
                }
                if ($total) {
                    $p['blockers'][] = ['key' => "convert:$t1.$name", 'count' => $total, 'rows' => $bad]
                        + Msg::of('msg_migration.convert_blocked', '{what}: {count} existing value(s) do not fit {type}. Please adjust the '
                            . 'data or change the diagram.', ['what' => $label, 'count' => $total, 'type' => self::typeLabel($f1)])->fields('text');
                }
                if (isset($f0['workflow']) !== isset($f1['workflow'])) {
                    $p['changes'][] = self::change('field_workflow', 'info', self::workflowFieldChange($label, isset($f1['workflow'])));
                }
                if ($f1['type'] === PumlParser::SORT_TYPE) {
                    $p['changes'][] = self::change('sort_converted', 'info', Msg::of('msg_migration.sort_converted', '{what}: the existing '
                        . 'values become the manual order - numbered without gaps per group (equal values by ID, empty values at the '
                        . 'end of their group).', ['what' => $label]));
                }
            } elseif ($f0 !== null && isset($f0['workflow']) !== isset($f1['workflow'])) {
                $p['changes'][] = self::change('field_workflow', 'info', self::workflowFieldChange($label, isset($f1['workflow'])));
            } elseif ($f0 !== null && $f0['foreign_key'] && $f1['foreign_key']
                && !empty($f0['foreign_key']['composition']) !== !empty($f1['foreign_key']['composition'])) {
                $this->compositionChange($t1, $e1, $t0, $e0, $f0, $f1, $label, $c, $p, $idCol);
            } elseif ($f0 !== null && $f0['required'] !== $f1['required']) {
                $p['changes'][] = self::change('field_required', 'info', $f1['required']
                    ? Msg::of('msg_migration.now_required', '{what}: now required.', ['what' => $label])
                    : Msg::of('msg_migration.now_optional', '{what}: now optional.', ['what' => $label]));
            } elseif ($f0 !== null && $f1['foreign_key'] && self::fkSignature($f0) !== self::fkSignature($f1)) {
                $p['changes'][] = self::change('fk_changed', 'info', Msg::of('msg_migration.fk_changed', '{what}: delete behaviour/1:1 '
                    . 'changed ({old} -> {new}).', ['what' => $label, 'old' => self::fkSignature($f0), 'new' => self::fkSignature($f1)]));
            }
            if ($f0 !== null && !$f1['foreign_key']) {
                $this->ruleChanges($t0, $f0, $f1, $label, (bool) $c['convert'], $p, $idCol);
            }
            // minimum count ("1..*" on the n side): soft rule, the table stays the same - only the model changes
            if ($f0 !== null && $f1['foreign_key'] && $f0['foreign_key']
                && !empty($f0['foreign_key']['min_required']) !== !empty($f1['foreign_key']['min_required'])) {
                $parent = $p['model']['entities'][$f1['foreign_key']['table']]['name'];
                $p['changes'][] = self::change('fk_min', 'info', !empty($f1['foreign_key']['min_required'])
                    ? Msg::of('msg_migration.min_added', '{what}: minimum count wanted (every row in {parent} should have at least one row '
                        . 'in {class}) - existing rows without one are only marked, nothing is changed.', [
                            'what' => $label, 'parent' => $parent, 'class' => $e1['name'],
                        ])
                    : Msg::of('msg_migration.min_removed', '{what}: minimum count dropped.', ['what' => $label]));
            }
            if ($f0 !== null && $f1['foreign_key'] && $f0['foreign_key']
                && ($f0['foreign_key']['filter_by'] ?? null) !== ($f1['foreign_key']['filter_by'] ?? null)) {
                $p['changes'][] = self::change('filter_by', 'info', Msg::of('msg_migration.what_colon', '{what}: {text}', [
                    'what' => $label, 'text' => self::filterChange($f1['foreign_key']['filter_by'] ?? null),
                ]));
            }
            // permission conflict: a denied field becomes required, or a (new) required relationship to a denied entity
            if ($f1['required'] && ($f0 === null || !$f0['required'])) {
                if ($f0 !== null) {
                    $this->permissionConflict($p, $t0, $f0['name'], null, Msg::of('msg_migration.becomes_required_denied', '{what} becomes '
                        . 'required but is denied', ['what' => $label]), $e1['name']);
                }
                $target = $f1['foreign_key'] ? ($p['entityMap'][$f1['foreign_key']['table']] ?? null) : null;
                if ($target !== null) {
                    $params = ['field' => "{$e1['name']}.$name", 'what' => $label,
                        'target' => $p['model']['entities'][$f1['foreign_key']['table']]['name']];
                    $this->permissionConflict($p, $t0, null, $target, $f0 === null
                        ? Msg::of('msg_migration.new_relation_denied', 'New required relationship {field} points to the entity {target}, '
                            . 'which is denied', $params)
                        : Msg::of('msg_migration.relation_required_denied', '{what} becomes required and points to the entity {target}, '
                            . 'which is denied', $params), $e1['name']);
                }
            }
            // empty required values -> backfill (new required column, optional -> required, type change with NULL values)
            if ($f1['required'] && $rows) {
                $empty = $f0 === null ? $rows
                    : (int) $this->pdo->query('SELECT COUNT(*) FROM ' . SqlGenerator::q($t0) . ' WHERE ' . SqlGenerator::q($f0['name'])
                        . ' IS NULL')->fetchColumn();
                if ($empty && $f1['type'] === PumlParser::SORT_TYPE) {
                    // sort field: rows without a value are numbered at the end of their group (see sortChanges(), execute())
                    $c['auto'] = Sorting::UNSET;
                } elseif ($empty && isset($f1['workflow'])) {
                    // workflow field: existing rows automatically start in the initial state (no backfill dialog)
                    $c['auto'] = $p['model']['workflows'][$f1['workflow']]['initial'];
                    $p['changes'][] = self::change('workflow_backfill', 'info', Msg::of('msg_migration.workflow_backfill', '{what}: {count} '
                        . "existing row(s) automatically get the initial state '{state}'.", ['what' => $label, 'count' => $empty, 'state' => $c['auto']]));
                } elseif ($empty && empty($c['composition_orphans'])) {
                    $this->requireBackfill($t1, $e1, $f1, $empty, $p, $c);
                }
            }
        }
        unset($c);
        // new uniqueness ({unique} group, 1:1): existing data must not contain duplicates
        $oldGroups = [];
        foreach (SqlGenerator::uniqueGroups($e0) as $g) {
            $oldGroups[] = array_map(function ($col) use ($p, $t1) {
                return $p['colMaps'][$t1][$col] ?? null;
            }, $g);
        }
        foreach (SqlGenerator::uniqueGroups($e1) as $g) {
            if (in_array($g, $oldGroups, true) || $rows < 2) {
                continue; // unchanged (the data already satisfies it) or at most one row
            }
            // New columns: without a backfill they stay NULL (NULL never counts as a duplicate) -> the group cannot be violated;
            // with a backfill all rows have the same value -> the remaining columns of the group are what matters
            $sources = [];
            $names = [];
            foreach ($g as $col) {
                $c = $cols[$col];
                if ($c['from'] === null) {
                    if (empty($c['backfill'])) {
                        continue 2;
                    }
                    continue;
                }
                $sources[] = $c['from'];
                $names[] = $col;
            }
            if (!$sources) {
                $p['blockers'][] = ['key' => "unique:$t1." . implode('+', $g), 'count' => $rows, 'rows' => []]
                    + Msg::of('msg_migration.unique_new_columns', '{class}: the new uniqueness ({columns}) consists only of new required '
                        . 'columns - a common backfill value for {count} existing rows would inevitably be duplicated. Please first create the '
                        . 'column(s) as optional, fill them and only then make them required or unique.', [
                            'class' => $e1['name'], 'columns' => implode(', ', $g), 'count' => $rows,
                        ])->fields('text');
                continue;
            }
            $q = implode(', ', array_map([SqlGenerator::class, 'q'], $sources));
            $notNull = implode(' AND ', array_map(function ($s) {
                return SqlGenerator::q($s) . ' IS NOT NULL';
            }, $sources));
            $dups = $this->pdo->query("SELECT $q, COUNT(*) AS n, GROUP_CONCAT(\"id\") AS ids FROM " . SqlGenerator::q($t0)
                . " WHERE $notNull GROUP BY $q HAVING COUNT(*) > 1 ORDER BY MIN(\"id\")")->fetchAll();
            if ($dups) {
                $list = [];
                foreach (array_slice($dups, 0, self::LIST_LIMIT) as $d) {
                    $values = [];
                    foreach ($sources as $i => $s) {
                        $values[$names[$i]] = $d[$s];
                    }
                    $list[] = ['ids' => array_map('intval', explode(',', $d['ids'])), 'values' => $values];
                }
                $p['blockers'][] = ['key' => "unique:$t1." . implode('+', $g), 'count' => count($dups), 'rows' => $list]
                    + Msg::of('msg_migration.unique_violated', '{class}: the new uniqueness ({columns}) is violated in the existing data - '
                        . '{count} value combination(s) occur more than once. Please clean up the duplicates or leave out the marker.', [
                            'class' => $e1['name'], 'columns' => implode(', ', $g), 'count' => count($dups),
                        ])->fields('text');
            } else {
                $p['changes'][] = self::change('unique_added', 'info', Msg::of('msg_migration.unique_added', '{class}: new uniqueness '
                    . '({columns}) - existing data fits.', ['class' => $e1['name'], 'columns' => implode(', ', $g)]));
            }
        }
    }

    /**
     * Changed field rules (Validation): only a notice, never a blocker - existing records are not changed. Severity 'warning'
     * with the number of existing records that violate the new rules ('count', first IDs in 'ids'), otherwise 'info'. Values
     * that cannot be converted for a type change are left out here (the type change blocks for them anyway).
     */
    private function ruleChanges(string $t0, array $f0, array $f1, Msg $label, bool $convert, array &$p, string $idCol = '"id"'): void
    {
        $r0 = $f0['rules'] ?? [];
        $r1 = $f1['rules'] ?? [];
        if ($r0 === $r1) {
            return;
        }
        $none = Msg::of('msg_migration.rules_none', '(none)');
        $rules = ['what' => $label, 'old' => $r0 ? trim(Validation::markers($r0)) : $none, 'new' => $r1 ? trim(Validation::markers($r1)) : $none];
        $ids = [];
        $seen = [];
        $count = 0;
        if ($r1) {
            $validation = new Validation($this->pdo);
            $col = SqlGenerator::q($f0['name']);
            $stmt = $this->pdo->query("SELECT $idCol AS id, $col AS v FROM " . SqlGenerator::q($t0) . " WHERE $col IS NOT NULL AND $col <> '' ORDER BY $idCol");
            foreach ($stmt as $r) {
                try {
                    $value = $convert ? Cms::coerce($f1, self::raw($f1, $r['v'])) : $r['v'];
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
                if ($validation->check($f1, $value) && !isset($seen[(int) $r['id']])) {
                    // a record counts once (a link table can hold several links of the same record)
                    $seen[(int) $r['id']] = true;
                    $count++;
                    if (count($ids) < self::LIST_LIMIT) {
                        $ids[] = (int) $r['id'];
                    }
                }
            }
        }
        $params = $rules + ['count' => $count, 'ids' => '#' . implode(', #', $ids) . ($count > count($ids) ? ', …' : '')];
        if ($count === 1) {
            $text = Msg::of('msg_migration.rules_violated_one', '{what}: rules {old} -> {new}. 1 existing record violates the new rules '
                . '({ids}). It is not changed; after applying it is listed under "Rule violations" and can only be saved again once it is '
                . 'valid.', $params);
        } elseif ($count) {
            $text = Msg::of('msg_migration.rules_violated', '{what}: rules {old} -> {new}. {count} existing records violate the new rules '
                . '({ids}). They are not changed; after applying they are listed under "Rule violations" and can only be saved again once '
                . 'they are valid.', $params);
        } else {
            $text = Msg::of('msg_migration.rules_changed', '{what}: rules {old} -> {new}.', $params);
        }
        $p['changes'][] = self::change('rules_changed', $count ? 'warning' : 'info', $text) + ['count' => $count, 'ids' => $ids];
    }

    /**
     * Notice "Berechtigungs-Konflikt" if the active schema has a denial (deny) on the property $prop of table
     * $t0 or on the entity $target: the affected users cannot fill in the future required field (it is not shown to
     * them at all, or its selection cannot be loaded). Owners who have also denied $t0 itself
     * do not count - they do not create anything there anyway.
     */
    private function permissionConflict(array &$p, string $t0, ?string $prop, ?string $target, Msg $what, string $entity): void
    {
        $owners = array_diff_key(
            $prop !== null ? $p['denials']['fields'][$t0][$prop] ?? [] : $p['denials']['entities'][$target] ?? [],
            $p['denials']['entities'][$t0] ?? []
        );
        if ($owners) {
            $p['changes'][] = self::change('permission_conflict', 'permission', Msg::of('msg_migration.permission_conflict', '{what} '
                . '({owners}) – affected users can then no longer create new records in {entity}.', [
                    'what' => $what, 'owners' => Msg::commaList($owners), 'entity' => $entity,
                ]));
        }
    }

    /**
     * Notice "Berechtigungs-Konflikt" for a (future) required media field: whoever has no access to the system area
     * 'media' cannot select a medium (see Permissions::mediaBlocked()). $t0 = null: new entity.
     */
    private function mediaConflict(array &$p, ?string $t0, Msg $what, string $entity): void
    {
        $owners = ($p['mediaBlocked'])($t0);
        if ($owners) {
            $more = count($owners) - self::LIST_LIMIT;
            $params = ['what' => $what, 'owners' => Msg::commaList(array_slice($owners, 0, self::LIST_LIMIT)), 'more' => $more,
                'entity' => $entity];
            $p['changes'][] = self::change('permission_conflict', 'permission', $more > 0
                ? Msg::of('msg_migration.media_conflict_more', "{what} cannot be filled without access to the system area 'media' "
                    . '({owners} and {more} more) – affected users can then no longer create new records in {entity}.', $params)
                : Msg::of('msg_migration.media_conflict', "{what} cannot be filled without access to the system area 'media' ({owners}) "
                    . '– affected users can then no longer create new records in {entity}.', $params));
        }
    }

    /**
     * Sort fields (manual order, see Sorting) whose numbering has to be made anew after the change: a new field, one that
     * was another type before (int -> sort), and one whose groups changed - other {by: …} relationships, or tables that
     * did not share the order before (a subclass with its own numbering joins an abstract class). The relative order of the
     * rows is kept (previous value, then ID). A field that only changes its name, or a set that only loses a table, keeps
     * its values.
     *
     * @return array[] one sort field per affected set of tables (argument of Sorting::renumber())
     */
    private function sortChanges(array &$p, array $id): array
    {
        $out = [];
        $done = [];
        foreach ($p['model']['entities'] as $t1 => $e1) {
            $f1 = Sorting::fieldOf($e1);
            if ($f1 === null || isset($done[$t1])) {
                continue;
            }
            $renumber = false;
            $regrouped = false;
            foreach ($f1['sort']['tables'] as $t) {
                $done[$t] = true;
                $t0 = $p['entityMap'][$t] ?? null;
                if ($t0 === null) {
                    continue; // new class: no rows yet
                }
                $g1 = Sorting::fieldOf($p['model']['entities'][$t]);
                $c = $id['cols'][$t][$g1['name']] ?? null;
                $f0 = $c['old'] ?? null;
                if ($f0 === null || $f0['type'] !== PumlParser::SORT_TYPE || !isset($f0['sort'])) {
                    $renumber = true; // new or converted (reported with the field)
                    continue;
                }
                $by = array_map(function ($col) use ($id, $t) {
                    return $id['cols'][$t][$col]['from'] ?? null;
                }, $g1['sort']['by']);
                $shared = array_filter(array_map(function ($x) use ($p) {
                    return $p['entityMap'][$x] ?? null;
                }, $g1['sort']['tables']));
                if ($by !== $f0['sort']['by'] || array_diff($shared, $f0['sort']['tables'])) {
                    $renumber = true;
                    $regrouped = true;
                }
            }
            if ($regrouped) {
                $p['changes'][] = self::change('sort_regrouped', 'info', Msg::of('msg_migration.sort_regrouped', '{what}: the groups of '
                    . 'the manual order change - the rows are numbered anew per group, their relative order is kept.', [
                        'what' => self::fieldLabel($e1['name'], $f1),
                    ]));
            }
            if ($renumber) {
                $out[] = $f1;
            }
        }
        return $out;
    }

    /**
     * A relationship becomes a composition (`-->` -> `*--`) or stops being one. The table only changes where the delete
     * behaviour changes (restrict <-> cascade); the rest is the model:
     *  - `-->` with {cascade} -> `*--`: notice only.
     *  - `-->` without {cascade} (restrict) -> `*--`: confirmation - deleting a whole deletes its parts from now on.
     *  - optional (`"0..1"`) -> `*--`: blocker as long as parts without a whole exist (with the list), never a backfill value -
     *    a common whole for all of them would be a guess. ($c['composition_orphans'] keeps the backfill away.)
     *  - `*--` -> `-->`: notice; without {cascade} it also says that deleting is now refused while parts exist ({cascade}
     *    recommended to keep everything as it was).
     */
    private function compositionChange(string $t1, array $e1, string $t0, array $e0, array $f0, array $f1, Msg $label, array &$c,
        array &$p, string $idCol): void
    {
        $whole = $p['model']['entities'][$f1['foreign_key']['table']]['name'];
        $params = ['what' => $label, 'part' => $e1['name'], 'whole' => $whole];
        if (empty($f1['foreign_key']['composition'])) {
            $p['changes'][] = self::change('composition_removed', 'info', ($f1['foreign_key']['on_delete'] ?? 'restrict') === 'cascade'
                ? Msg::of('msg_migration.composition_removed', '{what}: no longer a composition - only the model changes ({part} stays '
                    . 'in its own list; deleting a {whole} still deletes its {part} rows thanks to {cascade}).', $params + ['cascade' => '{cascade}'])
                : Msg::of('msg_migration.composition_removed_restrict', '{what}: no longer a composition. Without {cascade} a {whole} can '
                    . 'from now on only be deleted once it has no {part} rows - add {cascade} to the relationship to keep the previous '
                    . 'behaviour.', $params + ['cascade' => '{cascade}']));
            return;
        }
        if (!$f0['required']) {
            $empty = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . SqlGenerator::q($t0) . ' WHERE ' . SqlGenerator::q($f0['name'])
                . ' IS NULL')->fetchColumn();
            if ($empty) {
                $c['composition_orphans'] = true;
                $title = Cms::titleFieldOf($e0)[0];
                $rows = [];
                $stmt = $this->pdo->query("SELECT $idCol AS id, " . SqlGenerator::q($title) . ' AS v FROM ' . SqlGenerator::q($t0)
                    . ' WHERE ' . SqlGenerator::q($f0['name']) . " IS NULL ORDER BY $idCol LIMIT " . self::LIST_LIMIT);
                foreach ($stmt as $r) {
                    $rows[] = ['id' => (int) $r['id'], 'values' => [$title => $r['v']]];
                }
                $p['blockers'][] = ['key' => "composition:$t1.{$f1['name']}", 'count' => $empty, 'rows' => $rows]
                    + Msg::of('msg_migration.composition_orphans', '{what} becomes a composition, but {count} row(s) of {part} have no '
                        . '{whole} - a part cannot exist without its whole. Please assign a {whole} to them or delete them first.',
                        $params + ['count' => $empty])->fields('text');
                return;
            }
        }
        if (($f0['foreign_key']['on_delete'] ?? 'restrict') !== 'cascade') {
            $n = $this->count($t0);
            $p['changes'][] = self::change('composition_added', 'confirm', Msg::of('msg_migration.composition_added_restrict', '{what} becomes '
                . 'a composition: deleting a {whole} will from now on delete its {part} rows along with it (until now deleting was refused '
                . 'while they existed).', $params));
            $p['confirmations'][] = ['key' => "composition:$t1.{$f1['name']}", 'count' => $n] + Msg::of('msg_migration.confirm_composition',
                'I confirm that deleting a {whole} deletes its {part} rows from now on.', $params)->fields('text');
            return;
        }
        $p['changes'][] = self::change('composition_added', 'info', Msg::of('msg_migration.composition_added', '{what} becomes a composition '
            . '- only the model changes: {part} is edited in the form of its {whole} from now on, the table stays the same.', $params));
    }

    /** Backfill request, or a blocker if a backfill is not possible */
    private function requireBackfill(string $t1, array $e1, array $f1, int $empty, array &$p, array &$c): void
    {
        $key = "$t1.{$f1['name']}";
        $label = self::fieldLabel($e1['name'], $f1);
        $b = ['key' => $key, 'entity' => $e1['name'], 'table' => $t1, 'field' => $f1['name'], 'type' => $f1['type'],
            'rows' => $empty, 'label' => $label->text(), 'label_code' => $label->codeOnly()];
        if ($f1['foreign_key']) {
            $target = $f1['foreign_key']['table'];
            $oldTarget = $p['entityMap'][$target] ?? null;
            if ($target === $t1) {
                $p['blockers'][] = ['key' => "backfill:$key", 'count' => $empty, 'rows' => []] + Msg::of('msg_migration.backfill_self',
                    '{what}: a new required self-reference cannot be filled with a common value (a row would reference itself). Please '
                    . 'first create it as optional.', ['what' => $label])->fields('text');
                return;
            }
            $targetRows = $oldTarget !== null ? $this->count($oldTarget) : 0;
            if (!$targetRows) {
                $p['blockers'][] = ['key' => "backfill:$key", 'count' => $empty, 'rows' => []] + Msg::of('msg_migration.backfill_empty_target',
                    '{what}: {count} existing row(s) would need a value, but the target table {target} is empty. Please first create the '
                    . 'relationship as optional.', ['what' => $label, 'count' => $empty, 'target' => $p['model']['entities'][$target]['name']])
                    ->fields('text');
                return;
            }
            if (!empty($f1['foreign_key']['one_to_one']) && $empty > 1) {
                $p['blockers'][] = ['key' => "backfill:$key", 'count' => $empty, 'rows' => []] + Msg::of('msg_migration.backfill_one_to_one',
                    '{what}: 1:1 relationship - a common value for {count} rows would be assigned twice. Please first create it as optional '
                    . 'and assign the rows one by one.', ['what' => $label, 'count' => $empty])->fields('text');
                return;
            }
            $b['target'] = $p['model']['entities'][$target]['name'];
            $b['choices'] = $this->choices($oldTarget);
            $b['source_table'] = $oldTarget;
        } elseif ($f1['type'] === 'enum') {
            $b['choices'] = array_map(function ($v) {
                return ['value' => $v, 'label' => $v];
            }, $f1['enum_values']);
        } elseif ($f1['type'] === 'bool') {
            $b['choices'] = [
                ['value' => true] + Msg::of('msg_migration.choice_yes', 'Yes')->fields('label'),
                ['value' => false] + Msg::of('msg_migration.choice_no', 'No')->fields('label'),
            ];
        } elseif ($f1['type'] === 'visibility') {
            $b['choices'] = [
                ['value' => true] + Msg::of('msg_migration.choice_published', 'Published')->fields('label'),
                ['value' => false] + Msg::of('msg_migration.choice_draft', 'Draft')->fields('label'),
            ];
        }
        $text = Msg::of('msg_migration.backfill', '{what} is required: {count} existing row(s) need a value.', ['what' => $label, 'count' => $empty]);
        $b += $text->fields('text');
        $b['field_def'] = $f1;
        $p['backfills'][] = $b;
        $p['changes'][] = self::change('backfill', 'backfill', $text);
        $c['backfill'] = $key;
    }

    /** Backfill value according to the normal field rules (FK: existing ID of the target table) */
    private function backfillValue(array $b, $value)
    {
        $f = $b['field_def'];
        $v = Cms::coerce($f['foreign_key'] ? ['type' => 'int'] + $f : $f, $value);
        if ($v === null) {
            throw new ValueException(self::valueRequired());
        }
        // field rules (Validation): the value is written into every empty row - it has to satisfy them
        $violations = (new Validation($this->pdo))->check($f, $v);
        if ($violations) {
            throw new ValueException(Msg::spaceList(array_map(function ($v) {
                return isset($v['code']) ? Msg::fromArray($v) : Msg::of('msg_api.plain', '{text}', ['text' => $v['message']]);
            }, $violations)));
        }
        if ($f['foreign_key']) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . SqlGenerator::q($b['source_table']) . ' WHERE "id" = ?');
            $stmt->execute([$v]);
            if (!$stmt->fetchColumn()) {
                throw new ValueException(Msg::of('msg_migration.target_missing', '{target} #{id} does not exist', ['target' => $b['target'], 'id' => $v]));
            }
        }
        return $v;
    }

    /** Choices for an FK backfill: [{value: id, label: "Kategorie #3 – Bücher"}] */
    private function choices(string $oldTable): array
    {
        $cms = new Cms($this->pdo, $this->old);
        $title = $cms->describe($oldTable)['title_field'];
        $entityName = $this->old['entities'][$oldTable]['name'];
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM ' . SqlGenerator::q($oldTable) . ' ORDER BY "id" LIMIT ' . self::CHOICE_LIMIT) as $r) {
            $text = implode(', ', array_filter(array_map(function ($f) use ($r) {
                return $f !== 'id' && isset($r[$f]) ? (string) $r[$f] : '';
            }, $title), 'strlen'));
            $out[] = ['value' => (int) $r['id'], 'label' => "$entityName #{$r['id']}" . ($text !== '' ? " – $text" : '')];
        }
        return $out;
    }

    // ---------------------------------------------------------------- Execution

    /**
     * First the backup pair (SchemaBackup: database + previous .puml), then everything in one transaction; throws on any
     * error, the database then stays unchanged (and the backup just created is removed again - it would be
     * identical to the state that stays anyway). If the backup itself fails, the change does not even begin.
     */
    private function execute(array $plan, array $backfill, string $source, string $schemaFile, array $modelJson): array
    {
        $pdo = $this->pdo;
        $lock = Config::lockFile();
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw ApiException::of(409, 'locked', Msg::of('msg_migration.locked', 'A bootstrap or another schema change is running right '
                . 'now ({lock}). Please wait a moment and try again.', ['lock' => Config::displayPath($lock)]));
        }
        fclose($handle);
        $created = [];
        $rebuilt = [];
        $copied = 0;
        $backup = null;
        $committed = false;
        try {
            $backup = SchemaBackup::create($pdo, $schemaFile);
            // Foreign keys off during the rebuild (only takes effect outside a transaction), everything is checked at the end;
            // legacy_alter_table: RENAME does not rewrite references in other tables (their DDL comes from the plan)
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('PRAGMA legacy_alter_table = ON');
            // maintenance flag until COMMIT/rollback: content write access gets 503 meanwhile (Maintenance)
            Maintenance::begin();
            $pdo->beginTransaction();
            if (($delay = Config::testMigrationDelayMs()) > 0) {
                usleep($delay * 1000); // tests only (TEST_MIGRATION_DELAY_MS)
            }
            $sequences = [];
            if (Database::tableExists($pdo, 'sqlite_sequence')) {
                $sequences = $pdo->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            $renames = [];
            foreach ($plan['ops'] as $op) {
                if ($op['keep']) {
                    continue;
                }
                $tmp = '__new_' . $op['table'];
                $pdo->exec('DROP TABLE IF EXISTS ' . SqlGenerator::q($tmp));
                $ddl = preg_replace('/^CREATE TABLE "(?:[^"]|"")+"/', 'CREATE TABLE ' . SqlGenerator::q($tmp), $op['ddl'], 1);
                $pdo->exec($ddl);
                $renames[$tmp] = $op['table'];
                if ($op['source'] === null) {
                    $created[] = $op['table'];
                    continue;
                }
                $rebuilt[] = $op['table'];
                $copied += $this->copyRows($op, $tmp, $backfill);
            }
            foreach ($plan['drops'] as $t) {
                $pdo->exec('DROP TABLE IF EXISTS ' . SqlGenerator::q($t));
            }
            foreach ($renames as $tmp => $final) {
                $pdo->exec('ALTER TABLE ' . SqlGenerator::q($tmp) . ' RENAME TO ' . SqlGenerator::q($final));
            }
            // manual order (sort fields): number new/converted fields and regrouped ones per group, keeping the order
            foreach ($plan['sorts'] as $field) {
                Sorting::renumber($pdo, $plan['model'], $field);
            }
            // Stable IDs (SchemaIds): add missing ones of the previous schema (installation before phase 2), then transfer them
            // to the new schema via the same matching as above - before the foreign_key_check (positions of removed elements)
            SchemaIds::ensure($pdo);
            $ids = SchemaIds::reassign($pdo, $modelJson, self::stableKeys($plan['identity'], $plan['model'], $modelJson));
            // keep the AUTOINCREMENT state (otherwise IDs of deleted rows would be reassigned)
            foreach ($plan['ops'] as $op) {
                if (!$op['keep'] && $op['kind'] === 'entity' && $op['source'] !== null && isset($sequences[$op['source']])) {
                    $pdo->prepare('UPDATE sqlite_sequence SET seq = MAX(seq, ?) WHERE name = ?')->execute([(int) $sequences[$op['source']], $op['table']]);
                    if (!$pdo->query('SELECT COUNT(*) FROM sqlite_sequence WHERE name = ' . $pdo->quote($op['table']))->fetchColumn()) {
                        $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$op['table'], (int) $sequences[$op['source']]]);
                    }
                }
            }
            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM);
            if ($violations) {
                $list = array_map(function ($v) {
                    return "{$v[0]} #{$v[1]} -> {$v[2]}";
                }, array_slice($violations, 0, self::LIST_LIMIT));
                throw ApiException::of(409, 'conflict', Msg::of('msg_migration.dangling', '{count} reference(s) point to nothing after the '
                    . 'change: {list}. Nothing was changed.', ['count' => count($violations), 'list' => implode(', ', $list)]));
            }
            // rebuilt tables have lost their indexes, renamed columns/tables carry outdated names
            SchemaIndexes::sync($pdo, $plan['model']);
            $this->updateColumnPrefs($plan);
            Workflows::store($pdo, $this->workflows() !== null ? $this->workflow['file'] : null, $this->workflow['source']);
            Workflows::migrateTasks($pdo, $plan['entityMap'], $plan['model']);
            Revisions::migrate($pdo, $plan, $this->old); // versions: renamed entities/fields/lists along, removed ones away
            Webhooks::migrate($pdo, $plan['entityMap']); // entity selection of the webhooks follows renamed entities
            Database::setMeta($pdo, 'schema_hash', hash('sha256', $source));
            Database::setMeta($pdo, 'schema_json', json_encode($plan['model'], JSON_UNESCAPED_UNICODE));
            Database::setMeta($pdo, 'schema_source', $source);
            $pdo->commit();
            $committed = true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($backup !== null && !$committed) {
                SchemaBackup::remove($backup['stamp']);
            }
            if ($e instanceof PDOException) {
                throw ApiException::of(409, 'conflict', Msg::of('msg_migration.apply_failed', 'The change could not be applied: {error}. '
                    . 'Nothing was changed (completely rolled back).', ['error' => $e->getMessage()]));
            }
            throw $e;
        } finally {
            Maintenance::end();
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
            $pdo->exec('PRAGMA foreign_keys = ON');
            @unlink($lock);
        }
        return ['status' => 'applied'] + Msg::of('msg_migration.applied', 'Schema change applied.')->fields() + [
            'created'  => $created,
            'rebuilt'  => $rebuilt,
            'dropped'  => array_values(array_diff($plan['drops'], $rebuilt)),
            'unchanged' => count(array_filter($plan['ops'], function ($op) {
                return $op['keep'];
            })),
            'rows_copied' => $copied,
            'schema_ids' => $ids,
            'backup'   => ['stamp' => $backup['stamp'], 'files' => $backup['files'], 'dir' => Config::displayPath(SchemaBackup::dir(), true)],
            'backups_removed' => SchemaBackup::rotate(Settings::get($this->pdo, Settings::SCHEMA_BACKUP_KEEP)),
        ];
    }

    /** Carry over the rows of the source table into the shadow table (converted, with backfill) */
    private function copyRows(array $op, string $tmp, array $backfill): int
    {
        $names = array_keys($op['cols']);
        $sym = $op['symmetric_change'] ?? null;
        // {symmetric} changed: merge pairs ("OR IGNORE": A-B and B-A result in the same row) or duplicate them
        $ins = $this->pdo->prepare('INSERT ' . ($sym !== null ? 'OR IGNORE ' : '') . 'INTO ' . SqlGenerator::q($tmp) . ' ('
            . implode(', ', array_map([SqlGenerator::class, 'q'], $names)) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')');
        $n = 0;
        foreach ($this->pdo->query('SELECT * FROM ' . SqlGenerator::q($op['source'])) as $row) {
            $values = [];
            foreach ($op['cols'] as $name => $c) {
                $v = $c['from'] !== null && array_key_exists($c['from'], $row) ? $row[$c['from']] : null;
                if ($v !== null && !empty($c['convert'])) {
                    $v = Cms::coerce($c['field'], self::raw($c['field'], $v)); // checked in advance (checkColumns)
                }
                if ($v === null && !empty($c['backfill'])) {
                    $v = $backfill[$c['backfill']];
                }
                if ($v === null && isset($c['auto'])) {
                    $v = $c['auto']; // workflow field: initial state
                }
                $values[] = $v;
            }
            try {
                if ($sym === 'to_symmetric') {
                    $ins->execute([min($values), max($values)]);
                } elseif ($sym === 'to_directed') {
                    $ins->execute($values);
                    $ins->execute(array_reverse($values));
                } else {
                    $ins->execute($values);
                }
            } catch (PDOException $e) {
                $id = $row['id'] ?? null;
                $params = ['table' => $op['table'], 'id' => $id, 'reason' => self::constraintText($e->getMessage())];
                throw ApiException::of(409, 'conflict', $id !== null
                    ? Msg::of('msg_migration.copy_failed_row', "While taking over '{table}' (row #{id}): {reason}. Nothing was changed "
                        . '(completely rolled back).', $params)
                    : Msg::of('msg_migration.copy_failed', "While taking over '{table}': {reason}. Nothing was changed (completely rolled "
                        . 'back).', $params), ['table' => $op['table'], 'row' => $id]);
            }
            $n++;
        }
        return $n;
    }

    /** Adapt the users' column selection (user_column_prefs) to renamed/removed entities and columns */
    private function updateColumnPrefs(array $plan): void
    {
        if (!Database::tableExists($this->pdo, ColumnPrefs::TABLE)) {
            return;
        }
        $rows = $this->pdo->query('SELECT user_id, entity_name, visible_columns FROM user_column_prefs')->fetchAll();
        $del = $this->pdo->prepare('DELETE FROM user_column_prefs WHERE user_id = ? AND entity_name = ?');
        $put = $this->pdo->prepare('INSERT OR REPLACE INTO user_column_prefs (user_id, entity_name, visible_columns, updated_at)
                                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        foreach ($rows as $r) {
            $t1 = array_search($r['entity_name'], $plan['entityMap'], true);
            $del->execute([$r['user_id'], $r['entity_name']]);
            if ($t1 === false) {
                continue; // entity removed
            }
            $map = ($plan['colMaps'][$t1] ?? []) + array_flip($plan['listMaps'][$t1] ?? []);
            if (RecordMeta::applies($plan['model']['entities'][$t1])) {
                $map += array_combine(RecordMeta::FIELDS, RecordMeta::FIELDS); // tracking metadata columns stay selected
            }
            $cols = json_decode((string) $r['visible_columns'], true);
            $cols = array_values(array_unique(array_filter(array_map(function ($c) use ($map) {
                return $map[$c] ?? null;
            }, is_array($cols) ? $cols : []))));
            $put->execute([$r['user_id'], $t1, json_encode($cols, JSON_UNESCAPED_UNICODE)]);
        }
    }

    // ---------------------------------------------------------------- Helpers

    private static function publicPlan(array $p): array
    {
        $backfills = array_map(function ($b) {
            unset($b['field_def'], $b['source_table']);
            return $b;
        }, $p['backfills']);
        return [
            'has_changes'   => (bool) $p['changes'],
            'changes'       => $p['changes'],
            'confirmations' => $p['confirmations'],
            'backfills'     => $backfills,
            'blockers'      => $p['blockers'],
            'tables'        => [
                'create'  => array_values(array_map(function ($op) {
                    return $op['table'];
                }, array_filter($p['ops'], function ($op) {
                    return $op['source'] === null;
                }))),
                'rebuild' => array_values(array_map(function ($op) {
                    return $op['table'];
                }, array_filter($p['ops'], function ($op) {
                    return !$op['keep'] && $op['source'] !== null;
                }))),
                'drop'    => array_values(array_filter($p['drops'], function ($t) use ($p) {
                    foreach ($p['ops'] as $op) {
                        if ($op['source'] === $t && !$op['keep']) {
                            return false; // only replaced (rebuilt), not lost
                        }
                    }
                    return true;
                })),
                'unchanged' => count(array_filter($p['ops'], function ($op) {
                    return $op['keep'];
                })),
            ],
        ];
    }

    /** Analysis item: text (English) + code/params for the UI */
    private static function change(string $kind, string $severity, Msg $text): array
    {
        return ['kind' => $kind, 'severity' => $severity] + $text->fields('text');
    }

    private static function schemaError(Msg $message): ApiException
    {
        return ApiException::of(422, 'schema_error', $message);
    }

    private static function valueRequired(): Msg
    {
        return Msg::of('msg_migration.value_required', 'Value required');
    }

    /** "n:n relationship A – B (label)" */
    private static function nnWhat(string $class, string $target, string $label): Msg
    {
        $params = ['class' => $class, 'target' => $target, 'label' => $label];
        return $label !== ''
            ? Msg::of('msg_migration.nn_what_label', 'n:n relationship {class} – {target} ({label})', $params)
            : Msg::of('msg_migration.nn_what', 'n:n relationship {class} – {target}', $params);
    }

    /** All tables of a model (entities, n:n and media link tables) */
    private static function modelTables(array $model): array
    {
        $t = array_keys($model['entities']);
        foreach ($model['entities'] as $e) {
            foreach ($e['many_to_many'] as $m) {
                $t[] = $m['junction'];
            }
            foreach ($e['media'] as $m) {
                $t[] = $m['junction'];
            }
        }
        return array_values(array_unique($t));
    }

    /**
     * Notices for a changed display order (field_order) and changed form sections of an entity - pure model changes.
     * The order is compared via the names of the old model (renamed fields/lists count as the same entry); an old model
     * from before field_order gets no order notice (otherwise every entity would report one after the update).
     *
     * @param array<string,string> $colMap old column => new field name
     * @param array<string,string> $listMap new n:n/media name => old name
     */
    private function orderChanges(array &$p, array $e1, array $e0, array $colMap, array $listMap): void
    {
        $toNew = $colMap + array_flip($listMap);
        $sectionOf = function (array $e, array $rename): array {
            $out = [];
            foreach (array_merge($e['fields'], $e['media'] ?? [], $e['many_to_many']) as $x) {
                $out[$rename[$x['name']] ?? $x['name']] = $x['section'] ?? null;
            }
            return $out;
        };
        $s0 = $e0['sections'] ?? [];
        $s1 = $e1['sections'] ?? [];
        $added = array_values(array_diff($s1, $s0));
        $removed = array_values(array_diff($s0, $s1));
        $parts = [];
        if (count($added) === 1 && count($removed) === 1 && array_search($added[0], $s1, true) === array_search($removed[0], $s0, true)) {
            $parts[] = Msg::of('msg_migration.section_renamed', "section '{old}' renamed to '{new}'", ['old' => $removed[0], 'new' => $added[0]]);
            $renamedSection = [$removed[0] => $added[0]];
        } else {
            if ($added) {
                $parts[] = Msg::of('msg_migration.section_added', 'new section {titles}', ['titles' => implode(', ', array_map(function ($t) {
                    return "'$t'";
                }, $added))]);
            }
            if ($removed) {
                $parts[] = Msg::of('msg_migration.section_removed', 'section {titles} removed', ['titles' => implode(', ', array_map(function ($t) {
                    return "'$t'";
                }, $removed))]);
            }
            if (!$added && !$removed && $s0 !== $s1) {
                $parts[] = Msg::of('msg_migration.section_order', 'order of the sections changed');
            }
            $renamedSection = [];
        }
        if (isset($e0['field_order'])) {
            $old = array_map(function ($n) use ($toNew) {
                return $toNew[$n] ?? $n;
            }, $e0['field_order']);
            $common = array_values(array_intersect($old, $e1['field_order']));
            $moved = $common !== array_values(array_intersect($e1['field_order'], $common));
            // section assignment of the entries present in both (a renamed section does not count as a move)
            $sec0 = $sectionOf($e0, $toNew);
            $sec1 = $sectionOf($e1, []);
            foreach ($common as $n) {
                $was = $sec0[$n] ?? null;
                // no section (null) is never a renamed one - and null as an array offset is deprecated since PHP 8.5
                $now = $was !== null && isset($renamedSection[$was]) ? $renamedSection[$was] : $was;
                if (array_key_exists($n, $sec1) && $now !== $sec1[$n]) {
                    $moved = true;
                }
            }
            if ($moved) {
                $parts[] = Msg::of('msg_migration.field_order', 'order or section assignment of the fields changed');
            }
        }
        if ($parts) {
            $p['changes'][] = self::change('field_order', 'info', Msg::of('msg_migration.order_changed', 'Class {class}: {changes} (only '
                . 'form and list, the table stays unchanged).', ['class' => $e1['name'], 'changes' => Msg::semicolonList($parts)]));
        }
    }

    /**
     * Same table structure apart from the order of the column definitions? The column definitions and table constraints of
     * the CREATE TABLE statement (top-level comma-separated parts) are compared as a set - every part exactly (type,
     * NOT NULL, DEFAULT, CHECK with the enum values, FOREIGN KEY, UNIQUE with its column order). Reordered fields in the
     * diagram therefore keep the table; the physical column order may then differ from field_order, nothing depends on it
     * (all reads and copies go by column name).
     */
    private static function sameDdl(?string $old, string $new): bool
    {
        if ($old === null) {
            return false;
        }
        if ($old === $new) {
            return true;
        }
        $parts = function (string $ddl): ?array {
            if (!preg_match('/^(CREATE TABLE\s+"(?:[^"]|"")*"\s*)\((.*)\)$/s', $ddl, $m)) {
                return null;
            }
            $items = [];
            $depth = 0;
            $quote = null;
            $cur = '';
            foreach (str_split($m[2]) as $ch) {
                if ($quote !== null) {
                    $quote = $ch === $quote ? null : $quote; // "" / '' inside a quote: closes and reopens, same result
                } elseif ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $items[] = trim($cur);
                    $cur = '';
                    continue;
                }
                $cur .= $ch;
            }
            $items[] = trim($cur);
            sort($items);
            return [trim($m[1]), $items];
        };
        $a = $parts($old);
        return $a !== null && $a === $parts($new);
    }

    /** Columns without rename, conversion, backfill and without new columns? */
    private static function identity(array $cols): bool
    {
        foreach ($cols as $name => $c) {
            if ($c['from'] !== $name || !empty($c['convert']) || !empty($c['backfill']) || isset($c['auto'])) {
                return false;
            }
        }
        return true;
    }

    /** Comparison signature for type changes (enum: the list of values is part of it) */
    private static function typeSignature(array $f): string
    {
        return $f['type'] === 'enum' ? 'enum:' . implode(',', $f['enum_values']) : $f['type'];
    }

    /** "Workflow X (a, b)", "Enum X (a, b)" or "'string'" - plain words, the same in every language */
    private static function typeLabel(array $f): string
    {
        return $f['type'] === 'enum' ? (isset($f['workflow']) ? 'Workflow ' : 'Enum ') . ($f['enum'] ?? '') . ' ('
            . implode(', ', $f['enum_values']) . ')' : "'{$f['type']}'";
    }

    /** "Field X.y" or "Relationship X.y" */
    private static function fieldLabel(string $class, array $f): Msg
    {
        $params = ['field' => "$class.{$f['name']}"];
        return $f['foreign_key']
            ? Msg::of('msg_migration.relation_label', 'Relationship {field}', $params)
            : Msg::of('msg_migration.field_label', 'Field {field}', $params);
    }

    private static function workflowFieldChange(Msg $label, bool $becomes): Msg
    {
        return $becomes
            ? Msg::of('msg_migration.becomes_workflow', '{what}: becomes a workflow field (changes only via transitions from now on).', [
                'what' => $label,
            ])
            : Msg::of('msg_migration.no_longer_workflow', '{what}: is no longer a workflow field (freely editable).', ['what' => $label]);
    }

    /** Text for a changed {filter_by} value (only the model changes, no table, no stored selection) */
    private static function filterChange(?array $filter): Msg
    {
        if ($filter === null) {
            return Msg::of('msg_migration.filter_removed', 'Selection in the form no longer filtered ({filter_by} dropped).', [
                'filter_by' => '{filter_by}',
            ]);
        }
        $params = ['field' => $filter['field'], 'target' => $filter['target_field'], 'filter_by' => '{filter_by}'];
        return $filter['field'] !== $filter['target_field']
            ? Msg::of('msg_migration.filter_pair', 'Selection in the form filtered by {field} = {target} ({filter_by}); assignments '
                . 'already stored stay unchanged.', $params)
            : Msg::of('msg_migration.filter_field', 'Selection in the form filtered by {field} ({filter_by}); assignments already stored '
                . 'stay unchanged.', $params);
    }

    private static function fkSignature(array $f): string
    {
        return (($f['foreign_key']['on_delete'] ?? 'restrict') === 'cascade' ? 'cascade' : 'restrict')
            . (!empty($f['foreign_key']['one_to_one']) ? ', 1:1' : '');
    }

    /**
     * stored SQLite value -> input for Cms::coerce() of the new field. bool/visibility deliberately stay 0/1 (coerce
     * accepts that for bool): as true/false, bool -> string would turn into an empty text instead of "0" (found by the
     * fuzzer). decimal -> int: integral values (2.0) pass, 2.5 stays a conflict.
     */
    private static function raw(array $new, $v)
    {
        if (is_float($v) && $new['type'] === 'int' && floor($v) === $v && abs($v) < PHP_INT_MAX) {
            return (int) $v;
        }
        return $v;
    }

    /** @return Msg|string readable cause of an SQLite constraint error (other errors as they are) */
    private static function constraintText(string $sqlMessage)
    {
        if (preg_match('/UNIQUE constraint failed: (.+)$/', $sqlMessage, $m)) {
            return Msg::of('msg_migration.constraint_unique', 'uniqueness violated ({columns} would be duplicated)', ['columns' => $m[1]]);
        }
        if (preg_match('/NOT NULL constraint failed: (.+)$/', $sqlMessage, $m)) {
            return Msg::of('msg_migration.constraint_not_null', 'required value missing ({column})', ['column' => $m[1]]);
        }
        if (preg_match('/CHECK constraint failed/', $sqlMessage)) {
            return Msg::of('msg_migration.constraint_check', 'value violates a check rule (CHECK)');
        }
        return $sqlMessage;
    }

    private function count(string $table): int
    {
        if (!Database::tableExists($this->pdo, $table)) {
            return 0;
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . SqlGenerator::q($table))->fetchColumn();
    }

    /** Rows with a value (not NULL, not an empty text) */
    private function nonEmpty(string $table, string $col): int
    {
        $q = SqlGenerator::q($col);
        return (int) $this->pdo->query("SELECT COUNT(*) FROM " . SqlGenerator::q($table) . " WHERE $q IS NOT NULL AND $q <> ''")->fetchColumn();
    }

    /** Bring a stored model up to the current structure (older models do not know some keys) */
    private static function normalize(array $model): array
    {
        foreach ($model['entities'] as $t => &$e) {
            $e += ['media' => [], 'unique_fields' => [], 'title_fields' => [], 'package' => null];
            foreach ($e['many_to_many'] as &$m) {
                $m += ['label' => '', 'show_label' => false, 'symmetric' => false];
                $m['symmetric'] = !empty($m['symmetric']);
            }
            unset($m);
            foreach ($e['fields'] as &$f) {
                if ($f['foreign_key']) {
                    $f['foreign_key'] += ['label' => '', 'on_delete' => 'restrict', 'one_to_one' => false];
                }
            }
            unset($f);
        }
        unset($e);
        return $model + ['junctions' => [], 'enums' => [], 'abstract' => []];
    }
}
