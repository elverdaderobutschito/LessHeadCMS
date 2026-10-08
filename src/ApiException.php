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

/** Error with HTTP status and JSON payload for the REST API. */
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
        parent::__construct((string) ($payload['message'] ?? $payload['error'] ?? 'API-Fehler'));
        $this->status = $status;
        $this->payload = $payload;
        $this->headers = $headers;
    }
}
