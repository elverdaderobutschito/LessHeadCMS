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
 *   `user:<name>` or `initiator`. `action=` (optional, at most once per action type): `<type>:<value>`, so far only
 *   `set_visibility:true|false` (ACTIONS) - a further type is a further entry there.
 * - Comments (' at the start of a line, /' ... '/) and @startuml/@enduml are skipped; everything else is a schema error.
 *
 * Result: [ 'ArticleWorkflow' => [
 *     'name' => 'ArticleWorkflow',
 *     'states' => [ ['name' => 'draft', 'label' => 'Entwurf'], ... ],
 *     'flow' => ['draft', 'review'], 'initial' => 'draft',
 *     'transitions' => [ ['from', 'to', 'label', 'allowed' => [['type' => 'group', 'name' => 'redaktion'], ...],
 *         'assign' => null | ['type' => 'group'|'user', 'name'] | ['type' => 'initiator'],
 *         'actions' => [['type' => 'set_visibility', 'value' => true]] ] ],
 * ] ]
 */
final class WorkflowParser
{
    /** Suffix by which the main schema recognizes a workflow field type */
    public const SUFFIX = 'Workflow';

    /** Action type => check/conversion of the value (null = invalid) */
    private const ACTIONS = ['set_visibility' => ['true' => true, 'false' => false]];

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
                    throw new SchemaException("Workflow '{$current['name']}', Zeile $no: Die Transition '{$transition['label']}' (Zeile "
                        . "{$transition['line']}) wird nicht mit \"}\" geschlossen.", $transition['line']);
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
                    throw new SchemaException("Workflow-Datei, Zeile $no: nicht verständlich (erwartet: workflow <Klasse>Workflow { "
                        . "mit \"{\" auf derselben Zeile): $line", $no);
                }
                $name = $m[1];
                if (!preg_match(self::IDENT, $name) || strlen($name) <= strlen(self::SUFFIX)
                    || strcasecmp(substr($name, -strlen(self::SUFFIX)), self::SUFFIX) !== 0) {
                    throw new SchemaException("Workflow-Datei, Zeile $no: Ungültiger Workflow-Name '$name' (erwartet: "
                        . '<Klassenname>Workflow, z. B. ArticleWorkflow; Buchstaben, Ziffern, _ ohne Umlaute).', $no);
                }
                foreach (array_keys($workflows) as $other) {
                    if (strcasecmp($other, $name) === 0) {
                        throw new SchemaException("Workflow-Datei, Zeile $no: Workflow '$name' ist doppelt definiert.", $no);
                    }
                }
                $current = ['name' => $name, 'states' => [], 'flow' => null, 'initial' => null, 'transitions' => [], 'line' => $no,
                    'flow_line' => null, 'pos' => []];
                continue;
            }
            $wf = $current['name'];
            // the next workflow block starts before this one is closed (usually the "}" of a transition before it is missing)
            if (preg_match('/^workflow\s+\S+\s*\{$/i', $line)) {
                throw new SchemaException("Workflow '$wf' (Zeile {$current['line']}) wird nicht mit \"}\" geschlossen (Zeile $no beginnt "
                    . 'schon der nächste Workflow - fehlt davor die "}" einer Transition?).', $current['line']);
            }
            if ($line === '}') {
                $positions[$wf] = $current['pos'];
                $workflows[$wf] = self::finish($current);
                $current = null;
                continue;
            }
            if (preg_match('/^state\b\s*(.*)$/i', $line, $m)) {
                if (!preg_match('/^([^\s"]+)(?:\s+"([^"]*)")?$/', $m[1], $s)) {
                    throw new SchemaException("Workflow '$wf', Zeile $no: state-Zeile nicht verständlich (erwartet: state <name> "
                        . "\"Beschriftung\"): $line", $no);
                }
                if (!preg_match(self::IDENT, $s[1])) {
                    throw new SchemaException("Workflow '$wf', Zeile $no: Ungültiger Zustandsname '{$s[1]}' (erlaubt: Buchstaben, "
                        . 'Ziffern, _ ohne Umlaute, nicht mit Ziffer beginnend).', $no);
                }
                if (self::stateName($current, $s[1]) !== null) {
                    throw new SchemaException("Workflow '$wf', Zeile $no: Zustand '{$s[1]}' ist doppelt definiert.", $no);
                }
                $label = trim($s[2] ?? '');
                $current['states'][] = ['name' => $s[1], 'label' => $label !== '' ? $label : $s[1]];
                continue;
            }
            if (preg_match('/^flow\b\s*(.*)$/i', $line, $m)) {
                if ($current['flow'] !== null) {
                    throw new SchemaException("Workflow '$wf', Zeile $no: Nur eine flow-Zeile je Workflow möglich.", $no);
                }
                $flow = [];
                foreach (explode('->', $m[1]) as $part) {
                    $part = trim($part);
                    $state = $part !== '' ? self::stateName($current, $part) : null;
                    if ($state === null) {
                        throw new SchemaException($part === ''
                            ? "Workflow '$wf', Zeile $no: flow-Zeile nicht verständlich (erwartet: flow a -> b -> c): $line"
                            : "Workflow '$wf', Zeile $no: flow nennt den unbekannten Zustand '$part' (Zustände müssen vorher mit "
                                . 'state deklariert sein).', $no);
                    }
                    if (in_array($state, $flow, true)) {
                        throw new SchemaException("Workflow '$wf', Zeile $no: Zustand '$state' steht mehrfach in flow.", $no);
                    }
                    $flow[] = $state;
                }
                $current['flow'] = $flow;
                $current['flow_line'] = $no;
                continue;
            }
            if (preg_match('/^(\S+)\s*->\s*([^\s:]+)\s*(?::\s*(.*?))?\s*(\{(.*))?$/u', $line, $m)) {
                if (!isset($m[4]) || $m[4] === '') {
                    throw new SchemaException("Workflow '$wf', Zeile $no: Transition ohne \"{\" (erwartet: von -> nach : label { "
                        . "mit allowed=... in den Folgezeilen): $line", $no);
                }
                $states = [];
                foreach ([$m[1], $m[2]] as $part) {
                    $state = self::stateName($current, $part);
                    if ($state === null) {
                        throw new SchemaException("Workflow '$wf', Zeile $no: Transition nennt den unbekannten Zustand '$part'.", $no);
                    }
                    $states[] = $state;
                }
                $label = trim($m[3] ?? '');
                if ($label === '' || !preg_match(self::LABEL, $label)) {
                    throw new SchemaException("Workflow '$wf', Zeile $no: " . ($label === '' ? 'Transition ohne Label'
                        : "Ungültiges Transitions-Label '$label'") . ' (erwartet: von -> nach : label {, Label aus Buchstaben, Ziffern, '
                        . '_ - und Leerzeichen).', $no);
                }
                foreach ($current['transitions'] as $t) {
                    if ($t['from'] === $states[0] && self::fold($t['label']) === self::fold($label)) {
                        throw new SchemaException("Workflow '$wf', Zeile $no: Das Label '$label' gibt es am Zustand '{$states[0]}' "
                            . 'schon (Labels müssen je Ausgangszustand eindeutig sein).', $no);
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
            throw new SchemaException("Workflow '$wf', Zeile $no: nicht verständlich (erwartet: state, flow, eine Transition "
                . "\"von -> nach : label {\" oder \"}\"): $line", $no);
        }
        if ($transition !== null) {
            throw new SchemaException("Workflow '{$current['name']}': Die Transition '{$transition['label']}' (Zeile "
                . "{$transition['line']}) wird nicht mit \"}\" geschlossen.", $transition['line']);
        }
        if ($current !== null) {
            throw new SchemaException("Workflow '{$current['name']}' (Zeile {$current['line']}) wird nicht mit \"}\" geschlossen.", $current['line']);
        }
        return $workflows;
    }

    /** One property line key=value of a transition */
    private static function property(array &$t, string $line, string $wf, int $no): void
    {
        $where = "Workflow '$wf', Zeile $no (Transition '{$t['label']}')";
        if (!preg_match('/^(\w+)\s*=\s*(.*)$/u', $line, $m)) {
            throw new SchemaException("$where: nicht verständlich (erwartet: allowed=..., assign=... oder action=...): $line", $no);
        }
        $key = strtolower($m[1]);
        $value = trim($m[2]);
        if ($key === 'allowed') {
            if ($t['allowed'] !== null) {
                throw new SchemaException("$where: allowed= ist doppelt angegeben (mehrere Angaben mit Komma trennen).", $no);
            }
            $list = [];
            foreach (explode(',', $value) as $part) {
                if (!preg_match('/^(group|role)\s*:\s*(\S(?:.*\S)?)$/iu', trim($part), $p)) {
                    throw new SchemaException("$where: allowed= erwartet group:<name> oder role:<name>, mit Komma getrennt "
                        . "('" . trim($part) . "').", $no);
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
                throw new SchemaException("$where: assign= ist doppelt angegeben (nur ein Zuweisungsziel je Transition).", $no);
            }
            $t['has_assign'] = true;
            $t['lines']['assign'] = $no;
            if (strtolower($value) === 'initiator') {
                $t['assign'] = ['type' => 'initiator'];
            } elseif (preg_match('/^(group|user)\s*:\s*(\S(?:.*\S)?)$/iu', $value, $p) && strpos($p[2], ',') === false) {
                $t['assign'] = ['type' => strtolower($p[1]), 'name' => $p[2]];
            } else {
                throw new SchemaException("$where: assign= erwartet group:<name>, user:<name> oder initiator ('$value').", $no);
            }
        } elseif ($key === 'action') {
            if (!preg_match('/^(\w+)\s*:\s*(.*)$/u', $value, $p) || !isset(self::ACTIONS[strtolower($p[1])])) {
                throw new SchemaException("$where: Unbekannte Aktion '$value' (erlaubt: "
                    . implode(', ', array_map(function ($type) {
                        return $type . ':' . implode('|', array_keys(self::ACTIONS[$type]));
                    }, array_keys(self::ACTIONS))) . ').', $no);
            }
            $type = strtolower($p[1]);
            $arg = strtolower(trim($p[2]));
            if (!array_key_exists($arg, self::ACTIONS[$type])) {
                throw new SchemaException("$where: action=$type erwartet " . implode(' oder ', array_keys(self::ACTIONS[$type]))
                    . " ('" . trim($p[2]) . "').", $no);
            }
            if (in_array($type, array_column($t['actions'], 'type'), true)) {
                throw new SchemaException("$where: action=$type ist doppelt angegeben.", $no);
            }
            $t['actions'][] = ['type' => $type, 'value' => self::ACTIONS[$type][$arg]];
            $t['lines']['action:' . $type] = $no;
        } else {
            throw new SchemaException("$where: Unbekannte Angabe '{$m[1]}=' (erlaubt: allowed=, assign=, action=).", $no);
        }
    }

    private static function closeTransition(array &$wf, array $t): void
    {
        if ($t['allowed'] === null) {
            throw new SchemaException("Workflow '{$wf['name']}', Zeile {$t['line']}: Die Transition '{$t['label']}' ({$t['from']} "
                . "-> {$t['to']}) hat kein allowed= (wer darf sie ausführen? z. B. allowed=group:redaktion).", $t['line']);
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
            throw new SchemaException("Workflow '$name' hat keine Zustände (erwartet: state <name> \"Beschriftung\").", $wf['line']);
        }
        if ($wf['flow'] === null) {
            throw new SchemaException("Workflow '$name' hat keine flow-Zeile (erwartet: flow a -> b -> c; der erste Zustand ist "
                . 'der Startzustand neuer Datensätze).', $wf['line']);
        }
        for ($i = 0; $i + 1 < count($wf['flow']); $i++) {
            [$from, $to] = [$wf['flow'][$i], $wf['flow'][$i + 1]];
            $found = array_filter($wf['transitions'], function ($t) use ($from, $to) {
                return $t['from'] === $from && $t['to'] === $to;
            });
            if (!$found) {
                throw new SchemaException("Workflow '$name': Zum flow-Schritt $from -> $to gibt es keine Transition (erwartet: "
                    . "$from -> $to : label { allowed=... }).", $wf['flow_line']);
            }
        }
        $wf['initial'] = $wf['flow'][0];
        unset($wf['line'], $wf['flow_line'], $wf['pos']);
        return $wf;
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
