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
  (bcrypt). Upper and lower case do not have to be mixed. Violation → `422` with `errors.password`: the rule plus what is missing,
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
* **Sessions:** PHP sessions with their own cookie `lhcms_session` (HttpOnly, SameSite=Lax, `Secure` under HTTPS), valid for 8 hours from login.
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
`last_used_at` is updated at most every 5 minutes, not on every request. The table and the column
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
name or a wrong type); both for `role = admin` only. The available settings are defined in `Settings::DEFINITIONS`.

| Setting | Default | Effect |
|---------|---------|--------|
| `require_auth_for_read` | `false` | „Authentifizierung auch für Lesezugriffe erforderlich“. `false`: reading (`GET`) is public as described under "REST API". `true`: **every** request to the REST API without a valid session or API key is `401` – also `GET` on entities and rows that are published (`visibility = true`); with a session whose initial password has not been changed yet `403 password_change_required`. |

Not affected, because the login page needs them: `GET /`, `/assets/`, `GET /api/me`, `POST /api/login` and
`GET /api/_ui_texts` (texts of the UI only). **Also not affected: the files of the media library** – Apache delivers
`/media/…` directly, without PHP; whoever knows the address of a file can fetch it.

### Rate limiting on login

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
