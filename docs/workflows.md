# Workflows (phase 1: state machine, enforcement, management; phase 2: "My tasks")

_Part of the [LessHeadCMS documentation](../README.md)._

A workflow gives a class a state (`draft`, `review`, …) that can only be changed via defined transitions
– and only by users with the groups or roles named there. **Without a workflow file nothing changes:**
no entity gets a state, `PUT` works as before, the model and all API responses are byte-identical to the
state without this feature. The same applies to every class without a workflow field, even if workflows are active for other classes.

**Scope:**

* **Included:** the state machine with enforced transitions, the management page System → Workflows (workflow files are
  edited as text, with check and apply) and "My tasks" – the sidebar entry „Meine Aufgaben“ with the page of the own
  tasks (`GET /api/_tasks/mine`, see "Transitions and tasks").
* **Partly included – notifications:** the only hint at a new task is the counter of the open tasks at the sidebar entry
  „Meine Aufgaben“. It is loaded at the start and after every own transition (no polling), so it is only seen by a user who
  is logged in and reloads. There is no e-mail, no push message and no other active notification.
* **Not included:** a visual workflow editor (the diagram editor only covers the main schema) and dynamic assignment
  targets (`assign=field:…`) – `assign=` only accepts `group:<name>`, `user:<login name>` and `initiator`.

## Workflow files

Workflows are **not** part of the bootstrap. They live in files of their own with the extension **`.lhwf`** (LessHead Workflow)
in the folder `schema/workflows/` (placed there via FTP or created under **System → Workflows**; admins only). Like `schema/`, the folder cannot be retrieved directly (everything except
`assets/` and `media/` goes through `index.php`), in addition a separate `.htaccess` blocks it.

**File extension:** A workflow file has its own syntax and is not PlantUML – hence `.lhwf`; `.puml` stays reserved for the
diagrams of the main schema. Only `*.lhwf` is listed, offered and accepted; when creating via the
UI the extension is appended, a name with `.puml` is rejected (`422`). A `.puml` in the folder `schema/workflows/`
does not count as a workflow file: the page mentions it in a notice and leaves it untouched (rename it to `.lhwf` by hand
if it is one). The only exception is legacy data from before `.lhwf`: if the **active**
workflow file still has the extension `.puml`, the first opening of System → Workflows switches it automatically (name in
`_meta`, the file is renamed provided the new name is free) and reports that once.

At most one file is active (`_meta.active_workflow_file`). Work is always done with the **applied** text
(`_meta.workflow_source`); a file changed via FTP only takes effect after „Anwenden“ – the page shows the difference.

```plantuml
workflow ArticleWorkflow {
  state draft       "Entwurf"
  state review      "Freigabe"
  state published   "Veröffentlicht"

  flow draft -> review -> published

  draft -> review : einreichen {
    allowed=group:redaktion
    assign=group:chefredaktion
  }
  review -> published : veroeffentlichen {
    allowed=group:chefredaktion, role:Chef vom Dienst
    action=set_visibility:true
  }
  review -> draft : zurueckgeben {
    allowed=group:chefredaktion
    assign=initiator
  }
}
```

| Statement | Meaning |
|---|---|
| `workflow <Class>Workflow {` | Name = class name + `Workflow`. Several blocks per file; workflows without a matching class are not an error (like an unused enum). |
| `state <name> "label"` | State; the name is stored like this in the database (rules as for enum values), the label is optional. |
| `flow a -> b -> c` | Exactly one line: the main path. The first state is the initial state of new records. For every pair there must be a transition. |
| `from -> to : label {` | Transition. The label is unique per source state (not globally) and may contain letters, digits, `_`, `-` and spaces. |
| `allowed=` | Required. `group:<name>` and/or `role:<name>`, separated by commas. Admins may execute every transition. |
| `assign=` | Optional. `group:<name>`, `user:<login name>` or `initiator` (whoever started the workflow of this record). Creates a task. |
| `action=` | Optional. So far only `set_visibility:true` / `set_visibility:false` (sets the `visibility` field of the class). |

