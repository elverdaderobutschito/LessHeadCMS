# Follow-ups („Wiedervorlage“)

_Part of the [LessHeadCMS documentation](../README.md)._

Articles with current figures go out of date. A follow-up lets an author decide at the record: "check the figures in
3 months", assigned to themselves or to others, with a note on what is to be done. When the date is reached, the follow-up
appears under „Meine Aufgaben“ and counts towards its counter.

Follow-ups are a **built-in system function**, not a field of the diagram: they work at every record of every entity
without any modelling, and nothing in the PlantUML schema, the `_schema` description or the record JSON changes.

**Scope:**

* **Included:** follow-ups at every record (several per record), due date with quick choice, responsible users and/or
  groups, note; the sections „Fällige Wiedervorlagen“ and „Demnächst“ on „Meine Aufgaben“; „Erledigt“ and „Erledigt + neu
  vorlegen“; the hint "changed since the follow-up was created" with a link to the version comparison; the list filter
  „Nur mit offener Wiedervorlage“; changing and deleting open follow-ups (creator and administrators); a switch to turn
  the function off.
* **Partly included – notifications:** like workflow tasks, the only hint at a due follow-up is the counter at the
  sidebar entry „Meine Aufgaben“ (computed when the UI loads, no polling).
* **Not included:** e-mail or other active notifications (planned together with the general notification feature),
  changing a completed follow-up, follow-ups in the public API.
* **Planned – time zone:** a global setting „Zeitzone“ under System → Einstellungen („System & Wartung“), default taken
  from `php.ini`, for the due date reference („today“) and other dates. Until then the time zone of `php.ini` applies
  (see Rules → Due).

## In the editorial UI

**At the record:** the edit form has the button **„Wiedervorlage“** next to „Verlauf“, with the number of open follow-ups
(highlighted if one of them is due). It opens a dialog with

* the open follow-ups of the record, the nearest due date first – each with its state (*fällig* = due today or earlier,
  *demnächst* = later), date, note, who is responsible, who created it and when, and the actions „Erledigt“ / „Erledigt +
  neu vorlegen“ for those allowed to complete it, and **„Bearbeiten“ / „Löschen“** for the user who created it and
  administrators;
* the completed ones, folded away („Erledigte anzeigen (n)“), with who completed it, when and the optional note;
* **„Neue Wiedervorlage“**: date with the quick choice **+1 / +3 / +6 / +12 Mon.** (counted from today; preset: +3 months),
  **Zuständig** (check boxes for the active users with a login and all groups; preset: yourself) and **„Was ist zu tun?“**
  (required).

**„Meine Aufgaben“** (`#/_tasks`):

* **„Fällige Wiedervorlagen“** – open follow-ups assigned to you directly or via one of your groups whose date is today or
  earlier, the oldest first. These count towards the counter.
* **„Demnächst“** below it, subdued and without a counter – the open follow-ups whose date is still ahead.
* The workflow tasks stay above them (with the heading „Workflow-Aufgaben“) whenever a workflow is active or you have
  workflow tasks.

Each entry links to the record (`<entity> #<id> – {title}`). If the record was saved after the follow-up was created, the
entry says so – **„Der Datensatz wurde seit Anlage der Wiedervorlage geändert.“** – and **„Änderungen ansehen“** opens the
history of the record with exactly these two versions compared: the version current when the follow-up was created
(or the oldest one still kept, see [Record versions](record-versions.md)) and the newest one. The address of that view is
`#/<entity>/<id>/history/<a>/<b>`; closing the history returns to the plain address of the record.

**„Bearbeiten“** opens the same fields as a new follow-up (date with quick choice, responsible, note), filled with the
current values. **„Löschen“** asks first („Wiedervorlage vom … wirklich löschen?“) and deletes it for good. Both are offered
at the record and on „Meine Aufgaben“, only while the follow-up is open.

**„Erledigt“** asks for an optional note. **„Erledigt + neu vorlegen“** completes it and creates the next follow-up in
the same step: note, responsible users/groups and interval of the old one are preset (interval = the quick choice used
before, otherwise the number of days between creating it and its date), the date counted from today – everything can be
changed before saving.

**The counter and the menu entry:** the counter at „Meine Aufgaben“ is the number of open workflow tasks **plus due
follow-ups**, never upcoming ones. The entry is shown when a workflow is active **or** the counter is not 0 – without a
workflow it only appears once one of your follow-ups is due, and disappears again when nothing is due any more.

**List view:** the check box **„Nur mit offener Wiedervorlage“** in the toolbar of every list shows only records with at
least one open (due or upcoming) follow-up; „Filter zurücksetzen“ clears it as well.

## Rules

* **Due:** a follow-up is due when it is open and its date is today or earlier. "Today" is computed on every call on the
  server (`date()`, i.e. the time zone set in `php.ini` – no cron job, works on shared hosting), in the same call that
  delivers the task counter.
* **Groups:** a follow-up assigned to a group is shown to every member. Whoever completes it completes it for everyone.
* **Who may complete:** the responsible users (directly or via a group), the user who created it, and administrators. Every
  user who may read the record may create follow-ups at it and see all of its follow-ups.
