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
 * Error in the .puml file or workflow file (cannot be parsed, unknown type, ...).
 *
 * Workflow files: $sourceLine is the affected line of the workflow file (WorkflowParser; null = no unambiguous line).
 * If the main schema parser reports an error caused by a transition of the workflow file, $where names it
 * (['workflow' => name, 'transition' => index, 'key' => property]) - only WorkflowParser::positions() knows its line.
 */
final class SchemaException extends \RuntimeException
{
    /** @var int|null (not "line": that is already the name of the line in the PHP code where the exception was created) */
    public $sourceLine;
    /** @var array|null */
    public $where;

    public function __construct(string $message, ?int $line = null, ?array $where = null)
    {
        parent::__construct($message);
        $this->sourceLine = $line;
        $this->where = $where;
    }
}
