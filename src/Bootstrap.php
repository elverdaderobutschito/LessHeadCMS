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

final class Bootstrap
{
    /**
     * Checks ?token=... against BOOTSTRAP_TOKEN from config.php.
     *
     * @param mixed $given
     * @return string|null null = access allowed, otherwise the error message for the 403 response
     */
    public static function tokenProblem($given): ?string
    {
        $expected = Config::bootstrapToken();
        if ($expected === null) {
            return 'BOOTSTRAP_TOKEN ist nicht konfiguriert - config.php anlegen (Vorlage: config.example.php), '
                . 'mindestens 16 Zeichen.';
        }
        if (!is_string($given) || !hash_equals($expected, $given)) {
            return 'Ungültiges oder fehlendes Token.';
        }
        return null;
    }

    /**
     * Checks the ?schema= parameter: a plain file name only, no path components (path traversal).
     *
     * @param mixed $given
     * @return string|null null = not given (missing or empty)
     * @throws ApiException 400 for path components or an invalid type
     */
    public static function validSchemaParam($given): ?string
    {
        if ($given === null || $given === '') {
            return null;
        }
        if (!is_string($given)
            || strpbrk($given, "/\\\0") !== false
            || strpos($given, '..') !== false
            || basename($given) !== $given
        ) {
            throw new ApiException(400, [
                'error'   => 'schema_invalid',
                'message' => 'Der Parameter schema darf nur einen reinen Dateinamen enthalten (kein Pfad).',
            ]);
        }
        return $given;
    }

    /**
     * @param mixed $schema value of ?schema=... (optional)
     * @return array response for GET /bootstrap
     * @throws ApiException 400 (schema_invalid / schema_ambiguous), 404 (schema_not_found)
     */
    public static function run($schema = null): array
    {
        $requested = self::validSchemaParam($schema);

        $available = [];
        foreach (glob(Config::schemaDir() . '/*.puml') ?: [] as $path) {
            if (is_file($path)) {
                $available[] = basename($path);
            }
        }
        sort($available);

        if ($requested !== null && !in_array($requested, $available, true)) {
            throw new ApiException(404, [
                'error'   => 'schema_not_found',
                'message' => "Datei '" . $requested . "' nicht in schema/ gefunden.",
            ]);
        }
        if (!$available) {
            return ['status' => 'no_schema_found'];
        }

        Config::ensureDataDir();
        $lock = Config::lockFile();
        if (file_exists($lock)) {
            return ['status' => 'locked']; // someone else's lock file: process nothing, delete nothing
        }
        // 'x' = create atomically, fails if someone else was faster in parallel
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            if (file_exists($lock)) {
                return ['status' => 'locked'];
            }
            throw new \RuntimeException('Lock-Datei kann nicht angelegt werden.');
        }
        fclose($handle);

        try {
            $pdo = Database::connect();
            Database::ensureMetaTable($pdo);
            $stored = Database::getMeta($pdo, 'schema_hash');

            $file = self::chooseFile($requested, $available, Database::getMeta($pdo, 'active_schema'));
            $source = file_get_contents(Config::schemaDir() . '/' . $file);
            if ($source === false) {
                throw new \RuntimeException('Schema-Datei kann nicht gelesen werden.');
            }
            $hash = hash('sha256', $source);

            if ($stored === null) {
                $model = PumlParser::parse($source);
                $statements = SqlGenerator::createStatements($model);

                $pdo->beginTransaction();
                try {
                    foreach ($statements as $sql) {
                        $pdo->exec($sql);
                    }
                    SchemaIndexes::sync($pdo, $model); // indexes on FK columns and link tables
                    Database::setMeta($pdo, 'schema_hash', $hash);
                    Database::setMeta($pdo, 'active_schema', $file);
                    Database::setMeta($pdo, 'schema_json', json_encode($model, JSON_UNESCAPED_UNICODE));
                    Database::setMeta($pdo, 'schema_source', $source);
                    SchemaIds::ensure($pdo); // stable IDs of all elements (visual schema editor)
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                $result = ['status' => 'created', 'tables' => array_keys($statements)];
            } elseif (hash_equals($stored, $hash)) {
                // databases from before active_schema get the file name added here
                if (Database::getMeta($pdo, 'active_schema') !== $file) {
                    Database::setMeta($pdo, 'active_schema', $file);
                }
                SchemaIds::ensure($pdo); // installations from before the stable IDs get them added here
                $result = ['status' => 'up_to_date'];
            } else {
                return [
                    'status'  => 'migration_needed',
                    'schema'  => $file,
                    'message' => 'Schema hat sich geändert, manuelle Migration erforderlich',
                ];
            }

            // the users table + admin are not part of the PlantUML schema and are always ensured
            return $result + ['schema' => $file] + Auth::provision($pdo);
        } finally {
            @unlink($lock);
        }
    }

    /**
     * Which schema file applies: explicitly given > exactly one present > last active one (_meta.active_schema).
     *
     * @param string[] $available sorted file names from schema/
     * @throws ApiException 400 schema_ambiguous
     */
    private static function chooseFile(?string $requested, array $available, ?string $active): string
    {
        if ($requested !== null) {
            return $requested;
        }
        if (count($available) === 1) {
            return $available[0];
        }
        if ($active !== null && in_array($active, $available, true)) {
            return $active;
        }
        throw new ApiException(400, [
            'error'     => 'schema_ambiguous',
            'message'   => 'Mehrere Schema-Dateien gefunden, bitte mit ?schema=<dateiname> angeben.',
            'available' => $available,
        ]);
    }
}
