# Deployment and schema editing

_Part of the [LessHeadCMS documentation](../README.md)._

## What this folder is

This folder is the complete, ready-to-run application: copy it to a web server with PHP and it works. Nothing has to be
built or installed. Requirements: PHP ≥ 7.4 with `pdo_sqlite`, Apache with `mod_rewrite` (`.htaccess`).

```
./
├── index.php               entry point + routes
├── .htaccess               everything except /assets/ and /media/ -> index.php
├── config.example.php      template for config.php (BOOTSTRAP_TOKEN); you create config.php yourself, it is not part of this folder
├── src/                    PHP classes: PumlParser, SqlGenerator, Bootstrap, Auth (login/sessions/roles), Cms (CRUD), Users (user management), Http, ...
├── vendor/                 bundled PHP libraries (Flight) with their autoloader
├── schema/                 class diagrams *.puml; simplecms.puml is the example schema
│   └── workflows/          workflow files *.lhwf (at runtime: via FTP or via System → Workflows; .htaccess blocks everything)
├── assets/                 editorial UI (index.html with its JavaScript and CSS bundles)
├── translations/           language files (CSV) for the import; bundled: en.csv (not public, .htaccess blocks everything)
├── data/                   SQLite file, lock, sessions/ (at runtime, blocked via .htaccess)
├── media/                  files of the media library (at runtime, public; .htaccess blocks any script execution)
├── LICENSE                 license of LessHeadCMS (AGPL-3.0-or-later)
├── third-party-licenses/   license texts of the bundled third-party libraries, README.md lists them
├── docs/                   this documentation
└── README.md               overview and quick start
```

## Deployment

Copy the **contents** of this folder 1:1 into the docroot (shared hosting via FTP or DDEV `public/`), then:

1. **Create `config.php` on the server** (it contains a secret and is deliberately not part of this folder): copy
   `config.example.php`, rename it to `config.php` and set `BOOTSTRAP_TOKEN` to a random value of your own (at least
   16 characters, e.g. `openssl rand -hex 24`). Without a valid token `/bootstrap` always answers with 403.
2. Call `https://<host>/bootstrap?token=<BOOTSTRAP_TOKEN>` (creates the tables, `users` and the admin).
3. Open `https://<host>/`, log in with the initial password and **immediately** set a password of your own (see [Login](api-users.md)).

Note: if there are several `schema/*.puml`, you select one at `/bootstrap` with `?schema=<filename>` (see below).

PHP only reads in `translations/`, write permissions are not needed there. `data/` and `media/` come only with their
`.htaccess`. For writing, PHP needs permissions on `data/` (SQLite file and `data/sessions/`)
and on `media/` (uploaded files of the media library) – the same as for `data/`; if `media/` is missing, PHP creates it itself
on the first upload, including `.htaccess`. This folder never contains a real `config.php`; uploading it again therefore
does not overwrite yours.

## /bootstrap

`/bootstrap` needs `?token=<BOOTSTRAP_TOKEN>` (from `config.php`); without it, with a wrong or an unconfigured token → `403`.
The token is part of the URL and therefore ends up in server logs and in the browser history – treat it as a throwaway secret and change it if necessary.

| State                                     | Response                                                                |
|-------------------------------------------|-------------------------------------------------------------------------|
| no `schema/*.puml`                        | `{"status":"no_schema_found"}`                                          |
| `data/bootstrap.lock` exists              | `{"status":"locked"}` (the foreign lock file stays untouched)           |
| no `schema_hash` in `_meta` yet           | create tables, store hash → `{"status":"created","tables":[…]}`         |
| hash identical                            | `{"status":"up_to_date"}`                                               |
| hash differs                              | `{"status":"migration_needed","message":"…"}` – **no** migration (for that: System → Schema, see "Schema editing") |

### Schema selection (`?schema=`)

Optional parameter, e.g. `/bootstrap?token=…&schema=wordpress-like.puml`:

| Situation                                                        | Response                                                                                    |
|------------------------------------------------------------------|---------------------------------------------------------------------------------------------|
| `schema` set, file exists in `schema/`                           | exactly this file is used                                                                   |
| `schema` set, file missing (or not a `.puml`)                    | `404` `{"error":"schema_not_found","message":"Datei '…' nicht in schema/ gefunden."}`       |
| `schema` contains `/`, `\`, `..`, NUL or is not a string         | `400` `{"error":"schema_invalid",…}` – no file access                                       |
| not set (or empty), exactly one `.puml`                          | this one is used (as before)                                                                |
| not set, several `.puml`, `_meta.active_schema` exists           | the file active last keeps being used                                                       |
| not set, several `.puml`, no (still existing) active one         | `400` `{"error":"schema_ambiguous",…,"available":["a.puml","b.puml"]}`                      |

The file name is stored as `active_schema` in `_meta` – when the tables are created (`created`) and, for databases from before
this feature, on the first `up_to_date` with a matching hash. With `migration_needed`, `active_schema` does not change. The response additionally
contains `"schema": "<filename>"`.

With `created` and `up_to_date`, `/bootstrap` additionally ensures `users` and the admin and extends the response by
`users_table_created`, `login_attempts_table_created`, `user_column_prefs_table_created`, `api_keys_table_created`, `admin_created` (+ `admin_hint`), `rate_limit_check` and `session_check` (diagnostics: session extension loaded, `data/sessions/`
writable, handler, HTTPS detected; `"ok": true` = sessions should work).

The lock file is created atomically and deleted again via `try/finally`. If it stays behind after a hard abort
(timeout, fatal error), `/bootstrap` permanently answers with `locked` → delete `data/bootstrap.lock` via FTP.
Errors in the schema return HTTP 500 with a message
(`{"status":"error","message":"…"}`), the database then stays unchanged (everything runs in one transaction).

## Schema editing (without DB reset)

The active diagram of an **already populated** installation can be edited as text and applied in the UI under **System → Schema**
(`role = 'admin'` only) without resetting the database (`src/SchemaMigration.php`).
`/bootstrap` stays unchanged and is still only there for the initial setup or a deliberate reset.

1. **„Änderungen prüfen“** (check changes; `POST /api/_schema/analyze`, pure analysis, writes nothing) compares the active model
   (`_meta.schema_json`) with the model from the text and lists every change with its kind and the affected rows:

   | Change | Behaviour |
   |---|---|
   | new class / new optional field / new n:n / new media field | create – without a prompt |
   | class removed / field with values removed / n:n or media links removed | **data loss:** one confirmation each with the count (rows, or rows with a value – empty text and `NULL` do not count); without values only a notice |
   | new required field, optional → required, new required relationship with existing rows | **backfill value** for the existing rows (for a required relationship an existing ID of the target table, a selection is offered), checked according to the normal field rules; new rows require the value as usual |
   | rename with `{renamed_from:…}` | data is kept under the new name; FK columns, n:n link tables, media fields and the users' column selection move along |
   | type change, enum value list, `{unique}`/1:1, `{cascade}` etc. | the table is rebuilt (shadow table, carry over data, replace). Beforehand every existing value is checked against the new rules – if something does not fit (e.g. text `viele` → `int`, removed enum value still in use, new `{unique}` combination with duplicates), **applying is blocked**, the concrete rows/values are stated in the message |

   **Rename suggestions:** if, for a new element without a predecessor, there are removed elements of the same kind in the
   same context (removed field or media field of the same class, removed class, removed n:1/1:1 or
   n:n relationship between the same, possibly renamed classes), a dropdown asks „Ist das eine Umbenennung von …?“ with
   all candidates and „Nein, neues Element“. A choice only puts the matching `{renamed_from:…}` marker into the
   affected line (for inherited fields in the abstract class) and automatically checks again – so the matching
   still happens exclusively via the marker. „Nein“ or no choice leaves it at "removed + new". A relationship whose
   target class was renamed as well only gets its suggestion once the class is matched (next check).
   If the line of an element cannot be found unambiguously, there is no suggestion (then set the marker by hand).

   Also blocked are: a new required relationship to an empty target table, a new required self-reference and a new
   required 1:1 relationship with several rows (a common backfill value would not work), a new uniqueness rule consisting only
   of new required columns (common value = duplicate). Blockers are resolved by cleaning up the data or adapting the
   diagram (e.g. first create as optional, fill, then make required).
2. **„Anwenden“** (apply; `POST /api/_schema/apply`) can only be clicked once there is no blocker, all confirmations are set
   and all backfill values are entered (the server checks this again: `422 confirmation_required`/`backfill_invalid`,
   `409 blocked`/`conflict`/`locked`, each with the current plan). All table changes run in **one
   transaction** (foreign keys off meanwhile, `PRAGMA foreign_key_check` at the end); if any step fails –
   e.g. a backfill value that only violates a uniqueness rule when applied –, everything is rolled back and the
   message names table and row. IDs and the AUTOINCREMENT state are kept; tables without a structural change
   are not touched. Afterwards the server replaces the active `.puml` in `schema/`, sets `_meta.schema_hash`/`schema_json`
   – `/bootstrap` reports `up_to_date` again – and the UI loads the new schema (sidebar, forms). If a
   bootstrap is running in parallel (`data/bootstrap.lock`), → `409 locked`.

   **Maintenance flag:** from the beginning of the transaction until COMMIT or rollback, `data/migration.flag` exists (content:
   Unix timestamp, `src/Maintenance.php`). Meanwhile, content write access – `POST`/`PUT`/`DELETE` on
   `/api/{entity}`, the media library (upload, edit, delete), the column selection and the test data generator – is rejected with
   `503 {"error": "maintenance", "message": "Wartung: Eine Schema-Migration läuft gerade. Bitte in Kürze erneut
   versuchen."}` and `Retry-After: 10`. Reading (`GET`) stays unaffected (readers see the old state until the
   COMMIT). The flag is removed again in any case; if it stays behind after a hard abort (e.g. `max_execution_time`),
   it counts as orphaned after **2 minutes** (`Maintenance::MAX_AGE`) and is ignored. The same flag applies during
   a restore.