The file is checked in two stages: `WorkflowParser` purely structurally and without a database (unknown state, flow pair
without a transition, missing `allowed=`, …), then the **combined analysis** against database and main schema: does every
named group, role and user exist? Does the main schema match?

## Workflow field in the main schema

```plantuml
class Article {
  + titel: string {title}
  + veroeffentlicht: visibility?
  + state: ArticleWorkflow
}
```

The type `<ClassName>Workflow` turns the field into the workflow field (the field name is free). In the database it is a
required enum over the states (`VARCHAR` with `CHECK`), in lists a normal column with filter and sorting, the
states can be translated like enum values under System → Übersetzungen (without a translation the label from the
workflow file applies). Otherwise it follows rules of its own:

* **Schema errors:** no active workflow configured (also at `/bootstrap` – there never is one there), workflow not defined in the
  active file, type not equal to `<ClassName>Workflow`, more than one workflow field per class, `?`, `{title}`
  or `{unique}`, in an abstract class, `action=set_visibility` without a `visibility` field of the class, an enum of the same name.
* **Creating:** always the initial state; a value sent along does not count, the form shows no field.
* **Migration:** existing rows automatically get the initial state (no backfill dialog). If a state that
  still has rows in it is dropped, the analysis blocks as with a removed enum value.
* **`PUT /api/{entity}/{id}`** rejects every changed value with `422` (the unchanged one may come along) – for admins too.
* **Outside the field permissions:** `deny` on the field is rejected by the rights management with `422 workflow_field`. Who may change the
  state is determined exclusively by `allowed=`. Entity denials apply as everywhere (also for transitions).
* `visibility` stays a field of its own in its usual place; the workflow can only set it via `action=`.

## Checking and applying

System → Workflows works like the schema page: text field, „Änderungen prüfen“, „Herunterladen“, „Verwerfen“, „Anwenden“.
The dropdown „Aktive Workflow-Datei“ selects the file being edited; it only becomes active with „Anwenden“ (the choice
„Keine“ switches workflows off – only possible as long as no field uses them). „Anwenden“ writes the file, makes it the
active one and adapts model and database – via **the same** path as every schema change (`SchemaMigration`, backup pair
in `data/schema-backups/`, one transaction). Conversely, „Änderungen prüfen“ on the schema page always checks the main schema
together with the applied workflow state. When a backup is restored, the workflow file is also brought to the
state of the restored database.

| Route (admin only) | |
|---|---|
| `GET /api/_workflow/files` | files (`*.lhwf`), active file, classes with a workflow field, `legacy` (files with the old extension `.puml`), `renamed` (active file just switched); creates the (empty) table `lh_task` |
| `GET /api/_workflow/source?file=<name>` | text of a file, `file_matches_active` |
| `POST /api/_workflow/files` `{name}` | new, empty file (`.lhwf` is appended); `409` if it exists, `422` for `.puml` |
| `POST /api/_workflow/analyze` `{file, source}` | combined analysis like `/api/_schema/analyze`, additionally `workflow` (overview); errors `422 schema_error` with a line reference state `workflow_line`/`workflow_lines` (see below) |
| `POST /api/_workflow/apply` `{file, source, confirm, backfill}` | apply; `file: null` switches workflows off |
| `GET /api/_tasks` | tasks, optionally `?entity=`, `?record_id=`, `?status=open\|done` |

| Route (any role, with a session) | |
|---|---|
| `GET /api/_tasks/mine` | „Meine Aufgaben“ (my tasks): open tasks assigned to the logged-in user directly or via one of their groups, newest first → `{tasks, open}`; `?status=all` also completed ones, `?count=1` only `{open}`. Per task additionally `created_by_name`, `via` (`user`/`group`) and `assigned_group` (name) |

## Transitions and tasks

