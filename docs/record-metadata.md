# Record metadata (created/updated at/by)

_Part of the [LessHeadCMS documentation](../README.md)._

Every record of every regular entity carries four **system fields** that the CMS sets itself – authors never fill
them in, and a client cannot overwrite them:

| Field        | Type (`_schema`) | Content |
|--------------|------------------|---------|
| `created_at` | `datetime`       | time of creation, UTC, `"YYYY-MM-DD HH:MM:SS"` |
| `created_by` | `user`           | the user who created the record: `{"id", "username", "name"}` |
| `updated_at` | `datetime`       | time of the last change, UTC, `"YYYY-MM-DD HH:MM:SS"` |
| `updated_by` | `user`           | the user who made the last change: `{"id", "username", "name"}` |

This is the "who/when" part of tracking metadata in the sense of content-management practice (metadata the system
records, not the author). Status metadata (draft, review, published) is covered by [workflows](workflows.md); there is
no roll-up across several records.

## When the values are set

* **`POST /api/{entity}`**: all four – `updated_*` equals `created_*`.
* **`PUT /api/{entity}/{id}`**: `updated_at`/`updated_by`. Every successful `PUT` counts as a change, also one that only
  changes n:n lists or media fields, or that sends the stored values again.
* **Workflow transition** (`POST /api/{entity}/{id}/transition`): `updated_at`/`updated_by` – the state (and possibly
  visibility) of the record changes.
* **Test data generator**: like `POST`, with the admin who started it as the creator and the time of generation.
* **Not** changed by: schema migrations (rebuilding a table, converting values, backfilling a new required field),
  changes to another record (e.g. editing the other side of an n:n relationship, a `{cascade}` delete), restoring a
  backup.

Values sent along in a `POST`/`PUT` body (`"created_at": …`, `"updated_by": …`) are **ignored** without an error, exactly
like an `id` sent along – a form that sends a whole record back (e.g. after duplicating it) keeps working.

## Stored as: user ID, name resolved on reading

The columns hold the `users.id` (no database constraint, like `lh_task.created_by`). Every response resolves it to the
user's **current** username and display name, the same way the media library shows „hochgeladen von“
(`media.uploaded_by`). Users are never deleted in LessHeadCMS, only deactivated (`active = false`), so the ID always
resolves – also for a deactivated user. A user whose display name changes appears with the new name in all records;
the change history of names is not kept.

## In the API

* **`GET /api/{entity}/_schema`**: key `system_fields`, e.g.
  `[{"name": "created_at", "type": "datetime"}, {"name": "created_by", "type": "user"}, …]`. The fields are **not** part
  of `fields` – generic form builders that render `fields` therefore do not offer inputs for them.
* **Every row** of `GET` (list and single record), `POST`, `PUT` and of a transition contains the fields.
* **Sorting and filtering** (`GET /api/{entity}`, see [REST API](rest-api.md)): `sort=updated_at` etc.;
  `filter[updated_at][from]`/`[to]` as a date `YYYY-MM-DD` (whole day in UTC) or an exact UTC time
  `YYYY-MM-DD HH:MM:SS`; `filter[updated_by]=<text>` matches the user's name or username; `eq[updated_by]=<user id>`.
  Unknown values (`null`) come last in both sort directions.
* **Without a session (public API)**: `created_at` and `updated_at` are visible, `created_by` and `updated_by` are **not**
  – missing in `_schema` and in every row, and `sort`/`filter` on them answers `422` (the field does not exist for the
  caller). Usernames are login names; publishing them would hand half of a login to anyone, and the display names of
  editors are personal data. The same applies to media objects in records, which never contain the uploader.

## Not affected

* **System tables** – they are not records of the content model and partly have timestamps of their own: `users`
  (`created_at`), `media` (`uploaded_at`, `uploaded_by`), `lh_task` (`created_at`, `created_by`), `api_keys`, `languages`,
  `translations`, `user_column_prefs`, roles/groups/permissions, `schema_ids`/`schema_layout`, `_meta`.
* **Link tables** of n:n relationships and of media fields (`<a>_<b>`, `<entity>_<field>_media`): a link is not a
  record of its own; changing a list counts as a change of the record that owns it.
* **Roles and permissions**: the fields cannot be denied (like `id`) – they are no targets in `/api/_permissions/targets`
  and do not appear in the rights management.
* **Translations**: the labels are fixed UI texts (`meta.created_at` … under System → Lokalisierung → „Feste UI-Texte“,
  group „Systemfelder“), not schema elements.

## In the editorial UI

* **List view**: „Angelegt am“, „Angelegt von“, „Zuletzt geändert am“ and „Zuletzt geändert von“ can be chosen in the
  column selection (button „Spalten (x/y)“, after all other columns; not part of the default selection). They can be
  sorted and filtered like other columns. Times are shown in the browser's time zone in the date/time format of the
  display language; the date filter („von“/„bis“) uses days of the browser's time zone as well.
* **Edit form**: a small, read-only info area above the fields with the four values; it is updated after „Speichern und
  weiter“ and after a workflow transition. Unknown values show „unbekannt“. When creating (also when duplicating) there
  is nothing to show yet – the copy gets its own values.

## Reserved names

`created_at`, `created_by`, `updated_at` and `updated_by` (in any spelling, e.g. `Created_At`) cannot be used as field or
media field names in the diagram – schema error „… Der Name ist für die automatischen Systemfelder reserviert …“.
Similar names such as `erstellt_am`, `created` or `updated_at_text` stay free.

An installation whose diagram already had such a field from before this rule keeps working unchanged: that entity
simply has no system fields (its own `created_at` stays a normal, editable field). The next schema edit rejects the
diagram; rename the field with `{renamed_from:created_at}` (e.g. `+ angelegt: date? {renamed_from:created_at}`), and
the entity gets the system fields with that migration.

## Existing installations

The four columns are added with `ALTER TABLE … ADD COLUMN` to every entity table that lacks them – by
`/bootstrap` (response `"record_meta_added": [tables]`) or by the first API call after the update, whichever comes
first. Nothing is rebuilt and no data changes. Records from before have `null` in all four fields (shown as
„unbekannt“): who created them and when is not known, and nothing is invented. The first `PUT` sets `updated_*`; the
`created_*` values of such a record stay unknown. Afterwards a key `record_meta` in `_meta` saves the check.

The next schema change rebuilds these tables once to the standard layout (the columns move from the end of the
table to after the fields, as with a new installation); the values are carried over.

## Groundwork for later features

The fields are deliberately generic, so that later features can build on them without another migration of every
table:

* **Versioning / history**: implemented, see [Record versions](record-versions.md) – every version carries the
  `updated_at` of its save as its time.
* **Translation status „veraltet“**: a translation is outdated when the source record's `updated_at` is later than the
  time the translation was last saved.
* **Edit conflict protection (optimistic locking)**: the form already knows the `updated_at` it loaded; a `PUT` could
  send it along and be rejected with `409` if the stored value is newer. Note: the resolution is one second – two saves
  within the same second would not be told apart; a finer resolution or a version counter would be needed for that.
* **Reports**: „recently changed“, „changes per editor“, „not touched for a year“ – sortable and filterable via the list
  API already today (`sort=updated_at&order=desc`, `filter[updated_by]=…`, `filter[updated_at][to]=…`).
