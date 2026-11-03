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
     * renames of new elements, for which the UI only puts the {renamed_from} marker into the text.
     */
    public function analyze(string $source): array
    {
        $plan = $this->plan($source);
        return self::publicPlan($plan) + ['rename_suggestions' => $this->renameSuggestions($plan['identity'], $plan['model'], $source)]
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
        return $summary;
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
            'blockers' => [], 'entityMap' => $id['entityMap'], 'colMaps' => [], 'listMaps' => [],
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
                $keep = $md0['junction'] === $md1['junction'] && ($oldDdl[$md1['junction']] ?? null) === $statements[$md1['junction']]
                    && self::identity($cols);
                $p['ops'][] = ['table' => $md1['junction'], 'ddl' => $statements[$md1['junction']], 'source' => $md0['junction'],
                    'cols' => $cols, 'keep' => $keep, 'kind' => 'media'];
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
            }
        }
        return $p;
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
                $k = self::matchRelation($cands, $m1['label'], $m1['renamed_from'] ?? null, $what);
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
        $json = PumlParser::modelJson($source, $this->workflows());
        $classes = array_column($json['entities'], null, 'name');
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
                $line = $loc->relation($e1['name'], $target, $fk['label']);
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
                $line = $loc->relation($e1['name'], $target, $m1['label']);
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
                } elseif ($r !== $t1 && isset($old['entities'][$r])) {
                    // both names exist so far (also: two classes swapping names) - a silently ignored marker would be
                    // just as wrong as a guessed matching
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
     * @return int|string|null
     */
    private static function matchRelation(array $cands, string $label, ?string $renamedFrom, Msg $what)
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
            if ($old !== null && $old !== $own) {
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
                $k = self::matchRelation($cands, $f1['foreign_key']['label'], $f1['foreign_key']['renamed_from'] ?? null, $what);
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
                    if ($own !== null && isset($f1['renamed_from']) && strtolower($f1['renamed_from']) !== strtolower($f1['name'])
                        && isset($oldByName[strtolower($f1['renamed_from'])])) {
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
    private function checkColumns(string $t1, array $e1, string $t0, array $e0, array &$cols, array &$p): void
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
                if ($f1['foreign_key']) {
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
                $stmt = $this->pdo->query('SELECT "id", ' . SqlGenerator::q($f0['name']) . ' AS v FROM ' . SqlGenerator::q($t0)
                    . ' WHERE ' . SqlGenerator::q($f0['name']) . ' IS NOT NULL ORDER BY "id"');
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
            } elseif ($f0 !== null && isset($f0['workflow']) !== isset($f1['workflow'])) {
                $p['changes'][] = self::change('field_workflow', 'info', self::workflowFieldChange($label, isset($f1['workflow'])));
            } elseif ($f0 !== null && $f0['required'] !== $f1['required']) {
                $p['changes'][] = self::change('field_required', 'info', $f1['required']
                    ? Msg::of('msg_migration.now_required', '{what}: now required.', ['what' => $label])
                    : Msg::of('msg_migration.now_optional', '{what}: now optional.', ['what' => $label]));
            } elseif ($f0 !== null && $f1['foreign_key'] && self::fkSignature($f0) !== self::fkSignature($f1)) {
                $p['changes'][] = self::change('fk_changed', 'info', Msg::of('msg_migration.fk_changed', '{what}: delete behaviour/1:1 '
                    . 'changed ({old} -> {new}).', ['what' => $label, 'old' => self::fkSignature($f0), 'new' => self::fkSignature($f1)]));
            }
            if ($f0 !== null && !$f1['foreign_key']) {
                $this->ruleChanges($t0, $f0, $f1, $label, (bool) $c['convert'], $p);
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
                if ($empty && isset($f1['workflow'])) {
                    // workflow field: existing rows automatically start in the initial state (no backfill dialog)
                    $c['auto'] = $p['model']['workflows'][$f1['workflow']]['initial'];
                    $p['changes'][] = self::change('workflow_backfill', 'info', Msg::of('msg_migration.workflow_backfill', '{what}: {count} '
                        . "existing row(s) automatically get the initial state '{state}'.", ['what' => $label, 'count' => $empty, 'state' => $c['auto']]));
                } elseif ($empty) {
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
    private function ruleChanges(string $t0, array $f0, array $f1, Msg $label, bool $convert, array &$p): void
    {
        $r0 = $f0['rules'] ?? [];
        $r1 = $f1['rules'] ?? [];
        if ($r0 === $r1) {
            return;
        }
        $none = Msg::of('msg_migration.rules_none', '(none)');
        $rules = ['what' => $label, 'old' => $r0 ? trim(Validation::markers($r0)) : $none, 'new' => $r1 ? trim(Validation::markers($r1)) : $none];
        $ids = [];
        $count = 0;
        if ($r1) {
            $validation = new Validation($this->pdo);
            $col = SqlGenerator::q($f0['name']);
            $stmt = $this->pdo->query("SELECT \"id\", $col AS v FROM " . SqlGenerator::q($t0) . " WHERE $col IS NOT NULL AND $col <> '' ORDER BY \"id\"");
            foreach ($stmt as $r) {
                try {
                    $value = $convert ? Cms::coerce($f1, self::raw($f1, $r['v'])) : $r['v'];
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
                if ($validation->check($f1, $value)) {
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
                if (array_key_exists($n, $sec1) && ($renamedSection[$was] ?? $was) !== $sec1[$n]) {
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
