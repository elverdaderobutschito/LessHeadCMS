# Record versions (history, comparison, restore)

_Part of the [LessHeadCMS documentation](../README.md)._

Every save of a record creates a **version**: a complete snapshot of the record with time, user, kind of change and an
optional reason. Editors see the history in the edit form, compare any two versions field by field and can restore an
older state – as a new version, the history itself is never changed. This builds on the
[record metadata](record-metadata.md) and is the groundwork for later features (draft next to the published version,
derived content with change notifications, releases).

## When a version is created

| Event | `action` | Note |
|---|---|---|
| `POST /api/{entity}` | `create` | always version 1 |
| `PUT /api/{entity}/{id}` | `update` | every successful `PUT`, also one without an actual change (`changed: []`) |
| `POST /api/{entity}/{id}/transition` | `transition` | with the label of the transition (`transition`) – the history shows the approval process |
| `POST /api/{entity}/{id}/revisions/{v}/restore` | `restore` | with `restored_from` = the restored version |
| a `{symmetric}` n:n list of *another* record changes | `link` | with `linked_from` = the record that was saved, see below |
| test data generator | `create`, `update`, `link` | like `POST`/`PUT` (linking n:n lists is a second save) |

The version is written in the same transaction as the change – there is no change without a version and no version
without a change. Not versioned: schema migrations (rebuilding, converting values, backfill), changes to *other* records
(the other side of an n:n relationship, a `{cascade}` delete), restoring a database backup.

**`{symmetric}` n:n self-references** (e.g. `Person "n" -- "n" Person : befreundet {symmetric}`) store every pair once,
and the list of *both* records shows it. Whoever saves A with „befreundet mit B“ therefore also changes what B shows. B
gets a version of its own for this (`action: "link"`, `linked_from` = A, time of the save; B's `updated_at` stays, B itself
was not edited) – so the newest version of every record is always exactly the state the API shows, and restoring an
older version of A also removes or re-adds the pairs on the partners' side (each of them gets a `link` version again).
Directed n:n lists only appear at the record that owns them; nothing changes for the target.

**Existing records** (from before this feature, or created via direct SQL) have no history. Nothing is reconstructed:
their next save is version 1 (with `action: "update"`).

## What a version contains

Table `lh_revision` (system table; the class name `LH_Revision` is reserved in any spelling):
`entity`, `record_id`, `version` (from 1 per record, never reused), `action`, `snapshot` (JSON), `reason`,
`restored_from`, `transition`, `created_at` (UTC, the same point in time as `updated_at` of the record), `created_by`
(`users.id`, resolved to the current name when reading – like the [record metadata](record-metadata.md)).

**Snapshot** – exactly the shape `PUT` accepts, so a restore is an ordinary `PUT`:

```json
{"titel": "Spaten", "text": "…", "preis": 19.9, "veroeffentlicht": false, "kategorie_id": 3,
 "tag_ids": [4, 7], "bilder": [12, 9], "state": "review"}
```

* every field except `id` – an FK column as the id of the target row, the workflow field with its state;
* every **n:n list as the IDs** of the linked rows (sorted like the API delivers them);
* every **media field as the IDs** of the media, in list order.

Linked rows and media are **referenced, not copied**. Linked records have a history of their own; copying them into
every version of every record that points to them would multiply the storage and still not make them restorable from
here. A medium is a file plus metadata in the media library with a life of its own (it can be edited or replaced there
for every use at once). Labels are resolved when the history is shown; a linked row deleted in the meantime appears as
„Kategorie #3“. Not part of the snapshot: the record metadata (the version row has its own time and user) and
`_min_warnings`.

## Retention

Setting **„Aufbewahrte Versionen je Datensatz“** (`revisions_keep`, System → Einstellungen → „System & Wartung“,
`0` to `10000`, default **`5`**, `0` = keep all). After every save, versions older than the newest `revisions_keep` of
*that* record are deleted; the numbers keep counting (after pruning, the oldest kept version may be 6). The oldest kept
version has nothing to compare with (`changed: null`). Lowering the value takes effect per record on its next save.

