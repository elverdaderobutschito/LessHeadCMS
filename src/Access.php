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
 * Effective permissions of ONE user who is subject to the permission system (role = 'redakteur'), fully computed by
 * Permissions::access(). Admins and the public API without a session have no Access object (null = unrestricted).
 *
 * It only contains the deviations from the default: denied entities, denied fields per entity (field, FK column,
 * n:n list or media field, each under the name the API uses), the granted system areas (default there: denied) and
 * the denied write actions per entity (create, update, delete).
 */
final class Access
{
    /** @var array<string,true> table => true */
    private $entities;
    /** @var array<string,array<string,true>> table => [API name => true] */
    private $fields;
    /** @var array<string,true> system area => true (granted) */
    private $systables;
    /** @var array<string,array<string,true>> table => [create|update|delete => true] (denied) */
    private $actions;

    public function __construct(array $entities = [], array $fields = [], array $systables = [], array $actions = [])
    {
        $this->entities = $entities;
        $this->fields = $fields;
        $this->systables = $systables;
        $this->actions = $actions;
    }

    /** Is the write action (Permissions::ACTIONS) denied on the entity? Reading is never affected by this. */
    public function actionDenied(string $table, string $action): bool
    {
        return isset($this->actions[$table][$action]);
    }

    public function entityDenied(string $table): bool
    {
        return isset($this->entities[$table]);
    }

    /** @return array<string,true> denied API names of the entity (empty = none) */
    public function deniedFields(string $table): array
    {
        return $this->fields[$table] ?? [];
    }

    public function systable(string $name): bool
    {
        return isset($this->systables[$name]);
    }

    /** For GET /api/_permissions/me and /api/_users/{id}/permissions */
    public function export(): array
    {
        $fields = [];
        foreach ($this->fields as $table => $names) {
            if (!isset($this->entities[$table]) && $names) {
                $fields[$table] = array_keys($names);
            }
        }
        // in the fixed order create, update, delete; without entities that are denied anyway
        $actions = [];
        foreach ($this->actions as $table => $names) {
            $list = array_values(array_filter(Permissions::ACTIONS, function ($a) use ($names) {
                return isset($names[$a]);
            }));
            if (!isset($this->entities[$table]) && $list) {
                $actions[$table] = $list;
            }
        }
        $systables = [];
        foreach (Permissions::SYSTABLES as $name) {
            $systables[$name] = isset($this->systables[$name]);
        }
        return [
            'admin'           => false,
            'denied_entities' => array_keys($this->entities),
            'denied_fields'   => (object) $fields,
            'denied_actions'  => (object) $actions,
            'systables'       => $systables,
        ];
    }
}
