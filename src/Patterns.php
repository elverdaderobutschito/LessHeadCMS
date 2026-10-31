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
 * Pattern library (System → Einstellungen → Patterns, admins only): named regular expressions that a field of the
 * diagram references with `{pattern: <name>}` (see Validation). The diagram never contains a regex itself - only the name.
 *
 * Per pattern: name (technical identifier as used in the diagram, NAME), regex (PCRE, see below), message (shown to the
 * editors when a value does not match) and optionally an example value (placeholder in the form, test data generator).
 *
 * Built-in patterns (BUILTIN: email, url, slug, plz) live in the code and are read-only: whoever needs a variant creates it
 * under an own name. An update can therefore never overwrite something the admin has changed - and a name of a built-in
 * pattern cannot be taken. Own patterns are rows of lh_pattern (created on demand, ensure(); reserved as a class name).
 *
 * Regex: the expression without delimiters and without anchors - it always has to match the WHOLE value (it is wrapped as
 * \A(?:…)\z with the modifier u, see compile()). Inline options such as (?i) are possible. Protection against catastrophic
 * backtracking (ReDoS): every match runs with a low pcre.backtrack_limit/recursion_limit (match()); a PCRE error (limit
 * hit, invalid UTF-8) never counts as a match - the caller treats it as a validation error. An invalid regex is not saved,
 * and neither is one that fails the load test (tooComplex()): JavaScript has no backtracking limit, a regex like (a|a)+
 * would freeze the browser tab of an editor. Patterns saved before the load test existed are tested once on the first
 * access (upgrade()); one that fails stays (the schema may use it) but is marked 'complex' and never checked in the browser.
 *
 * A pattern used by the active schema cannot be deleted or renamed (409 with the fields using it, see usage()). Changing
 * the regex of a used pattern is allowed; impact() tells beforehand how many records would then violate it - records are
 * never changed (see docs/validation.md).
 *
 * The message of an own pattern is content of the admin, translatable (Languages, kind 'pattern', ref_id = name of the
 * pattern; also in the CSV import/export). The message of a built-in pattern is a system text with a code (builtinMessage());
 * a translation the admin enters for it under the same kind wins (messageOf()).
 */
final class Patterns
{
    public const TABLE = 'lh_pattern';

    /** Name of a pattern: lower-case letter first, then lower-case letters, digits, _ */
    public const NAME = '/^[a-z][a-z0-9_]{0,49}$/';
    private const REGEX_MAX = 1000;
    private const MESSAGE_MAX = 300;
    private const EXAMPLE_MAX = 255;

    /** PCRE limits of a single match (ReDoS protection, see match()) */
    private const BACKTRACK_LIMIT = 100000;
    private const RECURSION_LIMIT = 10000;

    /**
     * Load test (tooComplex()): repetitions of each probe unit in these lengths, each followed by one of these
     * characters, so that the whole value does not match and the engine has to try every way of splitting it. Above
     * STRESS_SECONDS for all probes together the regex counts as too complex as well.
     */
    private const STRESS_LENGTHS = [8, 16, 32, 64];
    private const STRESS_TAILS = ['!', ' ', "\n", '§'];
    private const STRESS_UNITS_MAX = 24;
    private const STRESS_SECONDS = 1.0;
    /** Characters tried as a sample of a character class, escape or "." (the first one matching it is taken) */
    private const SAMPLES = ['a', '1', ' ', 'A', '-', '.', '_', '@', '!', '/', ':', 'é', "\t"];


    /**
     * Built-in patterns: name => [regex, example]. Never change a regex here in a way that rejects old values. Their
     * messages are system texts, not content of the admin (builtinMessage(): code msg_patterns.builtin_<name>, German
     * text in the UI, English in translations/en.csv) - like every other server message.
     */
    public const BUILTIN = [
        'email' => ['[^@\s]+@[^@\s]+\.[^@\s]+', 'name@example.org'],
        'url'   => ['(?i)https?://[^\s/?#]+[^\s]*', 'https://example.org'],
        'slug'  => ['[a-z0-9]+(-[a-z0-9]+)*', 'my-article'],
        'plz'   => ['[0-9]{5}', '10115'],
    ];

