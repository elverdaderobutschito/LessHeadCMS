# Login, users, API keys and settings

_Part of the [LessHeadCMS documentation](../README.md)._

## Login and sessions

* **Users:** table `users` (`username` unique, `password_hash` via `password_hash()`/bcrypt, `must_change_password`,
  `name`, `email`, `role` (`admin`/`redakteur`/`api`), `active`, `api_read_only`, `created_at`). It is not part of the PlantUML schema; `users`,
  `login`, `logout`, `me` are therefore reserved as class names, as are `testdata` (route of the test data page) and
  the system tables `login_attempts` (rate limiting), `user_column_prefs` (column selection, see
  Editorial UI), `media`, `media_tags`, `media_tag_links` (media library; `media` is also the route `#/media`)
  as well as `schema_ids`, `schema_layout` (stable IDs and diagram layout of the schema editor, see [Diagram view](deployment.md#diagram-view-and-model-json)) and
  `languages`, `translations`, `user_language_prefs` (multilingual support) and `roles`, `groups`, `permissions`,
  `group_roles`, `user_roles`, `user_groups` (roles and permissions), `lh_task` (tasks of the workflows; a class `Tasks` is available) and `api_keys` (API keys). Case does not matter (`Login_Attempts` is reserved just the same).
* **First admin:** with an empty `users` table, `/bootstrap` creates `admin` with the initial password **`password`**
  (`must_change_password = 1`, `role = 'admin'`, `name = 'Administrator'`, `active = true`). Until the first login,
  anyone who knows the initial password can take over the admin – so log in right after the bootstrap and change the
  password.
* **Migration of existing installations:** if the `users` table still dates from before the user management (only
  `id`/`username`/`password_hash`/`must_change_password`/`created_at`), `/bootstrap` adds the missing columns via
  `ALTER TABLE` (`name`/`email` empty, `active = true`). For `role` there is an exception to the usual default `redakteur`:
  since there were no roles before this feature, every existing row was effectively an administrator – the migration sets
  `role = 'admin'` for all users existing at that time instead of otherwise locking them out of `/api/users` permanently.
* **Forced password change:** as long as it applies, the frontend only shows the password form, and the server rejects write requests
  with `403 password_change_required`. New password: complexity rule (see next item), not `password` and not your
  own login name.
* **Password rule** (`Auth::passwordError()`, applies to `POST /api/users` and `PUT /api/me/password`): at least 8 characters,
  at least one digit (0–9) and at least one special character – any character that is neither a letter (including umlauts) nor a digit
  nor whitespace, i.e. `! ? # - _ . @ $ % & * ( ) [ ] { } ; : , < > = + / …`, but also `€` or `§`; at most 72 bytes
  (bcrypt). Upper and lower case do not have to be mixed. This is the default; minimum length and the three kinds of
  characters can be changed under System → Einstellungen ([Settings](#settings-api_settings-system--einstellungen)). Violation → `422` with `errors.password`: the rule plus what is missing,
  e.g. „… Es fehlt: eine Ziffer, ein Sonderzeichen.“; the UI shows this directly at the password field. The rule only applies
  when **setting** a password: the login only checks the hash, older simpler passwords keep working. The button
  „Sicheres Passwort generieren“ (16 characters, at least one lower-case/upper-case letter, one digit, one special character each)
  always satisfies the rule. After creation the user list shows the password in bold in a separate, set-apart box.
  The same button exists in the form „Passwort ändern“ (forced change): it fills „Neues Passwort“ and „Passwort
  wiederholen“ and switches both fields to plain text; the checkbox „Passwort anzeigen“ masks/shows the fields at any time.
* **Voluntary password change (own account):** text link „Passwort ändern“ in the user area at the bottom of the sidebar
  (below the user name, above „Abmelden“), for any role. Opens the same form as the forced change (rule,
  generator, „Passwort anzeigen“) in the content area; the sidebar stays operable, the previous view is kept including
  unsaved input. „Abbrechen“ (or a click in the sidebar) leads back without a change, „Passwort
  speichern“ shows „Passwort geändert …“ above the previous view; the session stays valid. Unlike with the
  forced change, the field **„Aktuelles Passwort“** (current password; masked, without generator) is at the top - the server checks it
  so that a session someone left open cannot take over the account. Technically the same API
  `PUT /api/me/password` (user ID from the session - there is still no way to set another user's
  password). No hash route name of its own, hence no further reserved class name either.
* **Sessions:** PHP sessions with their own cookie `lhcms_session` (HttpOnly, SameSite=Lax, `Secure` under HTTPS), valid for 8 hours from login (setting `session_lifetime_hours`).
  The session files live in `data/sessions/` (not in the hoster's `session.save_path`); expired files are cleaned up by the
  login. Public GET requests do not start a session and do not set a cookie. A deactivated user (`active = false`)
  immediately counts as logged out even with a session that is still valid.

| Method  | Path                | Description                                                                                   |
|---------|---------------------|-----------------------------------------------------------------------------------------------|
| POST    | `/api/login`        | `{"username","password"}` → `{"status":"ok","must_change_password":bool}`, otherwise `401` (also for a deactivated account); when locked `429` |
| POST    | `/api/logout`       | destroys the session                                                                          |
| GET     | `/api/me`           | `{"logged_in":false}` or `{"logged_in":true,"username":…,"name":…,"role":…,"must_change_password":…}` |
| PUT     | `/api/me/password`  | `{"password":"…","current_password":"…"}`, only with a session, always the own account; sets `must_change_password = 0`. `current_password` is required as soon as no mandatory change is pending any more (stored `must_change_password = 0`, not a client flag); for the forced first change it is omitted. `422` for a new password that is too weak or a wrong/missing current password (`errors.current_password`: „Aktuelles Passwort ist falsch.“); wrong current passwords count towards the login rate limit (`429`, see below) |

### User management (`/api/users`, `role = admin` or system area `users`)

System level like `users`/`login` itself, independent of the PlantUML content schema. Every route requires (`GET` too) a
session **and** `role = 'admin'` (or a permission on the system area `users`, see [Roles and permissions](roles-and-permissions.md)): without a
session `401`, with a session but a pending password change `403 password_change_required`, as an editor without this
permission `403 forbidden`. Whoever reaches the user management only via the permission cannot create or change
administrators and cannot make anyone an administrator (`403`).

| Method  | Path                | Description                                                                                   |
|---------|---------------------|-----------------------------------------------------------------------------------------------|
| GET     | `/api/users`        | list of all users (without `password_hash`)                                                   |
| POST    | `/api/users`        | `{"name","username","email","role","password"}` → `201`; password according to the password rule (otherwise `422`); `username` must be unique (`409`); `must_change_password` automatically becomes `true` |
| PUT     | `/api/users/{id}`   | only changes the fields sent out of `name`/`email`/`role`/`active`/`api_read_only`; **no** password reset via this route (for that `PUT /api/me/password`, only by the user themselves) |
| DELETE  | `/api/users/{id}`   | `405` – no deletion in the MVP, instead `active = false` via `PUT` (soft delete)               |
| POST    | `/api/_testdata/generate` | generate test data (see below); without a session `401`, as an editor `403`             |

**Test data generator** (`src/TestData.php`): body `{"count": 10}` (for all) and/or `{"counts": {"<table>": n}}`
(individually, 0 = skip, 0–1000, otherwise `422`). Response `{"created": {table: n}, "links": {"table.x_ids": n},
"skipped": {table: reason}, "failed": {…}, "order": […]}`. Writing is done exclusively via the same logic as
`POST`/`PUT /api/{entity}` (same validation as in the UI); existing rows stay untouched.
Order: FK targets before dependent tables; FK values randomly from existing rows (optional ones occasionally empty);
self-references only to rows that already exist (≈ 70 % roots, no cycles); n:n 0–3 links per new
row, after all tables have been filled (n:n self-reference never with the row itself; with `{symmetric}` every
row adds to its pairs already created by others instead of replacing them). With a `{unique}` group, an already used combination is retried with new
random values (up to 50 times); if the value range is too small (e.g. only one `bool` marked), the
table aborts with „keine weitere freie Kombination …“ in `failed`. **Minimum count** (`"1..*"` on n:1): the rows of the
dependent table first go to parent rows that do not have any yet; if parent rows created in this run are still without
one afterwards, additional rows are created for them (e.g. 12 invoices and 5 line items requested → 12 line items;
`created` counts them, `"min_extra": {"position": 7}` states the additional ones, the UI shows „davon 7 zusätzlich
für die Mindestanzahl“). Limits: not for a self-reference, not with count 0 for the dependent table, not if it
is filled before its parent class because of a cycle, and parent rows without a row that already existed before only get
as many as are requested. 1:1 columns only get target rows not yet assigned
(optional ones otherwise empty); if none is free any more for a required 1:1 (more rows requested than the target table
has), the table aborts with „alle Zeilen in '…' sind bereits zugeordnet“ in `failed`. Values: `string` word combinations (for field names containing "mail" an
e-mail address, for `vorname`/`nachname` names), `text` sentences, `richtext` Markdown with **bold**/*italic*, `int`
1–1000, `decimal` 5.00–500.00, `date` the last two years, `enum` a random value from the list, `bool` random, `visibility` ≈ 80 % published (from two
rows on always at least one draft). Skipped (with the reason in `skipped`) is whatever cannot be created via `POST`:
a required FK to an empty table, a required self-reference in an empty table, tables that
need each other via required FKs. **Media fields deliberately stay empty** – no image/video files are invented;
this also applies to required media fields (only the generator may leave them empty, when editing the form then requires
a medium). The response lists them under `"media_empty": {"kurs.galerie": true}` (`true` = required field), the UI
shows a hint.

`role` is restricted to `admin`/`redakteur`/`api` (otherwise `422`); for `api` see "API users and API keys" below. Protection against locking yourself out: `PUT` with `active = false`
on your own ID → `422 self_deactivation`. If a `PUT` (deactivating **or** a role change away from `admin`) would remove the
last active admin → `422 last_admin` – at least one active admin must always remain in the system.

### API users and API keys (`role = api`)

For programmatic access to the REST API there is a third basic role next to `admin` and `redakteur`: `api`. An API user

* **has no password** – not on creation and never afterwards (`POST /api/users` with `"role": "api"` must not contain a
  `password`, otherwise `422`; `password_hash` stays empty),
* **cannot log in**: `POST /api/login` answers `403 api_user_no_login` whatever is entered (not counted as a failed
  attempt), and there is no session for this role,
* authenticates with an **API key** in the header `Authorization: Bearer <key>`,
* is otherwise subject to **exactly the same** roles, groups and permissions as an editor: a request with a key gets the
  same `Access` object as a request with a session (`Auth::currentUser()` → `Permissions::access()`), there is no
  second permission logic. Without any assignment an API user may do what an editor may do by default.

The role cannot be changed afterwards, in neither direction (`422` on `role`): an API user has no password, a login
user no key – create a new user instead.

**Keys** (`src/ApiKeys.php`, table `api_keys`: `id`, `user_id`, `key_hash`, `key_preview`, `created_at`, `created_by`,
`last_used_at`, `revoked_at`): `lh_` followed by 48 hex characters from `random_bytes` (192 bits). Stored is **only the
SHA-256 hash** (unique index) plus the last 6 characters for recognising the key; the plain text exists only in the
response that creates it. It is never written to a log or an error message, and it travels in a header, not in the URL.
Each API user has **at most one active key**: creating a new one revokes the previous one in the same transaction.
`last_used_at` is updated at most every 5 minutes (setting `api_key_last_used_seconds`), not on every request. The table and the column
`users.api_read_only` are created on demand – an existing installation needs no new `/bootstrap`.

| Method  | Path                          | Description |
|---------|-------------------------------|-------------|
| POST    | `/api/users`                  | with `"role": "api"`: creates the user **and the first key**; only this response contains `"api_key"` in plain text |
| GET     | `/api/_users/{id}/api_key`    | state of the key: `status` (`active`/`revoked`/`none`), `preview`, `created_at`, `last_used_at`, `revoked_at`, `read_only` – never the key |
| POST    | `/api/_users/{id}/api_key`    | new key → `201`, the previous one stops working at once; only this response contains `"key"` |
| DELETE  | `/api/_users/{id}/api_key`    | revoke: no access until a new key is created |
| PUT     | `/api/users/{id}`             | `{"api_read_only": true|false}` – switch "Nur lesen" (admins only, API users only) |

The three `_users/{id}/api_key` routes are reserved for `role = admin` (`404` unknown user, `422 not_api_user` for a user
of another role).

**Enforcement:** a request with `Authorization: Bearer` is authenticated by the key alone. An unknown, malformed or
revoked key, or the key of a deactivated user, is always `401 invalid_api_key` – also for a `GET` that would be public,
and also if a valid session cookie comes along (no fallback to the session or to anonymous access). **"Nur lesen"**
(`api_read_only = true`): everything except `GET`/`HEAD` is refused with `403 read_only` before any permission is looked
at – whatever roles and groups grant; it applies to every route, and at once. The protections of the user management
apply regardless of how the caller authenticated: an API user with the system area `users` cannot create or change
administrators (`403`), and the routes for roles, groups, permissions, keys and settings stay reserved for admins.

**UI:** „Neuer Nutzer“ offers the role „API“ (then without a password field); after creating, the key is shown once, in
the same way as the initial password of a new user. „Rollen & Rechte“ of an API user has the additional section
„API-Schlüssel“ above the assignments: preview, created, last used, status, „Neuen Schlüssel erzeugen“, „Schlüssel
widerrufen“ and the switch „Nur lesen“.

**Hosting note:** some hosters do not pass the `Authorization` header to PHP (CGI/FastCGI). `.htaccess` therefore copies
it into the environment (`HTTP_AUTHORIZATION`); `ApiKeys::bearerToken()` also reads `REDIRECT_HTTP_AUTHORIZATION` and
`apache_request_headers()`. If a valid key is answered as if no key had been sent (public data only, `401` on writing
with `unauthorized` instead of `invalid_api_key`), the header does not arrive.

### Settings (`/api/_settings`, System → Einstellungen)

Settings of the installation that are changed in the admin area instead of in `config.php` (`src/Settings.php`, stored
as rows of `_meta`, read on every request – a change applies at once, without a new bootstrap). `GET /api/_settings`
returns all of them as `{name: value}`, `PUT /api/_settings` changes the ones contained in the body (`422` for an unknown
name, a wrong type or a number outside the allowed range – then nothing is saved, the message is in `errors.<name>`);
both for `role = admin` only. The available settings are defined in `Settings::DEFINITIONS`. A setting that was never
saved has no row and keeps its default – the value that used to be fixed in the code, or the one from `config.php` – so
an existing installation behaves as before. Numbers are whole numbers (JSON integers, not strings); a stored value that
is unusable (edited by hand, outside the range) counts as not stored.

**The page** groups the settings in six tabs, the same building block as the rights management and the localization
(`TabPage.jsx`); every tab has its own address and can be linked directly:

| Tab | Address | Settings |
|-----|---------|----------|
| „Anmeldung & Sicherheit“ | `#/_settings` or `#/_settings/security` | read access, password rule, session, lock after failed attempts |
| „Mediathek“ | `#/_settings/media` | upload size, thumbnails, text lengths |
| „Listen“ | `#/_settings/lists` | page sizes, size of a list response, task list |
| „System & Wartung“ | `#/_settings/system` | schema backups, maintenance lock, API keys |
| „Import & Testdaten“ | `#/_settings/tools` | test data, translation import |
| „Webhooks“ | `#/_settings/webhooks` | the webhooks themselves (list, history, create/edit – see [Webhooks](webhooks.md)) and the timeout of their calls |

The switch `require_auth_for_read` saves at once. Everything else – numbers and the checkboxes of the password rule – is
saved per tab with „Speichern“; only the changed values are sent, so the other tabs stay untouched. The browser checks
the same ranges as the server before sending. Changes that were typed but not saved are lost when the tab is switched.

What the UI itself needs of these settings for every logged-in user, not only for admins (password rule, page sizes,
upper limit of the test data form), is part of `GET /api/me` as `settings` (`Settings::client()`); the UI reads it on
loading, after the login and after saving on the settings page.

#### „Anmeldung & Sicherheit“

| Setting | Default | Effect |
|---------|---------|--------|
| `require_auth_for_read` | `false` | „Authentifizierung auch für Lesezugriffe erforderlich“. `false`: reading (`GET`) is public as described under "REST API". `true`: **every** request to the REST API without a valid session or API key is `401` – also `GET` on entities and rows that are published (`visibility = true`); with a session whose initial password has not been changed yet `403 password_change_required`. |
| `password_min_length` | `8` | „Mindestlänge“ in characters, `8` to `64`, for every newly set password (creating a user, `PUT /api/me/password`). Not retroactive: the login only checks the hash, existing shorter passwords keep working. |
| `password_require_digit` | `true` | „Ziffer erforderlich“: a new password must contain a digit. |
| `password_require_special` | `true` | „Sonderzeichen erforderlich“: a new password must contain a character that is neither a letter nor a digit nor whitespace. |
| `password_require_mixed_case` | `false` | „Groß- und Kleinbuchstaben erforderlich“: a new password must contain an upper-case and a lower-case letter. |
| `session_lifetime_hours` | `8` | „Gültigkeit einer Sitzung“ in hours, `1` to `720` (30 days), counted from the login, independent of activity. Applies to running sessions as well: a session older than the new value is logged out on its next request. |
| `login_rate_limit_max` | `5` | „Erlaubte Fehlversuche“, `3` to `100`: failed attempts allowed per user name and per IP within the time window; the next attempt is locked. See [Rate limiting on login](#rate-limiting-on-login). |
| `login_rate_limit_window_minutes` | `15` | „Zeitfenster“ in minutes, `1` to `1440` (24 h): how long a failed attempt counts, and with that how long a lock lasts at most. |
| `login_attempts_retention_days` | `1` | „Aufbewahrung der Fehlversuche“ in days, `1` to `365`: older rows of `login_attempts` are deleted on every login call. Only the time window counts for the lock; a longer retention merely keeps the rows for looking them up. |

The message of the server for a password that is too simple and the hint below the password fields name exactly the rule
in force, and „Sicheres Passwort generieren“ always satisfies it (16 characters, or the minimum length if that is
larger; lower-case, upper-case, digit and special character are always contained). With the default rule the hint is the
fixed UI text `password.hint`; a changed rule is composed from `password.rule_*`.

A session is checked against its validity on every request (age of the session file); expired files are removed on the
next login.

The rate limit applies at once: a lower `login_rate_limit_max` also locks whoever already has that many failed attempts in the
window, a shorter window releases existing locks earlier. The session of a logged-in admin is not affected by a lock, so
the values can still be corrected.

`require_auth_for_read` – not affected, because the login page needs them: `GET /`, `/assets/`, `GET /api/me`, `POST /api/login` and
`GET /api/_ui_texts` (texts of the UI only). **Also not affected: the files of the media library** – Apache delivers
`/media/…` directly, without PHP; whoever knows the address of a file can fetch it.

#### „Mediathek“

| Setting | Default | Effect |
|---------|---------|--------|
| `media_max_mb` | `20`, or `MEDIA_MAX_MB` from `config.php` | „Maximale Dateigröße“ per file of the media library in MB, `1` to `2048`. A saved value takes precedence over `config.php`. The PHP limits `upload_max_filesize`/`post_max_size` still apply if they are lower – the page then shows a note with the effective limit (`GET /api/_media/limits`). Files already uploaded are not touched. |
| `media_thumbnail_px` | `200` | „Kantenlänge der Vorschaubilder“ in pixels, `50` to `1000`: thumbnails are created at most this wide and this high. Applies to images uploaded or replaced from then on (and to thumbnails that are still being caught up); existing thumbnails are **not** recreated. |
| `media_title_max` | `255` | „Titel“, `20` to `1000` characters. |
| `media_alt_max` | `1000` | „Alt-Text“, `50` to `10000` characters. |
| `media_description_max` | `20000` | „Beschreibung“, `100` to `100000` characters. |
| `media_tag_max` | `100` | „Tag-Name“, `10` to `255` characters. |

The text lengths are checked when a medium or a tag is saved (`422`, message at the field). Longer texts that are already
stored stay as they are; they only have to be shortened when that field is saved again.

#### „Listen“

| Setting | Default | Effect |
|---------|---------|--------|
| `list_default_per_page` | `50` | „Zeilen je Seite“, `5` to `500`: rows of `GET /api/{entity}` without `per_page`, and the page size a list of the UI opens with. Must not exceed `list_max_per_page` (`422`). |
| `list_max_per_page` | `500` | „Größte Seitengröße“, `100` to `5000`: largest `per_page` and largest number of `ids`; more is `422`. The choice „Zeilen je Seite“ of the lists offers 25/50/100/200 and the default, nothing above this value. |
| `list_select_per_page` | `25` | „Zeilen je Seite im Auswahldialog“, `5` to `100`: page size of the search and selection dialog for relationships. |
| `list_max_response_mb` | `0` | „Größte Listenantwort“ in MB, `0` to `256`: above this size of the raw data of one list page the API answers `413` (see [REST API](rest-api.md)). `0` = automatic: `LIST_MAX_BYTES` from `config.php` if defined, otherwise one tenth of the free `memory_limit`, at most 16 MB. A saved size takes precedence over `config.php`; a value above the memory PHP has available makes requests abort. |
| `tasks_list_limit` | `500` | „Aufgaben je Liste“, `10` to `5000`: `GET /api/_tasks` and `GET /api/_tasks/mine` deliver at most this many tasks, newest first. |

These values change the public REST API as well: a consuming frontend that relies on 50 rows without `per_page` or
on `per_page=500` being allowed should pass `per_page` explicitly and stay within `list_max_per_page`.

#### „System & Wartung“

| Setting | Default | Effect |
|---------|---------|--------|
| `revisions_keep` | `5` | „Aufbewahrte Versionen je Datensatz“, `0` to `10000`: versions kept per record ([Record versions](record-versions.md)); older ones are deleted on the next save of the record. `0` = keep all. |
| `schema_backup_keep` | `5`, or `SCHEMA_BACKUP_KEEP` from `config.php` | „Aufbewahrte Sicherungen“, `1` to `100`: backup pairs kept before schema changes (see [Deployment](deployment.md)). A saved value takes precedence over `config.php`; older backups are deleted on the next „Anwenden“, not when saving. |
| `schema_backup_warn_mb` | `50`, or `SCHEMA_BACKUP_WARN_MB` from `config.php` | „Speicherplatz-Hinweis ab“ in MB, `1` to `102400`: from this database size on, System → Schema points out the disk space the backups take up. A saved value takes precedence over `config.php`. |
| `maintenance_timeout_seconds` | `120` | „Wartungssperre gilt höchstens“ in seconds, `30` to `3600`: age from which the maintenance flag `data/migration.flag` counts as orphaned and is ignored (it stays behind if PHP aborts hard during a schema migration or a restore). Must not be shorter than a migration takes on the server – otherwise writes get through while the database is being rebuilt. |
| `api_key_last_used_seconds` | `300` | „„Zuletzt verwendet“ aktualisieren alle“ in seconds, `0` to `86400`: `last_used_at` of an API key is rewritten at most this often. `0` = on every request (one write access per request). |

While the maintenance flag is active, `PUT /api/_settings` is rejected with `503` like every other write access.

#### „Import & Testdaten“

| Setting | Default | Effect |
|---------|---------|--------|
| `testdata_max_count` | `1000` | „Größte Anzahl je Entität“, `10` to `10000`: largest count per entity of one call of `POST /api/_testdata/generate`; more is `422`. Large amounts may hit the execution time limit of the server. |
| `translations_csv_max_mb` | `2` | „Maximale Größe einer CSV-Datei“ in MB, `1` to `32`, for the translation import (uploaded content and files from `translations/`); larger is `422 too_large`. |

#### „Webhooks“

| Setting | Default | Effect |
|---------|---------|--------|
| `webhook_timeout_seconds` | `5` | „Längste Wartezeit“ in seconds, `1` to `30`: the webhooks of one save, transition or deletion are called at the same time, and the request waits at most this long for their answers; a webhook that has not answered by then is recorded as a timeout in its history. The write itself is already committed and stays so (see [Webhooks](webhooks.md)). |

Above the setting the tab shows the webhooks themselves (`GET/POST/PUT/DELETE /api/_webhooks`, admins only, see
[Webhooks](webhooks.md)).

A further setting is one more entry in `Settings::DEFINITIONS`, its field in the tab description in `SettingsPage.jsx`
and its texts (`settings.<name>`, `settings.<name>_unit`, `settings.<name>_hint` in `i18n-strings.js` and `en.csv`).

### Rate limiting on login

Threshold and time window are settings (`login_rate_limit_max`, `login_rate_limit_window_minutes`, see
[Settings](#settings-api_settings-system--einstellungen)); the numbers below are the defaults (5 failed attempts, 15 minutes).

**Also for `current_password`** in the voluntary password change (`PUT /api/me/password`): a wrong current
password counts as a failed attempt in the same counters (user name of the logged-in account + IP), when the limit is reached
the route answers with `429` (message additionally in `errors.current_password`). The counters are deliberately shared: whoever tries to
guess the current password with someone else's session thereby also locks the login of this account, and
vice versa. A correct entry resets the counter of the user name (like a successful login); an empty field
is not a guess and does not count.

Table `login_attempts` (`username`, `ip`, `attempted_at`; created by `/bootstrap`, `/api/login` creates it itself if need be).
Every failed attempt (wrong password, unknown user, invalid input) is stored. **Before** every password check, the
failed attempts of the last 15 minutes are checked in **two separate counters**: per **user name** (no matter from which IP – against
distributed attacks on one account) and per **IP** (no matter with which name – against password spraying). If one of the counters has reached
**5 failed attempts**, the next attempt is rejected with `429 {"error":"rate_limited","retry_after_seconds":N}` (+ header `Retry-After`)
**without** checking the password (even a correct password does not get through then). Locked attempts are not counted.
`N` = seconds until enough old attempts have expired that the counter falls below 5 again (with exactly 5 attempts: until the oldest
expires). A successful login deletes the entries of this user name. Entries older than 24 h are deleted on every login call.
On 429 the frontend shows „Zu viele Fehlversuche, bitte in X Minuten erneut versuchen“.

* **Threshold:** 5 failed attempts are allowed, the **6th** attempt is locked.
* **Client IP** is `REMOTE_ADDR`. With a proxy/load balancer in front, all visitors share the same address, and 5 failed attempts
  of any visitor then lock out everyone. For this, `/bootstrap` outputs `rate_limit_check` (`remote_addr`, `remote_addr_is_public`,
  `x_forwarded_for`, `hint`); `remote_addr` should match your own public IP.
* Anyone who enters `admin` wrongly 5 times locks the account for up to 15 minutes (the price of this protection).
