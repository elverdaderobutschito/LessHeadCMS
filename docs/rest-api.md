# REST API

_Part of the [LessHeadCMS documentation](../README.md)._

"public" in the table means: without authentication, unless the setting `require_auth_for_read` is switched on (see
"Settings"). "session" means: a session **or an API key** (`Authorization: Bearer <key>`, see [API users and API keys](api-users.md)).

| Method  | Path                    | Access        | Description                                             |
|---------|-------------------------|---------------|---------------------------------------------------------|
| GET     | `/api`                  | public        | list of the entities                                    |
| GET     | `/api/{entity}/_schema` | public        | `package` (name of the `package` block or `null`), field name, type (for `enum` additionally `enum_values`), `required`, `foreign_key` (target table, `title_field`, `label`, `on_delete`, `one_to_one`), `many_to_many` (`name`, target table, `title_field`, `label`, `show_label`, `symmetric`), `unique_fields` (`{unique}` group), only for media fields additionally `media` (`name`, `type: "media"`, `required`) |
| GET     | `/api/{entity}`         | public        | records, **always paginated** (default: page 1 with 50 rows) – see "Lists: paging, sorting, filtering" |
| GET     | `/api/{entity}/{id}`    | public        | one record                                              |
| POST    | `/api/{entity}`         | **session**   | create (JSON body) → 201                                |
| PUT     | `/api/{entity}/{id}`    | **session**   | change; only fields sent are changed                    |
| DELETE  | `/api/{entity}/{id}`    | **session**   | delete → `{"deleted": id}`, with `{cascade}` additionally `"cascade_deleted": {"<table>": n}` |

## Lists: paging, sorting, filtering

> **Behaviour change (2026-10):** `GET /api/{entity}` used to deliver **all** rows as a JSON array. Now **one page
> at a time** comes in an object with metadata – also without parameters and also for the public API without a session.
> A consuming frontend must read `data` and page (`page`) or increase `per_page` when there are more than 50 rows.
> Backward compatibility deliberately does not exist: the old behaviour failed at PHP's memory with large tables.

```json
{"data": [{"id": 51, "titel": "…"}, …], "total": 12345, "page": 2, "per_page": 50, "total_pages": 247}
```

| Parameter | Meaning |
|---|---|
| `page` | page from 1 (default 1). A page past the end delivers `data: []`, not an error |
| `per_page` | rows per page, 1 to **500** (default 50). More → `422` with a message, **no** silent capping |
| `sort`, `order` | field of the entity (also `id` and FK columns; not n:n, not a media field), `order=asc`/`desc`. Empty values come last in both directions, ties are resolved by the `id`. Text case-insensitive, umlauts like their base letter, numbers in the text numerically; FK column by the label of the target row; richtext by plain text. Without `sort`: by `id` |
| `filter[field]` | depending on the kind of field: **string/text/richtext** substring, case-insensitive (richtext in the plain text without Markdown); **FK column, n:n list** substring of the label `<entity> #<id> – <title_field>` – `<entity>` matches both in the spelling the caller sees (translation of their display language; public API: display text of the default language) and with the name from the diagram; **enum** one of the values, several separated by commas; **bool/visibility** `true`/`false` (`false` also matches empty); **int/decimal/date/id** `filter[field][from]` and/or `filter[field][to]`, bounds inclusive |
| `eq[field]` | exact value (for FK columns the id of the target row); empty = field is empty |
| `ids` | only these rows, separated by commas (at most 500) |
| `q` | substring across all fields except text/richtext/bool, including FK and n:n labels |
| `for`, `for_id` | `for=<entity>.<field>`: only rows that are **selectable** as a value of this relationship (FK column or n:n list pointing to this entity); `for_id` = the row of `<entity>` currently being edited (missing when creating). Excluded are, for a self-reference, the row itself and all its descendants, for 1:1 the target rows already taken by another row, for n:n to the own entity the row itself. The exclusions are part of the query: `total` and every page only count selectable rows. This is how dropdown and selection dialog of the UI query; saving checks the same rules independently (`422`/`409`) |

Several conditions apply together (AND). Examples:

```bash
curl '.../api/artikel?page=2&per_page=20&sort=titel&order=desc'
curl -g '.../api/artikel?filter[titel]=sommer&filter[preis][from]=10&filter[preis][to]=50&filter[status]=FERTIG,ARCHIV'
curl -g '.../api/artikel?eq[autor_id]=7&sort=datum&order=desc&per_page=5'
curl '.../api/kategorie?for=kategorie.unterkategorie_von_kategorie_id&for_id=7&per_page=100'   # possible parents of #7
```

