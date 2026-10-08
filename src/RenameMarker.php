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
 * Finds the line of an element (class head, field, relation) in the .puml text and returns it with the
 * {renamed_from:…} marker inserted. Basis of the rename suggestions of „Änderungen prüfen“ (SchemaMigration::analyze()):
 * the UI only replaces the line and checks again - the matching is then done via the marker as always
 * (SchemaMigration::identify()), this class does not match anything.
 *
 * Deliberately simple: comment lines (') and block comments (/' … '/ spanning whole lines) are skipped; if an element
 * cannot be found exactly once, the search returns null (then there is no suggestion for this element).
 */
final class RenameMarker
{
    /** @var string[] */
    private $lines;
    /** @var array<int,bool> comment lines */
    private $skip = [];

    public function __construct(string $source)
    {
        $this->lines = explode("\n", $source);
        $inBlock = false;
        foreach ($this->lines as $i => $line) {
            $t = trim($line);
            if ($inBlock) {
                $this->skip[$i] = true;
                $inBlock = strpos($t, "'/") === false;
            } elseif (strpos($t, "/'") === 0) {
                $this->skip[$i] = true;
                $inBlock = strpos(substr($t, 2), "'/") === false;
            } elseif ($t === '' || $t[0] === "'") {
                $this->skip[$i] = true;
            }
        }
    }

    /** Line index (0-based) of the class head or null */
    public function classHead(string $class): ?int
    {
        return $this->unique(array_keys(array_filter($this->lines, function ($line, $i) use ($class) {
            return !isset($this->skip[$i]) && preg_match(self::headPattern($class), $line);
        }, ARRAY_FILTER_USE_BOTH)));
    }

    /** Line index of the field (media field too) $field in the body of class $class or null */
    public function field(string $class, string $field): ?int
    {
        $head = $this->classHead($class);
        if ($head === null) {
            return null;
        }
        $found = [];
        for ($i = $head + 1; $i < count($this->lines) && !preg_match('/^\s*\}\s*$/', $this->lines[$i]); $i++) {
            if (!isset($this->skip[$i])
                && preg_match('/^\s*(?:\{\w+\}\s*)?[+\-#~]?\s*' . preg_quote($field, '/') . '\s*:/i', $this->lines[$i])) {
                $found[] = $i;
            }
        }
        return $this->unique($found);
    }

    /** Line index of the relation between the classes $a and $b (direction does not matter) with the label $label or null */
    public function relation(string $a, string $b, string $label): ?int
    {
        $want = [strtolower($a), strtolower($b)];
        sort($want);
        $found = [];
        foreach ($this->lines as $i => $line) {
            if (isset($this->skip[$i]) || !preg_match('/^\s*(\S+)\s+"[^"]*"\s+(\S+)\s+"[^"]*"\s+([^\s{]+)\s*(?::\s*(.*?))?\s*'
                . '((?:\{(?:\w*|(?i:renamed_from|filter_by):[^{}]*)\}\s*)*)$/', $line, $r) || !preg_match('/[-.]{2}/', $r[2])) {
                continue;
            }
            $ends = [strtolower($r[1]), strtolower($r[3])];
            sort($ends);
            if ($ends === $want && PumlParser::normalizeLabel($r[4] ?? '') === PumlParser::normalizeLabel($label)) {
                $found[] = $i;
            }
        }
        return $this->unique($found);
    }

    /** Original line */
    public function line(int $i): string
    {
        return $this->lines[$i];
    }

    /** Line with the marker at the end (field, relation) */
    public function appended(int $i, string $marker): string
    {
        $line = $this->lines[$i];
        $cr = substr($line, -1) === "\r" ? "\r" : '';
        return rtrim($line) . ' ' . $marker . $cr;
    }

    /** Class head with the marker before the opening "{" of the body (class Neu {renamed_from:Alt} {) */
    public function classMarked(int $i, string $class, string $marker): string
    {
        return (string) preg_replace_callback(
            '/^(\s*(?:abstract\s+)?class\s+' . preg_quote($class, '/') . '(?=[\s{])[^{]*?)\s*\{/',
            function ($m) use ($marker) {
                return $m[1] . ' ' . $marker . ' {';
            },
            $this->lines[$i],
            1
        );
    }

    private static function headPattern(string $class): string
    {
        return '/^\s*(?:abstract\s+)?class\s+' . preg_quote($class, '/') . '(?=[\s{])[^{]*\{/';
    }

    private function unique(array $found): ?int
    {
        return count($found) === 1 ? $found[0] : null;
    }
}
