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
 * One-time upgrade of installations whose stored model is from before field_order (2026-10-21): the model knew the order
 * of the class body only partially (media fields in a list of their own, see PumlParser::finishOrder()), so the form kept
 * the old order (fields, n:n, media) until the next schema change. ensure() parses the stored source of the active schema
 * once more and adds field_order, sections and section to the stored model - nothing else of the model changes.
 *
 * Conditions (otherwise the model stays as it is): no schema migration running, the stored source matches schema_hash
 * (no pending migration_needed - the hash itself is not touched), the source parses with the stored workflow definitions,
 * the generated tables are exactly those of the stored model and the new field_order names exactly its fields, media fields
 * and n:n lists. Beforehand a schema backup (SchemaBackup, like before „Anwenden“) is written. Idempotent: done once
 * (_meta field_order_upgrade), and a model that already has field_order is never touched. Every outcome other than "nothing
 * to do" is written to the PHP error log.
 */
final class FieldOrderUpgrade
{
    private const META_KEY = 'field_order_upgrade';

    /** @return array the model to work with (upgraded, or unchanged) */
    public static function ensure(PDO $pdo, array $model): array
    {
        if (!self::needed($model) || Maintenance::active() || Database::getMeta($pdo, self::META_KEY) !== null) {
            return $model;
        }
        $source = Database::getMeta($pdo, 'schema_source');
        $hash = Database::getMeta($pdo, 'schema_hash');
        if ($source === null || $hash === null || !hash_equals($hash, hash('sha256', $source))) {
            return $model; // migration pending: the next „Anwenden“ stores a model with field_order anyway
        }
        // only one request does the upgrade; the others go on with the unchanged model
        $lock = @fopen(Config::dataDir() . '/field-order-upgrade.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $model;
        }
        try {
            if (Database::getMeta($pdo, self::META_KEY) !== null) {
                $current = Database::loadModel($pdo);
                return $current ?? $model; // done in the meantime by another request
            }
            $upgraded = self::upgraded($model, $source);
            if (is_string($upgraded)) {
                self::finish($pdo, 'skipped', "Feldreihenfolge nicht nachgerüstet ($upgraded) - sie wird mit dem nächsten "
                    . '„Anwenden“ einer Schema-Änderung übernommen.');
                return $model;
            }
            $file = Config::schemaDir() . '/' . (Database::getMeta($pdo, 'active_schema') ?? '');
            try {
                $backup = SchemaBackup::create($pdo, $file, 'das Nachrüsten der Feldreihenfolge');
            } catch (ApiException $e) {
                self::finish($pdo, 'skipped', 'Feldreihenfolge nicht nachgerüstet: Schema-Sicherung fehlgeschlagen ('
                    . ($e->payload['message'] ?? $e->getMessage()) . ').');
                return $model;
            }
            $pdo->beginTransaction();
            Database::setMeta($pdo, 'schema_json', json_encode($upgraded, JSON_UNESCAPED_UNICODE));
            Database::setMeta($pdo, self::META_KEY, 'done');
            $pdo->commit();
            SchemaBackup::rotate(Settings::get($pdo, Settings::SCHEMA_BACKUP_KEEP));
            error_log('[LessHeadCMS] Feldreihenfolge (field_order, Formular-Abschnitte) für ' . count($upgraded['entities'])
                . " Entität(en) nachgerüstet; Sicherung data/schema-backups/{$backup['stamp']}_*.");
            return $upgraded;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function needed(array $model): bool
    {
        foreach ($model['entities'] as $e) {
            if (!isset($e['field_order'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array|string the model with field_order/sections/section, or the reason why not */
    private static function upgraded(array $model, string $source)
    {
        try {
            $parsed = PumlParser::parse($source, $model['workflows'] ?? null);
        } catch (SchemaException $e) {
            return 'gespeichertes Schema nicht lesbar: ' . $e->getMessage();
        }
        if (SqlGenerator::createStatements($parsed) !== SqlGenerator::createStatements($model)) {
            return 'die Tabellen des gespeicherten Modells weichen vom Schema ab';
        }
        foreach ($model['entities'] as $table => &$e) {
            $p = $parsed['entities'][$table];
            $names = array_merge(array_column($e['fields'], 'name'), array_column($e['media'] ?? [], 'name'),
                array_column($e['many_to_many'], 'name'));
            $order = $p['field_order'];
            sort($names);
            sort($order);
            if ($names !== $order) {
                return "die Felder von {$e['name']} weichen vom Schema ab";
            }
            $e['field_order'] = $p['field_order'];
            if (!empty($p['sections'])) {
                $e['sections'] = $p['sections'];
                foreach (['fields', 'media', 'many_to_many'] as $list) {
                    $section = array_column(array_filter($p[$list], function ($x) {
                        return array_key_exists('section', $x);
                    }), 'section', 'name');
                    foreach ($e[$list] ?? [] as $i => $x) {
                        if (array_key_exists($x['name'], $section)) {
                            $e[$list][$i]['section'] = $section[$x['name']];
                        }
                    }
                }
            }
        }
        unset($e);
        return $model;
    }

    private static function finish(PDO $pdo, string $state, string $message): void
    {
        Database::setMeta($pdo, self::META_KEY, $state);
        error_log('[LessHeadCMS] ' . $message);
    }
}