3. **Backup before every apply** (`src/SchemaBackup.php`): immediately before the transaction (after all checks, under
   the lock) the server creates a pair with a shared timestamp in `data/schema-backups/` – the complete
   database (via `VACUUM INTO`, older SQLite versions: file copy) and the `.puml` active until then:
   `2026-10-01_14-30-00_cms.sqlite` + `2026-10-01_14-30-00_<file>.puml` (several in the same second: `…-00-2_…`).
   **If the backup fails** (no write permission, no disk space), „Anwenden“ aborts with `500 backup_failed` and
   a clear message before anything is changed in the database. If the change fails afterwards, the backup just created
   is removed again (the database is unchanged after all). The last **5** pairs are kept
   (`define('SCHEMA_BACKUP_KEEP', 5);` in `config.php`); older ones are deleted together after a successful
   apply. If the database is larger than 50 MB (`SCHEMA_BACKUP_WARN_MB`), System → Schema shows a notice about the
   storage needed (size × count). The page lists the existing pairs; `data/` is blocked via `.htaccess`, the
   backups cannot be retrieved over the web. Older backups from before the database copies
   (`20261001-143000_<file>.puml`) also appear in the list and count towards the retention.

**Restoring a backup (System → Schema → „Vorhandene Sicherungen“ → „Wiederherstellen“):** The dialog states the
time and what gets lost (all changes since then: content, schema, users and passwords, media library entries,
diagram layout); it is only enabled after typing **`WIEDERHERSTELLEN`** (`POST /api/_schema/restore`, the
server checks the text again: otherwise `422 confirmation_required`, nothing happens). Procedure under `data/bootstrap.lock` and
the maintenance flag: first the current state is itself backed up as a new pair (an accidental restore
can thus be undone), then the backed-up database is checked (`integrity_check`, active schema
present) and put in place atomically as `data/cms.sqlite`, the `.puml` of the pair as the active file. `schema_ids`/`schema_layout`
are part of the database and come along automatically. If the backed-up `.puml` does not match the database (changed via FTP
beforehand), the server writes the text stored in the database (`_meta.schema_source`) – `/bootstrap` reports
`up_to_date` afterwards. If something fails before the swap, everything stays unchanged (and the backup just created is
removed). Older backups with only a `.puml` cannot be restored. Uploaded files in `media/`
stay untouched (media that were only uploaded after the backup then lie in `media/` without an entry). The
retention (`SCHEMA_BACKUP_KEEP`) only cleans up on the next „Anwenden“, so the backup of the state before stays
in addition for the time being.

