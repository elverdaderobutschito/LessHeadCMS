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
 * Versions of the records (table lh_revision): every save through Cms - create (version 1), update, workflow
 * transition, restore - writes one row with a JSON snapshot of the whole record, in the same transaction as the change.
 *
 * Snapshot: {"<field>": value, ...} of all fields of the model except the id (FK columns as the id of the target row,
 * the workflow field with its state), every n:n list as the IDs of the linked rows and every media field as the IDs of
 * the media in list order - exactly what PUT accepts, so a restore is a normal PUT. Not part of it: the tracking metadata
 * (RecordMeta; created_at/created_by of a version row take their place) and _min_warnings. Linked rows and media are
 * referenced, not copied: they have a life of their own (a linked record has its own history, a medium its own
 * metadata in the media library).
 *
 * {symmetric} n:n self-reference: the list of a record also shows the pairs saved from the other record. Whoever changes
 * such a list therefore also changes the records added or removed there - each of them gets a version of its own
 * (action link, linked_from = the saved record), so the newest version is always the state the API shows.
 *
 * Version numbers count per record from 1 and never repeat (the newest + 1, also after pruning). Retention:
 * Settings::REVISIONS_KEEP versions per record (default 5, 0 = all); older ones are deleted on the next save of the
 * record. Records from before the versioning have no history; it starts with their next save. Deleting a record deletes
 * its versions (Cms::delete()); a schema migration carries renamed entities, fields and lists along (migrate()).
 *
 * created_by: users.id without a database constraint (users are never deleted), resolved to the current name when
 * reading - like RecordMeta.
 */
final class Revisions
{
    public const TABLE = 'lh_revision';
    /** Longest reason for a change (characters) */
    public const REASON_MAX = 1000;
    /** Request body key of the optional reason for a change (POST/PUT/transition/restore) - cannot be a field name */
    public const REASON_KEY = '_reason';
    public const ACTIONS = ['create', 'update', 'transition', 'restore', 'link'];