* **Who may change or delete:** only the user who created it and administrators (others: `403`), and only while it is
  open – a completed follow-up is a record of what was done and can no longer be changed or deleted (`409`). It disappears
  only together with its record or entity. Changing keeps `created_at`/`record_version`, so „changed since“ still refers to
  the creation.
* **Names:** follow-ups show users by their display name (`name`) only; usernames (the login) and other account data are
  never delivered by these routes, even though every editor sees the list of possible responsible users.
* **Permissions:** follow-ups exist only for users who may read the record – if the entity is denied for a user
  ([Roles and permissions](roles-and-permissions.md)), its follow-ups are missing from their lists and counter, even if
  they are assigned to them, and the routes answer `403` like reading the record.
* **Not public:** every route needs a login; without a session `401`, API users (`role = api`) get `403`, and API users
  cannot be chosen as responsible. The list filter `?followup=open` needs a session as well.
* **Deleting:** deleting a record deletes its follow-ups (also records deleted along via `{cascade}`). Follow-ups refer to
  the entity by its stable ID (`schema_ids`), like permissions and translations: renaming a class with `{renamed_from:…}`
  keeps them, removing the class from the schema deletes them.

## Switching it off

**System → Einstellungen → „System & Wartung“ → „Wiedervorlagen an Datensätzen“** (`followups_enabled`, default: on,
saved at once). Switched off, the button at the record, the list filter and the sections on „Meine Aufgaben“ disappear, the
counter no longer includes follow-ups and all routes answer `404 followups_disabled`. The stored follow-ups are **kept** –
switching it on again brings everything back as it was.

## API

All routes are under `/api/_followups`, need a session (any login role except `api`) and answer `404 followups_disabled`
while the function is switched off. Server messages are English with a code (`msg_followups.*`), like everywhere in the API.

| Route | |
|---|---|
| `GET /api/_followups?entity=<entity>&record_id=<id>` | all follow-ups of a record → `{followups, today}`; open ones first (nearest date first), then completed ones (newest first). `403`/`404` like reading the record |
| `GET /api/_followups/mine` | open follow-ups assigned to the caller (directly or via a group) on readable records → `{due, upcoming, today}` (at most 500 each) |
| `GET /api/_followups/assignees` | who can be chosen: `{users: [{id, name}], groups: [{id, name}], me}` – active users with a login, all groups, by name; only id and display name, no usernames or other account data |
| `POST /api/_followups` | body `{entity, record_id, due_date: "YYYY-MM-DD", note, interval_months?: 1–120, users?: [ids], groups?: [ids]}` → `201` follow-up. Without `users` and `groups` the caller is responsible; both given but empty → `422`. `note` is required (at most 2000 characters) |
| `POST /api/_followups/{id}/done` | body `{done_note?, next?: {due_date, note, interval_months?, users?, groups?}}` → `{followup, next}`; with `next` the new follow-up is created in the same transaction (`previous_id` = this one; field errors then as `next.due_date`, …). `403` if not allowed, `409 already_done` |
| `PUT /api/_followups/{id}` | change an open follow-up: body `{due_date?, note?, interval_months?, users?, groups?}`, checked like creating it; what is left out keeps its value (`users` and `groups` both left out: responsible unchanged; already assigned users stay valid even if deactivated meanwhile) → follow-up. `403` unless creator or admin, `409 already_done` if completed |
| `DELETE /api/_followups/{id}` | delete an open follow-up (with its responsible users/groups) → `{deleted: id}`. `403` unless creator or admin, `409 already_done` if completed |
| `GET /api/_tasks/mine?count=1` | `{open, workflow_open, followups_due}`: `open` = open workflow tasks + due follow-ups (the counter); the full call (without `count`) has the same three keys next to `tasks` |
| `GET /api/<entity>?followup=open` | list filter: only rows with an open follow-up (session only; `422` for another value) |

A follow-up in API form: `id`, `entity`, `record_id`, `due_date`, `due` (open and due), `note`, `interval_months`,
`created_at`/`created_by` (`{id, name}`), `users` (`[{id, name}]`), `groups` (`[{id, name}]`), `done_at`/`done_by`
(`{id, name}`)/`done_note`, `previous_id`, `mine` (assigned to the caller), `can_complete`, `can_edit` (open and the caller
created it or is an administrator), `changed` (record saved after the follow-up was created) and
`compare_from`/`compare_to` (versions for the comparison, `null` if there is nothing to compare). Times are UTC
(`YYYY-MM-DD HH:MM:SS`), like the [record metadata](record-metadata.md).

## Storage

Two system tables, created on the first follow-up (the prefix `lh_` keeps the names free for classes of the diagram):

* `lh_followup`: `entity_ref` (stable ID of the entity), `record_id`, `due_date`, `note`, `interval_months`,
  `record_version` (newest version of the record when it was created), `previous_id`, `created_by`, `created_at`,
  `done_at`, `done_by`, `done_note`;
* `lh_followup_assignee`: one row per responsible user (`user_id`) or group (`group_id`).

No schema migration is needed after an update: the tables appear when the first follow-up is created.