**Restoring a backup (emergency, manually via FTP – if the UI can no longer be reached):**
1. Look for the desired pair in the folder `data/schema-backups/` (the time is in the name and in System → Schema).
2. Download the current `data/cms.sqlite` to be safe (or rename it, e.g. to `cms.sqlite.vorher`).
3. Copy (rename) `<timestamp>_cms.sqlite` to `data/cms.sqlite`, and upload `<timestamp>_<file>.puml` as
   `schema/<file>.puml` (the part after the timestamp is the original file name).
4. Call `/bootstrap?token=…`: it must report `up_to_date` (database and file belong together). If it reports
   `migration_needed`, file and database do not match – then check the `.puml` from the same pair.
5. Delete a `data/bootstrap.lock` that may have been left behind. Users, sessions and media library entries
   then have the state of the backup as well (files in `media/` stay untouched; media that were only
   uploaded afterwards then lie in `media/` without an entry).

**Renaming without data loss:** `{renamed_from:alter_name}` on the field (`+ telefon: string? {renamed_from:tel}`), on the
class `class Rubrik {renamed_from:Kategorie} {` (with `extends` after it: `class B extends A {renamed_from:Alt} {`), on
media fields and at the end of a **relationship** with the previous label (`Artikel "n" --> "1" Kategorie : Rubrik
{renamed_from:Kategorie-Zuordnung}`, the old label may contain spaces/hyphens; `{renamed_from:}` = previously
without a label; combinable with all other markers). For n:1/1:1 the FK column is renamed (values stay), for
n:n the link table/API list (links stay). The matching happens within the same class and the same
(possibly renamed) target class. Further simultaneous changes to the same relationship (e.g. a new `{cascade}`) count as a
change of the existing relationship. A change of `{symmetric}` on an existing n:n self-reference is likewise
a change, not a new creation: directed → `{symmetric}` merges "A → B" and "B → A" into one pair;
`{symmetric}` → directed carries every pair over in both directions. Without a marker a name change counts as "old removed + new created" (with a data loss confirmation
or backfill). The marker may stay after applying – if the new name already exists, it has no effect.
Schema errors: the old name does not exist so far; old **and** new name both exist so far (also two classes
swapping names – please do that in two steps); two classes/fields claim the same old name. At the
first bootstrap the markers have no meaning. FK columns are matched via target class and label: a
renamed target class renames the column along with it; a **changed label without `{renamed_from}`**, in contrast, counts as a new
relationship (old values are lost, confirmation needed). The same goes for an n:n relationship written from the other
side.

**Important on the server (e.g. Webgo shared hosting):** After applying, the `.puml` **on the server** is authoritative. Uploading an older local version from
`schema/` again later changes nothing about data and API (they work with `_meta.schema_json`), but makes
`/bootstrap` report `migration_needed`. So after editing use „Herunterladen (.puml)“ and take the file over into
`schema/`. If the file on the server differs from the active schema
(e.g. changed via FTP), the editor shows a notice – „Änderungen prüfen“ then shows exactly what the file
would change compared to the database, and is thus also the way out of `migration_needed`.