    private const DDL = "CREATE TABLE IF NOT EXISTS lh_revision (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  version INTEGER NOT NULL,
  action TEXT NOT NULL CHECK (action IN ('create', 'update', 'transition', 'restore', 'link')),
  snapshot TEXT NOT NULL,
  reason TEXT,
  restored_from INTEGER,
  transition TEXT,
  linked_from INTEGER,
  created_at DATETIME NOT NULL,
  created_by INTEGER,
  UNIQUE (entity, record_id, version)
)";

    /** @var bool table checked in this request */
    private static $ensured = false;

    /** Create the table if it is missing (on /bootstrap via Auth::provision(), otherwise on the first save). @return bool created */
    public static function ensure(PDO $pdo): bool
    {
        if (self::$ensured) {
            return false;
        }
        $created = !Database::tableExists($pdo, self::TABLE);
        $pdo->exec(self::DDL);
        self::$ensured = true;
        return $created;
    }

    /**
     * Reason for a change from a request body: null if the entity has a field of that name itself (older installation,
     * before the name was reserved - then the value belongs to the field).
     */
    public static function reasonOf(array $e, array $input): ?string
    {
        foreach ($e['fields'] as $f) {
            if (strtolower($f['name']) === self::REASON_KEY) {
                return null;
            }
        }
        return self::reason($input[self::REASON_KEY] ?? null);
    }

    /**
     * Optional reason for a change from the request body (_reason): trimmed text or null. 422 if it is not text or too long.
     *
     * @param mixed $value
     */
    public static function reason($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe',
                'errors' => [self::REASON_KEY => 'Text erwartet']]);
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > self::REASON_MAX) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe',
                'errors' => [self::REASON_KEY => 'Höchstens ' . self::REASON_MAX . ' Zeichen']]);
        }
        return $value === '' ? null : $value;
    }

    /**
     * Snapshot of a record as read by Cms (fetchRow, unfiltered): fields except id, n:n lists, media fields as IDs.
     */
    public static function snapshot(array $e, array $row): array
    {
        $out = [];
        foreach ($e['fields'] as $f) {
            if (!$f['primary']) {
                $out[$f['name']] = $row[$f['name']] ?? null;
            }
        }
        foreach ($e['many_to_many'] as $m) {
            $out[$m['name']] = array_values(array_map('intval', $row[$m['name']] ?? []));
        }
        foreach ($e['media'] ?? [] as $m) {
            $out[$m['name']] = array_values(array_map(function ($medium) {
                return (int) (is_array($medium) ? $medium['id'] : $medium);
            }, $row[$m['name']] ?? []));
        }
        return $out;
    }

    /**
     * New version of a record (inside the caller's transaction) and pruning beyond the retention. @return int version number
     *
     * @param array $row the record after the change (Cms::fetchRow, unfiltered)
     * @param array $extra 'reason', 'restored_from', 'transition', 'linked_from'
     */
    public static function record(PDO $pdo, array $e, int $id, array $row, string $action, ?array $user, array $extra = []): int
    {
        self::ensure($pdo);
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM lh_revision WHERE entity = ? AND record_id = ?');
        $stmt->execute([$e['table'], $id]);
        $version = (int) $stmt->fetchColumn() + 1;
        // the same point in time as updated_at of the record (RecordMeta), if it has one; a link version (the record itself was
        // not saved, its updated_at stays) gets the current time
        $at = $action !== 'link' && is_string($row[RecordMeta::UPDATED_AT] ?? null) ? $row[RecordMeta::UPDATED_AT] : RecordMeta::now();
        $pdo->prepare('INSERT INTO lh_revision (entity, record_id, version, action, snapshot, reason, restored_from, transition,
                       linked_from, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$e['table'], $id, $version, $action, json_encode(self::snapshot($e, $row), JSON_UNESCAPED_UNICODE),
                $extra['reason'] ?? null, $extra['restored_from'] ?? null, $extra['transition'] ?? null, $extra['linked_from'] ?? null,
                $at, $user !== null ? (int) $user['id'] : null]);
        $keep = Settings::get($pdo, Settings::REVISIONS_KEEP);
        if ($keep > 0 && $version > $keep) {
            $pdo->prepare('DELETE FROM lh_revision WHERE entity = ? AND record_id = ? AND version <= ?')
                ->execute([$e['table'], $id, $version - $keep]);
        }
        return $version;
    }

    /**
     * Versions of a record, newest first, without the snapshot: version, action, created_at, created_by
     * {id, username, name}, reason, restored_from, transition and changed (names that differ from the next older kept
     * version; null for the oldest kept one - nothing to compare with). $hidden: names the caller must not see.
     *
     * @param array<string,true> $hidden
     */
    public static function all(PDO $pdo, string $table, int $id, array $hidden): array
    {
        if (!Database::tableExists($pdo, self::TABLE)) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM lh_revision WHERE entity = ? AND record_id = ? ORDER BY version');
        $stmt->execute([$table, $id]);
        $rows = $stmt->fetchAll();
        $users = self::users($pdo, array_column($rows, 'created_by'));
        $out = [];
        $previous = null;
        foreach ($rows as $r) {
            $snapshot = array_diff_key((array) json_decode((string) $r['snapshot'], true), $hidden);
            $changed = null;
            if ($previous !== null) {
                $changed = [];
                foreach (array_unique(array_merge(array_keys($previous), array_keys($snapshot))) as $name) {
                    if (($previous[$name] ?? null) !== ($snapshot[$name] ?? null)) {
                        $changed[] = $name;
                    }
                }
            }
            $out[] = self::present($r, $users) + ['changed' => $changed];
            $previous = $snapshot;
        }
        return array_reverse($out);
    }

    /** One version with its snapshot (without $hidden), or null */
    public static function one(PDO $pdo, string $table, int $id, int $version, array $hidden): ?array
    {
        if (!Database::tableExists($pdo, self::TABLE)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM lh_revision WHERE entity = ? AND record_id = ? AND version = ?');
        $stmt->execute([$table, $id, $version]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        return self::present($r, self::users($pdo, [$r['created_by']]))
            + ['snapshot' => array_diff_key((array) json_decode((string) $r['snapshot'], true), $hidden)];
    }

    /** Delete the versions of deleted records (Cms::delete(), in its transaction) */
    public static function forgetRecords(PDO $pdo, string $table, array $ids): void
    {
        if (!$ids || !Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $pdo->prepare('DELETE FROM lh_revision WHERE entity = ? AND record_id IN (' . implode(', ', $chunk) . ')')->execute([$table]);
        }
    }

    /**
     * After a schema migration (in its transaction): versions of renamed entities move along, those of removed entities
     * are deleted; in the snapshots renamed fields, n:n lists and media fields get their new name, removed ones disappear
     * (their values were confirmed as lost by the migration). Values of fields whose type changed stay as they were - a
     * restore checks them like any input.
     *
     * Snapshots are only rewritten for entities where a name changes or something is removed - otherwise (the usual case)
     * at most the entity name is updated, independent of the number of versions.
     *
     * @param array $plan SchemaMigration plan: entityMap (new => old|null), colMaps (new table => [old => new] fields),
     *        listMaps (new table => [new => old] n:n and media)
     * @param array $oldModel the model before the migration
     */
    public static function migrate(PDO $pdo, array $plan, array $oldModel): void
    {
        if (!Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        $target = [];
        foreach ($plan['entityMap'] as $new => $old) {
            if ($old !== null) {
                $target[$old] = (string) $new;
            }
        }
        $entities = $pdo->query('SELECT DISTINCT entity FROM lh_revision')->fetchAll(PDO::FETCH_COLUMN);
        $del = $pdo->prepare('DELETE FROM lh_revision WHERE entity = ?');
        $put = $pdo->prepare('UPDATE lh_revision SET entity = ?, snapshot = ? WHERE id = ?');
        foreach ($entities as $old) {
            if (!isset($target[$old])) {
                $del->execute([$old]);
                continue;
            }
            $new = $target[$old];
            $map = ($plan['colMaps'][$new] ?? []) + array_flip($plan['listMaps'][$new] ?? []);
            $e0 = $oldModel['entities'][$old] ?? null;
            $names = $e0 === null ? [] : array_merge(array_column(array_filter($e0['fields'], function ($f) {
                return !$f['primary'];
            }), 'name'), array_column($e0['many_to_many'], 'name'), array_column($e0['media'] ?? [], 'name'));
            $same = $e0 !== null;
            foreach ($names as $name) {
                $same = $same && ($map[$name] ?? null) === $name;
            }
            if ($same) {
                if ($new !== $old) {
                    $pdo->prepare('UPDATE lh_revision SET entity = ? WHERE entity = ?')->execute([' ' . $new, $old]);
                }
                continue;
            }
            $stmt = $pdo->prepare('SELECT id, snapshot FROM lh_revision WHERE entity = ?');
            $stmt->execute([$old]);
            foreach ($stmt->fetchAll() as $r) {
                $snapshot = [];
                foreach ((array) json_decode((string) $r['snapshot'], true) as $name => $value) {
                    if (isset($map[$name])) {
                        $snapshot[$map[$name]] = $value;
                    }
                }
                // a placeholder with a space cannot be a table name: A -> B with a new A at the same time
                $put->execute([' ' . $new, json_encode($snapshot, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
            }
        }
        $pdo->exec("UPDATE lh_revision SET entity = substr(entity, 2) WHERE entity LIKE ' %'");
    }

    /** API form of a version row (without the snapshot) */
    private static function present(array $r, array $users): array
    {
        $by = $r['created_by'] === null ? null : (int) $r['created_by'];
        return [
            'version'       => (int) $r['version'],
            'action'        => (string) $r['action'],
            'created_at'    => (string) $r['created_at'],
            'created_by'    => $by === null ? null : ($users[$by] ?? ['id' => $by, 'username' => '', 'name' => '']),
            'reason'        => $r['reason'] === null ? null : (string) $r['reason'],
            'restored_from' => $r['restored_from'] === null ? null : (int) $r['restored_from'],
            'transition'    => $r['transition'] === null ? null : (string) $r['transition'],
            'linked_from'   => ($r['linked_from'] ?? null) === null ? null : (int) $r['linked_from'],
        ];
    }

    /** @return array<int,array{id:int,username:string,name:string}> */
    private static function users(PDO $pdo, array $ids): array
    {
        $ids = array_unique(array_map('intval', array_filter($ids, function ($v) {
            return $v !== null;
        })));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($pdo->query('SELECT id, username, name FROM users WHERE id IN (' . implode(', ', $ids) . ')')->fetchAll() as $u) {
            $out[(int) $u['id']] = ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'name' => (string) $u['name']];
        }
        return $out;
    }
}
