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
 * Column selection of the list view, stored per user and entity (system level like users, independent of the
 * PlantUML content schema). All routes using this class go through Http::userApi() and are therefore bound to the
 * session: user_id always comes from the session, never from the request.
 *
 * The table is created on the first save (and additionally on /bootstrap via Auth::provision()); an existing
 * installation therefore does not need another bootstrap. If it is missing when reading, there simply is no stored
 * selection yet.
 */
final class ColumnPrefs
{
    public const TABLE = 'user_column_prefs';

    public const DDL = 'CREATE TABLE IF NOT EXISTS user_column_prefs (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  entity_name VARCHAR NOT NULL,
  visible_columns TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, entity_name)
)';

    /** @var PDO */
    private $pdo;
    /** @var Cms */
    private $cms;
    /** @var int */
    private $userId;

    public function __construct(PDO $pdo, Cms $cms, int $userId)
    {
        $this->pdo = $pdo;
        $this->cms = $cms;
        $this->userId = $userId;
    }

    public static function ensureTable(PDO $pdo): bool
    {
        $created = !Database::tableExists($pdo, self::TABLE);
        $pdo->exec(self::DDL);
        return $created;
    }

    /**
     * @return array{entity:string,visible_columns:?array} visible_columns = null: no stored selection, the UI uses its
     *         default selection. Columns that no longer exist in the current schema are dropped.
     */
    public function get(string $entity): array
    {
        $known = $this->columnNames($entity); // throws 404 for an unknown entity
        $columns = null;
        if (Database::tableExists($this->pdo, self::TABLE)) {
            $stmt = $this->pdo->prepare(
                'SELECT visible_columns FROM user_column_prefs WHERE user_id = ? AND entity_name = ?'
            );
            $stmt->execute([$this->userId, $entity]);
            $json = $stmt->fetchColumn();
            if ($json !== false) {
                $decoded = json_decode((string) $json, true);
                if (is_array($decoded)) {
                    $columns = self::normalize($decoded, $known);
                }
            }
        }
        return ['entity' => $entity, 'visible_columns' => $columns];
    }

    /** @param array $input {"visible_columns": ["name", "preis", ...]} */
    public function put(string $entity, array $input): array
    {
        $known = $this->columnNames($entity);
        $given = $input['visible_columns'] ?? null;
        $error = null;
        if (!is_array($given) || array_values($given) !== $given) {
            $error = 'Liste von Spaltennamen erwartet';
        } else {
            foreach ($given as $name) {
                if (!is_string($name) || !in_array($name, $known, true)) {
                    $error = 'Unbekannte Spalte: ' . (is_scalar($name) ? (string) $name : gettype($name));
                    break;
                }
            }
        }
        if ($error !== null) {
            throw new ApiException(422, [
                'error'   => 'validation',
                'message' => 'Ungültige Eingabe',
                'errors'  => ['visible_columns' => $error],
            ]);
        }

        $columns = self::normalize($given, $known);
        self::ensureTable($this->pdo);
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO user_column_prefs (user_id, entity_name, visible_columns, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$this->userId, $entity, json_encode($columns, JSON_UNESCAPED_UNICODE)]);
        return ['entity' => $entity, 'visible_columns' => $columns];
    }

    /** Discard the stored selection: afterwards the default selection applies again. */
    public function delete(string $entity): array
    {
        $this->columnNames($entity);
        if (Database::tableExists($this->pdo, self::TABLE)) {
            $stmt = $this->pdo->prepare('DELETE FROM user_column_prefs WHERE user_id = ? AND entity_name = ?');
            $stmt->execute([$this->userId, $entity]);
        }
        return ['entity' => $entity, 'visible_columns' => null];
    }

    /** @return string[] all columns of the list view: field names, then the n:n names, then the media fields (each in diagram order) */
    private function columnNames(string $entity): array
    {
        $schema = $this->cms->describe($entity);
        return array_merge(
            array_column($schema['fields'], 'name'),
            array_column($schema['many_to_many'], 'name'),
            array_column($schema['media'] ?? [], 'name')
        );
    }

    /**
     * Known columns only, without duplicates, in diagram order; id is always included (cannot be deselected).
     *
     * @param string[] $known
     * @return string[]
     */
    private static function normalize(array $columns, array $known): array
    {
        return array_values(array_filter($known, function ($name) use ($columns) {
            return $name === 'id' || in_array($name, $columns, true);
        }));
    }
}
