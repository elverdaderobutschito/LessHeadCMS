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
 * Field rules from the diagram ("richer validation"): length limits, value ranges and patterns per field, e.g.
 * `+ teaser: string {maxLength: 140}`. The rules are part of the model (field 'rules', see PumlParser) and are enforced
 * by the server before every save (Cms::create()/update(), i.e. POST, PUT, PATCH, the editorial form, restore and the
 * test data generator) - always against the complete record: on PUT/PATCH the stored state merged with the change.
 *
 * Rules (each at most once per field, canonical order RULES):
 *  - minLength / maxLength (string, text, richtext): characters (Unicode, mb_strlen), not bytes. For richtext the visible
 *    plain text counts, not the Markdown syntax (Database::plainText(), the same function lhc_plain uses for lists).
 *  - min / max (int, decimal, date): inclusive limits; for date in the form JJJJ-MM-TT.
 *  - pattern (string): name of an entry of the pattern library (Patterns) - never a regex in the diagram.
 * Empty values (null, '') are never checked - whether a field must be filled stays the business of required ('?').
 *
 * Messages: the system builds them for length and range itself - English with code (validation.*, the keys of the fixed
 * UI texts in frontend/src/i18n-strings.js) and params, the UI shows them in the display language (see Msg). A violated
 * pattern instead brings the message of the pattern - for an own pattern content written by the admin, translatable as
 * kind 'pattern' and returned in the display language of the logged-in user, therefore without code; for a built-in
 * pattern a system text with code (msg_patterns.builtin_*), unless the admin translated it (the translation wins). The 422 response carries 'errors'
 * {field: message} as before plus 'violations' [{field, rule, code, message, params}] (see failure()).
 *
 * Existing records are never changed when a rule becomes stricter: they are reported (SchemaMigration, Patterns::impact(),
 * Cms::ruleViolations()) and can be saved again only once they are valid.
 */
final class Validation
{
    public const RULES = ['minLength', 'maxLength', 'min', 'max', 'pattern'];

    /** Field types per rule */
    public const TYPES = [
        'minLength' => ['string', 'text', 'richtext'],
        'maxLength' => ['string', 'text', 'richtext'],
        'min'       => ['int', 'decimal', 'date'],
        'max'       => ['int', 'decimal', 'date'],
        'pattern'   => ['string'],
    ];


    /** @var PDO|null */
    private $pdo;
    /** @var array|null logged-in user (language of the pattern messages); null = their source texts */
    private $user;
    /** @var array<string,array>|null Patterns::all(), on demand */
    private $patterns = null;
    /** @var array<string,string>|null translated pattern messages, on demand */
    private $patternTexts = null;

    public function __construct(?PDO $pdo, ?array $user = null)
    {
        $this->pdo = $pdo;
        $this->user = $user;
    }

    // ---------------------------------------------------------------- Diagram (PumlParser)

    /**
     * Checks and normalizes the rule markers of one field line (PumlParser::parseClass()).
     *
     * @param array<int,array{0:string,1:string}> $pairs [rule name as written, value as written] in diagram order
     * @param string[]|null $patterns names of the existing patterns (Patterns::names()); null = existence is not checked
     *        (the model of an already applied schema is only re-read)
     * @return array<string,int|float|string> rule => value in the canonical order of RULES (empty = no rules)
     * @throws SchemaException rule unknown or doubled, not allowed for the type, invalid value, min > max, unknown pattern
     */
    public static function parseRules(string $class, string $field, string $type, bool $primary, array $pairs, ?array $patterns): array
    {
        $where = Msg::of('msg_schema.field_in_class', "Field '{field}' in class '{class}'", ['field' => $field, 'class' => $class]);
        $rules = [];
        foreach ($pairs as [$raw, $value]) {
            $rule = null;
            foreach (self::RULES as $r) {
                if (strcasecmp($r, $raw) === 0) {
                    $rule = $r;
                }
            }
            if ($rule === null) {
                throw new SchemaException(Msg::of('msg_schema.rule_unknown',
                    "{where}: Unknown rule '{rule}' (allowed: {allowed}, each as {rule: value}).",
                    ['where' => $where, 'rule' => $raw, 'allowed' => implode(', ', self::RULES)]));
            }
            if (array_key_exists($rule, $rules)) {
                throw new SchemaException(Msg::of('msg_schema.rule_twice', '{where}: Rule {{rule}} given twice.',
                    ['where' => $where, 'rule' => $rule]));
            }
            if ($primary) {
                throw new SchemaException(Msg::of('msg_schema.rule_on_id', '{where}: The ID takes no rules such as {{rule}}.',
                    ['where' => $where, 'rule' => $rule]));
            }
            if (!in_array($type, self::TYPES[$rule], true)) {
                throw new SchemaException(Msg::of('msg_schema.rule_type',
                    "{where}: The rule {{rule}} does not fit the type '{type}' (allowed for {types}).",
                    ['where' => $where, 'rule' => $rule, 'type' => $type, 'types' => implode(', ', self::TYPES[$rule])]));
            }
            $value = trim($value);
            $rules[$rule] = self::parseValue($where, $rule, $type, $value, $patterns);
        }
        foreach ([['minLength', 'maxLength'], ['min', 'max']] as [$lo, $hi]) {
            if (isset($rules[$lo], $rules[$hi]) && $rules[$lo] > $rules[$hi]) {
                throw new SchemaException(Msg::of('msg_schema.rule_min_max', '{where}: {{lo}: {low}} is greater than {{hi}: {high}}.',
                    ['where' => $where, 'lo' => $lo, 'low' => self::format($rules[$lo]), 'hi' => $hi,
                        'high' => self::format($rules[$hi])]));
            }
        }
        $ordered = [];
        foreach (self::RULES as $r) {
            if (array_key_exists($r, $rules)) {
                $ordered[$r] = $rules[$r];
            }
        }
        return $ordered;
    }

