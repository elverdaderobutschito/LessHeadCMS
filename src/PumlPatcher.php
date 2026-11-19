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
 * Writes a model JSON edited in the diagram editor back into the ORIGINAL .puml text by changing only the affected
 * lines (instead of generating the whole text anew like PumlExporter::toPuml(), which loses comments, notes, skinparam,
 * blank lines, indentation and order). The .puml is the source of truth; everything the editor did not touch stays
 * byte-identical, including the BOM and the line breaks (CRLF/LF) of every line.
 *
 * How: PumlParser::sourceMap() yields the model JSON of the original text plus the line of every class, field, section,
 * enum value, relationship, package block and decorative statement. The edited model JSON is compared with it element by
 * element via the key the editor keeps for every element (the model-JSON ID of the original text; new elements have none
 * of the original's keys):
 *  - changed (the line as PumlExporter would write it differs): only this line is rewritten, keeping its indentation and,
 *    for fields, the visibility sign; class lines keep their colour, relationship lines their arrow (colour, direction)
 *    and the side the classes stand on, as long as the kind of relationship stays the same
 *  - new: inserted next to its neighbours (field after the field before it in the class body, class after the last class
 *    of its package or into the package block, relationship after the last relationship, enum after the last enum)
 *  - deleted: the line or block is removed, together with the comment directly above it (no blank line in between) and
 *    the notes attached to it (`note … of Class`, `note … of Class::field`, connecting lines `N1 .. Class`) - all of
 *    them listed in 'removed' for the preview
 *  - renamed: the line is rewritten as above; notes referring to the class/field are renamed along
 *  - class moved to another package: the block (with the comment directly above it) moves into the package block
 *  - a section without fields left: its separator line is removed (the parser would reject it)
 * New classes get an explicit `+ id: int` only if the original text writes the id explicitly in most of its classes
 * (otherwise it stays implicit, as the parser adds it).
 *
 * Afterwards the result is parsed again and compared semantically with the model JSON of toPuml(edited model) - the
 * proven complete export - and its warnings with those of the original text (and of the export): a warning the patched
 * text has and neither of them (e.g. a line left over from a deleted note, now an "unknown statement") means the patch
 * broke the text, even if the model is right. If the check fails, or a change cannot be expressed safely as a patch (e.g.
 * a line that shares a block comment with other content, enum values on the enum line), the result is mode 'export': the
 * complete export with the list of what would be lost ('lost', line + text) and the reason. Never silently: the editor
 * shows the list and the admin has to confirm it explicitly. A failed check is a fault of the patcher ('verify_failed',
 * logged by the caller in data/patcher-failures/, see PatcherFailures).
 *
 * A comment directly above a deleted element goes with it - unless it heads a group of elements of the same kind (see
 * commentTop()): then only with the whole group.
 */
final class PumlPatcher
{
    /** @var string[] lines of the original text without line break (BOM removed) */
    private $lines;
    /** @var bool[] line ended with "\r\n" */
    private $cr;
    /** @var string line break for inserted lines */
    private $eol;
    /** @var bool[] line lies (partly) in a block comment /' ... '/ */
    private $inComment = [];
    /** @var array<int,array{0:int,1:int}> block comments: [first line, last line] */
    private $comments = [];
    /** @var bool */
    private $bom = false;
    /** @var bool[] line shares a block comment with other content (never changed by the patcher) */
    private $mixed = [];
    /** @var array positions of PumlParser::sourceMap() */
    private $pos;
    /** @var array model JSON of the original text */
    private $orig;
    /** @var array edited model JSON */
    private $edit;
    /** @var bool[] structural lines (class/field/enum/relation/package lines) - everything else may be a comment or decoration */
    private $structural = [];
    /** @var string indentation unit of the class bodies */
    private $unit = '  ';

    /** @var array<int,string> line => new text */
    private $replace = [];
    /** @var array<int,bool> line => deleted */
    private $delete = [];
    /** @var array<int,array> line => lines inserted before it ([text, eol|null]) */
    private $before = [];
    /** @var array<int,array> line => lines inserted after it */
    private $after = [];
    /** @var array<int,array> line => lines written after it AND after everything inserted after it (the "}" of a split enum line) */
    private $closing = [];
    /** @var array<int,bool> lines deleted together with an element (comments, notes) */
    private $removedWith = [];
    /** @var array<string,array> new package blocks: name => ['anchor' => line, 'items' => [lines of one class, ...]] */
    private $newPackages = [];
    /** @var array<int,array{0:int,1:int}> moved ranges: first line => [first, last] */
    private $moved = [];
    /**
     * @var array<int,array{0:string,1:int}> elements of the text by their first line => [kind, last line]; kinds: 'type'
     *      (class or enum block), 'field', 'section', 'value' (enum value on a line of its own), 'relation'
     */
    private $units = [];
    /** @var array<int,bool> class lines of the classes moved to another package */
    private $moving = [];
    /** @var array<int,array{0:int,1:int,2:bool,3:bool}> deletions still to finish: [first, last, with attached comment, tidy] */
    private $pending = [];

    /** @var callable|null tests only: changes the patched text before the check (simulates a faulty patch) */
    public static $testHook = null;

    /**
     * @param array $edited model JSON from the editor (PumlExporter::check() passed)
     * @return array{source:string, mode:string, removed:array, lost:array, reason:?Msg, verify_failed:bool}
     *         mode 'unchanged' (source === original), 'patch', or 'export' (complete export, 'lost' lists what goes missing);
     *         removed/lost: [['line' => 1-based number, 'text' => original line], ...]; verify_failed: the check after the
     *         patch failed (a fault of the patcher, not a known case that cannot be patched)
     * @throws SchemaException if the edited model is not a valid schema (its complete export does not parse)
     */
    public static function patch(string $source, array $edited, ?array $workflows = null): array
    {
        $reference = PumlExporter::toPuml($edited);
        $referenceModel = PumlParser::modelJson($reference, $workflows);
        $expected = self::canonical($referenceModel);
        try {
            $map = PumlParser::sourceMap($source, $workflows);
        } catch (SchemaException $e) {
            return self::fallback($source, $reference, null, Msg::of('msg_patch.original_invalid', 'The previous text is not a valid '
                . 'diagram ({error}), so it cannot be changed line by line.', ['error' => $e->msg]));
        }
        try {
            $patcher = new self($source, $map, $edited);
            [$text, $removed] = $patcher->run();
        } catch (PatchUnsafe $u) {
            // msg_patch.verify from inside the patcher: a state that cannot occur - a fault like a failed check
            return self::fallback($source, $reference, $map, $u->msg, $u->msg->code === 'msg_patch.verify');
        }
        if (self::$testHook !== null) {
            $text = (string) (self::$testHook)($text);
        }
        try {
            $patched = PumlParser::modelJson($text, $workflows);
            $ok = self::canonical($patched) === $expected;
        } catch (SchemaException $e) {
            $patched = null;
            $ok = false;
        }
        if (!$ok) {
            return self::fallback($source, $reference, $map, Msg::of('msg_patch.verify', 'The changed text would not match the diagram '
                . '(check after the change).'), true);
        }
        $extra = self::newWarnings($patched, [$map['model'], $referenceModel]);
        if ($extra) {
            return self::fallback($source, $reference, $map, Msg::of('msg_patch.verify_warnings', 'The changed text would produce a '
                . 'warning the previous text does not have ({warning}) (check after the change).', ['warning' => $extra[0]]), true);
        }
        return ['source' => $text, 'mode' => $text === $source ? 'unchanged' : 'patch', 'removed' => $removed, 'lost' => [],
            'reason' => null, 'verify_failed' => false];
    }

    /**
     * Warnings of model JSON $m that none of $before has (as a multiset: two equal ones where there was one count as a new
     * one). Compared by code and content without the line number (lines shift with every change) and without the names of
     * classes and enums (a renamed class keeps its warning, e.g. its colour).
     *
     * @param array[] $before model JSONs of the original text and of the complete export
     * @return string[] texts of the new warnings
     */
    public static function newWarnings(array $m, array $before): array
    {
        $key = function ($code): string {
            $c = json_decode((string) json_encode($code), true);
            if (($c['code'] ?? null) === 'msg_schema.warn_line' && is_array($c['params']['text'] ?? null)) {
                $c = $c['params']['text'];
            }
            $params = (array) ($c['params'] ?? []);
            unset($params['line'], $params['class'], $params['enum']);
            ksort($params);
            return (string) json_encode([$c['code'] ?? null, $params], JSON_UNESCAPED_UNICODE);
        };
        $allowed = []; // per warning the most any of $before has
        foreach ($before as $b) {
            $count = [];
            foreach ($b['warning_codes'] ?? [] as $code) {
                $k = $key($code);
                $count[$k] = ($count[$k] ?? 0) + 1;
                $allowed[$k] = max($allowed[$k] ?? 0, $count[$k]);
            }
        }
        $new = [];
        foreach ($m['warning_codes'] ?? [] as $i => $code) {
            $k = $key($code);
            if (($allowed[$k] ?? 0) > 0) {
                $allowed[$k]--;
            } else {
                $new[] = (string) ($m['warnings'][$i] ?? $k);
            }
        }
        return $new;
    }

    /** Model JSON for the semantic comparison: without warnings; classes and enums by ID (their order in the text has no effect) */
    public static function canonical(array $m): array
    {
        unset($m['warnings'], $m['warning_codes']);
        foreach (['entities', 'enums', 'media_meta'] as $k) {
            $list = $m[$k] ?? [];
            usort($list, function ($a, $b) {
                return strcmp($a['id'], $b['id']);
            });
            $m[$k] = $list;
        }
        return $m;
    }

    /**
     * Complete export instead of a patch: 'lost' = every line of the original that the export does not reproduce - comments,
     * decorative statements, other blocks (together), `@startuml Name`, and structural lines with a colour.
     */
    private static function fallback(string $source, string $reference, ?array $map, Msg $reason, bool $verifyFailed = false): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $source));
        $lines = explode("\n", $text);
        $structural = $map !== null ? self::structuralLines($map['positions'], $lines) : [];
        $lost = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || $t === '@startuml' || $t === '@enduml') {
                continue;
            }
            // written anew by the export - unless a colour or (part of) a block comment on the line would get lost
            $extra = strpos((string) preg_replace('/"[^"]*"/', '""', $t), '#') !== false || strpos($t, "/'") !== false || strpos($t, "'/") !== false;
            if (isset($structural[$i]) && !$extra) {
                continue;
            }
            if ($map === null && preg_match('/^(?:(?:abstract\s+)?class\s|enum\s|package\s|[+\-#~]|\}$)/i', $t) && strpos($t, '#') === false) {
                continue;
            }
            $lost[] = ['line' => $i + 1, 'text' => $line];
        }
        return ['source' => $reference, 'mode' => 'export', 'removed' => [], 'lost' => $lost, 'reason' => $reason,
            'verify_failed' => $verifyFailed];
    }

    /** @return array<int,bool> lines that toPuml() writes anew (class, field, section, enum, relationship, package lines) */
    private static function structuralLines(array $pos, array $lines): array
    {
        $s = [];
        foreach ($pos['classes'] as $c) {
            $s[$c['head']] = $s[$c['end']] = true;
            foreach (array_merge(array_values($c['fields']), array_values($c['sections'])) as $l) {
                $s[$l] = true;
            }
        }
        foreach ($pos['enums'] as $en) {
            $s[$en['head']] = $s[$en['end']] = true;
            foreach ($en['values'] as $v) {
                $s[$v[1]] = true;
            }
        }
        foreach ($pos['relations'] as $l) {
            $s[$l] = true;
        }
        foreach ($pos['blocks'] as $b) {
            if ($b['package'] !== null) {
                $s[$b['head']] = true;
                if ($b['end'] !== null) {
                    $s[$b['end']] = true;
                }
            }
        }
        return $s;
    }

    private function __construct(string $source, array $map, array $edited)
    {
        $this->orig = $map['model'];
        $this->pos = $map['positions'];
        $this->edit = $edited;
        $this->bom = strncmp($source, "\xEF\xBB\xBF", 3) === 0;
        $body = $this->bom ? substr($source, 3) : $source;
        if (preg_match("/\r(?!\n)/", $body)) {
            throw new PatchUnsafe(Msg::of('msg_patch.line_breaks', 'The text contains line breaks of an unusual kind (CR without LF).'));
        }
        $this->lines = explode("\n", $body);
        $crlf = 0;
        foreach ($this->lines as $i => $line) {
            $this->cr[$i] = substr($line, -1) === "\r";
            if ($this->cr[$i]) {
                $this->lines[$i] = substr($line, 0, -1);
                $crlf++;
            }
        }
        $this->eol = $crlf * 2 > count($this->lines) - 1 ? "\r\n" : "\n";
        // block comments: lines inside, and lines that share one with other content
        $text = implode("\n", $this->lines);
        preg_match_all("#/'.*?'/#s", $text, $comments, PREG_OFFSET_CAPTURE);
        foreach ($comments[0] as [$comment, $offset]) {
            $first = substr_count($text, "\n", 0, $offset);
            $last = $first + substr_count($comment, "\n");
            for ($l = $first; $l <= $last; $l++) {
                $this->inComment[$l] = true;
            }
            $this->comments[] = [$first, $last];
            $lineStart = strrpos(substr($text, 0, $offset), "\n");
            $before = substr($text, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));
            $endOffset = $offset + strlen($comment);
            $lineEnd = strpos($text, "\n", $endOffset);
            $after = substr($text, $endOffset, ($lineEnd === false ? strlen($text) : $lineEnd) - $endOffset);
            if (trim($before) !== '') {
                $this->mixed[$first] = true;
            }
            if (trim($after) !== '') {
                $this->mixed[$last] = true;
            }
        }
        $this->structural = self::structuralLines($this->pos, $this->lines);
        foreach ($this->pos['classes'] as $c) {
            $this->units[$c['head']] = ['type', $c['end']];
            foreach ($c['fields'] as $l) {
                if ($l !== $c['head']) {
                    $this->units[$l] = ['field', $l];
                }
            }
            foreach ($c['sections'] as $l) {
                $this->units[$l] = ['section', $l];
            }
        }
        foreach ($this->pos['enums'] as $en) {
            $this->units[$en['head']] = ['type', $en['end']];
            foreach ($en['values'] as [$value, $l, $alone]) {
                if ($alone && $l !== $en['head']) {
                    $this->units[$l] = ['value', $l];
                }
            }
        }
        foreach ($this->pos['relations'] as $l) {
            $this->units[$l] = ['relation', $l];
        }
        // indentation unit: first field line relative to its class line
        foreach ($this->pos['classes'] as $c) {
            foreach ($c['fields'] as $l) {
                $field = self::indentOf($this->lines[$l]);
                $head = self::indentOf($this->lines[$c['head']]);
                if (strlen($field) > strlen($head) && substr($field, 0, strlen($head)) === $head) {
                    $this->unit = substr($field, strlen($head));
                }
                break 2;
            }
        }
    }

    /** @return array{0:string,1:array} patched text, lines removed together with elements */
    private function run(): array
    {
        $this->enums();
        $this->classes();
        $this->relations();
        $this->finishDeletions();
        return [$this->render(), $this->removedList()];
    }

    // --- enums ---

    private function enums(): void
    {
        $orig = [];
        foreach ($this->orig['enums'] as $en) {
            if (empty($en['workflow'])) {
                $orig[$en['id']] = $en;
            }
        }
        $kept = [];
        $new = [];
        foreach ($this->edit['enums'] ?? [] as $en) {
            if (!empty($en['workflow'])) {
                continue;
            }
            $key = $en['key'] ?? $en['id'] ?? '';
            if (isset($orig[$key])) {
                $kept[$key] = true;
                $this->changeEnum($orig[$key], $en);
            } else {
                $new[] = $en;
            }
        }
        foreach ($orig as $id => $en) {
            if (!isset($kept[$id])) {
                $p = $this->enumPos($en);
                $this->deleteBlock($p['head'], $p['end']);
            }
        }
        if (!$new) {
            return;
        }
        // after the last remaining enum, otherwise before the first class (or package block)
        $last = null;
        foreach ($orig as $id => $en) {
            $p = $this->enumPos($en);
            if (isset($kept[$id]) && ($last === null || $p['end'] > $last['end'])) {
                $last = $p;
            }
        }
        foreach ($new as $en) {
            if ($last !== null) {
                $this->insertAfter($last['end'], array_merge([''], $this->indent(PumlExporter::enumLines($en, $this->unit),
                    self::indentOf($this->lines[$last['head']]))));
                continue;
            }
            $first = $this->firstTopLevel();
            if ($first === null) {
                $this->insertAfter($this->startLine(), array_merge([''], PumlExporter::enumLines($en, $this->unit)));
            } else {
                $this->insertBefore($first, array_merge(PumlExporter::enumLines($en, $this->unit), ['']));
            }
        }
    }

    private function enumPos(array $en): array
    {
        return $this->pos['enums'][strtolower($en['name'])];
    }

    private function changeEnum(array $o, array $e): void
    {
        $p = $this->enumPos($o);
        if ($o['name'] !== $e['name']) {
            $this->setLine($p['head'], (string) preg_replace('/^(\s*enum\s+)' . preg_quote($o['name'], '/') . '(?![A-Za-z0-9_])/i',
                '${1}' . $e['name'], $this->line($p['head'])));
        }
        if ($o['values'] === $e['values']) {
            return;
        }
        $indent = null;
        foreach ($p['values'] as [$value, $line, $alone]) {
            if ($alone) {
                $indent = $indent ?? self::indentOf($this->lines[$line]);
            }
        }
        $indent = $indent ?? self::indentOf($this->lines[$p['head']]) . $this->unit;
        // last value before the closing "}" on the same line (`  OFFEN }`): split it into the value line and a line with "}"
        // (only this line changes) - then it can be changed, deleted or followed like any other value
        foreach ($p['values'] as $k => [$value, $line, $alone]) {
            if (!$alone && $line === $p['end'] && $line !== $p['head']) {
                $this->setLine($line, $indent . $value);
                $this->closing[$line][] = self::indentOf($this->lines[$p['head']]) . '}';
                $p['values'][$k][2] = true;
            }
        }
        foreach ($p['values'] as [$value, $line, $alone]) {
            if (!$alone) {
                throw new PatchUnsafe(Msg::of('msg_patch.enum_layout', "Line {line}: the values of enum '{enum}' stand on the enum line "
                    . 'or before the closing "}" - they cannot be changed one by one.', ['line' => $line + 1, 'enum' => $o['name']]));
            }
        }
        $lineOf = [];
        foreach ($p['values'] as [$value, $line]) {
            $lineOf[$value] = $line;
        }
        // align old and new values (LCS); in every gap the old values are replaced in place pairwise, surplus ones deleted,
        // additional new ones inserted after the last line of the gap
        $anchor = $p['head'];
        foreach (self::align($o['values'], $e['values']) as [$olds, $news]) {
            if ($news === null) { // common value
                $anchor = $lineOf[$olds[0]];
                continue;
            }
            for ($k = 0; $k < max(count($olds), count($news)); $k++) {
                if ($k < count($olds) && $k < count($news)) {
                    $l = $lineOf[$olds[$k]];
                    $this->setLine($l, self::indentOf($this->lines[$l]) . $news[$k]);
                    $anchor = $l;
                } elseif ($k < count($olds)) {
                    $this->deleteLine($lineOf[$olds[$k]], true);
                    $anchor = $lineOf[$olds[$k]];
                } else {
                    $this->insertAfter($anchor, [$indent . $news[$k]]);
                }
            }
        }
    }

    /**
     * Alignment of two lists (longest common subsequence): list of [olds, news] - a common element as [[x], null], a gap
     * as [old elements, new elements] (one of the two may be empty).
     *
     * @return array<int,array{0:array,1:?array}>
     */
    private static function align(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i][$j] = $a[$i] === $b[$j] ? $len[$i + 1][$j + 1] + 1 : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }
        $out = [];
        $gapA = [];
        $gapB = [];
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                if ($gapA || $gapB) {
                    $out[] = [$gapA, $gapB];
                    $gapA = $gapB = [];
                }
                $out[] = [[$a[$i]], null];
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $len[$i][$j + 1] >= $len[$i + 1][$j])) {
                $gapB[] = $b[$j++];
            } else {
                $gapA[] = $a[$i++];
            }
        }
        if ($gapA || $gapB) {
            $out[] = [$gapA, $gapB];
        }
        return $out;
    }

    // --- classes ---

    private function classes(): void
    {
        $orig = [];
        foreach ($this->orig['entities'] as $c) {
            $orig[$c['id']] = $c;
        }
        foreach ($this->orig['media_meta'] ?? [] as $c) {
            $orig[$c['id']] = $c + ['meta_class' => true, 'media' => []];
        }
        $edit = [];
        foreach ($this->edit['entities'] as $c) {
            $edit[] = $c;
        }
        foreach ($this->edit['media_meta'] ?? [] as $c) {
            $edit[] = $c + ['meta_class' => true, 'media' => []];
        }
        $kept = [];
        $new = [];
        $moves = [];
        foreach ($edit as $e) {
            $key = $e['key'] ?? $e['id'] ?? '';
            if (!isset($orig[$key]) || !empty($orig[$key]['meta_class']) !== !empty($e['meta_class'])) {
                $new[] = $e;
                continue;
            }
            $kept[$key] = $e;
            $o = $orig[$key];
            $this->changeClass($o, $e);
            if (($o['package'] ?? null) !== ($e['package'] ?? null)) {
                $moves[] = [$o, $e];
            }
        }
        // classes that stay where they are: anchors for new and moved classes
        $staying = [];
        foreach ($orig as $id => $o) {
            if (isset($kept[$id]) && ($o['package'] ?? null) === ($kept[$id]['package'] ?? null)) {
                $staying[] = $o;
            }
        }
        foreach ($orig as $id => $o) {
            if (!isset($kept[$id])) {
                $p = $this->classPos($o);
                $this->deleteBlock($p['head'], $p['end']);
                $this->deleteNotes($o['name'], null);
            }
        }
        foreach ($moves as [$o, $e]) {
            $this->moving[$this->classPos($o)['head']] = true;
        }
        foreach ($moves as [$o, $e]) {
            $p = $this->classPos($o);
            $top = $this->commentTop($p['head']);
            $this->checkRange($top, $p['end']);
            $this->moved[$top] = [$top, $p['end']];
            $this->tidy($top, $p['end']);
            $this->place($e['package'] ?? null, $staying, null, [$top, $p['end'], self::indentOf($this->lines[$p['head']])]);
        }
        $explicitId = $this->explicitIdConvention();
        foreach ($new as $e) {
            $withId = $explicitId && empty($e['meta_class']) && ($e['extends'] ?? null) === null;
            if ($withId && !array_filter($e['fields'], function ($f) {
                return !empty($f['primary']);
            })) {
                $e['fields'] = array_merge([['name' => 'id', 'type' => 'int', 'required' => false, 'primary' => true]], $e['fields']);
                $e['field_order'] = array_merge(['id'], $e['field_order'] ?? []);
            }
            $this->place($e['package'] ?? null, $staying, PumlExporter::classLines($e, $this->unit, $withId), null);
        }
        $this->flushPackages();
    }

    private function classPos(array $c): array
    {
        return $this->pos['classes'][strtolower($c['name'])];
    }

    /** Do most classes of the original text (with an id of their own) write `+ id: int` explicitly? */
    private function explicitIdConvention(): bool
    {
        $explicit = $implicit = 0;
        foreach ($this->orig['entities'] as $c) {
            if ($c['extends'] !== null) {
                continue;
            }
            if (isset($this->classPos($c)['fields']['id'])) {
                $explicit++;
            } else {
                $implicit++;
            }
        }
        return $explicit > $implicit;
    }

    /**
     * Places a new class ($lines) or a moved block ($range = [first, last, indentation of its class line]) for package
     * $package: after the last staying class of that package; otherwise into an existing (empty) package block of that
     * name; otherwise into a new package block after the last class; without a package after the last class outside of
     * packages, otherwise after the last class or block.
     */
    private function place(?string $package, array $staying, ?array $lines, ?array $range): void
    {
        $same = null;
        foreach ($staying as $c) {
            if (($c['package'] ?? null) === $package) {
                $p = $this->classPos($c);
                if ($same === null || $p['end'] > $same['end']) {
                    $same = $p;
                }
            }
        }
        $payload = function (string $indent) use ($lines, $range): array {
            if ($lines !== null) {
                return $this->indent($lines, $indent);
            }
            return [['range' => $range, 'indent' => $indent]];
        };
        if ($same !== null) {
            $this->insertAfter($same['end'], array_merge([''], $payload(self::indentOf($this->lines[$same['head']]))));
            return;
        }
        if ($package !== null) {
            foreach ($this->pos['blocks'] as $b) {
                if ($b['package'] === $package && $b['end'] !== null && $b['end'] > $b['head']) {
                    $indent = self::indentOf($this->lines[$b['head']]) . $this->unit;
                    $gap = trim($this->lines[$b['end'] - 1]) !== '' && $b['end'] - 1 !== $b['head'] ? [''] : [];
                    $this->insertBefore($b['end'], array_merge($gap, $payload($indent)));
                    return;
                }
            }
        }
        $anchor = $this->lastTopLevel($staying) ?? $this->startLine();
        if ($package === null) {
            $this->insertAfter($anchor, array_merge([''], $payload('')));
            return;
        }
        // new package block: all classes for it go into ONE block (written by flushPackages())
        $this->newPackages[$package]['anchor'] = $this->newPackages[$package]['anchor'] ?? $anchor;
        $this->newPackages[$package]['items'][] = $payload($this->unit);
    }

    /** Writes the new package blocks collected by place() */
    private function flushPackages(): void
    {
        foreach ($this->newPackages as $package => $p) {
            $lines = ['', PumlExporter::packageLine((string) $package)];
            foreach ($p['items'] as $k => $item) {
                $lines = array_merge($lines, $k > 0 ? [''] : [], $item);
            }
            $lines[] = '}';
            $this->insertAfter($p['anchor'], $lines);
        }
        $this->newPackages = [];
    }

    /** End line of the last top-level construct holding a staying class (its outermost block), or of the last block/enum */
    private function lastTopLevel(array $staying): ?int
    {
        $last = null;
        foreach ($staying as $c) {
            $end = $this->outermostEnd($this->classPos($c)['head']) ?? $this->classPos($c)['end'];
            $last = $last === null ? $end : max($last, $end);
        }
        if ($last === null) {
            foreach ($this->pos['blocks'] as $b) {
                if ($b['end'] !== null) {
                    $last = $last === null ? $b['end'] : max($last, $b['end']);
                }
            }
        }
        if ($last === null) {
            foreach ($this->pos['enums'] as $en) {
                $last = $last === null ? $en['end'] : max($last, $en['end']);
            }
        }
        return $last;
    }

    private function outermostEnd(int $line): ?int
    {
        $best = null;
        foreach ($this->pos['blocks'] as $b) {
            if ($b['end'] !== null && $b['head'] < $line && $b['end'] > $line && ($best === null || $b['head'] < $best['head'])) {
                $best = $b;
            }
        }
        return $best !== null ? $best['end'] : null;
    }

    /** First class or block line at the top level (with the comment directly above it): place for a first enum */
    private function firstTopLevel(): ?int
    {
        $first = null;
        foreach ($this->pos['classes'] as $c) {
            $first = $first === null ? $c['head'] : min($first, $c['head']);
        }
        foreach ($this->pos['blocks'] as $b) {
            $first = $first === null ? $b['head'] : min($first, $b['head']);
        }
        return $first === null ? null : $this->attachedTop($first);
    }

    /** Line of @startuml (or -1: insert at the very beginning) */
    private function startLine(): int
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^\s*@startuml\b/i', $line)) {
                return $i;
            }
        }
        return -1;
    }

    private function changeClass(array $o, array $e): void
    {
        $p = $this->classPos($o);
        $oldHead = PumlExporter::classHead($o);
        $newHead = PumlExporter::classHead($e);
        if ($oldHead !== $newHead) {
            $re = '/^([ \t]*)(abstract[ \t]+)?class[ \t]+([^\s{]+)((?:[ \t]+<<[ \t]*(?i:media_meta)[ \t]*>>)?)((?:[ \t]+extends\b[^{]*?)?)'
                . '((?:[ \t]+#\w+)?)(?:[ \t]*\{renamed_from:([A-Za-z_][A-Za-z0-9_]*)\})?[ \t]*\{(.*)$/';
            if (!preg_match($re, $this->lines[$p['head']], $m) || trim($m[8]) !== '') {
                throw new PatchUnsafe(Msg::of('msg_patch.class_line', 'Line {line}: the class line is laid out in a way that cannot be '
                    . 'changed safely ({source}).', ['line' => $p['head'] + 1, 'source' => trim($this->lines[$p['head']])]));
            }
            $this->setLine($p['head'], $m[1] . PumlExporter::classHead($e, trim($m[6])) . ' {');
        }
        if ($o['name'] !== $e['name']) {
            $this->renameInNotes('/(\bof\s+)' . preg_quote($o['name'], '/') . '(?![A-Za-z0-9_])/i', '${1}' . $e['name']);
            $this->renameInNotes('/(?<![A-Za-z0-9_])' . preg_quote($o['name'], '/') . '::/i', $e['name'] . '::');
            $this->renameInLinks($o['name'], $e['name']);
        }
        $this->changeBody($o, $e, $p);
    }

    /** Body entries (fields and media fields) in the order of field_order: [key => ['f' => field, 'media' => bool]] */
    private static function entries(array $c, bool $useKey): array
    {
        $byName = [];
        foreach ($c['fields'] as $f) {
            $byName[$f['name']] = ['f' => $f, 'media' => false];
        }
        foreach ($c['media'] ?? [] as $m) {
            $byName[$m['name']] = ['f' => $m, 'media' => true];
        }
        $order = array_values(array_unique(array_merge(array_values(array_filter($c['field_order'] ?? [], function ($n) use ($byName) {
            return is_string($n) && isset($byName[$n]);
        })), array_keys($byName))));
        $out = [];
        foreach ($order as $name) {
            $x = $byName[$name];
            $out[$useKey ? ($x['f']['key'] ?? $x['f']['id'] ?? 'new:' . $name) : $x['f']['id']] = $x;
        }
        return $out;
    }

    private static function entryText(array $x, array $c): string
    {
        return $x['media'] ? PumlExporter::mediaCore($x['f']) : PumlExporter::fieldCore($x['f'], $c);
    }

    private function changeBody(array $o, array $e, array $p): void
    {
        $old = self::entries($o, false);
        $new = self::entries($e, true);
        $lineOf = function (array $x) use ($p): ?int {
            return $p['fields'][strtolower($x['f']['name'])] ?? null;
        };
        $bodyChange = false;
        foreach ($new as $key => $x) {
            if (!isset($old[$key]) || self::entryText($old[$key], $o) !== self::entryText($x, $e)) {
                $bodyChange = true;
            }
        }
        $bodyChange = $bodyChange || array_diff_key($old, $new);
        if (!$bodyChange) {
            return;
        }
        foreach ($old as $x) {
            if ($lineOf($x) === $p['head']) {
                throw new PatchUnsafe(Msg::of('msg_patch.class_line', 'Line {line}: the class line is laid out in a way that cannot be '
                    . 'changed safely ({source}).', ['line' => $p['head'] + 1, 'source' => trim($this->lines[$p['head']])]));
            }
        }
        // changed and deleted entries
        foreach ($old as $key => $x) {
            $line = $lineOf($x);
            if (!isset($new[$key])) {
                if ($line !== null) {
                    $this->deleteLine($line, true);
                }
                $this->deleteNotes($o['name'], $x['f']['name']);
                continue;
            }
            $y = $new[$key];
            if (($x['f']['section'] ?? null) !== ($y['f']['section'] ?? null) && empty($x['f']['primary'])) {
                throw new PatchUnsafe(Msg::of('msg_patch.section', "Class '{class}': a field moved to another section cannot be written "
                    . 'into the existing text.', ['class' => $e['name']]));
            }
            if (self::entryText($x, $o) !== self::entryText($y, $e)) {
                if ($line === null) {
                    // implicit id that now differs - cannot happen (the id is fixed), but never write a guess
                    throw new PatchUnsafe(Msg::of('msg_patch.verify', 'The changed text would not match the diagram (check after the change).'));
                }
                if (!preg_match('/^(\s*)((?:\{\w+\}\s*)?[+\-#~]?\s*)(\S.*)$/', $this->lines[$line], $m)) {
                    throw new PatchUnsafe(Msg::of('msg_patch.verify', 'The changed text would not match the diagram (check after the change).'));
                }
                $this->setLine($line, $m[1] . $m[2] . self::entryText($y, $e));
            }
            if ($x['f']['name'] !== $y['f']['name']) {
                $this->renameInNotes('/(?<![A-Za-z0-9_])((?:' . preg_quote($o['name'], '/') . '|' . preg_quote($e['name'], '/') . ')::)'
                    . preg_quote($x['f']['name'], '/') . '(?![A-Za-z0-9_])/i', '${1}' . $y['f']['name']);
            }
        }
        // new entries: after the entry before them (in the same section), otherwise after the separator line of their section
        // or after the class line
        $anchor = null; // [line, section]
        foreach ($new as $key => $y) {
            $section = $y['f']['section'] ?? null;
            if (isset($old[$key])) {
                $line = $lineOf($old[$key]);
                if ($line !== null) {
                    $anchor = [$line, empty($old[$key]['f']['primary']) ? ($old[$key]['f']['section'] ?? null) : ($anchor[1] ?? null)];
                }
                continue;
            }
            if ($section !== null && !isset($p['sections'][$section])) {
                throw new PatchUnsafe(Msg::of('msg_patch.section', "Class '{class}': a field moved to another section cannot be written "
                    . 'into the existing text.', ['class' => $e['name']]));
            }
            if ($anchor === null || $anchor[1] !== $section) {
                $anchor = [$section !== null ? $p['sections'][$section] : $p['head'], $section];
            }
            $indent = $this->fieldIndent($p, $anchor[0]);
            $this->insertAfter($anchor[0], [$indent . ($y['media'] ? PumlExporter::mediaText($y['f']) : PumlExporter::fieldText($y['f'], $e))]);
        }
        // sections without fields: remove the separator line (the parser rejects an empty section)
        foreach ($p['sections'] as $title => $line) {
            $left = false;
            foreach ($new as $key => $y) {
                if (($y['f']['section'] ?? null) === $title) {
                    $left = true;
                }
            }
            if (!$left) {
                $this->deleteLine($line, true);
            }
        }
    }

    private function fieldIndent(array $p, int $anchor): string
    {
        if ($anchor !== $p['head']) {
            return self::indentOf($this->lines[$anchor]);
        }
        foreach ($p['fields'] as $l) {
            return self::indentOf($this->lines[$l]);
        }
        return self::indentOf($this->lines[$p['head']]) . $this->unit;
    }

    // --- relationships ---

    private function relations(): void
    {
        $orig = [];
        foreach ($this->orig['relations'] as $k => $r) {
            $orig[$r['id']] = [$r, $this->pos['relations'][$k]];
        }
        $kept = [];
        $new = [];
        foreach ($this->edit['relations'] as $r) {
            $key = $r['key'] ?? $r['id'] ?? '';
            if (!isset($orig[$key])) {
                $new[] = $r;
                continue;
            }
            $kept[$key] = true;
            [$o, $line] = $orig[$key];
            if (PumlExporter::relationText($o) !== PumlExporter::relationText($r)) {
                $this->setLine($line, $this->relationLine($this->lines[$line], $o, $r));
            }
        }
        $last = null;
        foreach ($orig as $id => [$o, $line]) {
            if (!isset($kept[$id])) {
                $this->deleteLine($line, true);
            }
            $last = $last === null ? $line : max($last, $line);
        }
        foreach ($new as $r) {
            if ($last !== null) {
                $this->insertAfter($last, [self::indentOf($this->lines[$last]) . PumlExporter::relationText($r)]);
                continue;
            }
            $end = null;
            foreach ($this->lines as $i => $l) {
                if (preg_match('/^\s*@enduml\b/i', $l)) {
                    $end = $i;
                }
            }
            if ($end === null) {
                $this->insertAfter(count($this->lines) - 1 - (end($this->lines) === '' ? 1 : 0), [PumlExporter::relationText($r)]);
            } else {
                $at = $end;
                while ($at > 0 && trim($this->lines[$at - 1]) === '') {
                    $at--;
                }
                $this->insertBefore($at, ['', PumlExporter::relationText($r)]);
            }
        }
    }

    /**
     * Rewrites a relationship line: with the same kind the arrow (colour, direction) and the side the classes stand on
     * are kept, only names, multiplicities, label and markers are written anew; otherwise the line as toPuml() writes it,
     * with the colour of the arrow.
     */
    private function relationLine(string $line, array $o, array $r): string
    {
        $re = '/^(\s*)(\S+)\s+"([^"]*)"\s+(\S+)\s+"([^"]*)"\s+([^\s{]+)\s*(?::\s*(.*?))?\s*((?:\{(?:\w*|(?i:renamed_from|filter_by):[^{}]*)\}\s*)*)$/';
        $indent = self::indentOf($line);
        // composition before or after: the line is written anew (whole first, `*--`) - its arrow and sides cannot be kept
        if (!empty($o['composition']) || !empty($r['composition']) || !preg_match($re, $line, $m)) {
            return $indent . PumlExporter::relationText($r);
        }
        $tail = ($r['label'] !== '' ? ' : ' . $r['label'] : '') . PumlExporter::relationMarkers($r);
        if ($o['kind'] === $r['kind']) {
            $forward = strcasecmp($m[2], $o['from_entity']) === 0 && strcasecmp($m[6], $o['to_entity']) === 0
                && trim($m[3]) === $o['from_multiplicity'] && trim($m[5]) === $o['to_multiplicity'];
            $backward = strcasecmp($m[2], $o['to_entity']) === 0 && strcasecmp($m[6], $o['from_entity']) === 0
                && trim($m[3]) === $o['to_multiplicity'] && trim($m[5]) === $o['from_multiplicity'];
            if ($forward) {
                return "$indent{$r['from_entity']} \"{$r['from_multiplicity']}\" {$m[4]} \"{$r['to_multiplicity']}\" {$r['to_entity']}$tail";
            }
            if ($backward) {
                return "$indent{$r['to_entity']} \"{$r['to_multiplicity']}\" {$m[4]} \"{$r['from_multiplicity']}\" {$r['from_entity']}$tail";
            }
        }
        $arrow = PumlExporter::relationArrow($r);
        if (preg_match('/\[#\w+(?:[,;]#?\w+)*\]/', $m[4], $color)) {
            $arrow = '-' . $color[0] . substr($arrow, 1);
        }
        return "$indent{$r['from_entity']} \"{$r['from_multiplicity']}\" $arrow \"{$r['to_multiplicity']}\" {$r['to_entity']}$tail";
    }

    // --- notes ---

    /**
     * Notes `note … of Class` (with $field: `of Class::field`) and connecting lines to the class (with $field: to
     * `Class::field`) are deleted with it - a multi-line note as a whole, from its first line to `end note` (the range of
     * PumlParser::sourceMap())
     */
    private function deleteNotes(string $class, ?string $field): void
    {
        $target = preg_quote($class, '/') . ($field !== null ? '::' . preg_quote($field, '/') : '(?:::\w+)?');
        foreach ($this->pos['decor'] as $d) {
            if ($d['kind'] !== 'note') {
                continue;
            }
            $head = $this->lines[$d['start']];
            $of = preg_match('/^\s*note\b.*?\bof\s+' . $target . '(?![A-Za-z0-9_:])/i', $head);
            $link = false;
            if (preg_match(self::LINK, $head, $lm)) {
                foreach ([[$lm[2], $lm[3]], [$lm[5], $lm[6]]] as [$c, $f]) {
                    $link = $link || (strcasecmp($c, $class) === 0 && ($field === null || ($f !== '' && strcasecmp(substr($f, 2), $field) === 0)));
                }
            }
            if ($of || $link) {
                $this->deleteBlock($d['start'], $d['end'], false);
            }
        }
    }

    /** Connecting line of a note (`N1 .. Class`, `Class::field .. N1`): indentation, side 1 (name, ::field), arrow, side 2, rest */
    private const LINK = '/^(\s*)(\w+)((?:::\w+)?)(\s*[-.]{2,}>?\s*)(\w+)((?:::\w+)?)(\s*)$/';

    private function renameInNotes(string $pattern, string $replacement): void
    {
        foreach ($this->pos['decor'] as $d) {
            if ($d['kind'] === 'note' && (preg_match('/^\s*note\b/i', $this->lines[$d['start']]) || preg_match(self::LINK, $this->lines[$d['start']]))
                && !isset($this->delete[$d['start']])) {
                $now = $this->line($d['start']);
                $changed = (string) preg_replace($pattern, $replacement, $now);
                if ($changed !== $now) {
                    $this->setLine($d['start'], $changed);
                }
            }
        }
    }

    private function renameInLinks(string $old, string $new): void
    {
        foreach ($this->pos['decor'] as $d) {
            $now = $this->line($d['start']);
            if ($d['kind'] === 'note' && preg_match(self::LINK, $now, $m) && !isset($this->delete[$d['start']])) {
                $a = strcasecmp($m[2], $old) === 0 ? $new : $m[2];
                $b = strcasecmp($m[5], $old) === 0 ? $new : $m[5];
                if ($a !== $m[2] || $b !== $m[5]) {
                    $this->setLine($d['start'], $m[1] . $a . $m[3] . $m[4] . $b . $m[6] . $m[7]);
                }
            }
        }
    }

    // --- line operations ---

    private function line(int $i): string
    {
        return $this->replace[$i] ?? $this->lines[$i];
    }

    private function guard(int $i): void
    {
        if (isset($this->mixed[$i])) {
            throw new PatchUnsafe(Msg::of('msg_patch.block_comment', "Line {line} would have to be changed, but it shares a block "
                . "comment /' … '/ with other content.", ['line' => $i + 1]));
        }
    }

    /**
     * Rewrites line $i. A block comment at the end of the line (`+ text: text /' kurz '/`, the parser allows it) is kept:
     * appended again if the new text does not end with it already. Any other line sharing a block comment is refused.
     */
    private function setLine(int $i, string $text): void
    {
        if (isset($this->mixed[$i])) {
            $tail = $this->trailingComment($i);
            if ($tail === null) {
                $this->guard($i);
            }
            if (substr($text, -strlen($tail)) !== $tail) {
                $text = rtrim($text) . $tail;
            }
        }
        $this->replace[$i] = $text;
    }

    /** Block comments at the end of line $i, if they lie completely on it and only comments follow the code; otherwise null */
    private function trailingComment(int $i): ?string
    {
        foreach ($this->comments as [$cf, $cl]) {
            if ($cf <= $i && $cl >= $i && ($cf !== $i || $cl !== $i)) {
                return null;
            }
        }
        if (!preg_match("#^(?:[^']*?\S)(\s*(?:/'.*?'/\s*)+)$#", $this->lines[$i], $m)) {
            return null;
        }
        return $m[1];
    }

    /** Deletes line $i; with $attached also the comment lines directly above it (see commentTop()), and a doubled blank line */
    private function deleteLine(int $i, bool $attached): void
    {
        $this->checkRange($i, $i);
        $this->delete[$i] = true;
        $this->pending[] = [$i, $i, $attached, true];
    }

    /**
     * Deletes lines $first..$last (with $attached also the comment directly above it, see commentTop(), and a blank line
     * that would otherwise be doubled)
     */
    private function deleteBlock(int $first, int $last, bool $attached = true): void
    {
        $this->checkRange($first, $last);
        for ($k = $first; $k <= $last; $k++) {
            $this->delete[$k] = true;
            if (!isset($this->structural[$k]) && trim($this->lines[$k]) !== '') {
                $this->removedWith[$k] = true;
            }
        }
        $this->pending[] = [$first, $last, $attached, true];
    }

    /**
     * After all operations (only then it is known which elements of a group go): the comments directly above deleted
     * elements, then the blank lines around deleted blocks
     */
    private function finishDeletions(): void
    {
        foreach ($this->pending as [$first, $last, $attached, $tidy]) {
            $top = $attached ? $this->commentTop($first) : $first;
            $this->checkRange($top, $last);
            for ($k = $top; $k < $first; $k++) {
                $this->delete[$k] = true;
                $this->removedWith[$k] = true;
            }
            if ($tidy) {
                $this->tidy($top, $last);
            }
        }
        $this->pending = [];
    }

    /**
     * First line of the comment that belongs to the element starting at line $first (deleted or moved): the comment
     * directly above it (attachedTop()) - unless it stands above a group of elements of the same kind (several fields,
     * relationships, enum values, classes/enums in a row, without a blank line or another element in between; comment
     * lines between them are part of the group). Such a comment heads the group and goes only with the whole group
     * (every element deleted or moved away); otherwise $first (the comment stays).
     */
    private function commentTop(int $first): int
    {
        $top = $this->attachedTop($first);
        if ($top === $first || !isset($this->units[$first])) {
            return $top;
        }
        foreach ($this->group($first) as $u) {
            if (!isset($this->delete[$u]) && !isset($this->moving[$u])) {
                return $first;
            }
        }
        return $top;
    }

    /** First lines of the elements of the same kind that follow the element at line $first directly (itself included) */
    private function group(int $first): array
    {
        [$kind, $last] = $this->units[$first];
        $out = [$first];
        $k = $last + 1;
        while (isset($this->lines[$k])) {
            if ($this->isCommentLine($k)) {
                $k++;
            } elseif (isset($this->units[$k]) && $this->units[$k][0] === $kind) {
                $out[] = $k;
                $k = $this->units[$k][1] + 1;
            } else {
                break;
            }
        }
        return $out;
    }

    /** After removing lines $top..$last: drop the following blank line if the one before is blank too (or a block start) */
    private function tidy(int $top, int $last): void
    {
        $next = $last + 1;
        $prev = $top - 1;
        while ($prev >= 0 && isset($this->delete[$prev])) {
            $prev--;
        }
        // blank line before and a closing "}" (or the end) after: the blank line before goes
        $following = $next;
        while (isset($this->lines[$following]) && isset($this->delete[$following])) {
            $following++;
        }
        if ($prev >= 0 && trim($this->lines[$prev]) === '' && (!isset($this->lines[$following]) || trim($this->lines[$following]) === '}'
            || preg_match('/^\s*@enduml\b/i', $this->lines[$following]))) {
            $this->delete[$prev] = true;
            return;
        }
        if (isset($this->lines[$next]) && trim($this->lines[$next]) === '' && !isset($this->delete[$next])
            && ($prev < 0 || trim($this->lines[$prev]) === '' || substr(rtrim($this->lines[$prev]), -1) === '{'
                || preg_match('/^\s*@startuml\b/i', $this->lines[$prev]))
            && $next < count($this->lines) - 1) {
            $this->delete[$next] = true;
        }
    }

    /** A range that is deleted or moved: no block comment may reach into it from outside */
    private function checkRange(int $first, int $last): void
    {
        foreach ($this->comments as [$cf, $cl]) {
            if ($cl >= $first && $cf <= $last && ($cf < $first || $cl > $last)) {
                throw new PatchUnsafe(Msg::of('msg_patch.block_comment', "Line {line} would have to be changed, but it shares a block "
                    . "comment /' … '/ with other content.", ['line' => max($cf, $first) + 1]));
            }
        }
    }

    /** First line of the comment directly above line $i (no blank line in between); $i if there is none */
    private function attachedTop(int $i): int
    {
        $k = $i;
        while ($k > 0 && $this->isCommentLine($k - 1)) {
            $k--;
        }
        // a block comment only partly above (its first line also holds other content): it does not belong to the element
        foreach ($this->comments as [$cf, $cl]) {
            if ($cf < $k && $cl >= $k && $cl < $i) {
                return $i;
            }
        }
        return $k;
    }

    private function isCommentLine(int $k): bool
    {
        $t = trim($this->lines[$k]);
        if ($t === '' || isset($this->mixed[$k]) || isset($this->structural[$k])) {
            return false;
        }
        return $t[0] === "'" || isset($this->inComment[$k]);
    }

    private function insertAfter(int $i, array $lines): void
    {
        foreach ($lines as $l) {
            $this->after[$i][] = $l;
        }
    }

    private function insertBefore(int $i, array $lines): void
    {
        foreach ($lines as $l) {
            $this->before[$i][] = $l;
        }
    }

    /** @param string[] $lines */
    private function indent(array $lines, string $indent): array
    {
        return array_map(function ($l) use ($indent) {
            return $l === '' ? '' : $indent . $l;
        }, $lines);
    }

    private static function indentOf(string $line): string
    {
        return (string) preg_replace('/^([ \t]*).*$/s', '$1', $line);
    }

    // --- output ---

    /** Output lines of original line $i: [text, line break] (inserted lines get the line break of the text) */
    private function renderLine(int $i, int $last): array
    {
        $out = [];
        foreach ($this->before[$i] ?? [] as $l) {
            array_push($out, ...$this->renderInserted($l));
        }
        if (!isset($this->delete[$i])) {
            $out[] = [$this->line($i), $i === $last ? '' : ($this->cr[$i] ? "\r\n" : "\n")];
        }
        foreach (array_merge($this->after[$i] ?? [], $this->closing[$i] ?? []) as $l) {
            array_push($out, ...$this->renderInserted($l));
        }
        return $out;
    }

    private function renderInserted($l): array
    {
        if (is_string($l)) {
            return [[$l, $this->eol]];
        }
        // moved block: its lines with their own changes, re-indented (indentation of the class line -> new indentation)
        [$first, $last, $from] = $l['range'];
        $out = [];
        for ($k = $first; $k <= $last; $k++) {
            foreach ($this->renderLine($k, -1) as [$text, $eol]) {
                if ($text !== '' && substr($text, 0, strlen($from)) === $from) {
                    $text = $l['indent'] . substr($text, strlen($from));
                }
                $out[] = [$text, $eol === '' ? $this->eol : $eol];
            }
        }
        return $out;
    }

    private function render(): string
    {
        $last = count($this->lines) - 1;
        $out = [];
        $skip = [];
        foreach ($this->moved as [$first, $end]) {
            for ($k = $first; $k <= $end; $k++) {
                $skip[$k] = true;
            }
        }
        foreach ($this->lines as $i => $unused) {
            if (isset($skip[$i])) {
                continue;
            }
            foreach ($this->renderLine($i, $last) as $l) {
                $out[] = $l;
            }
        }
        $text = '';
        foreach ($out as [$line, $eol]) {
            $text .= $line . $eol;
        }
        return ($this->bom ? "\xEF\xBB\xBF" : '') . $text;
    }

    private function removedList(): array
    {
        $out = [];
        ksort($this->removedWith);
        foreach (array_keys($this->removedWith) as $i) {
            $out[] = ['line' => $i + 1, 'text' => $this->lines[$i]];
        }
        return $out;
    }
}

/** A change that cannot be written safely into the original text (PumlPatcher falls back to the complete export with a warning) */
final class PatchUnsafe extends \RuntimeException
{
    /** @var Msg */
    public $msg;

    public function __construct(Msg $msg)
    {
        parent::__construct($msg->text());
        $this->msg = $msg;
    }
}
