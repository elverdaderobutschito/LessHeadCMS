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
 * A value does not fit a field (Cms::coerce() and the value checks of the schema migration). getMessage() is the English
 * text; $msg carries code and parameters for the UI.
 */
final class ValueException extends \InvalidArgumentException
{
    /** @var Msg */
    public $msg;

    public function __construct(Msg $msg)
    {
        $this->msg = $msg;
        parent::__construct($msg->text());
    }

    /** Message of any \InvalidArgumentException (a plain one, e.g. from PHP, as its text) */
    public static function msgOf(\InvalidArgumentException $e): Msg
    {
        return $e instanceof self ? $e->msg : Msg::of('msg_api.plain', '{text}', ['text' => $e->getMessage()]);
    }
}