| Method | Path | Description |
|---|---|---|
| GET  | `/api/_schema/source`  | `{"file", "source", "file_matches_active", "backup": {"backups": [{stamp, created, files: [{name, kind, bytes}]}], "keep", "db_bytes", "warning"}}` – text of the active `.puml` and existing backups |
| POST | `/api/_schema/analyze` | body `{"source"}` → `{"has_changes", "changes": [{kind, severity, text}] (`severity`: `info`, `warning`, `confirm`, `backfill`, `permission`), "confirmations": [{key, count, text}], "backfills": [{key, label, type, rows, choices?}], "blockers": [{key, count, text, rows}], "tables": {create, rebuild, drop, unchanged}, "rename_suggestions": [{key, kind, text, line, original, options: [{value, label, replacement}]}]}`; `422 schema_error` for errors in the text |
| POST | `/api/_schema/apply`   | body `{"source", "confirm": [keys], "backfill": {key: value}}` → `200 {"status": "applied", created, rebuilt, dropped, unchanged, rows_copied, "schema_ids": {kept, created, removed}, "backup": {stamp, files, dir}, backups_removed}`; `500 backup_failed` if the backup cannot be written |
| POST | `/api/_schema/model`   | body `{"source"}` (arbitrary text, also unsaved; changes nothing, not `schema_ids` either) → model JSON with stable IDs (see below); `422 schema_error` with the same message as `analyze` (also if a `{renamed_from}` points at nothing in the active schema) |
| POST | `/api/_schema/export`  | body `{"model"}` (model JSON as the diagram editor edits it) → `{"source"}` (`PumlExporter::toPuml()`); changes nothing. `422 validation` with `errors[]` if the form is wrong (names/types/enum values not identifiers, line break, `"` in the package, `{}` in the label, …) – whether the schema is valid is checked afterwards by `/api/_schema/model` |
| POST | `/api/_schema/restore` | body `{"stamp", "confirm": "WIEDERHERSTELLEN"}` → `200 {"status": "restored", "restored": {stamp, created, schema}, "backup": {stamp, files, dir}}` (backup of the state before); `422 confirmation_required` without the exact confirmation text, `404 backup_not_found`, `409 locked`/`backup_incomplete` (only `.puml`)/`backup_invalid` (damaged) |
| GET  | `/api/_schema/layout`  | `{"layout_version", "positions": {id: {x, y}}, "viewport": {x, y, zoom} \| null}` – stored diagram layout |
| PUT  | `/api/_schema/layout`  | body `{"positions"?: {id: {x, y}}, "viewport"?: {x, y, zoom}}` → as GET; `positions` replaces the whole stored layout (if it is missing, it stays), every call increments `layout_version`. Only IDs of entities/enums from `schema_ids`, otherwise `422` per entry (`draft:` IDs too) |

All eight only with a session and `role = 'admin'` (otherwise `401`/`403`). Limits: reversing an n:n (written from the other side) is not possible without data loss; large tables are copied row by row
when rebuilt (uncritical for CMS data volumes, possibly slow with very many rows on shared hosting).

### Diagram view and model JSON

„Diagramm anzeigen“ draws the current text – including unsaved changes – via `POST /api/_schema/model` as a
class diagram in an almost full-screen window (closes with „Schließen“, Escape or a click next to it, like
the other dialogs): classes with their field list, abstract classes dashed with an italic name, enums as separate yellowish
boxes, packages as a dashed group area, relationships with multiplicities and label (`{cascade}` red, n:n dashed,
inheritance with a triangle). A click highlights a class including its relationships and opens the properties panel (see
"Editing in the diagram"); boxes cannot be moved. **Layout:** without a stored layout the arrangement is automatic. „Neu anordnen“ recomputes
the automatic layout and stores it (`PUT /api/_schema/layout`, `layout_version` + 1); afterwards every opening shows
exactly these positions. Classes/enums without a stored position – new after a migration or not yet applied
in the text – are automatically placed as a block below the existing layout without moving existing positions
(the line above the diagram states their number; „Neu anordnen“ includes them in the stored layout as far as they are already
applied). If such a class is in a package with classes already placed, its area grows up to
there – „Neu anordnen“ tidies that up. On closing, the view (zoom, scroll position) is stored if it has
changed, and restored on the next opening. Zoom via −/+ (the display in between
shows the current factor, a click on it resets to 100 %) and „Einpassen“ (whole diagram visible). As long as it is open, the text,
„Änderungen prüfen“, „Herunterladen“, „Verwerfen“ and „Anwenden“ are locked; „Schließen“ (or „Abbrechen“) releases everything
unchanged, „Speichern“ with the new text. With
a schema error no diagram opens, the message appears in the same place as with „Änderungen prüfen“. The
diagram library (`@joint/core`, MPL-2.0) is only loaded on the click (`assets/schemaDiagram.*.js`, approx. 150 KB gzip).

