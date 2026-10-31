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
 * Parses a workflow file (schema/workflows/*.lhwf) into workflow definitions (plain array, JSON-serializable).
 * Purely structural and without a database: whether the named groups, roles and users exist is only checked by the
 * combined analysis (Workflows::checkPrincipals()); whether there is a class for a workflow, by the main schema parser
 * (PumlParser).
 *
 *   workflow ArticleWorkflow {
 *     state draft  "Entwurf"
 *     state review "Freigabe"
 *
 *     flow draft -> review
 *
 *     draft -> review : einreichen {
 *       allowed=group:redaktion, role:Chef
 *       assign=group:chefredaktion
 *       action=set_visibility:false
 *     }
 *   }
 *
 * - Name: `<ClassName>Workflow`. Several workflow blocks per file; an empty file is valid (no workflow).
 * - `state <name> ["label"]`: name like an enum value (it is stored like this in the database), label optional.
 * - `flow a -> b -> c`: exactly one line per workflow, the main path. Its first state is the initial state of new
 *   records. For every consecutive pair there must be a transition. States outside the path are allowed.
 * - `from -> to : label { ... }`: transition. The label is unique within the same from-state (not globally).
 *   `allowed=` (required): `group:<name>` and/or `role:<name>`, separated by commas. `assign=` (optional): `group:<name>`,
 *   `user:<name>` or `initiator`. `action=` (optional): `<type>:<value>` - `set_visibility:true|false` (ACTIONS, at
 *   most once per transition) and `webhook:<name>` (one line per webhook, several allowed, each name at most once;
 *   whether the webhook exists is only checked by the combined analysis, Webhooks::checkWorkflows()).
 * - Comments (' at the start of a line, /' ... '/) and @startuml/@enduml are skipped; everything else is a schema error.
 *
 * Result: [ 'ArticleWorkflow' => [
 *     'name' => 'ArticleWorkflow',
 *     'states' => [ ['name' => 'draft', 'label' => 'Entwurf'], ... ],
 *     'flow' => ['draft', 'review'], 'initial' => 'draft',
 *     'transitions' => [ ['from', 'to', 'label', 'allowed' => [['type' => 'group', 'name' => 'redaktion'], ...],
 *         'assign' => null | ['type' => 'group'|'user', 'name'] | ['type' => 'initiator'],
 *         'actions' => [['type' => 'set_visibility', 'value' => true], ['type' => 'webhook', 'value' => 'netlify']] ] ],
 * ] ]
 */
final class WorkflowParser
{
    /** Suffix by which the main schema recognizes a workflow field type */
    public const SUFFIX = 'Workflow';

    /** Action type => check/conversion of the value (null = invalid); action=webhook:<name> has a free value, see property() */
    private const ACTIONS = ['set_visibility' => ['true' => true, 'false' => false]];
    /** Action type that calls a webhook (System → Einstellungen → Webhooks) */
    public const WEBHOOK = 'webhook';
    /** Name of a webhook: letter first, then letters, digits, _ and - (the same rule as in Webhooks) */
    public const WEBHOOK_NAME = '/^[A-Za-z][A-Za-z0-9_-]{0,49}$/';

    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    /** Transition label: letters (umlauts too), digits, _ - and spaces in between */
    private const LABEL = '/^[\p{L}\p{N}_](?:[\p{L}\p{N}_ \-]*[\p{L}\p{N}_])?$/u';

    /**
     * @return array<string,array> name => definition, in file order
     * @throws SchemaException
     */
    public static function parse(string $source): array
    {
        return self::run($source);
    }

    /**
     * Lines of the transitions of a (valid) workflow file, for messages that only arise after parsing (missing
     * group/role/user, action without a matching field): name => per transition (index as in 'transitions')
     * ['line' => head line, 'allowed' => line, 'assign' => line, 'action:<type>' => line]. Deliberately separate from the
     * definitions - those are part of the model, and moved lines must not be a change there.
     *
     * @return array<string,array<int,array<string,int>>>
     * @throws SchemaException
     */
    public static function positions(string $source): array
    {
        $positions = [];
        self::run($source, $positions);
        return $positions;
    }

    private static function run(string $source, ?array &$positions = null): array
    {
        $source = (string) preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        // remove block comments, keep line breaks (line numbers of the messages)
        $source = (string) preg_replace_callback("#/'.*?'/#s", function (array $m): string {
            return str_repeat("\n", substr_count($m[0], "\n"));
        }, $source);

        $workflows = [];
        $current = null;   // open workflow block
        $transition = null; // open transition inside it
        $lines = explode("\n", $source);
        foreach ($lines as $i => $raw) {
            $no = $i + 1;
            $line = trim($raw);
            if ($line === '' || $line[0] === "'" || ($current === null && preg_match('/^@(start|end)uml\b/i', $line))) {
                continue;
            }
            if ($transition !== null) {
                // properties of the transition, "}" ends it (also directly after the last property)
                $closes = substr($line, -1) === '}';
                $body = $closes ? trim(substr($line, 0, -1)) : $line;
                // the next transition starts before this one is closed: say so instead of reading the line as a property
                if (preg_match('/^\S+\s*->/', $body)) {
                    throw new SchemaException(Msg::of('msg_workflows.transition_unclosed_at', '{where}: The transition \'{transition}\' '
                        . '(line {start}) is not closed with "}".', [
                            'where' => self::at($current['name'], $no), 'transition' => $transition['label'], 'start' => $transition['line'],
                        ]), $transition['line']);
                }
                if ($body !== '') {
                    self::property($transition, $body, $current['name'], $no);
                }
                if ($closes) {
                    self::closeTransition($current, $transition);
                    $transition = null;
                }
                continue;
            }
            if ($current === null) {
                if (!preg_match('/^workflow\s+([^\s{]+)\s*\{$/i', $line, $m)) {
                    throw new SchemaException(Msg::of('msg_workflows.header_unclear', '{where}: not understood (expected: workflow '
                        . '<Class>Workflow { with "{" on the same line): {source}', ['where' => self::fileAt($no), 'source' => $line]), $no);
                }
                $name = $m[1];
                if (!preg_match(self::IDENT, $name) || strlen($name) <= strlen(self::SUFFIX)
                    || strcasecmp(substr($name, -strlen(self::SUFFIX)), self::SUFFIX) !== 0) {
                    throw new SchemaException(Msg::of('msg_workflows.name_invalid', "{where}: Invalid workflow name '{name}' (expected: "
                        . '<ClassName>Workflow, e.g. ArticleWorkflow; letters, digits, _ without umlauts).', [
                            'where' => self::fileAt($no), 'name' => $name,
                        ]), $no);
                }
                foreach (array_keys($workflows) as $other) {
                    if (strcasecmp($other, $name) === 0) {
                        throw new SchemaException(Msg::of('msg_workflows.duplicate', "{where}: Workflow '{name}' is defined twice.", [
                            'where' => self::fileAt($no), 'name' => $name,
                        ]), $no);
                    }
                }
                $current = ['name' => $name, 'states' => [], 'flow' => null, 'initial' => null, 'transitions' => [], 'line' => $no,
                    'flow_line' => null, 'pos' => []];
                continue;
            }
            $wf = $current['name'];
            // the next workflow block starts before this one is closed (usually the "}" of a transition before it is missing)
            if (preg_match('/^workflow\s+\S+\s*\{$/i', $line)) {
                throw new SchemaException(Msg::of('msg_workflows.unclosed_next', "Workflow '{workflow}' (line {start}) is not closed with "
                    . '"}" (line {line} already starts the next workflow - is the "}" of a transition missing before it?).', [
                        'workflow' => $wf, 'start' => $current['line'], 'line' => $no,
                    ]), $current['line']);
            }
            if ($line === '}') {
                $positions[$wf] = $current['pos'];
                $workflows[$wf] = self::finish($current);
                $current = null;
                continue;
            }
            if (preg_match('/^state\b\s*(.*)$/i', $line, $m)) {
                if (!preg_match('/^([^\s"]+)(?:\s+"([^"]*)")?$/', $m[1], $s)) {
                    throw new SchemaException(Msg::of('msg_workflows.state_unclear', '{where}: state line not understood (expected: '
                        . 'state <name> "Label"): {source}', ['where' => self::at($wf, $no), 'source' => $line]), $no);
                }
                if (!preg_match(self::IDENT, $s[1])) {
                    throw new SchemaException(Msg::of('msg_workflows.state_invalid', "{where}: Invalid state name '{state}' (allowed: "
                        . 'letters, digits, _ without umlauts, not starting with a digit).', ['where' => self::at($wf, $no), 'state' => $s[1]]), $no);
                }
                if (self::stateName($current, $s[1]) !== null) {
                    throw new SchemaException(Msg::of('msg_workflows.state_duplicate', "{where}: State '{state}' is defined twice.", [
                        'where' => self::at($wf, $no), 'state' => $s[1],
                    ]), $no);
                }
                $label = trim($s[2] ?? '');
                $current['states'][] = ['name' => $s[1], 'label' => $label !== '' ? $label : $s[1]];
                continue;
            }
            if (preg_match('/^flow\b\s*(.*)$/i', $line, $m)) {
                if ($current['flow'] !== null) {
                    throw new SchemaException(Msg::of('msg_workflows.flow_twice', '{where}: Only one flow line per workflow is possible.', [
                        'where' => self::at($wf, $no),
                    ]), $no);
                }
                $flow = [];
                foreach (explode('->', $m[1]) as $part) {
                    $part = trim($part);
                    $state = $part !== '' ? self::stateName($current, $part) : null;
                    if ($state === null) {
                        throw new SchemaException($part === ''
                            ? Msg::of('msg_workflows.flow_unclear', '{where}: flow line not understood (expected: flow a -> b -> c): {source}', [
                                'where' => self::at($wf, $no), 'source' => $line,
                            ])
                            : Msg::of('msg_workflows.flow_unknown_state', "{where}: flow names the unknown state '{state}' (states must be "
                                . 'declared with state before).', ['where' => self::at($wf, $no), 'state' => $part]), $no);
                    }
                    if (in_array($state, $flow, true)) {
                        throw new SchemaException(Msg::of('msg_workflows.flow_repeated', "{where}: State '{state}' appears more than once in flow.", [
                            'where' => self::at($wf, $no), 'state' => $state,
                        ]), $no);
                    }
                    $flow[] = $state;
                }
                $current['flow'] = $flow;
                $current['flow_line'] = $no;
                continue;
            }
            if (preg_match('/^(\S+)\s*->\s*([^\s:]+)\s*(?::\s*(.*?))?\s*(\{(.*))?$/u', $line, $m)) {
                if (!isset($m[4]) || $m[4] === '') {
                    throw new SchemaException(Msg::of('msg_workflows.transition_no_brace', '{where}: Transition without "{" (expected: '
                        . 'from -> to : label { with allowed=... in the following lines): {source}', ['where' => self::at($wf, $no), 'source' => $line]), $no);
                }
                $states = [];
                foreach ([$m[1], $m[2]] as $part) {
                    $state = self::stateName($current, $part);
                    if ($state === null) {
                        throw new SchemaException(Msg::of('msg_workflows.transition_unknown_state', "{where}: Transition names the unknown state '{state}'.", [
                            'where' => self::at($wf, $no), 'state' => $part,
                        ]), $no);
                    }
                    $states[] = $state;
                }
                $label = trim($m[3] ?? '');
                if ($label === '' || !preg_match(self::LABEL, $label)) {
                    throw new SchemaException($label === ''
                        ? Msg::of('msg_workflows.label_missing', '{where}: Transition without a label (expected: from -> to : label {, label '
                            . 'of letters, digits, _ - and spaces).', ['where' => self::at($wf, $no)])
                        : Msg::of('msg_workflows.label_invalid', "{where}: Invalid transition label '{label}' (expected: from -> to : label {, "
                            . 'label of letters, digits, _ - and spaces).', ['where' => self::at($wf, $no), 'label' => $label]), $no);
                }
                foreach ($current['transitions'] as $t) {
                    if ($t['from'] === $states[0] && self::fold($t['label']) === self::fold($label)) {
                        throw new SchemaException(Msg::of('msg_workflows.label_duplicate', "{where}: The label '{label}' already exists at "
                            . "state '{state}' (labels must be unique per source state).", [
                                'where' => self::at($wf, $no), 'label' => $label, 'state' => $states[0],
                            ]), $no);
                    }
                }
                $transition = ['from' => $states[0], 'to' => $states[1], 'label' => $label, 'allowed' => null, 'assign' => null,
                    'actions' => [], 'line' => $no, 'has_assign' => false, 'lines' => ['line' => $no]];
                // properties or "}" on the same line
                $rest = trim($m[5] ?? '');
                if ($rest !== '') {
                    $closes = substr($rest, -1) === '}';
                    $body = $closes ? trim(substr($rest, 0, -1)) : $rest;
                    if ($body !== '') {
                        self::property($transition, $body, $wf, $no);
                    }
                    if ($closes) {
                        self::closeTransition($current, $transition);
                        $transition = null;
                    }
                }
                continue;
            }
            throw new SchemaException(Msg::of('msg_workflows.line_unclear', '{where}: not understood (expected: state, flow, a transition '
                . '"from -> to : label {" or "}"): {source}', ['where' => self::at($wf, $no), 'source' => $line]), $no);
        }
        if ($transition !== null) {
            throw new SchemaException(Msg::of('msg_workflows.transition_unclosed', "Workflow '{workflow}': The transition '{transition}' "
                . '(line {start}) is not closed with "}".', [
                    'workflow' => $current['name'], 'transition' => $transition['label'], 'start' => $transition['line'],
                ]), $transition['line']);
        }
        if ($current !== null) {
            throw new SchemaException(Msg::of('msg_workflows.unclosed', "Workflow '{workflow}' (line {start}) is not closed with \"}\".", [
                'workflow' => $current['name'], 'start' => $current['line'],
            ]), $current['line']);
        }
        return $workflows;
    }

    /** One property line key=value of a transition */
    private static function property(array &$t, string $line, string $wf, int $no): void
    {
        $where = Msg::of('msg_workflows.at_transition', "Workflow '{workflow}', line {line} (transition '{transition}')", [
            'workflow' => $wf, 'line' => $no, 'transition' => $t['label'],
        ]);
        if (!preg_match('/^(\w+)\s*=\s*(.*)$/u', $line, $m)) {
            throw new SchemaException(Msg::of('msg_workflows.property_unclear', '{where}: not understood (expected: allowed=..., assign=... '
                . 'or action=...): {source}', ['where' => $where, 'source' => $line]), $no);
        }
        $key = strtolower($m[1]);
        $value = trim($m[2]);
        if ($key === 'allowed') {
            if ($t['allowed'] !== null) {
                throw new SchemaException(Msg::of('msg_workflows.allowed_twice', '{where}: allowed= is given twice (separate several '
                    . 'entries with commas).', ['where' => $where]), $no);
            }
            $list = [];
            foreach (explode(',', $value) as $part) {
                if (!preg_match('/^(group|role)\s*:\s*(\S(?:.*\S)?)$/iu', trim($part), $p)) {
                    throw new SchemaException(Msg::of('msg_workflows.allowed_invalid', "{where}: allowed= expects group:<name> or "
                        . "role:<name>, separated by commas ('{value}').", ['where' => $where, 'value' => trim($part)]), $no);
                }
                $entry = ['type' => strtolower($p[1]), 'name' => $p[2]];
                if (!in_array($entry, $list, true)) {
                    $list[] = $entry;
                }
            }
            $t['allowed'] = $list;
            $t['lines']['allowed'] = $no;
        } elseif ($key === 'assign') {
            if ($t['has_assign']) {
                throw new SchemaException(Msg::of('msg_workflows.assign_twice', '{where}: assign= is given twice (only one assignment '
                    . 'target per transition).', ['where' => $where]), $no);
            }
            $t['has_assign'] = true;
            $t['lines']['assign'] = $no;
            if (strtolower($value) === 'initiator') {
                $t['assign'] = ['type' => 'initiator'];
            } elseif (preg_match('/^(group|user)\s*:\s*(\S(?:.*\S)?)$/iu', $value, $p) && strpos($p[2], ',') === false) {
                $t['assign'] = ['type' => strtolower($p[1]), 'name' => $p[2]];
            } else {
                throw new SchemaException(Msg::of('msg_workflows.assign_invalid', "{where}: assign= expects group:<name>, user:<name> "
                    . "or initiator ('{value}').", ['where' => $where, 'value' => $value]), $no);
            }
        } elseif ($key === 'action') {
            if (preg_match('/^(\w+)\s*:\s*(.*)$/u', $value, $p) && strtolower($p[1]) === self::WEBHOOK) {
                self::webhookAction($t, trim($p[2]), $where, $no);
                return;
            }
            if (!preg_match('/^(\w+)\s*:\s*(.*)$/u', $value, $p) || !isset(self::ACTIONS[strtolower($p[1])])) {
                throw new SchemaException(Msg::of('msg_workflows.action_unknown', "{where}: Unknown action '{value}' (allowed: {allowed}).", [
                    'where'   => $where, 'value' => $value,
                    'allowed' => implode(', ', array_map(function ($type) {
                        return $type . ':' . implode('|', array_keys(self::ACTIONS[$type]));
                    }, array_keys(self::ACTIONS))) . ', ' . self::WEBHOOK . ':<name>',
                ]), $no);
            }
            $type = strtolower($p[1]);
            $arg = strtolower(trim($p[2]));
            if (!array_key_exists($arg, self::ACTIONS[$type])) {
                throw new SchemaException(Msg::of('msg_workflows.action_value', "{where}: action={type} expects {choices} ('{value}').", [
                    'where' => $where, 'type' => $type, 'choices' => self::orList(array_keys(self::ACTIONS[$type])), 'value' => trim($p[2]),
                ]), $no);
            }
            if (in_array($type, array_column($t['actions'], 'type'), true)) {
                throw new SchemaException(Msg::of('msg_workflows.action_twice', '{where}: action={type} is given twice.', [
                    'where' => $where, 'type' => $type,
                ]), $no);
            }
            $t['actions'][] = ['type' => $type, 'value' => self::ACTIONS[$type][$arg]];
            $t['lines']['action:' . $type] = $no;
        } else {
            throw new SchemaException(Msg::of('msg_workflows.property_unknown', "{where}: Unknown property '{key}=' (allowed: allowed=, "
                . 'assign=, action=).', ['where' => $where, 'key' => $m[1]]), $no);
        }
    }

    /**
     * action=webhook:<name>: one line per webhook (a transition may call several), the same name twice is an error.
     * Only the syntax of the name is checked here; whether the webhook exists, Webhooks::checkWorkflows() decides.
     */
    private static function webhookAction(array &$t, string $name, Msg $where, int $no): void
    {
        if (!preg_match(self::WEBHOOK_NAME, $name)) {
            throw new SchemaException(Msg::of('msg_workflows.webhook_name', "{where}: action=webhook expects the name of a webhook "
                . "(letters, digits, _ and -, starting with a letter, at most 50 characters; '{name}').", ['where' => $where, 'name' => $name]), $no);
        }
        foreach ($t['actions'] as $a) {
            if ($a['type'] === self::WEBHOOK && self::fold($a['value']) === self::fold($name)) {
                throw new SchemaException(Msg::of('msg_workflows.webhook_twice', '{where}: action=webhook:{name} is given twice.', [
                    'where' => $where, 'name' => $name,
                ]), $no);
            }
        }
        $t['actions'][] = ['type' => self::WEBHOOK, 'value' => $name];
        $t['lines']['action:' . self::WEBHOOK . ':' . self::fold($name)] = $no;
    }

    private static function closeTransition(array &$wf, array $t): void
    {
        if ($t['allowed'] === null) {
            throw new SchemaException(Msg::of('msg_workflows.allowed_missing', "{where}: The transition '{transition}' ({from} -> {to}) "
                . 'has no allowed= (who may execute it? e.g. allowed=group:redaktion).', [
                    'where' => self::at($wf['name'], $t['line']), 'transition' => $t['label'], 'from' => $t['from'], 'to' => $t['to'],
                ]), $t['line']);
        }
        $wf['pos'][] = $t['lines']; // lines of the transition: only for positions(), not part of the definition
        unset($t['line'], $t['has_assign'], $t['lines']);
        $wf['transitions'][] = $t;
    }

    /** Completion of a workflow block: states, flow and the transitions for every flow pair */
    private static function finish(array $wf): array
    {
        $name = $wf['name'];
        if (!$wf['states']) {
            throw new SchemaException(Msg::of('msg_workflows.no_states', "Workflow '{workflow}' has no states (expected: state <name> \"Label\").", [
                'workflow' => $name,
            ]), $wf['line']);
        }
        if ($wf['flow'] === null) {
            throw new SchemaException(Msg::of('msg_workflows.no_flow', "Workflow '{workflow}' has no flow line (expected: flow a -> b -> c; "
                . 'the first state is the initial state of new records).', ['workflow' => $name]), $wf['line']);
        }
        for ($i = 0; $i + 1 < count($wf['flow']); $i++) {
            [$from, $to] = [$wf['flow'][$i], $wf['flow'][$i + 1]];
            $found = array_filter($wf['transitions'], function ($t) use ($from, $to) {
                return $t['from'] === $from && $t['to'] === $to;
            });
            if (!$found) {
                throw new SchemaException(Msg::of('msg_workflows.flow_step_missing', "Workflow '{workflow}': There is no transition for "
                    . 'the flow step {from} -> {to} (expected: {from} -> {to} : label { allowed=... }).', [
                        'workflow' => $name, 'from' => $from, 'to' => $to,
                    ]), $wf['flow_line']);
            }
        }
        $wf['initial'] = $wf['flow'][0];
        unset($wf['line'], $wf['flow_line'], $wf['pos']);
        return $wf;
    }

    /** "Workflow 'X', line N" - start of most messages */
    private static function at(string $workflow, int $line): Msg
    {
        return Msg::of('msg_workflows.at_line', "Workflow '{workflow}', line {line}", ['workflow' => $workflow, 'line' => $line]);
    }

    /** "Workflow file, line N" - outside of a workflow block */
    private static function fileAt(int $line): Msg
    {
        return Msg::of('msg_workflows.file_line', 'Workflow file, line {line}', ['line' => $line]);
    }

    /**
     * "a or b or c" (a single item as it is)
     *
     * @param array<int,string|int> $items at least one
     * @return Msg|string|int
     */
    private static function orList(array $items)
    {
        $items = array_values($items);
        if (count($items) === 1) {
            return $items[0];
        }
        return Msg::of('msg_workflows.or_list', '{a} or {b}', ['a' => $items[0], 'b' => self::orList(array_slice($items, 1))]);
    }

    /** Declared state name for a given value (case-insensitive), null = unknown */
    private static function stateName(array $wf, string $name): ?string
    {
        foreach ($wf['states'] as $s) {
            if (strcasecmp($s['name'], $name) === 0) {
                return $s['name'];
            }
        }
        return null;
    }

    /** Comparison form for labels and names of groups/roles/users: case-insensitive, also for umlauts */
    public static function fold(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
