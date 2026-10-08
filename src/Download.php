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

/** Response of an API handler that is sent as a file instead of JSON (Http::adminApi(), e.g. the CSV export). */
final class Download
{
    /** @var string */
    public $filename;
    /** @var string */
    public $contentType;
    /** @var string */
    public $body;

    public function __construct(string $filename, string $contentType, string $body)
    {
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->body = $body;
    }
}