The **model JSON** (`PumlParser::modelJson()`, format `lessheadcms-model`, version 1) is an intermediate format independent of the
diagram library that represents the diagram the way it is written: `entities[]` (abstract ones too, per class
only its own fields, `extends`, `package`, `title_fields`, `unique_fields`, `media`), `relations[]` (`kind`
`n1`/`one_to_one`/`nn`, `from_entity` = class with the FK column or, for n:n, the one named first, multiplicities as
written, `label`, `show_label`, `cascade`, `unique`, `symmetric`, `own_column`, `junction`), `enums[]` and `warnings[]`.
Every object has a `key` (derived from the name: `entity:kurs`, `field:kurs.titel`, `media:kurs.galerie`,
`relation:kurs.dozent_id` or, for n:n, `relation:<linktable>`, `enum:status` – changes on renaming) and,
via `/api/_schema/model`, a **stable `id`** (UUID) that survives renames; `PumlParser::modelJson()` itself sets
`id` = key. Comments,
formatting and purely decorative statements are lost; the latter (`title`, `skinparam`, `note`, colours like `#FFAAAA` on class/
package/enum/arrow, `hide`/`show`, `legend`, layout direction, methods) and every otherwise silently ignored line
appear as „Zeile N: … wird nicht übernommen“ in `warnings`. `PumlExporter::toPuml()` writes a model JSON back as
PlantUML (diagram editor via `/api/_schema/export`, round-trip tests). Via `/api/_schema/model` every object additionally
carries `active` = `{name, label}` of its counterpart in the active schema (`null` for a provisional ID).

### Editing in the diagram

A click on a box, a field row or a relationship line (or its label) opens a **properties panel** on the right,
a click on empty space closes it:

* **Entity:** name, abstract, extends (abstract classes), package (existing ones, none or a new one via text input),
  field list (inherited ones grey) including „Feld hinzufügen“, its relationships.
* **Field:** name, type (base types incl. `media`, or enum + choice of the enum), optional (`?`), `{title}`, `{unique}`
  (only where the parser allows them).
* **Relationship:** source and target (dropdowns, „Richtung tauschen“), kind n:1/1:1/n:n, multiplicities per kind, label,
  `{cascade}`/`{unique}` (n:1, 1:1), `{label}` (n:n), `{symmetric}` (n:n self-reference), filter field for `{filter_by}`
  (text: `sprache` or `sprache=lang`; checked by the server on saving, a renamed field has to be adjusted there
  by hand).
* **Enum:** name, values (add, remove, reorder).

„Neue Entität“ and „Neues Enum“ create an element (below the existing layout, panel open), „Neue Beziehung“ opens
the relationship form. Deleting via „… löschen“ in the panel with a prompt; an entity takes its relationships along, a
base class with subclasses and an enum in use cannot be deleted. Obvious errors (empty or duplicate
name, invalid characters, `{title}` on the wrong type, self-reference without a label, …) are shown by the panel immediately.

Everything stays in the browser until **„Speichern“**: the diagram is generated as text (`/api/_schema/export`), checked by the server like
any text (`/api/_schema/model`, schema errors appear in the diagram) and shown as a **line diff** against the text field.
„Übernehmen“ writes it into the text field, closes the diagram and automatically triggers „Änderungen prüfen“ –
the result appears as after a click on it. „Anwenden“ deliberately stays a separate step with the usual
confirmations. Because the text is generated anew, comments and
formatting of the previous text are dropped (the preview points this out). **„Abbrechen“** (also Escape/click next to it) discards
all changes after a prompt; without changes the button is still called „Schließen“.