Deleting a record deletes its versions (also rows deleted along via `{cascade}`). Records that were linked to it lose
the link in their lists without a new version of their own; their older versions still name it, and a restore skips it
(„… gibt es nicht mehr“). A schema migration carries the
versions along: renamed classes, fields, n:n lists and media fields get their new names in all snapshots, removed fields
disappear from them, versions of removed classes are deleted. Values of a field whose type changed stay as they were;
a restore checks them (see below).

## API

All with a **session or API key** – without one `401`, also for reading: the history contains drafts and usernames.

```bash
# versions, newest first
curl -b jar https://example.org/api/artikel/7/revisions
# {"versions": [{"version": 3, "action": "update", "created_at": "2026-10-15 09:12:01",
#   "created_by": {"id": 1, "username": "admin", "name": "Administrator"}, "reason": "Preis angepasst",
#   "restored_from": null, "transition": null, "changed": ["preis"]}, …]}

# one version with its snapshot
curl -b jar https://example.org/api/artikel/7/revisions/2

# restore version 2 as a new version (optional reason)
curl -b jar -X POST https://example.org/api/artikel/7/revisions/2/restore \
     -H 'Content-Type: application/json' -d '{"_reason": "versehentlich überschrieben"}'
# {"record": {…}, "version": 4, "restored_from": 2, "skipped": []}
```

`changed` lists the names that differ from the next older kept version (`null` for the oldest kept one).

**Reason for a change:** `POST`, `PUT`, a transition and a restore accept an optional `"_reason"` (text, at most 1000
characters, trimmed, empty = none; otherwise `422` with `errors._reason`). It is stored with the new version only – never
retroactively. The field name `_reason` is therefore reserved in the diagram.

**Restore** is a `PUT` of the snapshot with the current rules – the same validation, the same rights. Taken over is
everything that still fits; what does not is **skipped and reported** in `skipped` (`[{field, reason}]`) instead of
failing the whole restore:

* the workflow state – it only changes via transitions;
* an FK whose target row no longer exists – the current value stays;
* n:n entries and media that no longer exist – left out of the list;
* values the current schema rejects (after a type change, a new required field, a removed enum value …).

A uniqueness conflict (`{unique}`, 1:1) is answered with `409` like a normal `PUT`; nothing is changed then.
`created_at`/`created_by` stay, `updated_at`/`updated_by` are set ([record metadata](record-metadata.md)).

## Rights

The history follows the rights of the record ([roles and permissions](roles-and-permissions.md)), nothing new to configure:

* entity denied → `403` for history, version and restore;
* a denied field is missing in every snapshot and in `changed`, and is never restored (its current value stays);
* viewing the history needs the right to read the record – i.e. a session; restoring additionally needs the action
  „Bearbeiten“ (`update`) – without it `403`, and the button in the UI is disabled with the reason.

## In the editorial UI

* **„Änderungsgrund (optional)“** above the save buttons of the form – saved with the version, empty again after saving.
* **„Verlauf“** in the header of the edit form opens the history: all versions with time, user, kind („Angelegt“,
  „Gespeichert“, „Übergang „…““, „Wiederhergestellt aus Version n“), reason and changed fields.
* **Comparison:** choose version A (older) and B (newer) with the radio buttons – by default the newest against the one
  before. The table shows field by field the value of A and of B; changed fields are highlighted (old value struck
  through on red, new value on green), „Nur geänderte Felder“ hides the rest. Long texts (`text`, `richtext`) are shown
  as a line diff, like the text preview of the schema editing. FK and n:n values appear with their labels, numbers and
  dates in the format of the display language.
* **„Wiederherstellen“** at every version except the newest, with an optional reason and a confirmation. Unsaved input in
  the form is replaced by the restored state; a notice names the new version and anything that was skipped.

## Performance and size

A save does a few more indexed operations (newest version number, one `INSERT`, possibly one `DELETE` for the
retention) in the transaction it already has; reading records and lists does not touch `lh_revision` at all. The table
grows by one snapshot (about the size of the record as JSON, plus about 150 bytes) per save – with the default of 50
versions at most 50 times the content per record. Installations with many automated writes should lower
`revisions_keep`; the test data generator writes one or two versions per generated record.