    /** @return int|float|string */
    private static function parseValue(Msg $where, string $rule, string $type, string $value, ?array $patterns)
    {
        $bad = function (Msg $expected) use ($where, $rule, $value): SchemaException {
            return new SchemaException(Msg::of('msg_schema.rule_value', "{where}: Invalid value '{value}' for {{rule}} ({expected} expected).",
                ['where' => $where, 'value' => $value, 'rule' => $rule, 'expected' => $expected]));
        };
        if ($rule === 'minLength' || $rule === 'maxLength') {
            $from = $rule === 'maxLength' ? 1 : 0;
            if (!preg_match('/^\d{1,9}$/', $value) || (int) $value < $from) {
                throw $bad(Msg::of('msg_schema.expect_int_from', 'a whole number from {from}', ['from' => $from]));
            }
            return (int) $value;
        }
        if ($rule === 'pattern') {
            $name = strtolower($value);
            if (!preg_match(Patterns::NAME, $name)) {
                throw $bad(Msg::of('msg_schema.expect_pattern', 'the name of a pattern from the pattern library, e.g. plz'));
            }
            if ($patterns !== null && !in_array($name, $patterns, true)) {
                throw new SchemaException(Msg::of('msg_schema.pattern_unknown', "{where}: The pattern '{pattern}' does not exist "
                    . '(available: {available}). Please create it under System → Settings → Patterns or use an existing name.',
                    ['where' => $where, 'pattern' => $value, 'available' => implode(', ', $patterns)]));
            }
            return $name;
        }
        // min / max
        if ($type === 'int') {
            if (!preg_match('/^-?\d{1,18}$/', $value)) {
                throw $bad(Msg::of('msg_schema.expect_int', 'a whole number'));
            }
            return (int) $value;
        }
        if ($type === 'decimal') {
            if (!preg_match('/^-?\d{1,15}(\.\d{1,15})?$/', $value)) {
                throw $bad(Msg::of('msg_schema.expect_decimal', 'a number with a decimal point'));
            }
            // a whole number stays an int: the model is stored as JSON (1.0 would come back as 1), and SchemaMigration
            // compares the rules of the stored and the new model exactly
            $number = (float) $value;
            return floor($number) == $number && abs($number) < 1e15 ? (int) $number : $number;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw $bad(Msg::of('msg_schema.expect_date', 'a date YYYY-MM-DD'));
        }
        return $value;
    }

    /** Value of a rule as it is written in the diagram (PumlExporter, messages) */
    public static function format($value): string
    {
        if (is_float($value)) {
            if ($value == floor($value) && abs($value) < 1e15) {
                return number_format($value, 0, '.', '');
            }
            $s = rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
            // shortest representation that reads back as the same number
            for ($p = 1; $p <= 17; $p++) {
                $t = sprintf("%.{$p}F", $value);
                if ((float) $t === $value) {
                    return rtrim(rtrim($t, '0'), '.');
                }
            }
            return $s;
        }
        return (string) $value;
    }