Unknown fields, wrong values and a `per_page` that is too large are answered by the API with `422`, naming the parameter
(`errors`). For a logged-in editor, denied fields do not exist here either: filter, sorting and `eq`
on them → `422`, `q` does not search them. `total` counts the matches of the query (publicly, that is, only published rows).

**Protection against oversized responses:** Besides the upper limit for `per_page`, the server counts the raw size
of the rows while reading the page. If it exceeds the limit, it answers with `413 response_too_large` (with `suggested_per_page`) before
the response is built – so even 500 rows of a very "wide" schema (long texts) do not lead to a silent
abort for lack of memory. The limit is one tenth of the free PHP `memory_limit`, at most 16 MB, at least 1 MB;
it can be fixed with `define('LIST_MAX_BYTES', …)` in `config.php`.

**Sorting and text search** are computed by PHP per row (SQLite knows upper/lower case and collation for ASCII only). The
default order by `id` and range/equality filters run directly in the database; a text sort or
search across a table with hundreds of thousands of rows takes correspondingly longer.

**Indexes:** `/bootstrap` and every schema migration automatically create an index on every foreign key column and on
the second column of every link table (`idx_<table>_<column>`, class `SchemaIndexes`). Installations from before that
catch up with **System → Schema → „Fehlende Indizes nachrüsten“** (`POST /api/_schema/indexes`, admins only,
response `{created, existing, removed}`): without analysis, confirmation and backup, because an index changes no data.