**Renaming:** The migration only recognizes renames via the name or `{renamed_from}`, not via the stable ID.
The editor therefore sets the marker itself: if the name of a class, field or media field (or the label of a
relationship with the same source/target/kind) with a stored ID differs from the name in the active schema (`active`), it writes
`{renamed_from:<name in the active schema>}`. A marker in the text that has already been applied stays (it has no effect).
Abstract classes and enums need no marker (no table, and data is bound to the values respectively).

**Stable IDs** (`SchemaIds`, table `schema_ids`: `id`, `kind` entity/field/media/relation/enum, `model_key`, plus,
in readable form, `entity_name`, `name`, `to_entity`, `label`, `relation_kind`): `/bootstrap` assigns them for every element of the
diagram. **When applying** a change, the same matching the migration uses to carry over data
(`SchemaMigration::identify()`: classes/fields/media fields via name or `{renamed_from}`, FK relationships via
target class + label or `{renamed_from}`, n:n via target + label) decides which ID an element gets: counterpart exists →
ID stays (even if name or key change), new → new ID, no longer present → entry including its stored
position deleted – all in the transaction of the migration (if it fails, `schema_ids` stays unchanged as well).
Abstract classes have no table and no `{renamed_from}`: they keep their ID via the name, their fields via the
column mapping of a subclass; enums via the name. The **preview** (`/api/_schema/model`) uses the same matching
read-only: stored ID where a counterpart exists in the active schema, otherwise provisionally `draft:<key>` (changes
on applying). **Layout** (`schema_layout`: `node_id` = ID of an entity/enum, `x`, `y`, `layout_version` at the time of
saving; the current version and the viewport are stored in `_meta` because they concern the whole diagram) has nothing to do with
migrations. Installations from before these tables get them including IDs when first needed (`/bootstrap`,
opening the diagram, applying) from the active schema text (`_meta.schema_source`, otherwise the `.puml` as long as it matches the
active schema); no bootstrap call needed.

## Important notes