```bash
curl -b jar -X POST https://example.org/api/article/7/transition \
     -H 'Content-Type: application/json' -d '{"transition": "einreichen"}'
```

The only way to change the state. Every execution is checked completely, regardless of what the UI
offers: `401` without a session, `403` for a denied entity, `404` for entities without a workflow or unknown records,
`422` for an unknown label, `409 invalid_state` if the transition does not start from the current state, `403` if the
user has none of the groups/roles from `allowed=` (roles count directly and via groups; admins always). On success, in one
transaction: set the state, execute `action=`, set the open tasks of the record to `done`, create a new
task for `assign=`. Response: `{record, transition: {label, from, to}, task}`.

**Errors with a line reference:** if a schema error is caused by a specific line of the workflow file, the response
(`422 schema_error`, for "check" as well as for "apply") states it in structured form in addition to the message text: `workflow_line` (the
first, decisive line) and `workflow_lines` (all, without duplicates). This applies to structural errors of the file (the parser
aborts at the first one, so exactly one line), to missing groups/roles/users (all affected `allowed=`/`assign=`
lines in the order of the message) and to `action=set_visibility` without a `visibility` field of the class. Errors that concern the
whole file (e.g. a workflow used by the schema is missing) do not have the keys. System → Workflows
then puts the cursor at the start of the first line, brings it into the visible area and highlights all lines named
in red; the message at the top gets the button „Zeile n anzeigen“. The highlighting applies to the checked text and
disappears with the first input. The schema page is unchanged: errors of the main schema do not name a line.

Tasks are stored in the system table `lh_task` (`entity`, `record_id`, `transition_label`, `assigned_group_id`,
`assigned_user_id`, `created_by`, `created_at`, `status`); `GET /api/_tasks` (admin only, all tasks) is for
supervision. They disappear with their record and move along when a class is renamed. The class name `LH_Task` is therefore reserved (in any
spelling); a class `Tasks` in the diagram is allowed.

**My tasks:** Above the data tables, the sidebar has the entry „Meine Aufgaben“ (`#/_tasks`) for every role, with
a counter of the open tasks assigned to the user directly or via a group (without an open task no counter).
It is loaded at the start and updated after every own transition; transitions of other users appear on the
next load (no polling). The page lists per task the status, record (`<entity> #<id> – {title}`), transition,
executing user, time (date+time format of the display language) and „Zugewiesen an“ (to you directly / group …), newest
first, reversible via the column „Zeitpunkt“; „Auch erledigte anzeigen“ shows completed tasks greyed out with a ✓.
A click on a row opens the normal edit form, starting at the top. There, for entities with a workflow field,
the link „↓ Zu den Workflow-Aktionen“ is to the right of the heading; only this click scrolls to the workflow block.

In the create form the block „Workflow“ is a pure preview: chain and badge show the initial state, instead of
dropdown and button there is the hint „Der Datensatz startet nach dem Anlegen in diesem Zustand. Workflow-Aktionen stehen
erst nach dem Speichern zur Verfügung.“ After „Speichern und weiter“ the full block is there.

In the edit form there is a block „Workflow“. At the top the information: a progress chain with all states of the
`flow` path (passed ✓, current one highlighted, upcoming ones empty; `_schema` delivers `workflow.flow` for this) and the
current status as a badge. The chain only shows the position in the `flow` – transitions outside of it (e.g. a jump back)
are not a station; if the state itself is not on the `flow` path, no station is highlighted. Below it, set apart by
a line, the action: the dropdown „Nächster Schritt“ and the button, which gets the accent colour as soon as a
transition is chosen. The dropdown only shows
transitions the user may execute (`_schema` lists them with a session including `allowed=`, the own groups and roles
are delivered by `GET /api/_workflow/me`) – purely a pre-selection. Execution only happens with „Übergang ausführen“. Without the dropdown
one of two hints appears there: final state (no outgoing transition defined) or no transition allowed for the user.