    private const DDL = "CREATE TABLE IF NOT EXISTS lh_pattern (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  regex TEXT NOT NULL,
  message TEXT NOT NULL,
  example TEXT,
  too_complex INTEGER,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME
)";

    /** @var bool ensure() already ran in this request */
    private static $ensured = false;
    /** @var bool upgrade() already ran in this request */
    private static $upgraded = false;
    /** @var int preg_last_error() of the last failed match() (for matchError()) */
    private static $lastError = PREG_NO_ERROR;

    public static function ensure(PDO $pdo): void
    {
        if (self::$ensured && Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        $pdo->exec(self::DDL);
        self::$ensured = true;
        self::upgrade($pdo);
    }

    /**
     * Table from before the load test: adds the column too_complex and tests every pattern not tested yet (NULL) once.
     * If the database cannot be written right now, fromRow() tests in memory and the next request tries again.
     */
    private static function upgrade(PDO $pdo): void
    {
        if (self::$upgraded) {
            return;
        }
        self::$upgraded = true;
        try {
            if (!in_array('too_complex', array_column($pdo->query('PRAGMA table_info(lh_pattern)')->fetchAll(), 'name'), true)) {
                $pdo->exec('ALTER TABLE lh_pattern ADD COLUMN too_complex INTEGER');
            }
            $update = $pdo->prepare('UPDATE lh_pattern SET too_complex = ? WHERE id = ?');
            foreach ($pdo->query('SELECT id, regex FROM lh_pattern WHERE too_complex IS NULL')->fetchAll() as $row) {
                $update->execute([self::tooComplex((string) $row['regex']) ? 1 : 0, $row['id']]);
            }
        } catch (\PDOException $e) {
            self::$upgraded = false;
        }
    }

    // ---------------------------------------------------------------- Reading

    /**
     * All patterns, built-in ones first, then the own ones by name: name => ['id' (null for built-in ones), 'name',
     * 'regex', 'message', 'example', 'builtin', 'complex' (failed the load test)]. Without the table (nothing created yet)
     * only the built-in ones.
     *
     * @return array<string,array>
     */
    public static function all(?PDO $pdo): array
    {
        $out = [];
        foreach (self::BUILTIN as $name => [$regex, $example]) {
            $out[$name] = ['id' => null, 'name' => $name, 'regex' => $regex, 'message' => self::builtinMessage($name)->text(),
                'example' => $example,
                'builtin' => true, 'complex' => false];
        }
        if ($pdo !== null && Database::tableExists($pdo, self::TABLE)) {
            self::upgrade($pdo);
            foreach ($pdo->query('SELECT * FROM lh_pattern ORDER BY name')->fetchAll() as $row) {
                // a row under the name of a built-in pattern (cannot be created via the API) never replaces it
                if (!isset(self::BUILTIN[$row['name']])) {
                    $out[(string) $row['name']] = self::fromRow($row);
                }
            }
        }
        return $out;
    }

    /** Names of all patterns (for PumlParser: `{pattern: x}` must name an existing pattern) */
    public static function names(?PDO $pdo): array
    {
        return array_keys(self::all($pdo));
    }

    /** System text of a built-in pattern's message (code msg_patterns.builtin_<name>) */
    public static function builtinMessage(string $name): Msg
    {
        switch ($name) {
            case 'email':
                return Msg::of('msg_patterns.builtin_email', 'Please enter a valid email address.');
            case 'url':
                return Msg::of('msg_patterns.builtin_url', 'Please enter a valid URL.');
            case 'slug':
                return Msg::of('msg_patterns.builtin_slug', 'Only lowercase letters, digits and hyphens.');
            default:
                return Msg::of('msg_patterns.builtin_plz', 'Please enter a five-digit German postal code.');
        }
    }

    /**
     * Message of a pattern as editors see it. $translations: name => text in the display language (Languages::
     * patternMessages(): translation of the display language, otherwise the override of the default language).
     * Own pattern: the translation, otherwise the stored message (content of the admin, plain text). Built-in pattern:
     * a translation of the admin wins, otherwise the system text as Msg - the UI shows it in the display language.
     *
     * @return Msg|string
     */
    public static function messageOf(array $pattern, array $translations)
    {
        if (isset($translations[$pattern['name']])) {
            return $translations[$pattern['name']];
        }
        return !empty($pattern['builtin']) ? self::builtinMessage($pattern['name']) : $pattern['message'];
    }

    /**
     * The message as fields of a response item: ['message' => text] for content of the admin, plus code and params for
     * the system text of a built-in pattern (Msg::fields())
     */
    public static function messageFields($message, array $params = []): array
    {
        return $message instanceof Msg ? ['message' => $message->text(), 'code' => $message->code, 'params' => $params]
            : ['message' => $message, 'params' => $params];
    }

    /**
     * GET /api/_patterns: all patterns with the fields of the active schema that use them ('used_by'), whether the
     * regex failed the load test ('complex') and whether the browser may check it itself ('client', see client()).
     * A built-in pattern shows its message as editors in the language of $user see it (messageOf(): the admin's
     * translation, otherwise the system text with 'code'); an own one its stored message (that is what the form edits).
     */
    public static function overview(PDO $pdo, ?array $model, ?array $user = null): array
    {
        $usage = self::usage($model);
        $language = $user !== null ? Languages::current($pdo, (int) $user['id']) : null;
        $translations = Languages::patternMessages($pdo, $language);
        return ['patterns' => array_values(array_map(function ($p) use ($usage, $translations) {
            if ($p['builtin']) {
                $message = self::messageOf($p, $translations);
                $p = ['message' => Msg::textOf($message)] + ($message instanceof Msg ? ['code' => $message->code] : []) + $p;
            }
            return self::present($p, $usage);
        }, self::all($pdo)))];
    }

    /**
     * Fields of a model that use a pattern: name => [['entity' => class name, 'table', 'field']]. Inherited fields appear
     * once per concrete class (each has its own table and data).
     *
     * @return array<string,array<int,array{entity:string,table:string,field:string}>>
     */
    public static function usage(?array $model): array
    {
        $out = [];
        foreach ($model['entities'] ?? [] as $table => $e) {
            foreach ($e['fields'] as $f) {
                if (isset($f['rules']['pattern'])) {
                    $out[$f['rules']['pattern']][] = ['entity' => $e['name'], 'table' => (string) $table, 'field' => $f['name']];
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- Management (POST/PUT/DELETE /api/_patterns)

    /** POST /api/_patterns: body {name, regex, message, example?} -> 201 */
    public static function create(PDO $pdo, ?array $model, array $body): array
    {
        self::ensure($pdo);
        $v = self::validate($pdo, $body, null);
        $pdo->prepare('INSERT INTO lh_pattern (name, regex, message, example, too_complex) VALUES (?, ?, ?, ?, 0)')
            ->execute([$v['name'], $v['regex'], $v['message'], $v['example']]);
        return self::present(self::all($pdo)[$v['name']], self::usage($model));
    }

    /**
     * PUT /api/_patterns/{id}: the fields in the body are changed, the others stay. Renaming only while no field of the
     * active schema uses the pattern (409). A changed regex applies at once; records violating it stay as they are (see
     * impact(), which the UI shows before saving). The load test runs only for a changed regex: a pattern marked as too
     * complex keeps its mark while only name, message or example change.
     */
    public static function update(PDO $pdo, ?array $model, int $id, array $body): array
    {
        self::ensure($pdo);
        $current = self::fromRow(self::row($pdo, $id));
        $v = self::validate($pdo, $body + $current, $current);
        if ($v['name'] !== $current['name']) {
            self::guardUse($model, $current['name'], true);
        }
        $complex = $v['regex'] === $current['regex'] && $current['complex'];
        $pdo->prepare('UPDATE lh_pattern SET name = ?, regex = ?, message = ?, example = ?, too_complex = ?, updated_at = ? WHERE id = ?')
            ->execute([$v['name'], $v['regex'], $v['message'], $v['example'], $complex ? 1 : 0, RecordMeta::now(), $id]);
        if ($v['name'] !== $current['name']) {
            Languages::renamePattern($pdo, $current['name'], $v['name']);
        }
        return self::present(self::all($pdo)[$v['name']], self::usage($model));
    }

    /** DELETE /api/_patterns/{id}: not while a field of the active schema uses it (409 with the fields) */
    public static function delete(PDO $pdo, ?array $model, int $id): array
    {
        self::ensure($pdo);
        $row = self::row($pdo, $id);
        self::guardUse($model, (string) $row['name'], false);
        $pdo->prepare('DELETE FROM lh_pattern WHERE id = ?')->execute([$id]);
        Languages::deletePattern($pdo, (string) $row['name']);
        return ['deleted' => $id];
    }

    /**
     * POST /api/_patterns/test: body {regex, value} -> {valid, error?, match?, client, complex}. Checks the syntax and
     * tests one value (the test field of the UI); saves nothing. A PCRE error during the match (e.g. the backtracking
     * limit) is reported as 'error' with match false - exactly as validation treats it.
     */
    public static function test(array $body): array
    {
        $regex = is_string($body['regex'] ?? null) ? $body['regex'] : '';
        $value = $body['value'] ?? '';
        if (!is_scalar($value)) {
            throw ApiException::invalid(['value' => Msg::of('msg_api.text_expected', 'Text expected')]);
        }
        $check = self::check(['regex' => $regex]);
        if (!$check['valid']) {
            return $check + ['match' => false];
        }
        $match = self::match($regex, (string) $value);
        return ['valid' => true, 'match' => $match === true, 'client' => $check['client'], 'complex' => $check['complex']]
            + ($match === null ? self::matchError()->fields('error') : []);
    }

    /**
     * POST /api/_patterns/check: body {regex} -> {valid, error?, code?, params?, client, complex} - the hints below the
     * regex field of the UI while typing (syntax, load test, can the editing form check it in the browser?); saves nothing.
     * error is the English text of the problem, code + params its code (see Msg).
     */
    public static function check(array $body): array
    {
        $regex = is_string($body['regex'] ?? null) ? $body['regex'] : '';
        $syntax = self::syntaxError($regex);
        if ($syntax !== null) {
            return ['valid' => false, 'client' => false, 'complex' => false] + $syntax->fields('error');
        }
        $complex = self::tooComplex($regex);
        return ['valid' => true, 'client' => !$complex && self::clientSafe($regex), 'complex' => $complex]
            + ($complex ? self::tooComplexMessage()->fields('error') : []);
    }

    /** Message of a regex failing the load test (tooComplex()) */
    public static function tooComplexMessage(): Msg
    {
        return Msg::of('patterns.too_complex', 'This expression is too complex: for certain inputs the check would have to '
            . 'try an extreme number of possibilities. Please simplify it, e.g. avoid nested or overlapping repetitions such '
            . 'as (a|a)+ or (a+)+.');
    }

    /**
     * POST /api/_patterns/impact: body {name, regex} -> how many records of the active schema would violate the pattern
     * with this regex, per field using it ('now' = with the regex stored today, 'after' = with the new one). Only
     * non-empty values count (empty optional fields are never checked). Changes nothing.
     */
    public static function impact(PDO $pdo, ?array $model, array $body): array
    {
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $regex = is_string($body['regex'] ?? null) ? $body['regex'] : '';
        $syntax = self::syntaxError($regex);
        if ($syntax !== null) {
            throw ApiException::of(422, 'validation', $syntax, ApiException::fields(['regex' => $syntax]));
        }
        $current = self::all($pdo)[$name] ?? null;
        $fields = [];
        $total = 0;
        foreach (self::usage($model)[$name] ?? [] as $use) {
            $now = 0;
            $after = 0;
            $stmt = $pdo->query('SELECT ' . SqlGenerator::q($use['field']) . ' FROM ' . SqlGenerator::q($use['table'])
                . ' WHERE ' . SqlGenerator::q($use['field']) . " IS NOT NULL AND " . SqlGenerator::q($use['field']) . " <> ''");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                if ($current !== null && self::match($current['regex'], (string) $value) !== true) {
                    $now++;
                }
                if (self::match($regex, (string) $value) !== true) {
                    $after++;
                }
            }
            $fields[] = $use + ['now' => $now, 'after' => $after];
            $total += $after;
        }
        return ['name' => $name, 'fields' => $fields, 'total' => $total];
    }

    // ---------------------------------------------------------------- Matching

    /**
     * Does $value match the regex as a whole? null = PCRE error (backtracking/recursion limit, invalid UTF-8, invalid
     * regex) - never a match. The limits are lowered only for this call.
     */
    public static function match(string $regex, string $value): ?bool
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);
        try {
            $result = @preg_match(self::compile($regex), $value);
        } finally {
            self::$lastError = preg_last_error();
            ini_set('pcre.backtrack_limit', (string) $backtrack);
            ini_set('pcre.recursion_limit', (string) $recursion);
        }
        return $result === false ? null : $result === 1;
    }

    /** Message for the PCRE error of the last failed match() (for the test field) */
    public static function matchError(): Msg
    {
        return in_array(self::$lastError, [PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR], true)
            || (defined('PREG_JIT_STACKLIMIT_ERROR') && self::$lastError === PREG_JIT_STACKLIMIT_ERROR)
            ? Msg::of('msg_patterns.match_aborted', 'The check was aborted because the expression is too complex (protection '
                . 'against endless backtracking). The value counts as invalid.')
            : Msg::of('msg_patterns.match_failed', 'The check failed (PCRE error {error}). The value counts as invalid.',
                ['error' => self::$lastError]);
    }

    /** Complete PCRE pattern: whole value, UTF-8 (delimiter \x01 cannot occur in a regex entered by the admin, see syntaxError()) */
    private static function compile(string $regex): string
    {
        return "\x01\\A(?:" . $regex . ")\\z\x01u";
    }

    /** Problem with the regex itself (empty, too long, control characters, PCRE syntax), otherwise null */
    public static function syntaxError(string $regex): ?Msg
    {
        if (trim($regex) === '') {
            return Msg::of('msg_patterns.regex_missing', 'Regular expression missing.');
        }
        if (mb_strlen($regex) > self::REGEX_MAX) {
            return Msg::of('msg_patterns.regex_too_long', 'At most {max} characters.', ['max' => self::REGEX_MAX]);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $regex)) {
            return Msg::of('msg_patterns.regex_control', 'Control characters and line breaks are not allowed.');
        }
        if (!mb_check_encoding($regex, 'UTF-8')) {
            return Msg::of('msg_patterns.regex_encoding', 'Invalid character encoding (UTF-8 expected).');
        }
        // an unbalanced ")" would close the wrapping group of compile() and change its meaning - PCRE does not notice that;
        // some regexes are valid on their own but not inside the wrapper (\Q without \E, verbs such as (*UTF))
        error_clear_last();
        if (@preg_match("\x01" . $regex . "\x01u", '') === false || self::closesWrapper($regex)
            || @preg_match(self::compile($regex), '') === false) {
            $error = error_get_last();
            $detail = $error !== null && strpos((string) $error['message'], 'preg_match') !== false
                ? preg_replace('/^preg_match\(\):\s*/', '', (string) $error['message']) : '';
            return $detail !== '' ? Msg::of('msg_patterns.regex_invalid_detail', 'Invalid regular expression ({detail}).',
                ['detail' => $detail]) : Msg::of('msg_patterns.regex_invalid', 'Invalid regular expression.');
        }
        return null;
    }

    /** Does the regex close more groups than it opens (outside of character classes and escapes)? */
    private static function closesWrapper(string $regex): bool
    {
        $depth = 0;
        $class = false;
        for ($i = 0, $n = strlen($regex); $i < $n; $i++) {
            $c = $regex[$i];
            if ($c === '\\') {
                if (substr($regex, $i + 1, 1) === 'Q') { // \Q…\E: literal text
                    $end = strpos($regex, '\E', $i + 2);
                    $i = $end === false ? $n : $end + 1;
                    continue;
                }
                $i++;
            } elseif ($class) {
                $class = $c !== ']';
            } elseif ($c === '[') {
                $class = true;
                if (substr($regex, $i + 1, 1) === '^') {
                    $i++;
                }
                if (substr($regex, $i + 1, 1) === ']') {
                    $i++; // "]" right after "[" or "[^" is a literal
                }
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth < 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * May the browser check the regex itself (JavaScript RegExp with the flag u, wrapped as ^(?:…)$)? Only for a
     * conservative subset in which PCRE and JavaScript certainly behave the same: literal characters, character classes
     * with literal characters and ranges, groups (also (?:…)), alternation and the quantifiers ? * + {n} {n,} {n,m} (also
     * lazy). Everything else - \d, \w, \s, \b (Unicode semantics differ), ".", anchors, lookarounds, backreferences,
     * inline options, possessive quantifiers, POSIX classes - is left to the server, which always decides in the end.
     */
    public static function clientSafe(string $regex): bool
    {
        if (self::syntaxError($regex) !== null) {
            return false;
        }
        $punct = '\\^$.|?*+()[]{}/-';
        $n = strlen($regex);
        $atom = false; // is there something a quantifier can refer to?
        for ($i = 0; $i < $n; $i++) {
            $c = $regex[$i];
            if ($c === '\\') {
                $next = substr($regex, $i + 1, 1);
                if ($next === '' || strpos($punct, $next) === false) {
                    return false;
                }
                $i++;
                $atom = true;
            } elseif ($c === '[') {
                $i++;
                if (substr($regex, $i, 1) === '^') {
                    $i++;
                }
                $first = true;
                for (; $i < $n && ($regex[$i] !== ']' || $first); $i++) {
                    $d = $regex[$i];
                    if ($d === '[' || ($d === ']' && $first)) {
                        return false; // POSIX classes, nested classes and a leading "]" differ between the engines
                    }
                    if ($d === '\\') {
                        $next = substr($regex, $i + 1, 1);
                        if ($next === '' || strpos($punct, $next) === false) {
                            return false;
                        }
                        $i++;
                    }
                    $first = false;
                }
                if ($i >= $n) {
                    return false;
                }
                $atom = true;
            } elseif ($c === '(') {
                if (substr($regex, $i + 1, 1) === '?') {
                    if (substr($regex, $i + 2, 1) !== ':') {
                        return false; // lookarounds, named groups, inline options, atomic groups
                    }
                    $i += 2;
                }
                $atom = false;
            } elseif ($c === ')') {
                $atom = true;
            } elseif ($c === '|') {
                $atom = false;
            } elseif (strpos('?*+{', $c) !== false) {
                if (!$atom) {
                    return false;
                }
                if ($c === '{') {
                    if (!preg_match('/\G\{\d+(,\d*)?\}/', $regex, $m, 0, $i)) {
                        return false; // "{" as a literal or "{,n}": interpreted differently
                    }
                    $i += strlen($m[0]) - 1;
                }
                if (substr($regex, $i + 1, 1) === '+') {
                    return false; // possessive
                }
                if (substr($regex, $i + 1, 1) === '?') {
                    $i++; // lazy - the same in both
                }
                $atom = false;
            } elseif (strpos('^$.}]', $c) !== false) {
                return false;
            } else {
                $atom = true;
            }
        }
        return true;
    }

    /** May the browser check this pattern (from all())? Only if the regex passed the load test and clientSafe() holds. */
    public static function client(array $pattern): bool
    {
        return empty($pattern['complex']) && self::clientSafe($pattern['regex']);
    }

    // ---------------------------------------------------------------- Load test (ReDoS)

    /**
     * Would the regex need catastrophically many steps for some inputs? It is run against generated values that typically
     * trigger backtracking traps (stressProbes()): long repetitions of characters and literals from the regex itself, each
     * with a non-matching character at the end. Too complex if one of them ends in a PCRE error (backtracking, recursion
     * or JIT stack limit - the same limits as every match()) or all of them together take longer than STRESS_SECONDS.
     * A heuristic: it reliably catches nested and overlapping repetitions such as (a|a)+, (a+)+ or (.*a){20}; the browser
     * additionally checks with a time limit (frontend/src/components/patternCheck.js). An invalid regex is not "too complex".
     */
    public static function tooComplex(string $regex): bool
    {
        if (self::syntaxError($regex) !== null) {
            return false;
        }
        $deadline = microtime(true) + self::STRESS_SECONDS;
        foreach (self::stressProbes($regex) as $probe) {
            if (self::match($regex, $probe) === null || microtime(true) > $deadline) {
                return true;
            }
        }
        return false;
    }

    /**
     * Probe values of the load test: every unit (stressUnits()) repeated in every STRESS_LENGTHS, followed by every
     * STRESS_TAILS character - on its own, after the literal start of the regex (e.g. "ID-" of ID-(a+)+) and before its
     * literal end (e.g. "y" of (x+x+)+y: PCRE gives up at once if a required last character is missing, the browser does
     * not), shorter ones first.
     *
     * @return string[]
     */
    private static function stressProbes(string $regex): array
    {
        [$units, $prefix, $suffix] = self::stressUnits($regex);
        $probes = [];
        foreach (self::STRESS_LENGTHS as $length) {
            foreach (array_unique([['', ''], [$prefix, ''], ['', $suffix]], SORT_REGULAR) as [$start, $end]) {
                foreach ($units as $unit) {
                    foreach (self::STRESS_TAILS as $tail) {
                        $probes[] = $start . str_repeat($unit, $length) . $end . $tail;
                    }
                }
            }
        }
        return $probes;
    }

    /**
     * Units of the probe values, read from the regex: a sample character of every atom (literal character; character
     * class, escape such as \d or "." -> the first SAMPLES character it matches) and every run of consecutive atoms between
     * groups and alternatives (quantifiers ignored, e.g. "ab" and "cd" of (ab|cd)+), plus "a" and "1"; at most
     * STRESS_UNITS_MAX. Further values: the run at the very start and at the very end of the regex (prefix and suffix of
     * the probes, '' if it starts or ends with a group). Anchors, lookarounds and backreferences give no sample - it is a heuristic, not a parser.
     *
     * @return array{0:string[],1:string,2:string}
     */
    private static function stressUnits(string $regex): array
    {
        $chars = preg_split('//u', $regex, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        $singles = [];
        $runs = [];
        $run = '';
        $prefix = null;
        $flush = function () use (&$run, &$runs, &$prefix) {
            if ($prefix === null) {
                $prefix = $run;
            }
            if (mb_strlen($run) > 1) {
                $runs[] = $run;
            }
            $run = '';
        };
        $add = function (?string $sample) use (&$run, &$singles) {
            if ($sample !== null) {
                $singles[] = $sample;
                $run .= $sample;
            }
        };
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\') {
                $next = $chars[$i + 1] ?? '';
                $i++;
                if ($next === 'Q') { // \Q…\E: literal text
                    for ($i++; $i < $n && !($chars[$i] === '\\' && ($chars[$i + 1] ?? '') === 'E'); $i++) {
                        $add($chars[$i]);
                    }
                    $i++;
                } elseif ($next !== '' && !preg_match('/[a-zA-Z0-9]/', $next)) {
                    $add($next); // escaped punctuation
                } else {
                    $atom = '\\' . $next;
                    if (in_array($next, ['p', 'P', 'x'], true) && ($chars[$i + 1] ?? '') === '{') {
                        for (; $i < $n && $chars[$i] !== '}'; $i++) {
                            $atom .= $chars[$i + 1] ?? '';
                        }
                    } elseif (in_array($next, ['p', 'P'], true)) {
                        $atom .= $chars[++$i] ?? '';
                    }
                    $add(self::sample($atom));
                }
            } elseif ($c === '[') {
                $class = '[';
                $start = $i + 1;
                for ($i++; $i < $n; $i++) {
                    $d = $chars[$i];
                    $class .= $d;
                    if ($d === '\\') {
                        $class .= $chars[++$i] ?? '';
                    } elseif ($d === '[' && ($chars[$i + 1] ?? '') === ':') { // POSIX class [:alpha:]
                        for ($i++; $i < $n && !($chars[$i] === ']' && $chars[$i - 1] === ':'); $i++) {
                            $class .= $chars[$i];
                        }
                        $class .= $chars[$i] ?? '';
                    } elseif ($d === ']' && $i > $start && !($i === $start + 1 && $chars[$start] === '^')) {
                        break;
                    }
                }
                $add(self::sample($class));
            } elseif ($c === '(') {
                $flush();
                if (($chars[$i + 1] ?? '') === '?' || ($chars[$i + 1] ?? '') === '*') {
                    // group header (?:, (?=, (?<=, (?<name>, (?i), (*UTF) …: skipped up to its last character
                    for ($i += 2; $i < $n && !in_array($chars[$i], [':', ')', '>', "'", '=', '!', '|'], true); $i++) {
                    }
                }
            } elseif ($c === ')' || $c === '|') {
                $flush();
            } elseif ($c === '{' && preg_match('/^\{\d+(,\d*)?\}/', implode('', array_slice($chars, $i, 12)), $m)) {
                $i += strlen($m[0]) - 1; // quantifier
                if (in_array($chars[$i + 1] ?? '', ['?', '+'], true)) {
                    $i++;
                }
            } elseif (in_array($c, ['?', '*', '+'], true)) {
                if (in_array($chars[$i + 1] ?? '', ['?', '+'], true)) {
                    $i++;
                }
            } elseif ($c === '.') {
                $add('a');
            } elseif ($c !== '^' && $c !== '$') {
                $add($c);
            }
        }
        $suffix = $run;
        $flush();
        $units = array_slice(array_values(array_unique(array_merge($singles, ['a', '1'], $runs))), 0, self::STRESS_UNITS_MAX);
        return [$units, (string) $prefix, $suffix];
    }

    /** First SAMPLES character matched by a single atom (character class, escape), null if none (e.g. an anchor \b) */
    private static function sample(string $atom): ?string
    {
        foreach (self::SAMPLES as $candidate) {
            if (@preg_match("\x01^(?:" . $atom . ")$\x01u", $candidate) === 1) {
                return $candidate;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Helpers

    private static function row(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM lh_pattern WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiException::of(404, 'not_found', Msg::of('msg_patterns.not_found',
                'Pattern not found (built-in patterns cannot be changed)'));
        }
        return $row;
    }

    private static function fromRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'regex' => (string) $row['regex'],
            'message' => (string) $row['message'], 'example' => $row['example'] !== null && $row['example'] !== '' ? (string) $row['example'] : null,
            'builtin' => false, 'complex' => ($row['too_complex'] ?? null) === null ? self::tooComplex((string) $row['regex'])
                : (bool) $row['too_complex']];
    }

    private static function present(array $p, array $usage): array
    {
        return $p + ['client' => self::client($p), 'used_by' => $usage[$p['name']] ?? []];
    }

    /**
     * Checked values: name (NAME, not a built-in one, unique), regex (syntaxError(); a new or changed one also
     * tooComplex(), code patterns.too_complex), message (1-300 characters, one line), example (optional, at most 255 characters, must match the regex - it serves as a valid test
     * value).
     *
     * @return array{name:string,regex:string,message:string,example:?string}
     */
    private static function validate(PDO $pdo, array $body, ?array $current): array
    {
        $errors = [];
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (!preg_match(self::NAME, $name)) {
            $errors['name'] = Msg::of('msg_patterns.name_invalid', 'Name of lower-case letters, digits and _ expected, '
                . 'starting with a letter (at most 50 characters)');
        } elseif (isset(self::BUILTIN[$name])) {
            $errors['name'] = Msg::of('msg_patterns.name_builtin', "'{name}' is a built-in pattern - please choose a name of your own",
                ['name' => $name]);
        }
        $regex = is_string($body['regex'] ?? null) ? $body['regex'] : '';
        $syntax = self::syntaxError($regex);
        if ($syntax !== null) {
            $errors['regex'] = $syntax;
        } elseif (($current === null || $regex !== $current['regex']) && self::tooComplex($regex)) {
            $errors['regex'] = self::tooComplexMessage();
        }
        $message = is_string($body['message'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $body['message'])) : '';
        if ($message === '' || mb_strlen($message) > self::MESSAGE_MAX) {
            $errors['message'] = Msg::of('msg_patterns.message_invalid', 'Message for editors expected (1 to {max} characters)',
                ['max' => self::MESSAGE_MAX]);
        }
        $example = $body['example'] ?? null;
        $example = is_string($example) ? trim($example) : ($example === null ? '' : null);
        if ($example === null || mb_strlen($example) > self::EXAMPLE_MAX || preg_match('/[\x00-\x1F\x7F]/', $example)) {
            $errors['example'] = Msg::of('msg_patterns.example_invalid', 'At most {max} characters on one line',
                ['max' => self::EXAMPLE_MAX]);
        } elseif ($example !== '' && $syntax === null && self::match($regex, $example) !== true) {
            $errors['example'] = Msg::of('msg_patterns.example_mismatch', 'The example value does not match the regular expression');
        }
        if ($errors) {
            throw ApiException::invalid($errors);
        }
        $stmt = $pdo->prepare('SELECT id FROM lh_pattern WHERE name = ? AND id != ?');
        $stmt->execute([$name, $current !== null ? $current['id'] : 0]);
        if ($stmt->fetchColumn() !== false) {
            throw ApiException::of(409, 'conflict', Msg::of('msg_patterns.exists', "A pattern '{name}' already exists.",
                ['name' => $name]), ApiException::fields(['name' => Msg::of('msg_api.taken', 'already taken')]));
        }
        return ['name' => $name, 'regex' => $regex, 'message' => $message, 'example' => $example !== '' ? $example : null];
    }

    /** 409 if a field of the active schema uses the pattern */
    private static function guardUse(?array $model, string $name, bool $rename): void
    {
        $uses = self::usage($model)[$name] ?? [];
        if ($uses) {
            $params = ['name' => $name, 'fields' => implode(', ', array_map(function ($u) {
                return "{$u['entity']}.{$u['field']}";
            }, $uses))];
            throw ApiException::of(409, 'in_use', $rename
                ? Msg::of('msg_patterns.in_use_rename', "The pattern '{name}' cannot be renamed: the active schema uses it "
                    . '({fields}). Please remove {pattern: {name}} from the diagram first and apply the schema.', $params)
                : Msg::of('msg_patterns.in_use_delete', "The pattern '{name}' cannot be deleted: the active schema uses it "
                    . '({fields}). Please remove {pattern: {name}} from the diagram first and apply the schema.', $params),
                ['used_by' => $uses]);
        }
    }
}