**Media fields in records:** `GET` delivers per media field a list in stored order, every element
`{"id", "url", "path", "filename", "original_name", "mime_type", "kind", "extension", "size_bytes", "title", "alt_text",
"description", "width", "height", "thumbnail_url", "thumbnail_path"}` (`thumbnail_*` = thumbnail, `null` if there is none, see
"Thumbnails" below) – `url` is absolute (scheme + host + CMS directory + `/media/<file>`) so that a
consuming frontend can load the file without a detour through PHP and without access to the (authenticated) media library API;
user data (uploaded by) is deliberately not included there. `POST`/`PUT` accept per field a list of media IDs in the
desired order (`"galerie": [7, 3]`), as well as the objects read back (`id` is what counts); duplicate IDs count
once. If the field is missing in a `PUT`, the list stays unchanged. Unknown ID → `422` („Medium #9 existiert nicht“).

**Media library (any logged-in role; a session is always needed, also for GET → otherwise `401`/`403`; class `src/Media.php`):**

| Method  | Path                    | Description |
|---------|-------------------------|--------------|
| GET     | `/api/_media`           | list, newest first; filters `?q=` (title, description, alt text, original name), `?kind=image\|video\|audio\|document`, `?tag=<id or name>`. Per medium the fields above plus `tags`, `uploaded_by` (`id`, `username`, `name`), `uploaded_at` (UTC), `usage_count` |
| POST    | `/api/_media`           | upload as `multipart/form-data`: `file`, optionally `title`, `alt_text`, `description`, `tag_ids[]` (existing tags) and/or `tags[]` (names, missing ones are created) → `201` with the medium. `width`/`height` are read by the server from the file (images incl. SVG, MP4, WebM), never from the request |
| GET     | `/api/_media/{id}`      | one medium |
| PUT     | `/api/_media/{id}`      | JSON: `title`, `alt_text`, `description`, `tag_ids` and/or `tags` (replaces the tags) – only keys sent along change |
| DELETE  | `/api/_media/{id}`      | deletes entry and file → `{"deleted": id}`; if it is still in use → `409 in_use` with `dependents` (`{"kurs.galerie": 2}`) and `usages` (entity, field, IDs) |
| GET     | `/api/_media/tags`      | all tags with count (`[{"id", "name", "count"}]`) |
| POST    | `/api/_media/tags`      | create a tag (`{"name": "…"}`; if it exists, case-insensitive, the existing one is returned) |
| PUT     | `/api/_media/tags/{id}` | rename a tag (`{"name": "…"}`) → `{"id", "name", "count"}`; the assignments stay (all media show the new name). If another tag already has that name → `422` (no merging); unknown → `404` |
| DELETE  | `/api/_media/tags/{id}` | delete a tag including its assignments, the media stay → `{"deleted": id, "name", "removed_links": n}` |
| POST    | `/api/_media/{id}/file` | replace the file (`multipart/form-data`, field `file`): `id`, title, alt text, description, tags and all links stay; replaced are `filename` (new random name, so a new `url` as well), `original_name`, `mime_type`, `kind`, `extension`, `size_bytes`, `width`, `height` and the thumbnail. Checks and errors as for the upload (`422`); the old file is deleted after success → `200` with the medium |
| GET     | `/api/_media/limits`    | effective size limit `max_bytes` (minimum of `MEDIA_MAX_MB` and the PHP limits `upload_max_filesize`/`post_max_size`, which are additionally included individually), `thumbnails_available` (GD present) and the allowed extensions per kind |

Upload check: only the extensions jpg/jpeg/png/gif/webp/svg (image), mp4/webm (video), mp3/wav/ogg (audio),
pdf/doc/docx/xls/xlsx/ppt/pptx/txt/csv (document), otherwise `422 type_not_allowed`. The actual content is determined via `finfo`
and must match the extension (a `bild.jpg` that is a script → `422 content_mismatch`; for raster images
additionally `getimagesize`, for docx/xlsx/pptx the ZIP must contain the matching folder). SVG with a script,
event handler, `javascript:`, `<foreignObject>` or an external reference → `422 svg_unsafe`. Larger than the limit →
`422 too_large` („Die Datei ist zu groß (21 MB). Erlaubt sind höchstens 20 MB je Datei.“, for a PHP limit with
the hint that it is set at the hoster – even if PHP discards the request completely because of `post_max_size`).
Storage is under a random name (32 hex characters + checked extension, lower case); the original name
is display metadata only (without path and control characters; `<`, `>` and `"` are replaced by `_` on upload and on
file replacement – entries already stored stay unchanged, so a frontend should still escape
`original_name`). `mime_type` is the standard type belonging to the extension, `kind` follows from it. A
duration for video/audio deliberately does not exist (it would need e.g. ffprobe, usually not available on shared hosting).

**Thumbnails** (`src/Thumbnail.php`, GD): for raster images (jpg/jpeg/png/gif/webp), `media/<same name>_thumb.<same extension>`
is additionally created on upload, at most 200 × 200 pixels, aspect ratio kept, in the format of the
original (PNG/WebP with alpha channel, GIF with a transparent colour; animated GIFs become the first frame). No
thumbnail for SVG, video, audio, documents and for images that are already at most 200 × 200 in size – there the
original is the preview. If the creation fails (GD is missing or cannot handle the format, image broken, too large for
`memory_limit` – estimated in advance so that PHP does not abort), the **upload still completes normally**, just without a
thumbnail (`thumbnail_path: null`); the UI then shows the original as before. Media library list,
selection dialog and media tiles in the form load the thumbnail, the link in the media library opens the original.
Deleting removes both files. Database: column `media.thumb_filename` (`NULL` = not tried yet, `''` = none,
otherwise the file name), added automatically via `ALTER TABLE` on existing installations. **Existing images** (from before the
thumbnails) get theirs later when the media library list is called (`GET /api/_media`), at most 25 or
3 seconds per call; an unsuccessful attempt is not repeated. If GD is missing, it stays at "not tried yet" and
is caught up as soon as GD is available.

**Media files are publicly reachable (deliberate decision):** Every file uploaded in the media library is
**publicly retrievable via its URL from the moment of upload** – without a login and **regardless of whether a record using it is published, still a draft, or whether it
is used anywhere at all**. This applies to the thumbnails (`…_thumb.<extension>`) as well.

* **The only protection is that the URL cannot be guessed:** the file name consists of 32 random hex characters
  (128 bits, `random_bytes`), the original name does not appear in the URL, and `media/` has no directory listing.
  This is **not access control**: when a file is retrieved, nobody checks a login, a role or the
  publication status of the record. Whoever knows the URL gets the file – and a URL spreads easily
  (forwarded links, browser history, proxy/server logs, embedded images in a preview page, copying
  from the API response, which is public for records anyway). After the medium is deleted the file is gone;
  as long as it exists, every URL once known stays valid.
* **Why this way:** the files are served directly by the web server (Apache), without a detour through PHP. This keeps delivery
  fast and simple (caching, range requests for video/audio, no PHP load per image, no additional
  rewrite/download logic) and allows a consuming frontend to embed the `url` directly. Real
  access control would mean channelling every file through PHP and checking the status of all referencing records in the
  process – this is deliberately not part of this CMS. The open reachability is therefore **not an oversight** but the
  consequence of this decision.
* **Recommendation for admins and editors:** **do not treat media URLs as confidential**. Only upload what
  could also be published – an image for a draft that is still secret can already be retrieved in advance as soon as someone knows the
  URL. **For really sensitive, non-public files** (contracts, personal documents, internal
  papers or the like) **the CMS is currently not intended** – this use case is deliberately not covered. Such
  files belong in a system with real access control.

**Column selection (per logged-in user, any role; a session is always needed, also for GET → otherwise `401`/`403`):**

| Method  | Path                              | Description |
|---------|-----------------------------------|--------------|
| GET     | `/api/_prefs/columns/{entity}`    | `{"entity": "produkt", "visible_columns": ["id", "name", …]}`; `null` = no selection of your own (the UI uses the default selection) |
| PUT     | `/api/_prefs/columns/{entity}`    | body `{"visible_columns": ["name", "preis", "tag_ids"]}` (field, n:n and media field names from `_schema`); `id` is always added, duplicates are dropped; unknown column → `422` |
| DELETE  | `/api/_prefs/columns/{entity}`    | discard your own selection → default again |

**Multilingual support** (see [Editorial UI](editorial-ui.md); session needed, maintenance only `role = admin`, otherwise `403`):

| Method  | Path                              | Description |
|---------|-----------------------------------|--------------|
| GET     | `/api/_languages`                 | `{"languages": [{id, code, name, is_default, date_format, datetime_format, decimal_separator, thousands_separator}], "current_id"}` – any role; `current_id` = own display language (without a choice the default language, without languages `null`) |
| POST    | `/api/_languages`                 | Admin. Body `{"code", "name", "date_format"?, "datetime_format"?, "decimal_separator"?, "thousands_separator"?}` (formats: see [Date and number format per language](multilingual.md), the default values if not given) → `201`; the first language becomes the default language; `422` for an invalid/duplicate code or name |
| PUT     | `/api/_languages/{id}`            | Admin. Body `{"code"?, "name"?, "date_format"?, "datetime_format"?, "decimal_separator"?, "thousands_separator"?}` (also for the default language; what is not sent stays); `422` with `errors` per field for an invalid format |
| DELETE  | `/api/_languages/{id}`            | Admin. `{"deleted", "translations_removed"}`; default language → `409 default_language` |
| PUT     | `/api/_prefs/language`            | any role. Body `{"language_id"}` → as GET `/api/_languages` |
| GET     | `/api/_translations?language_id=` | Admin. `{"language", "groups": [{title, kind: "entity"\|"enum", abstract, items: [{kind, ref_id, ref, value, original, type, text}]}], "ui_texts": {key: text}}`; default language → `422` |
| PUT     | `/api/_translations`              | Admin. Body `{"language_id", "items": [{kind, ref_id, value?, text}]}` → `{"saved", "removed"}`; empty text removes; element not in the active schema or, for `kind: "ui_text"`, not a valid key (`area.name`, at most 100 characters) → `422`, then nothing is saved |
| GET     | `/api/_i18n`                      | any role. Display texts in the own language: `{"language", "entities": {table: {entity, fields: {field: text}, many: {list: text}, enum_values: {field: {value: text}}}}}`; empty for the default language |
| GET     | `/api/_ui_texts?lang=`            | **without login**. Translated fixed UI texts `{"language": {code, name, is_default}\|null, "texts": {key: text}}`: with a session (also during the mandatory password change) the language of the user, otherwise the one for the code `lang`, otherwise the default language; `texts` empty for the default language |
| GET     | `/api/_translations/export?lang=` | Admin. `lang` = ID of the language → file `<code>.csv` (`text/csv`, `Content-Disposition: attachment`) with all stored translations, for the format see [Translations as CSV](multilingual.md) |
| GET     | `/api/_translations/files`        | Admin. `{"directory": "translations", "files": [{name, size, modified}]}` – the `.csv` files in `translations/` (empty if the directory does not exist) |
| POST    | `/api/_translations/import/preview` | Admin. Body `{"language_id", "content": "<CSV text>"}` or `{"language_id", "file": "en.csv"}` (file from `translations/`) → `{"applied": false, "language", "file", "rows", "new", "changed", "unchanged", "skipped", "changed_rows": [{line, kind, ref, old, new}], "skipped_rows": [{line, kind, ref, reason}]}` (lists shortened to 200 entries); saves nothing. No header line `kind,ref,text`/not UTF-8/above 2 MB → `422`, file or language unknown → `404` |
| POST    | `/api/_translations/import/apply` | Admin. Same body → saves new and changed texts in one transaction, `200` with the same summary and `"applied": true`; during a schema migration `503` |

Unknown entity → `404`. The user ID comes exclusively from the session. Columns that no longer exist after a
schema change are dropped when reading. The table `user_column_prefs` (`user_id`,
`entity_name`, `visible_columns` as a JSON array, `updated_at`) is created by `/bootstrap`; if it is missing on an existing
installation, it is created by itself on the first save – another `/bootstrap` is not needed.

**`title_field` in `_schema`** (at the top level for the entity itself, as well as per `foreign_key` and `many_to_many` for
the respective target table) is always an **array** of field names, e.g. `["status"]` or, with several `{title}` markers,
`["bestelldatum", "status"]` – also in the (still most common) case of a single field, for a uniform
form instead of "sometimes string, sometimes array". Order = diagram order of the `{title}` markers, or one element with the
automatic heuristic (see [PlantUML rules](plantuml-syntax.md)).

**`unique_fields` in `_schema`** (at the top level): the `{unique}` group of the entity as a list of column names, e.g.
`["bestellung_id", "variante_id"]` (first fields in diagram order, then FK columns in relation order), `[]`
without a rule. A `POST`/`PUT` that would create an existing combination gets `409`:
`{"error": "duplicate", "message": "Die Kombination bestellung_id = 3, variante_id = 7 existiert bereits (Bestellposition #12).",
"unique_fields": […], "existing_id": 12, "errors": {"bestellung_id": "Kombination existiert bereits", …}}` (for a
single column „Der Wert code = 'X' ist bereits vergeben (Gutschein #4).“ / „Bereits vergeben“). The form of the
editorial UI shows the message and marks the fields involved. A `PUT` is partial: missing columns of the
group count with their stored value; the own row never counts as a duplicate. If a parallel duplicate gets ahead of the
check, the database constraint kicks in (also `409`, then `existing_id: null`).

**1:1 (`foreign_key.one_to_one: true`):** The same `409 duplicate` response if a target row is already assigned to another
row, with `unique_fields: ["nutzer_id"]` and the message „Nutzer #3 ist bereits Profil #1 zugeordnet
(1:1-Beziehung: höchstens eine Zuordnung je Nutzer).“ (field: „Bereits Profil #1 zugeordnet“). The 1:1 column does **not**
appear in `unique_fields` (that stays the `{unique}` group); `one_to_one` is enough. In the
FK select and in the search dialog (magnifier), the editorial UI hides target rows that are already referenced by another row (the own one stays
selectable when editing); the `409` message only appears if someone has assigned the row in parallel. Older
stored models without the key deliver `false`.

**`on_delete` in `_schema`** (per `foreign_key`): `"restrict"` or `"cascade"` – what happens to rows of this entity
when the referenced row is deleted (see `{cascade}` in the [PlantUML rules](plantuml-syntax.md)). A frontend can use this to warn before
deleting; the editorial UI currently only shows the `409` message.

**FK label:** `foreign_key.label` in `_schema` is the relationship label **unchanged** from the `.puml` (empty if
the relation had none) – upper/lower case is not touched here. For an FK selection the editorial UI shows
this label as the field label, not the technical column name (e.g. „Lieferadresse“ instead of
`lieferadresse_adresse_id`); without a label, as before, the column name stripped of `_id`.

**n:n label:** Unlike with FKs, an n:n label is often just diagram documentation (in UML usually `: hat`). The
UI therefore shows it **only with the `{label}` marker** (`Mitglied "n" -- "n" Kurs : interessiert an {label}`):
then as the field label of the checkbox list, as the column header in list view and selection dialog („Interessiert an“)
and in the title of the selection dialog („Interessiert an – Kurs auswählen“). Without the marker – with or without a label – it stays
the target table name („Tag“ or „Tag auswählen“). For this, `_schema.many_to_many[]` delivers `label` (unchanged, also
without the marker) and `show_label` (`true` only with `{label}`); the frontend only follows this flag. The buttons next to it
always name the target entity („Kurs suchen“, „Kurs neu anlegen“).

**Field labels (frontend):** Every field label in the generated form and in the list view (normal
fields, FK selection, n:n) is prepared purely at display level (`capitalize()`): first
underscores become spaces (`erstellt_am` → „Erstellt am“, `preis_aufschlag` → „Preis aufschlag“), then only the
first letter of the result is capitalized – no full title-case conversion. Applies regardless of
how the field/label was written in the diagram (`bestelldatum` → „Bestelldatum“, `bestellt von` → „Bestellt von“,
`gehört zu` → „Gehört zu“). `_schema` and the underlying field/column names in the database stay
untouched by this.

**Visibility:** If an entity has a `visibility` field, `GET /api/{entity}` delivers **for everyone** (also logged in) only
rows with `true` by default. `?include_drafts=true` additionally delivers drafts (`false`/`NULL`), but only with a session: without one → `401` (the values
`true`/`1`/`yes`/`on` count as true; nonsensical values are treated like "not set", never as a grant). `GET /api/{entity}/{id}`
of a draft without a session → `404` (not 403, body identical to an unknown ID). With a session but a pending password change,
you do not count as authorized (`include_drafts` → `403`, single fetch → `404`). Entities **without** a `visibility` field stay completely
open; `include_drafts` is ignored there. The editorial UI always queries lists with `include_drafts=true`.

Reading deliberately stays public so that external (headless) frontends can consume without a login. Writing routes check the
session in `Http::api()` – **every** non-GET route there is protected by default. Without a session: `401 {"error":"unauthorized"}`;
with a session but a pending password change: `403 {"error":"password_change_required"}`.
**Minimum count (`"1..*"` on the n side of an n:1 relationship, e.g. `Position "1..*" --> "1" Rechnung`):**
* `GET /api/position/_schema`: the FK field carries `"foreign_key": {…, "min_required": true}`.
* `GET /api/rechnung/_schema`: additionally `"relations": [{"name": "position.rechnung_id", "table": "position", "entity":
  "Position", "column": "rechnung_id", "label": "", "min_required": true}]` – the incoming relationships with a
  minimum count; `name` (`<table>.<FK column>`) is the identifier in `_min_warnings`.
* `GET /api/rechnung` and `GET /api/rechnung/{id}` (likewise the responses of `POST`/`PUT`): every row carries
  `"_min_warnings": []` or `["position.rechnung_id"]` if it does not have a row for this relationship yet. The list
  determines this with **one** query per relationship (`… WHERE id NOT IN (SELECT rechnung_id FROM position …)`), not
  per row; measured with 20 000 invoices and 100 000 line items: 46 ms for the query, 135 ms for the whole response.
  All rows are counted, drafts (`visibility`) too. When writing, `_min_warnings` is ignored.
* `DELETE /api/position/{id}` of the last line item of an invoice → `409` with `"error": "min_required"`, `message` and
  `"min_required": [{"table": "rechnung", "entity": "Rechnung", "id": 5, "relation": "position.rechnung_id",
  "child_entity": "Position"}]`. This also applies if the line item would only be deleted along (`{cascade}` from a third
  class) – unless the invoice itself is deleted in the same go.
* Entities without such a relationship deliver neither `min_required`, `relations` nor `_min_warnings`; their `_schema` and
  their rows are unchanged.

**Cycle protection (API):** A `PUT` that would create a cycle in a self-reference (parent value = the row itself or one of its
descendants, across any number of levels) is rejected with `422` and a message at the FK field. The check uses a single recursive
SQL query (`WITH RECURSIVE`); it also terminates with already existing legacy cycles.

Write access via `curl` needs the session cookie: `curl -c jar.txt -X POST …/api/login -d '{"username":"admin","password":"…"}'`,
then `curl -b jar.txt -X POST …/api/artikel -d '{…}'`.
`{entity}` is the lower-case class name (`artikel`). For n:n relations the
declaring side delivers and accepts an ID list, e.g. `"tag_ids": [1, 2]` for `artikel`. Errors: `400` (not JSON), `404`,
`401`/`403` (see above), `409` (record is still referenced; when deleting with `"dependents": {"<table>": n}`;
last row of a relationship with a minimum count: `"error": "min_required"`;
for `{unique}` `"error": "duplicate"`, see `unique_fields`), `422` (validation, with `errors` per field), `503` (not bootstrapped yet).

Example:

```bash
curl -b jar.txt -X POST https://<host>/api/artikel -d '{"titel":"Hallo","inhalt":"# Hi","veroeffentlicht":true,"erstellt_am":"2026-09-20","gehort_zu_kategorie_id":1,"geschrieben_von_autor_id":1,"tag_ids":[1,2]}'
```
