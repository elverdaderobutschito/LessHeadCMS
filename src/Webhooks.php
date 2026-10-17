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
 * Webhooks (System → Einstellungen → Webhooks, admins only): on certain events the CMS calls a URL entered by the admin,
 * e.g. a build hook of Netlify or an own static site generator. The call is only the notice "this record changed" - the
 * target system fetches the content itself through the normal API with its own login.
 *
 * Two triggers, a webhook may use both:
 *  - workflow: action=webhook:<name> in a transition of the active workflow file (WorkflowParser; several lines allowed).
 *    Event "<entity>.<target state>" (technical state name).
 *  - global: on create/update/delete of all or selected entities (columns events, entities). Event "<entity>.created",
 *    ".updated", ".deleted". A transition and a restore count as update. If the same webhook is called by the transition
 *    via action=webhook, the global call for that transition is left out (one call per webhook and event).
 *
 * Call: POST with a JSON body {event, entity, id, version, hash}, header X-LessHeadCMS-Token (the token of the webhook, if
 * one is set - deliberately not Authorization, which the target system uses for its own API login) and
 * X-LessHeadCMS-Event. All webhooks of one event are called in parallel (curl_multi), synchronously after the
 * transaction has been committed, and wait at most Settings::WEBHOOK_TIMEOUT_SECONDS in total. A failure (timeout,
 * connection error, status >= 400) is only recorded in the history (the last HISTORY calls per webhook) - the save, the
 * transition or the deletion has already succeeded and stays so. Redirects are not followed.
 *
 * version: the number of the version the event has just created (Revisions); missing for deleted. hash: "sha256:" +
 * SHA-256 of the record as the public API (without login) delivers it, see hash(); missing for deleted.
 *
 * Tables are created on demand (ensure()): lh_webhook (one row per webhook) and lh_webhook_call (history). Both names
 * are reserved as class names (PumlParser::RESERVED).
 */
final class Webhooks
{
    public const TABLE = 'lh_webhook';
    public const CALLS = 'lh_webhook_call';

    /** Default of Settings::WEBHOOK_TIMEOUT_SECONDS: the editor waits at most this long for the webhooks of a save */
    public const TIMEOUT = 5;
    /** Connection setup counts towards the timeout, but takes at most this long (seconds) */
    private const CONNECT_TIMEOUT = 3;
    /** Calls kept per webhook in the history */
    public const HISTORY = 10;

    /** Header with the token of the webhook (sent only if a token is set) */
    public const TOKEN_HEADER = 'X-LessHeadCMS-Token';
    /** Header with the event, e.g. "artikel.updated" - lets the target system route without parsing the body */
    public const EVENT_HEADER = 'X-LessHeadCMS-Event';

    /** Global events in display order */
    public const EVENTS = ['created', 'updated', 'deleted'];

    private const URL_MAX = 2000;
    private const TOKEN_MAX = 200;
    private const ERROR_MAX = 300;