    /** Diagram text of the rules of a field: ` {maxLength: 140} {pattern: plz}` (empty without rules) */
    public static function markers(array $rules): string
    {
        $out = '';
        foreach (self::RULES as $r) {
            if (array_key_exists($r, $rules)) {
                $out .= " {{$r}: " . self::format($rules[$r]) . '}';
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- Checking records

    /**
     * Rule violations of a record: only fields with rules whose value in $values is not empty; fields named in $skip
     * (already rejected for another reason, or denied to the user) are left out.
     *
     * @param array $values column => value in storage form (Cms::coerce()) or as read from the database
     * @return array<int,array{field:string,rule:string,code?:string,message:string,params:array}>
     */
    public function violations(array $entity, array $values, array $skip = []): array
    {
        $out = [];
        foreach ($entity['fields'] as $f) {
            if (empty($f['rules']) || isset($skip[$f['name']]) || !array_key_exists($f['name'], $values)) {
                continue;
            }
            foreach ($this->check($f, $values[$f['name']]) as $v) {
                $out[] = ['field' => $f['name']] + $v;
            }
        }
        return $out;
    }

    /**
     * Violations of one value against the rules of its field
     *
     * @param mixed $value
     * @return array<int,array{rule:string,code?:string,message:string,params:array}>
     */
    public function check(array $f, $value): array
    {
        $rules = $f['rules'] ?? [];
        if (!$rules || $value === null || $value === '') {
            return [];
        }
        $out = [];
        if (isset($rules['minLength']) || isset($rules['maxLength'])) {
            $text = $f['type'] === 'richtext' ? (string) Database::plainText((string) $value) : (string) $value;
            $count = mb_strlen($text, 'UTF-8');
            $min = $rules['minLength'] ?? null;
            $max = $rules['maxLength'] ?? null;
            $rule = $min !== null && $count < $min ? 'minLength' : ($max !== null && $count > $max ? 'maxLength' : null);
            if ($rule !== null) {
                $key = $min !== null && $max !== null ? 'validation.length_between' : ($rule === 'minLength' ? 'validation.length_min' : 'validation.length_max');
                $params = array_filter(['min' => $min, 'max' => $max], function ($v) {
                    return $v !== null;
                }) + ['count' => $count];
                $out[] = ['rule' => $rule] + self::message($key, $params);
            }
        }
        if (array_key_exists('min', $rules) || array_key_exists('max', $rules)) {
            $min = $rules['min'] ?? null;
            $max = $rules['max'] ?? null;
            $v = $f['type'] === 'date' ? (string) $value : ($f['type'] === 'int' ? (int) $value : (float) $value);
            $rule = $min !== null && $v < $min ? 'min' : ($max !== null && $v > $max ? 'max' : null);
            if ($rule !== null) {
                $kind = $f['type'] === 'date' ? 'date' : 'range';
                $key = "validation.{$kind}_" . ($min !== null && $max !== null ? 'between' : $rule);
                $params = array_filter(['min' => $min, 'max' => $max], function ($x) {
                    return $x !== null;
                });
                $out[] = ['rule' => $rule] + self::message($key, $params);
            }
        }
        if (isset($rules['pattern'])) {
            $name = $rules['pattern'];
            $pattern = $this->patterns()[$name] ?? null;
            $params = ['pattern' => $name];
            if ($pattern === null) {
                $out[] = ['rule' => 'pattern'] + self::message('validation.pattern_missing', $params);
            } else {
                $match = Patterns::match($pattern['regex'], (string) $value);
                if ($match === null) {
                    $out[] = ['rule' => 'pattern'] + self::message('validation.pattern_error', $params);
                } elseif (!$match) {
                    $out[] = ['rule' => 'pattern'] + Patterns::messageFields($this->patternMessage($pattern), $params);
                }
            }
        }
        return $out;
    }

    /**
     * Rules of a field for GET /api/{entity}/_schema: {minLength?, maxLength?, min?, max?, pattern?: {name, message, code?,
     * example, regex}} - code only for the system text of a built-in pattern (see patternMessage()); regex only if the
     * browser can check it exactly like the server and it passed the load test (Patterns::client()), otherwise null.
     * null = the field has no rules (then _schema stays as before).
     */
    public function describe(array $f): ?array
    {
        if (empty($f['rules'])) {
            return null;
        }
        $out = $f['rules'];
        if (isset($out['pattern'])) {
            $p = $this->patterns()[$out['pattern']] ?? null;
            $message = $p !== null ? $this->patternMessage($p) : null;
            $out['pattern'] = ['name' => $out['pattern'], 'message' => $message !== null ? Msg::textOf($message) : null]
                + ($message instanceof Msg ? ['code' => $message->code] : [])
                + ['example' => $p['example'] ?? null, 'regex' => $p !== null && Patterns::client($p) ? $p['regex'] : null];
        }
        return $out;
    }

    /**
     * 422 response of a failed save: 'errors' {field: message} as before (several violations of one field joined), plus
     * 'violations' - one entry per problem with the rule type: the rule of the diagram, 'required' for a missing required
     * value, otherwise 'invalid' (wrong type, unknown reference, ...).
     *
     * 'error_codes' {field: {code, params}} names the first problem of a field; the UI builds its texts from 'violations'.
     *
     * @param array<string,Msg> $errors previous field errors of Cms (message per field)
     * @param array<int,array> $violations see violations()
     */
    public static function failure(array $errors, array $violations): ApiException
    {
        $list = [];
        $texts = [];
        $codes = [];
        foreach ($errors as $field => $message) {
            $field = (string) $field;
            $list[] = ['field' => $field, 'rule' => $message->code === 'msg_api.required' ? 'required' : 'invalid',
                'code' => $message->code] + $message->fields();
            $texts[$field] = $message->text();
            $codes[$field] = $message->codeOnly();
        }
        foreach ($violations as $v) {
            $field = $v['field'];
            $texts[$field] = isset($texts[$field]) ? $texts[$field] . ' ' . $v['message'] : $v['message'];
            $codes[$field] = $codes[$field] ?? ['code' => $v['code'] ?? null, 'params' => (object) $v['params']];
            $list[] = ['field' => $field, 'rule' => $v['rule']] + (isset($v['code']) ? ['code' => $v['code']] : [])
                + ['message' => $v['message'], 'params' => (object) $v['params']];
        }
        return ApiException::of(422, 'validation', Msg::of('msg_api.invalid_input', 'Invalid input'),
            ['errors' => $texts, 'error_codes' => (object) $codes, 'violations' => $list]);
    }

    // ---------------------------------------------------------------- Texts

    /** @return array<string,array> */
    private function patterns(): array
    {
        if ($this->patterns === null) {
            $this->patterns = Patterns::all($this->pdo);
        }
        return $this->patterns;
    }

    /** Violation of a system rule: {code, message (English), params} - params as given (numbers stay numbers) */
    private static function message(string $key, array $params): array
    {
        return ['code' => $key, 'message' => self::msg($key, $params)->text(), 'params' => $params];
    }

    private static function msg(string $key, array $params): Msg
    {
        switch ($key) {
            case 'validation.length_min':
                return Msg::of('validation.length_min', 'At least {min} characters (currently {count}).', $params);
            case 'validation.length_max':
                return Msg::of('validation.length_max', 'At most {max} characters (currently {count}).', $params);
            case 'validation.length_between':
                return Msg::of('validation.length_between', 'Between {min} and {max} characters (currently {count}).', $params);
            case 'validation.range_min':
                return Msg::of('validation.range_min', 'At least {min}.', $params);
            case 'validation.range_max':
                return Msg::of('validation.range_max', 'At most {max}.', $params);
            case 'validation.range_between':
                return Msg::of('validation.range_between', 'Between {min} and {max}.', $params);
            case 'validation.date_min':
                return Msg::of('validation.date_min', 'Not before {min}.', $params);
            case 'validation.date_max':
                return Msg::of('validation.date_max', 'Not after {max}.', $params);
            case 'validation.date_between':
                return Msg::of('validation.date_between', 'Between {min} and {max}.', $params);
            case 'validation.pattern_error':
                return Msg::of('validation.pattern_error', 'The format could not be checked (pattern “{pattern}”). '
                    . 'Please enter a different value.', $params);
            default:
                return Msg::of('validation.pattern_missing', 'The pattern “{pattern}” no longer exists. Please inform the '
                    . 'administrators.', $params);
        }
    }

    /**
     * Message of a pattern for the logged-in user (Patterns::messageOf()): translation of the admin in the display language
     * (kind 'pattern'), otherwise the stored message of an own pattern or the system text (Msg) of a built-in one
     *
     * @return Msg|string
     */
    private function patternMessage(array $pattern)
    {
        if ($this->patternTexts === null) {
            $this->patternTexts = [];
            if ($this->pdo !== null && Database::tableExists($this->pdo, Languages::TRANSLATIONS)) {
                $language = $this->user !== null ? Languages::current($this->pdo, (int) $this->user['id']) : null;
                $this->patternTexts = Languages::patternMessages($this->pdo, $language);
            }
        }
        return Patterns::messageOf($pattern, $this->patternTexts);
    }
}
