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
 * Error with HTTP status and JSON payload for the REST API.
 *
 * Payload of every error: 'error' (machine-readable kind, e.g. validation, conflict, forbidden), 'code' + 'params' +
 * 'message' (the English text, see Msg). Field errors additionally 'errors' {field: English text} and 'error_codes'
 * {field: {code, params}} (fields()). Build it with of()/invalid(); the constructor takes a ready payload.
 */
final class ApiException extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var array */
    public $payload;
    /** @var array<string,string> additional response headers (e.g. Retry-After) */
    public $headers;

    public function __construct(int $status, array $payload, array $headers = [])
    {
        parent::__construct((string) ($payload['message'] ?? $payload['error'] ?? 'API error'));
        $this->status = $status;
        $this->payload = $payload;
        $this->headers = $headers;
    }

    /** Error of kind $error with the message $message; $extra: further fields (e.g. used_by, fields()) */
    public static function of(int $status, string $error, Msg $message, array $extra = [], array $headers = []): self
    {
        return new self($status, ['error' => $error] + $message->fields() + $extra, $headers);
    }

    /** 422 "Invalid input" with one message per field (field => Msg); $extra: further fields */
    public static function invalid(array $fieldErrors, array $extra = []): self
    {
        return self::of(422, 'validation', Msg::of('msg_api.invalid_input', 'Invalid input'), self::fields($fieldErrors) + $extra);
    }

    /** 'errors' {field: English text} and 'error_codes' {field: {code, params}} of field => Msg */
    public static function fields(array $fieldErrors): array
    {
        $errors = [];
        $codes = [];
        foreach ($fieldErrors as $field => $message) {
            $errors[$field] = $message->text();
            $codes[$field] = $message->codeOnly();
        }
        return ['errors' => $errors, 'error_codes' => (object) $codes];
    }
}