    private const DDL = [
        "CREATE TABLE IF NOT EXISTS lh_webhook (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE COLLATE NOCASE,
  url TEXT NOT NULL,
  token TEXT NOT NULL DEFAULT '',
  events TEXT NOT NULL DEFAULT '[]',
  entities TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME
)",
        "CREATE TABLE IF NOT EXISTS lh_webhook_call (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  webhook_id INTEGER NOT NULL REFERENCES lh_webhook(id) ON DELETE CASCADE,
  at DATETIME NOT NULL,
  event TEXT NOT NULL,
  record_id INTEGER,
  ok INTEGER NOT NULL,
  http_status INTEGER,
  error TEXT,
  duration_ms INTEGER
)",
        'CREATE INDEX IF NOT EXISTS lh_webhook_call_webhook ON lh_webhook_call (webhook_id, id)',
    ];

    /** @var bool ensure() already ran in this request */
    private static $ensured = false;

    public static function ensure(PDO $pdo): void
    {
        if (self::$ensured && Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        foreach (self::DDL as $sql) {
            $pdo->exec($sql);
        }
        self::$ensured = true;
    }

    // ---------------------------------------------------------------- Management (GET/POST/PUT/DELETE /api/_webhooks)

    /** GET /api/_webhooks: all webhooks (by name) with their history and where the active workflow file calls them */
    public static function all(PDO $pdo, array $model): array
    {
        self::ensure($pdo);
        $rows = $pdo->query('SELECT * FROM lh_webhook ORDER BY name COLLATE NOCASE')->fetchAll();
        return ['webhooks' => array_map(function ($row) use ($pdo, $model) {
            return self::present($pdo, $model, $row);
        }, $rows), 'events' => self::EVENTS];
    }

    /** POST /api/_webhooks: body {name, url, token, events, entities} -> 201 */
    public static function create(PDO $pdo, array $model, array $body): array
    {
        self::ensure($pdo);
        $v = self::validate($pdo, $model, $body, null);
        $pdo->prepare('INSERT INTO lh_webhook (name, url, token, events, entities) VALUES (?, ?, ?, ?, ?)')
            ->execute([$v['name'], $v['url'], $v['token'], json_encode($v['events']), self::encodeEntities($v['entities'])]);
        return self::present($pdo, $model, self::row($pdo, (int) $pdo->lastInsertId()));
    }

    /** PUT /api/_webhooks/{id}: the fields in the body are changed, the others stay */
    public static function update(PDO $pdo, array $model, int $id, array $body): array
    {
        self::ensure($pdo);
        $current = self::present($pdo, $model, self::row($pdo, $id));
        $v = self::validate($pdo, $model, $body + $current, $current);
        $pdo->prepare('UPDATE lh_webhook SET name = ?, url = ?, token = ?, events = ?, entities = ?, updated_at = ? WHERE id = ?')
            ->execute([$v['name'], $v['url'], $v['token'], json_encode($v['events']), self::encodeEntities($v['entities']),
                RecordMeta::now(), $id]);
        return self::present($pdo, $model, self::row($pdo, $id));
    }

    /** DELETE /api/_webhooks/{id}: with its history; not while the active workflow file calls it (409) */
    public static function delete(PDO $pdo, array $model, int $id): array
    {
        self::ensure($pdo);
        $row = self::row($pdo, $id);
        self::guardUse($model, (string) $row['name'], 'gelöscht');
        $pdo->prepare('DELETE FROM lh_webhook_call WHERE webhook_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM lh_webhook WHERE id = ?')->execute([$id]);
        return ['deleted' => $id];
    }

    private static function row(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM lh_webhook WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiException(404, ['error' => 'not_found', 'message' => 'Webhook nicht gefunden']);
        }
        return $row;
    }

    private static function present(PDO $pdo, array $model, array $row): array
    {
        $calls = $pdo->prepare('SELECT * FROM lh_webhook_call WHERE webhook_id = ? ORDER BY id DESC LIMIT ' . self::HISTORY);
        $calls->execute([(int) $row['id']]);
        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'url' => (string) $row['url'], 'token' => (string) $row['token'],
            'events' => self::decodeList($row['events']) ?? [], 'entities' => self::decodeList($row['entities']),
            'created_at' => (string) $row['created_at'], 'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'workflow_refs' => self::refs($model, (string) $row['name']),
            'calls' => array_map(function ($c) {
                return [
                    'at' => (string) $c['at'], 'event' => (string) $c['event'],
                    'record_id' => $c['record_id'] !== null ? (int) $c['record_id'] : null, 'ok' => (int) $c['ok'] === 1,
                    'http_status' => $c['http_status'] !== null ? (int) $c['http_status'] : null,
                    'error' => $c['error'] !== null ? (string) $c['error'] : null,
                    'duration_ms' => $c['duration_ms'] !== null ? (int) $c['duration_ms'] : null,
                ];
            }, $calls->fetchAll()),
        ];
    }

    /**
     * Checked values of a webhook. name: letter first, letters/digits/_/- (WorkflowParser::WEBHOOK_NAME), unique without
     * regard to case (409); url: http(s); token: visible ASCII characters (it travels in a header), may be empty - the same
     * token in several webhooks is fine; events: subset of EVENTS; entities: null = all, otherwise existing entities (at
     * least one, as long as events is not empty).
     *
     * @param array|null $current the webhook before the change (PUT)
     * @return array{name:string,url:string,token:string,events:string[],entities:?array}
     */
    private static function validate(PDO $pdo, array $model, array $body, ?array $current): array
    {
        $errors = [];
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (!preg_match(WorkflowParser::WEBHOOK_NAME, $name)) {
            $errors['name'] = 'Name aus Buchstaben, Ziffern, _ und - erwartet, mit einem Buchstaben beginnend (höchstens 50 Zeichen)';
        }
        $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || strlen($url) > self::URL_MAX || preg_match('/\s/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)) {
            $errors['url'] = 'Vollständige Adresse mit http:// oder https:// erwartet';
        }
        $token = $body['token'] ?? '';
        $token = is_string($token) ? trim($token) : null;
        if ($token === null || strlen($token) > self::TOKEN_MAX || !preg_match('/^[\x21-\x7E]*$/', $token)) {
            $errors['token'] = 'Höchstens ' . self::TOKEN_MAX . ' Zeichen ohne Leerzeichen und Umlaute (der Token wird als Header gesendet)';
            $token = '';
        }
        $events = $body['events'] ?? [];
        if (!is_array($events) || array_diff($events, self::EVENTS) !== [] || count($events) !== count(array_unique($events, SORT_REGULAR))) {
            $errors['events'] = 'Liste aus ' . implode(', ', self::EVENTS) . ' erwartet';
            $events = [];
        }
        $events = array_values(array_intersect(self::EVENTS, $events)); // fixed order
        $entities = $body['entities'] ?? null;
        if ($entities !== null) {
            if (!is_array($entities) || array_filter($entities, 'is_string') !== $entities) {
                $errors['entities'] = 'null (alle Entitäten) oder eine Liste von Entitäten erwartet';
                $entities = null;
            } else {
                $entities = array_values(array_unique(array_map('strtolower', $entities)));
                $unknown = array_diff($entities, array_keys($model['entities'] ?? []));
                if ($unknown) {
                    $errors['entities'] = "Unbekannte Entität '" . implode("', '", $unknown) . "'";
                } elseif ($events && !$entities) {
                    $errors['entities'] = 'Mindestens eine Entität auswählen (oder alle)';
                }
                sort($entities);
            }
        }
        if (!$events) {
            $entities = null; // only triggered by workflows: a selection would have no effect
        }
        if ($errors) {
            throw new ApiException(422, ['error' => 'validation', 'message' => 'Ungültige Eingabe', 'errors' => $errors]);
        }
        $stmt = $pdo->prepare('SELECT id FROM lh_webhook WHERE name = ? COLLATE NOCASE AND id != ?');
        $stmt->execute([$name, $current !== null ? $current['id'] : 0]);
        if ($stmt->fetchColumn() !== false) {
            throw new ApiException(409, ['error' => 'conflict', 'message' => "Einen Webhook '$name' gibt es schon.",
                'errors' => ['name' => 'bereits vergeben']]);
        }
        if ($current !== null && WorkflowParser::fold($current['name']) !== WorkflowParser::fold($name)) {
            self::guardUse($model, $current['name'], 'umbenannt');
        }
        return ['name' => $name, 'url' => $url, 'token' => $token, 'events' => $events, 'entities' => $entities];
    }

    /** 409 if the active workflow file calls the webhook (it would then call a webhook that no longer exists) */
    private static function guardUse(array $model, string $name, string $what): void
    {
        $refs = self::refs($model, $name);
        if ($refs) {
            $where = implode(', ', array_map(function ($r) {
                return "{$r['workflow']}, Transition '{$r['transition']}' ({$r['from']} -> {$r['to']})";
            }, $refs));
            throw new ApiException(409, ['error' => 'in_use', 'workflow_refs' => $refs, 'message' => "Der Webhook '$name' kann nicht "
                . "$what werden: Die aktive Workflow-Datei ruft ihn auf ($where). Bitte zuerst dort action=webhook:$name entfernen "
                . 'bzw. anpassen und die Workflow-Datei anwenden.']);
        }
    }

    /**
     * Transitions of the active workflow file (all workflows of the file, also unused ones - like the analysis) that call
     * the webhook via action=webhook
     *
     * @return array<int,array{workflow:string,transition:string,from:string,to:string}>
     */
    private static function refs(array $model, string $name): array
    {
        $out = [];
        foreach ($model['workflows'] ?? [] as $wf) {
            foreach ($wf['transitions'] as $t) {
                foreach (self::workflowNames($t) as $ref) {
                    if (WorkflowParser::fold($ref) === WorkflowParser::fold($name)) {
                        $out[] = ['workflow' => $wf['name'], 'transition' => $t['label'], 'from' => $t['from'], 'to' => $t['to']];
                    }
                }
            }
        }
        return $out;
    }

    /** @return string[] names from action=webhook:<name> of a transition */
    public static function workflowNames(array $transition): array
    {
        $out = [];
        foreach ($transition['actions'] ?? [] as $a) {
            if ($a['type'] === WorkflowParser::WEBHOOK) {
                $out[] = (string) $a['value'];
            }
        }
        return $out;
    }

    private static function encodeEntities(?array $entities): ?string
    {
        return $entities === null ? null : json_encode($entities);
    }

    private static function decodeList($json): ?array
    {
        if ($json === null) {
            return null;
        }
        $list = json_decode((string) $json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    // ---------------------------------------------------------------- Workflow file, schema migration

    /**
     * Combined analysis (like Workflows::checkPrincipals()): does every webhook named in action=webhook:<name> exist? All
     * workflows of the file are checked, including ones not used (yet). Comparison without regard to case.
     *
     * @param array<string,array> $workflows
     * @param array $positions WorkflowParser::positions() of the same file (lines of the missing names in the response)
     * @throws ApiException 422 schema_error
     */
    public static function checkWorkflows(PDO $pdo, array $workflows, array $positions = []): void
    {
        $known = [];
        if (Database::tableExists($pdo, self::TABLE)) {
            foreach ($pdo->query('SELECT name FROM lh_webhook')->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $known[WorkflowParser::fold((string) $name)] = true;
            }
        }
        $missing = [];
        $lines = [];
        foreach ($workflows as $wfKey => $wf) {
            foreach ($wf['transitions'] as $ti => $t) {
                foreach (self::workflowNames($t) as $name) {
                    if (!isset($known[WorkflowParser::fold($name)])) {
                        $missing[] = "Workflow '{$wf['name']}', Transition '{$t['label']}' ({$t['from']} -> {$t['to']}): Webhook '$name' "
                            . 'aus action= gibt es nicht';
                        $pos = $positions[$wfKey][$ti] ?? [];
                        $lines[] = $pos['action:' . WorkflowParser::WEBHOOK . ':' . WorkflowParser::fold($name)] ?? $pos['line'] ?? null;
                    }
                }
            }
        }
        if ($missing) {
            $more = count($missing) - 10;
            throw new ApiException(422, ['error' => 'schema_error', 'message' => implode('; ', array_slice($missing, 0, 10))
                . ($more > 0 ? " und $more weitere" : '') . '. Bitte unter System → Einstellungen → Webhooks anlegen oder die '
                . 'Workflow-Datei anpassen.'] + Workflows::linePayload($lines));
        }
    }

    /**
     * After a schema migration (in its transaction): entity selections follow renamed entities, removed entities drop out
     * of them. A selection that ends up empty stays empty (the webhook then only fires via workflows) - it is shown so in
     * the list, nothing is silently widened to "all".
     *
     * @param array<string,?string> $entityMap new table => previous table|null
     */
    public static function migrate(PDO $pdo, array $entityMap): void
    {
        if (!Database::tableExists($pdo, self::TABLE)) {
            return;
        }
        $target = [];
        foreach ($entityMap as $new => $old) {
            if ($old !== null) {
                $target[$old] = (string) $new;
            }
        }
        $put = $pdo->prepare('UPDATE lh_webhook SET entities = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, entities FROM lh_webhook WHERE entities IS NOT NULL')->fetchAll() as $row) {
            $before = self::decodeList($row['entities']) ?? [];
            $after = [];
            foreach ($before as $table) {
                if (isset($target[$table])) {
                    $after[] = $target[$table];
                }
            }
            sort($after);
            if ($after !== $before) {
                $put->execute([json_encode(array_values(array_unique($after))), (int) $row['id']]);
            }
        }
    }

    // ---------------------------------------------------------------- Firing

    /**
     * Calls the webhooks of an event (Cms, after the transaction has been committed). Never throws: a failure only ends up in
     * the history (and an unexpected error in the PHP error log) - the write has already succeeded.
     *
     * @param string $kind created|updated|deleted
     * @param callable|null $publicRow fn(): ?array - the record as the public API delivers it (only called if a webhook
     *        fires; null = deleted, no hash)
     * @param array|null $transition the executed transition: its action=webhook entries fire "<entity>.<to>"
     * @param array $extra further payload fields (cascade_deleted)
     */
    public static function fire(PDO $pdo, string $table, int $id, string $kind, ?int $version, ?callable $publicRow,
        ?array $transition = null, array $extra = []): void
    {
        try {
            if (!Database::tableExists($pdo, self::TABLE)) {
                return;
            }
            $hooks = $pdo->query('SELECT * FROM lh_webhook ORDER BY id')->fetchAll();
            if (!$hooks) {
                return;
            }
            $jobs = [];
            foreach ($transition !== null ? self::workflowNames($transition) : [] as $name) {
                foreach ($hooks as $hook) {
                    if (WorkflowParser::fold((string) $hook['name']) === WorkflowParser::fold($name)) {
                        $jobs[(int) $hook['id']] = [$hook, "$table.{$transition['to']}"];
                    }
                }
            }
            foreach ($hooks as $hook) {
                $entities = self::decodeList($hook['entities']);
                if (!isset($jobs[(int) $hook['id']]) && in_array($kind, self::decodeList($hook['events']) ?? [], true)
                    && ($entities === null || in_array($table, $entities, true))) {
                    $jobs[(int) $hook['id']] = [$hook, "$table.$kind"];
                }
            }
            if (!$jobs) {
                return;
            }
            $payload = ['entity' => $table, 'id' => $id];
            if ($version !== null) {
                $payload['version'] = $version;
            }
            $row = $publicRow !== null ? $publicRow() : null;
            if ($row !== null) {
                $payload['hash'] = self::hash($row);
            }
            $payload += $extra;
            $requests = [];
            foreach ($jobs as [$hook, $event]) {
                $requests[] = ['url' => (string) $hook['url'], 'token' => (string) $hook['token'], 'event' => $event,
                    'body' => json_encode(['event' => $event] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
            $results = self::send($requests, (int) Settings::get($pdo, Settings::WEBHOOK_TIMEOUT_SECONDS));
            self::ensure($pdo);
            $at = RecordMeta::now();
            $insert = $pdo->prepare('INSERT INTO lh_webhook_call (webhook_id, at, event, record_id, ok, http_status, error, duration_ms)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $prune = $pdo->prepare('DELETE FROM lh_webhook_call WHERE webhook_id = :w AND id NOT IN (
                                      SELECT id FROM lh_webhook_call WHERE webhook_id = :w ORDER BY id DESC LIMIT ' . self::HISTORY . ')');
            foreach (array_values($jobs) as $i => [$hook, $event]) {
                $r = $results[$i];
                $insert->execute([(int) $hook['id'], $at, $event, $id, $r['ok'] ? 1 : 0, $r['status'], $r['error'], $r['ms']]);
                $prune->execute([':w' => (int) $hook['id']]);
            }
        } catch (\Throwable $e) {
            error_log('[LessHeadCMS] Webhooks: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * hash of the payload: "sha256:" + SHA-256 (hex) over the canonical JSON of the record as GET /api/{entity}/{id}
     * delivers it without a login - reproducible by the target system from its own response:
     *  - without _min_warnings (an editorial hint, not content) and, in nested objects (media), without url and
     *    thumbnail_url (absolute URLs depend on the host name of the request; path/thumbnail_path stay);
     *  - object keys sorted (byte order) at every level, lists in their order;
     *  - compact JSON (no spaces), UTF-8 and "/" unescaped - the form of JSON.stringify() in JavaScript.
     * The public view never contains created_by/updated_by; field permissions only apply to logged-in users, so it holds
     * every field. For a draft the hash is formed the same way (the public API then answers 404, a logged-in
     * target system gets the same fields plus created_by/updated_by).
     */
    public static function hash(array $row): string
    {
        unset($row['_min_warnings']);
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1'); // shortest form of a float, like JavaScript
        try {
            $json = json_encode(self::canonical($row, true),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
        return 'sha256:' . hash('sha256', (string) $json);
    }

    /** @return mixed */
    private static function canonical($value, bool $top)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            return array_map(function ($v) {
                return self::canonical($v, false);
            }, $value);
        }
        if (!$top) {
            unset($value['url'], $value['thumbnail_url']);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) {
            $value[$k] = self::canonical($v, false);
        }
        return (object) $value;
    }

    /**
     * The HTTP calls, all at the same time; together they take at most $timeout seconds. Via curl if available,
     * otherwise one after the other via the http stream wrapper (each with the full timeout).
     *
     * @param array<int,array{url:string,token:string,event:string,body:string}> $requests
     * @return array<int,array{ok:bool,status:?int,error:?string,ms:int}> in the order of $requests
     */
    private static function send(array $requests, int $timeout): array
    {
        $headers = function (array $r): array {
            $h = ['Content-Type: application/json', 'User-Agent: LessHeadCMS-Webhook/1', self::EVENT_HEADER . ': ' . $r['event']];
            if ($r['token'] !== '') {
                $h[] = self::TOKEN_HEADER . ': ' . $r['token'];
            }
            return $h;
        };
        if (!function_exists('curl_multi_init')) {
            return array_map(function ($r) use ($headers, $timeout) {
                return self::sendStream($r['url'], $headers($r), $r['body'], $timeout);
            }, $requests);
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $r) {
            $ch = curl_init($r['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $r['body'], CURLOPT_HTTPHEADER => $headers($r),
                CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => $timeout * 1000, CURLOPT_CONNECTTIMEOUT_MS => min($timeout, self::CONNECT_TIMEOUT) * 1000,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // the answer is not needed: discard it instead of collecting it in memory
                CURLOPT_WRITEFUNCTION => function ($ch, string $data): int {
                    return strlen($data);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 0.2) === -1) {
                usleep(10000);
            }
        } while ($running && $status === CURLM_OK);
        $done = [];
        while ($info = curl_multi_info_read($mh)) {
            $done[self::handleKey($info['handle'])] = (int) $info['result'];
        }
        $results = [];
        foreach ($handles as $i => $ch) {
            $code = $done[self::handleKey($ch)] ?? curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $error = $code !== CURLE_OK ? self::curlError($code, curl_error($ch) ?: curl_strerror($code), $timeout) : null;
            $results[$i] = self::result($error, $http > 0 ? $http : null, $ms);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    /** curl handle as an array key: a resource up to PHP 7, an object (CurlHandle) from PHP 8 on */
    private static function handleKey($handle): int
    {
        return is_object($handle) ? spl_object_id($handle) : (int) $handle;
    }

    /** Readable cause of a failed call (German, like the rest of the UI messages), the curl text appended */
    private static function curlError(int $code, string $text, int $timeout): string
    {
        $what = [
            CURLE_OPERATION_TIMEDOUT => "Zeitüberschreitung (keine Antwort innerhalb von $timeout s)",
            CURLE_COULDNT_CONNECT => 'Verbindung abgelehnt bzw. nicht möglich',
            CURLE_COULDNT_RESOLVE_HOST => 'Host nicht gefunden',
            CURLE_SSL_CONNECT_ERROR => 'TLS-Verbindung fehlgeschlagen',
        ][$code] ?? 'Aufruf fehlgeschlagen';
        return $what . ' – ' . $text;
    }

    /** @return array{ok:bool,status:?int,error:?string,ms:int} */
    private static function result(?string $error, ?int $status, int $ms): array
    {
        $ok = $error === null && $status !== null && $status < 400;
        if ($error === null && $status === null) {
            $error = 'Keine gültige HTTP-Antwort';
        }
        return ['ok' => $ok, 'status' => $error === null ? $status : null,
            'error' => $error !== null ? self::shorten($error) : null, 'ms' => $ms];
    }

    /** At most ERROR_MAX characters, valid UTF-8 (curl texts may contain anything) */
    private static function shorten(string $text): string
    {
        $text = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'UTF-8', 'UTF-8') : $text;
        return function_exists('mb_substr') ? mb_substr($text, 0, self::ERROR_MAX) : substr($text, 0, self::ERROR_MAX);
    }

    /** Fallback without curl: http stream wrapper */
    private static function sendStream(string $url, array $headers, string $body, int $timeout): array
    {
        $start = microtime(true);
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout,
            'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true,
        ]]);
        $error = null;
        set_error_handler(function (int $no, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $handle = fopen($url, 'r', false, $context);
            $meta = $handle !== false ? stream_get_meta_data($handle) : [];
            if ($handle !== false) {
                fclose($handle);
            }
        } finally {
            restore_error_handler();
        }
        $status = null;
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        return self::result($status === null ? ('Aufruf fehlgeschlagen – ' . ($error ?? 'unbekannter Fehler')) : null, $status, $ms);
    }
}
