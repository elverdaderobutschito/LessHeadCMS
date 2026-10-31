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
 * A text the server produces for people (error, hint, analysis result): code + parameters + English text.
 *
 * English is the language of the API. Clients translate or decide by code and params, never by the text. The admin UI
 * shows the text of the code in the display language (frontend/src/i18n-strings.js holds the German source text of
 * every code, translations/en.csv the English one - the same as here, see backend/tests/messages_test.php) and falls
 * back to the English text of the server if a code is unknown.
 *
 * Code: msg_<area>.<name> (the area groups them under Lokalisierung → Übersetzungen). The English template sits at the
 * call site, placeholders {name} are filled in from params. A parameter may itself be a Msg (e.g. the reason inside an
 * "Line 12: …" message) - text() fills in its text, JSON carries it as {code, params, message}. Dynamic parts (line,
 * class, field, value) are always parameters, never baked into the text.
 */
final class Msg implements \JsonSerializable
{
    /** @var string */
    public $code;
    /** @var array<string,mixed> */
    public $params;
    /** @var string */
    private $template;

    public function __construct(string $code, string $template, array $params = [])
    {
        $this->code = $code;
        $this->template = $template;
        $this->params = $params;
    }

    public static function of(string $code, string $template, array $params = []): self
    {
        return new self($code, $template, $params);
    }

    /** English text with the parameters filled in */
    public function text(): string
    {
        $replace = [];
        foreach ($this->params as $name => $value) {
            $replace['{' . $name . '}'] = self::show($value);
        }
        return strtr($this->template, $replace);
    }

    public function __toString(): string
    {
        return $this->text();
    }

    /** {code, params, message} - params as an object also when empty */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'params' => (object) self::plain($this->params), 'message' => $this->text()];
    }

    /**
     * The message as fields of a response item, e.g. ['text' => …, 'code' => …, 'params' => …] for $textKey 'text'
     * (analysis results, hints) - the text stays where clients already read it.
     */
    public function fields(string $textKey = 'message'): array
    {
        return [$textKey => $this->text(), 'code' => $this->code, 'params' => (object) self::plain($this->params)];
    }

    /** {code, params} without the text (error_codes of a field) */
    public function codeOnly(): array
    {
        return ['code' => $this->code, 'params' => (object) self::plain($this->params)];
    }

    /**
     * List of messages joined as "a, b and c" (German „a, b und c“) - the joining words belong to the language too.
     * One item is returned as it is; built from the two-part messages msg_api.and_list/comma_list (nested).
     *
     * @param Msg[] $items at least one
     */
    public static function andList(array $items): self
    {
        $items = array_values($items);
        if (count($items) === 1) {
            return $items[0];
        }
        if (count($items) === 2) {
            return Msg::of('msg_api.and_list', '{a} and {b}', ['a' => $items[0], 'b' => $items[1]]);
        }
        return Msg::of('msg_api.comma_list', '{a}, {b}', ['a' => $items[0], 'b' => self::andList(array_slice($items, 1))]);
    }

    /**
     * List of messages joined as "a, b, c"
     *
     * @param Msg[] $items at least one
     */
    public static function commaList(array $items): self
    {
        $items = array_values($items);
        return count($items) === 1 ? $items[0]
            : Msg::of('msg_api.comma_list', '{a}, {b}', ['a' => $items[0], 'b' => self::commaList(array_slice($items, 1))]);
    }

    /**
     * List of messages joined as "a b c" (sentences one after the other)
     *
     * @param Msg[] $items at least one
     */
    public static function spaceList(array $items): self
    {
        $items = array_values($items);
        return count($items) === 1 ? $items[0]
            : Msg::of('msg_api.space_list', '{a} {b}', ['a' => $items[0], 'b' => self::spaceList(array_slice($items, 1))]);
    }

    /**
     * List of messages joined as "a; b; c"
     *
     * @param Msg[] $items at least one
     */
    public static function semicolonList(array $items): self
    {
        $items = array_values($items);
        return count($items) === 1 ? $items[0]
            : Msg::of('msg_api.semicolon_list', '{a}; {b}', ['a' => $items[0], 'b' => self::semicolonList(array_slice($items, 1))]);
    }

    /**
     * Number parameter with a fixed number of decimals: English text 1,234.5 - the UI formats it with the separators of
     * the display language (German 1.234,5). JSON {type: 'number', value, decimals}.
     */
    public static function number(float $value, int $decimals): array
    {
        return ['type' => 'number', 'value' => $value, 'decimals' => $decimals];
    }

    /** Message from its JSON form {code, params, message} (e.g. error_codes + errors of a caught ApiException) */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['code'] ?? 'msg_api.plain'), (string) ($data['message'] ?? ''), (array) ($data['params'] ?? []));
    }

    /** Text of a message or of a plain string (for places that accept both) */
    public static function textOf($message): string
    {
        return $message instanceof self ? $message->text() : (string) $message;
    }

    private static function show($value): string
    {
        if ($value instanceof self) {
            return $value->text();
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            return Validation::format($value); // shortest form, 2.5 and not 2.50000000001
        }
        if (is_array($value) && ($value['type'] ?? null) === 'number') {
            return number_format((float) $value['value'], (int) $value['decimals'], '.', ',');
        }
        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function plain(array $params): array
    {
        return array_map(function ($v) {
            return $v instanceof self ? $v->jsonSerialize() : $v;
        }, $params);
    }
}