* **Authentication:** Writing and the UI are protected by login, **reading the API is public** (intended;
  drafts of entities with a `visibility` field excepted). The login has a rate limit (see [Rate limiting on login](api-users.md#rate-limiting-on-login)). Several users with the
  roles `admin`/`redakteur`/`api` are managed via `/api/users` (see [User management](api-users.md)) – by default every
  editor and API user may write all content, restrictable per entity, field and action via [Roles and permissions](roles-and-permissions.md); the
  system areas `users`, `media`, `languages`, `translations` and `testdata` are denied for them until granted there, and
  schema editing, workflows, settings and the rights management itself stay reserved for `admin`. API users
  authenticate with a key instead of a login (see [API users and API keys](api-users.md)). Not included: an
  admin-side password reset for other users (stays reserved for `PUT /api/me/password`, i.e. the user themselves).
  Operate over HTTPS only (otherwise password and session cookie travel over the wire in plain text).
* **Forgotten password (admin):** download `data/cms.sqlite`, in `users` replace the `password_hash` of the admin with a new
  `password_hash("neu", PASSWORD_DEFAULT)` value and set `must_change_password = 1` – or, if it is the only
  user, delete the `users` row and call `/bootstrap?token=…` again (recreates `admin`/`password`). For
  other users a second admin account is enough to reactivate them via `PUT /api/users/{id}` or adapt their role –
  a password itself can only be set by the respective user via `PUT /api/me/password`.
* **`richtext` is stored as raw Markdown** (Toast UI Editor, WYSIWYG mode) – the API delivers and stores the
  text unchanged. When rendering the Markdown in a website, still use a renderer with HTML escaping/sanitizing
  if Markdown inline HTML is possible. The image button of the editor is deactivated (uploads would end up as Base64 in the DB).
* **Media library files are public:** Everything in `media/` is served directly by Apache – whoever knows the URL can load the
  file, even if it is only part of a draft; protected only by the unguessable file name, no
  access control. Deliberate decision, for details and the recommendation see "Media files are publicly reachable"
  under [REST API](rest-api.md); **do not upload anything confidential**. `media/.htaccess` prevents any script execution there (PHP handler off, `.php`/`.phtml`/… → `403`,
  no directory listing, `X-Content-Type-Options: nosniff`, SVG called directly only with `Content-Security-Policy:
  sandbox`) – in addition to the extension and content check on upload. Size limit per file in `config.php` via
  `define('MEDIA_MAX_MB', 20);` (default 20 MB); if the hoster's `upload_max_filesize`/`post_max_size` are lower,
  their value applies (the media library shows the effective limit). Thumbnails need GD (see [REST API](rest-api.md)
  "Thumbnails"); without GD the preview loads the original. Tags no longer in use are not removed automatically; delete them via „Tags verwalten“ in the media library.
* **Apache only:** The `.htaccess` protects `data/`, `schema/`, `src/` and `vendor/` by routing everything except `/assets/` and
  `/media/` through `index.php`. With nginx, access to these folders (above all `data/cms.sqlite`) must be blocked in the server configuration
  – and for `media/` what `media/.htaccess` does there must be replicated (no script execution, no listing).
* **Security headers of the UI:** The `.htaccess` sets on every HTML response (the page itself – not on the
  JSON API, `assets/` and `media/`) `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` and a
  `Content-Security-Policy`: `default-src 'none'`, scripts, images and API calls only from the own address (images
  additionally `data:` for the icons of the rich text editor), `frame-ancestors 'none'`, `base-uri 'none'`, `form-action
  'self'`. For styles two exceptions are needed: `style` attributes (`style-src-attr 'unsafe-inline'`, the Toast UI Editor
  sets them itself) and exactly one embedded `<style>` – the fixed stylesheet of JointJS in the diagram, allowed via
  its `sha256` value in `style-src-elem`. **After a JointJS update** this value can change: then
  the browser console reports a violation and states the new value for the `.htaccess`. Whoever embeds
  fonts, images or scripts from a foreign address in the frontend must extend the matching directive.
  Needs `mod_headers` and Apache 2.4.10 or later (without `mod_headers` only the headers are missing, the page keeps working); with nginx
  set the same three headers on the `location` block of the page.
* **Security window after the bootstrap:** As long as the initial password `password` applies, the admin can be taken over by anyone who knows the
  login URL. After `/bootstrap`, log in immediately and change the password.
* The frontend uses relative URLs and hash routing; when running in a subfolder, call `https://host/cms/` (with the slash).
* **Schema change via file:** Even a purely "cosmetic" change like `bool` → `visibility` or adding
  `package` blocks later changes the hash of the `.puml`. On an
  existing database `/bootstrap` then reports `migration_needed` and the API keeps working with the old model (new behaviour
  stays inactive). Activation is done via **System → Schema** (see "Schema editing", without data loss) or a
  DB reset (see below; all data and users are lost in the process).
  **This does not apply to code changes** such as to the FK column name rule (label + target table, see [PlantUML rules](plantuml-syntax.md)): the hash only
  refers to the text of the `.puml` file, not to the column names derived from it. If the `.puml` stays unchanged,
  `/bootstrap` still delivers `up_to_date` and the model frozen at the first bootstrap (`_meta.schema_json`, incl. old column names)
  stays active – a pure code deploy therefore changes nothing on an already bootstrapped installation until someone deliberately resets.
* **Visibility and relationships:** If a publicly visible record points to a draft via FK or n:n, the draft's ID
  (not the content) is visible in the response; fetching the draft itself delivers 404.
* Changes to the `.puml` file after the first bootstrap are only detected by `/bootstrap` (`migration_needed`); migration is done via
  System → Schema. To reset the
  MVP database, delete `data/cms.sqlite` via FTP and call `/bootstrap?token=…` again (all data is lost; the admin is recreated with the initial password).
