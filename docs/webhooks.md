# Webhooks

_Part of the [LessHeadCMS documentation](../README.md)._

On certain events LessHeadCMS calls a URL entered by an admin – typically a build hook of Netlify (or Vercel,
Cloudflare Pages, …) or an own static site generator that rebuilds the website afterwards. The call only says "this
record has changed"; it carries **no content**. The target system fetches the data itself through the normal
[REST API](rest-api.md), with its own login (e.g. an [API key](api-users.md)) or publicly.

A failed call never blocks anything: the save, the transition or the deletion has already succeeded and stays so. The
failure only appears in the history of the webhook.

## Managing webhooks

System → Einstellungen → tab **„Webhooks“** (`#/_settings/webhooks`, admins only – no permission type of its own and no
feature switch; editors never see it). The tab shows the webhooks as a table – name, target URL, triggers, last call – with
„Verlauf“ (history of the last calls), „Bearbeiten“ and „Löschen“, below it the form for a new webhook (or the one being
edited), and at the bottom the setting „Längste Wartezeit“ (see [Timeout](#timeout-and-failures)).

| Field | Rules |
|---|---|
| **Name** | Reference for `action=webhook:<name>` in workflow files and display name. Letter first, then letters, digits, `_` and `-`, at most 50 characters; unique **without regard to case** (`Netlify` and `netlify` are the same webhook). |
| **Ziel-URL** | Complete `http://` or `https://` address, at most 2000 characters. Use `https://` – the token travels in a header. |
| **Token** | Free text field; „Token erzeugen“ fills in a random value (64 hex characters), which can still be edited. Visible ASCII characters only (no spaces, no umlauts – it is sent as a header value), at most 200 characters; empty = no token header. There is **no uniqueness check**: the same token may be entered in several webhooks, so the target system only has to know one token no matter how many webhooks call it. |
| **Global auslösen bei** | Any of „Anlegen“, „Ändern“, „Löschen“, for „allen Entitäten“ or a selection. Nothing ticked = the webhook only fires via workflows. |

A webhook can be triggered via a workflow **and** globally at the same time. The list shows both: the transitions of
the active workflow file that call it („Workflow ArticleWorkflow: Transition „veroeffentlichen“ (review → published)“) and the
global triggers („Global: Ändern – Artikel“).

**Renaming or deleting** a webhook that the active workflow file calls is rejected with `409 in_use` (the message names the
transitions): the workflow would otherwise call a webhook that no longer exists. First remove or change the
`action=webhook:<name>` lines and apply the workflow file, then rename/delete. A different spelling of the same name
(`netlify` → `Netlify`) is always possible. Deleting a webhook deletes its history.

### API

All routes for `role = admin` only (`403` otherwise, `401` without a session); writes get `503` during a schema migration.

| Method | Path | Description |
|---|---|---|
| GET | `/api/_webhooks` | `{webhooks: [...], events: ["created", "updated", "deleted"]}` – every webhook with `id`, `name`, `url`, `token`, `events`, `entities` (`null` = all), `created_at`, `updated_at`, `workflow_refs` (`[{workflow, transition, from, to}]`) and `calls` (history, newest first) |
| POST | `/api/_webhooks` | body `{name, url, token, events, entities}` → `201` with the webhook; `422` with `errors.<field>`, `409` for a name already in use |
| PUT | `/api/_webhooks/{id}` | changes the fields contained in the body; `409` for a name already in use or a rename of a webhook the workflow file calls |
| DELETE | `/api/_webhooks/{id}` | `{deleted: id}`; `409 in_use` while the active workflow file calls the webhook |

`events` is a list of `created`, `updated`, `deleted` (stored in this order); `entities` is `null` (all entities) or a
list of entity names (`artikel`, lower case as in the API paths) – with at least one entry as long as `events` is not empty.
Without `events` a selection has no effect and is stored as `null`.

## Triggers

### Workflow: `action=webhook:<name>`

In a transition of the [workflow file](workflows.md), one line per webhook – a transition may call several, in addition to
`assign=` and `action=set_visibility`:

```
review -> published : veroeffentlichen {
  allowed=group:chefredaktion
  action=set_visibility:true
  action=webhook:netlify
  action=webhook:archiv-generator
}
```

Event: **`<entity>.<target state>`** with the technical state name from the workflow file (`article.published`), not
the translated label. Checked in two stages like groups and roles: the parser only checks the form of the name (same
rules as above; the same webhook twice in one transition is an error); the **combined analysis** (System → Workflows
„Prüfen“/„Anwenden“, also every schema change while the file is active) checks that the webhook exists – otherwise
`422 schema_error` with the lines of all missing names.

`webhook:` is an action type of its own next to `set_visibility`; there are no other `action=` values it could collide
with. Webhook names are a namespace of their own: they never appear in the diagram, so they cannot clash with class or
field names (`_reason`, group and role names are unaffected as well).

### Global: create, update, delete

Per webhook for all or selected entities:

| Event | Fires on |
|---|---|
| `<entity>.created` | `POST /api/{entity}` (also „Speichern und neu“, inline creation in the form) |
| `<entity>.updated` | `PUT /api/{entity}/{id}`, restoring a version, and a **workflow transition** (it changes the record) |
| `<entity>.deleted` | `DELETE /api/{entity}/{id}` |

**One call per webhook and event:** if a transition calls a webhook via `action=webhook:` that would also fire globally on
"update" for this entity, it gets only the workflow event (`article.published`), not additionally `article.updated`.

**Not triggered:**

* the test data generator (System → Testdaten) – otherwise every global webhook would be called once per generated record;
* rows deleted along via `{cascade}`: only the deleted record itself fires, the payload names the rows deleted along as
  `cascade_deleted: {"<table>": n}` (as in the response of the `DELETE`);
* partner rows of a `{symmetric}` n:n relationship whose list changed through the save of another row (they get a version of
  their own, `action: "link"`, but no event);
* changes in the media library, the translations, the schema, users and settings;
* failed writes (validation error, `403`, `409`, …).

## The call

`POST` to the target URL, synchronously after the transaction has been committed:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-LessHeadCMS-Token` | the token of the webhook – only if one is set |
| `X-LessHeadCMS-Event` | the event, e.g. `article.published` (the same as in the body, for routing without parsing) |
| `User-Agent` | `LessHeadCMS-Webhook/1` |

**Token convention:** a header of its own, deliberately **not** `Authorization`. The target system usually calls the
LessHeadCMS API afterwards with `Authorization: Bearer <API key>` – keeping the two apart avoids mixing up "this call comes
from our CMS" with "I am allowed to read the CMS". The target system compares the header with the token it knows
(constant-time, e.g. `hash_equals()` in PHP, `crypto.timingSafeEqual()` in Node) and ignores calls without a matching
token. Build hooks that cannot check headers (e.g. Netlify) simply ignore the header – their URL itself is the secret.

Body (keys in this order):

```json
{
  "event": "article.published",
  "entity": "article",
  "id": 4711,
  "version": 7,
  "hash": "sha256:9f2c…"
}
```

| Key | Meaning |
|---|---|
| `event` | `<entity>.<target state>` (workflow) or `<entity>.created` / `.updated` / `.deleted` (global) |
| `entity` | entity name as in the API paths (`/api/article/4711`) |
| `id` | id of the record |
| `version` | number of the version this event has just created ([Record versions](record-versions.md)) – `1` for `created`. **Missing for `deleted`** |
| `hash` | SHA-256 of the public view of the record, see below. **Missing for `deleted`** |
| `cascade_deleted` | only for `deleted` with `{cascade}`: `{"<table>": n}` of the rows deleted along |

**Why `version` and `hash` are missing for `deleted`:** a deletion creates no version (it removes the record together
with its versions) and there is no record left to hash. An absent key is unambiguous – `null` or `0` could be mistaken
for a real value by a careless consumer – and the event name `….deleted` already says what happened. The target system
removes the page and does not fetch anything.

### hash

`"sha256:"` + SHA-256 (hex) of the record **as `GET /api/{entity}/{id}` delivers it without a login** – regardless of who
saved it and of the login the target system uses later. With it the target system can tell whether it already has exactly
this state (e.g. skip a rebuild, or detect that the data it fetched is older or newer than the event). The public view has
the timestamps (`created_at`, `updated_at`) but never `created_by`/`updated_by`; field permissions only apply to logged-in
users, so the public view holds every field.

Form the hash from the public response like this:

1. remove `_min_warnings` (an editorial hint, not content);
2. in nested objects (the media objects of media fields) remove `url` and `thumbnail_url` – absolute URLs depend on the
   host name used for the request; `path` and `thumbnail_path` stay;
3. sort the keys of every object (byte/code-unit order), keep lists in their order;
4. serialize as compact JSON without spaces, UTF-8 and `/` unescaped – exactly what `JSON.stringify()` produces;
5. `sha256` over these bytes, hex, prefixed with `sha256:`.

```js
// Node.js
const crypto = require('crypto');
function canonical(value, top = true) {
  if (Array.isArray(value)) return value.map((v) => canonical(v, false));
  if (value === null || typeof value !== 'object') return value;
  const out = {};
  for (const key of Object.keys(value).sort()) {
    if ((top && key === '_min_warnings') || (!top && (key === 'url' || key === 'thumbnail_url'))) continue;
    out[key] = canonical(value[key], false);
  }
  return out;
}
const record = await (await fetch(`https://cms.example.org/api/${event.entity}/${event.id}`)).json();
const hash = 'sha256:' + crypto.createHash('sha256').update(JSON.stringify(canonical(record))).digest('hex');
const current = hash === event.hash; // the public API delivers exactly the state of the event
```

PHP: the same with `ksort($obj, SORT_STRING)` per object and `json_encode($data, JSON_UNESCAPED_UNICODE |
JSON_UNESCAPED_SLASHES)` (with `serialize_precision = -1`, the PHP default, so that `19.9` stays `19.9`).

**Drafts** (entity with a `visibility` field, record not published): the public API answers `404`, but the hash is formed
by the same rules. A target system with a login gets the same fields plus `created_by`/`updated_by` – remove those two and
the hash matches.

The hash changes on every save even without a change of content, because `updated_at` is part of the public view.

## Timeout and failures

All webhooks of one event are called **at the same time**. The request of the editor waits at most
**`webhook_timeout_seconds`** for their answers – setting „Längste Wartezeit“ on the same tab, `1` to `30`,
**default 5 seconds**. Connection setup counts towards it and takes at most 3 seconds.

Why 5 seconds: a build hook only queues the build and answers almost immediately, an own
generator should do the same and work in the background. 5 seconds is generous for that even on slow networks, but still
short enough that an unreachable target does not make every save feel broken – and since all webhooks of an event run in
parallel, the worst case stays 5 seconds no matter how many webhooks fire. Longer values only make sense for a slow target
that cannot answer first and work later.

| Outcome | History |
|---|---|
| answer with status `< 400` | success, HTTP status |
| answer with status `>= 400` | failure, HTTP status |
| no answer within the timeout | failure „Zeitüberschreitung (keine Antwort innerhalb von 5 s)“ |
| connection refused, host unknown, TLS error | failure with the cause and the message of curl |

Redirects are not followed (`3xx` counts as success – the call arrived). There are no retries: the next event calls the
webhook again anyway, and a build triggered later covers the earlier changes too. If PHP has no curl extension, the calls
are made one after the other via PHP's HTTP stream wrapper (each with the full timeout).

**History:** the last 10 calls per webhook (time – stored in UTC, shown in local time –, event, record, success/failure, HTTP status or error message,
duration) – System → Einstellungen → Webhooks, „Verlauf“; the column „Letzter Aufruf“ shows the newest at a glance. Older
entries are removed with every new call. A deliberately small log, not a reporting feature.

## Schema changes

The entity selection of a webhook follows renamed classes (`{renamed_from:…}`); removed classes drop out of it. A
selection that ends up empty stays empty – the webhook then only fires via workflows; nothing is silently widened to
"all entities". The list shows „keine Entität (Auswahl leer)“.

## Storage

Two system tables, created on the first call of the webhook management (an existing installation needs no new
`/bootstrap`): `lh_webhook` (`name` unique without regard to case, `url`, `token`, `events` and `entities` as JSON,
`created_at`, `updated_at`) and `lh_webhook_call` (history: `webhook_id`, `at`, `event`, `record_id`, `ok`, `http_status`,
`error`, `duration_ms`). The class names `LH_Webhook` and `LH_Webhook_Call` are reserved in any spelling. The token is
stored in plain text – the CMS has to send it.

## Example: Netlify

1. Netlify → Site configuration → Build & deploy → Build hooks → „Add build hook“, copy the URL.
2. System → Einstellungen → Webhooks: name `netlify`, the URL as „Ziel-URL“, token optional (Netlify ignores it).
3. Either tick „Anlegen“, „Ändern“, „Löschen“ for the entities the website shows – or only call it on publication:
   `action=webhook:netlify` in the transition to `published` of the workflow file, then „Anwenden“.
4. The build fetches the content via the REST API (publicly or with a read-only API key).

## Tests

The DDEV test site (`e2e/scripts/site.sh up`) gets a **test receiver** at `http://localhost/_webhook_sink/` (from
`e2e/fixtures/webhook-sink/`, only on the test site, never in `deploy/`): it appends every call as one JSON line (method,
query, headers, body) to `data/webhook-sink.jsonl`; `?status=500` answers with that status, `?sleep=3` answers late.

* `backend/tests/parser_test.php`: `action=webhook:` syntax (several lines, positions, case, invalid and duplicate names,
  reserved table names).
* `e2e/migration/migration_test.php`, scenarios `webhooks_verwaltung` (API, validation, admins only), `webhooks_global`
  (events, payload, headers, hash reproduced from the public API also with a different host name, restore, `{cascade}`,
  test data generator without calls), `webhooks_fehler` (unreachable, `500`, unknown host, timeout with the setting at 1 s,
  parallel calls, history capped at 10), `webhooks_workflow` (analysis with lines, events of transitions, one call per
  webhook, `409` on rename/delete), `webhooks_schema` (selection follows renamed/removed classes).
* `e2e/tests/webhooks.spec.js`: the tab in the browser (token generator, selection, history with success and failure), a save
  in the form and a transition in the workflow block, hash recomputed in JavaScript from the public API.
* Schema fuzzer: every schema with test data gets a global webhook (the generator must not call it, every other call must
  carry a valid payload); workflow cases with random `action=webhook:` lines and the error kinds `missing_webhook`,
  `bad_webhook`, `dup_webhook` (`--no-webhooks` switches the schema part off).
