# Roles and permissions (phase 1: data model, enforcement, management API; phase 2: UI)

_Part of the [LessHeadCMS documentation](../README.md)._

For `role = 'redakteur'` (editor); `role = 'admin'` may always do everything (bypass, only this column is checked). Management is done in
the UI (phase 2) or directly via the API.

**UI (phase 2):** System → **Rechteverwaltung** (rights management) – one sidebar entry, one page with the tabs **Nutzer** |
**Rollen** | **Gruppen** (users, roles, groups). Every view can be linked directly, the tab follows
from the address: `#/_rights/users[/<id>]`, `#/_rights/roles[/<id>]`, `#/_rights/groups[/<id>]`. The former
addresses (`#/users/<id>`, `#/_roles/<id>`, `#/_groups/<id>`) redirect there. Only admins see roles and groups;
an editor with the system area `users` sees the tab Nutzer alone.

Roles and groups: list with a short form of the deviations
from the default, create, rename, delete with a prompt; the built-in role „Admin“ is only marked. In the
user list **„Rollen & Rechte“** per user (`#/_rights/users/<id>`: effective permissions from all sources, roles and
groups of the user, own permissions). All three use the same permission editor,
which only shows deviations and saves every change immediately:

* **Entities** (default allowed): expandable per entity, „Zugriff auf X“ (unticked = denied), below it the fields
  with the opposite meaning (ticked = restricted) and the counter „n von m Feldern eingeschränkt“.
* **Actions** per entity: „Anlegen verbieten“, „Bearbeiten verbieten“, „Löschen verbieten“ (ticked = forbidden, see
  "Action permissions" below; „verbieten“ instead of „einschränken“ because the action is dropped entirely – for fields and
  entities it stays „einschränken“). The origin here reads „Bereits verboten durch …“. If the entity is denied anyway, they stay operable, a
  hint says that they only take effect again once access exists.
* **System areas** (default denied), set apart by colour: ticked = „Zugriff gewähren“.
* **Origin:** what the owner already receives from another source carries a lock next to the checkbox; tooltip and
  `aria-label` name the sources („Bereits gesperrt durch Gruppe 'Testgruppe'“, with several „Bereits gesperrt durch:
  Rolle 'X', Gruppe 'Y'“, a role from a group as „Rolle 'X' (über Gruppe 'Y')“; for system areas also
  „Bereits freigegeben durch …“). The checkbox is then disabled because a row of its own would change nothing – unless
  there already is an own, now superfluous row: that can still be removed. For a **user**, their
  roles, their groups and the groups' roles count; for a **group** only its own roles (what else a member has
  is not known from the group's point of view); a **role** has no superordinate source. The target itself is compared:
  a denial on an abstract class appears at that class, not at its subclasses. The data comes from
  `GET /api/_permissions/inherited`.
* If the server rejects a change (required field, required relationship, required media field), its message appears directly at
  the checkbox, and the checkbox stays as it was.
* Only creatable via the API remain `allow` on entity/field (no effect) and an explicit denial (`deny`) on
  a system area; the UI shows such a denial („ausdrücklich gesperrt“) and can lift it.

The following description of the rules and the API applies unchanged; the UI only uses this API.

**Basic principle:** additive, **deny always wins**. Entities and fields are allowed by default – only deviations
are stored. System areas are **denied** by default and need an explicit `allow`:
`users` (user management), `media` (media library), `languages` (create/change/delete languages), `translations`
(translations including CSV), `testdata` (test data generator). Deliberately **not** a system area and reserved for admins:
schema editing (`/api/_schema/*`, diagram editor, backups/restore) and this management API.

> **Change compared to earlier versions:** The media library used to be open to any logged-in role. Now it is denied for editors
> (`403`, no sidebar entry, media fields in forms cannot open the media library) until `media` is granted.
> Everything else behaves as before for an editor without a role/group.

**Tables** (`Permissions`, created on the first call of the management API – no further bootstrap needed):
`roles` (`name`, `is_admin`; exactly one built-in role „Admin“, cannot be deleted/renamed, cannot be assigned),
`groups`, `group_roles`, `user_roles`, `user_groups` (m:n) and `permissions`: `owner_type` (`role`/`group`/`user`),
`owner_id`, `scope` (`entity`/`field`/`systable`/`action`), `effect` (`allow`/`deny`) and the target – for
`entity`/`field`/`action` `ref_id` = stable ID from `schema_ids`, **never** the name, for `systable` the identifier in
`systable_name`, for `action` additionally the column `action` (`create`/`update`/`delete`). A `permissions` table from
before the action permissions is rebuilt on the next call of the management API (rows and IDs stay).
An element renamed by migration keeps its ID and with it its denial; if it is removed, its
permissions disappear along with it (like layout positions and translations).

**Effective permission** of an editor: all rows from directly assigned roles, roles of their groups,
permissions of these groups themselves and permissions of the user themselves.

* `deny` on an **entity**: it is missing in `GET /api`, every route on it (`_schema`, list, single fetch, writing,
  column selection) answers `403`. n:n lists and optional FK columns of other entities that reference it
  disappear along with it; a required FK stays visible (otherwise no record could be created). That is why an
  entity cannot be denied as long as another entity has a required relationship (n:1 or 1:1 without `?`/`0..1`) to it:
  `422 required_relation`, the message and `relations` name the relationship(s), e.g. „Die Entität 'Rubrik' kann nicht
  gesperrt werden: 'Artikel.einsortiert_in_rubrik_id' ist eine Pflicht-Beziehung zu dieser Entität.“ – the same for role,
  group and user, for `POST` and `PUT`; for an abstract class, relationships to its subclasses count.
  Only what newly creates a denial is rejected: a `PUT` on a row that was already `entity`/`deny` on
  the same entity before (e.g. only a different owner) stays possible, even if a migration has added a
  required relationship in the meantime – this does not change the denial situation.
* `deny` on a **field** (`scope: field` – field, media field or relationship, i.e. FK column or n:n list): it is missing in
  `_schema` and in every row (key removed, not `null`); a value sent along in `POST`/`PUT` is discarded without
  an error, the stored value stays. `deny` on a required field or `id` is rejected (`422`). A field
  of an abstract class applies in all subclasses.
* **System area**: denied as soon as there is a `deny` anywhere; otherwise allowed if there is at least one `allow`.
  An **explicit** denial (`deny`) on `media` is rejected as long as the same owner has access to an entity
  with a required media field (no denial of its own on the entity): `422 required_media`, message and `fields` name the
  fields, e.g. „Der Systembereich 'media' kann nicht gesperrt werden: 'Artikel.bild' ist ein Pflicht-Medien-Feld, und
  diese Rolle hat Zugriff auf 'Artikel'.“ The default without `allow` is not affected by this. As with the
  entity denial, this only applies where the denial newly arises – a `PUT` on an existing `media` denial stays possible.
* **Action permissions** (`scope: action`, the target is the entity, plus `action`): `deny` on `create` rejects
  `POST /api/{entity}` with `403` – and with it also „Duplizieren“, which uses the same endpoint; `deny` on `update`
  rejects `PUT /api/{entity}/{id}`, `deny` on `delete` rejects `DELETE /api/{entity}/{id}`. Response:
  `{"error": "forbidden", "action": "delete", "message": "Keine Berechtigung zum Löschen in 'Produkt'"}`. **Reading is never
  affected** – that is still controlled only by entity and field denials. The default is allowed, `deny` wins across all
  sources, admins are exempt; on an abstract class the denial applies to all subclasses. The entity the call is aimed at
  is checked: rows that go along when deleting via `{cascade}` follow the rule in the
  diagram. On a completely denied entity an action permission is permitted but has no effect (everything is
  `403` there anyway); it applies again as soon as the entity denial is gone.
  **In the editorial UI** the affected buttons are disabled (not hidden) and state the reason as a
  tooltip and in the `aria-label`, e.g. „Keine Berechtigung zum Löschen in 'Produkt'.“: with a forbidden `create` „Neu
  anlegen“ and „Duplizieren“ in the list as well as all save buttons in the create form, with `delete` „Löschen“
  per row, with `update` the save buttons in the edit form (the form stays readable). Above such a form
  (editing, creating, duplicating) there is additionally a warning notice, e.g. „Keine Berechtigung zum Bearbeiten von
  'Produkt'. Sie können die Inhalte einsehen, aber nicht speichern.“ Likewise disabled is the "+" next to a
  relationship (inline creation) if `create` is forbidden in the **target entity**; the magnifier for selecting existing
  rows stays usable. If the inline dialog opens nevertheless, it shows the same notice and an inactive
  „Anlegen“ button. For this, list and form query
  `GET /api/_permissions/me` on loading. This is only an operating aid – the
  server's rejection applies unchanged.
* `allow` on entity/field/action can be stored but changes nothing (the default is allowed, it does not lift a `deny`).

**Schema changes and existing denials:** „Änderungen prüfen“ (`/api/_schema/analyze`) reports an entry with
`severity: "permission"` (displayed as „Berechtigungs-Konflikt“, `kind: "permission_conflict"`) if a denied field,
media field or a denied relationship becomes required, or if a required relationship to a denied entity
arises (new or optional → required). The text names the owners of the denial (`Rolle '…'`, `Gruppe '…'`, `Nutzer '…'`);
affected users could no longer create records afterwards. It is purely a notice: nothing is blocked, nothing
has to be confirmed. Owners for whom the denial changes nothing are not named (administrators; whoever has denied the
entity itself).

The same applies to **required media fields and the system area `media`**: if a media field becomes required or is
newly created as a required field (inherited ones too – one entry per subclass), a „Berechtigungs-Konflikt“ names who cannot fill it for lack of
`media`. Affected is every active non-admin who effectively has no `media` and has not denied the
entity. Named are groups and roles that do not grant `media` themselves (a group not via its
roles either) – unless they have members and all of them get `media` from elsewhere – as well as affected users who belong to none
of the named groups or roles (e.g. editors without any role). More than 20 names are abbreviated.

The **public API without a session** stays unchanged: it still delivers all entities and fields. The denials
concern logged-in editors (reading and writing), so they are not a secrecy protection against the public `GET`.

**Management API** (`role = admin` only):

| Route | |
|---|---|
| `GET/POST /api/_roles`, `PUT/DELETE /api/_roles/{id}` | body `{name}`; built-in role → `409 builtin_role` |
| `GET/POST /api/_groups`, `PUT/DELETE /api/_groups/{id}` | body `{name}`; response with `role_ids`, `user_ids` |
| `PUT /api/_groups/{id}/roles` | `{role_ids: [...]}` – replaces the list |
| `PUT /api/_users/{id}/roles`, `PUT /api/_users/{id}/groups` | `{role_ids}` or `{group_ids}` – replaces the list |
| `GET /api/_users/{id}/assignments`, `GET /api/_users/{id}/permissions` | assignments or effective permissions |
| `GET /api/_permissions[?owner_type=&owner_id=]` | all rows, each with a readable `target` (current name) |
| `POST /api/_permissions`, `PUT/DELETE /api/_permissions/{id}` | `{owner_type, owner_id, scope, effect}` + `ref_id` **or** `ref` (readable key, e.g. `field:produkt.internal_cost`, is resolved into the ID immediately) or `systable_name`; for `scope: action` the entity as the target and `action` (`create`/`update`/`delete`) |
| `GET /api/_permissions/targets` | system areas, actions (`actions`) and all elements of the active schema with `ref_id`, `ref`, `required`, `lockable` |
| `GET /api/_permissions/inherited?owner_type=&owner_id=` | what a user or a group receives from other sources: per target and effect one row with `sources` (`{type, id, name, via}`); empty for a role |
| `GET /api/_permissions/me` | any role: own effective permissions (`denied_entities`, `denied_fields`, `denied_actions`, `systables`) |

```bash
# create a role with a denied field and assign it (cookie jar of a logged-in admin)
curl -b jar -X POST .../api/_roles -d '{"name":"Nur Lesen Produkte"}'                      # -> {"id":2,...}
curl -b jar -X POST .../api/_permissions \
  -d '{"owner_type":"role","owner_id":2,"scope":"field","ref":"field:produkt.internal_cost","effect":"deny"}'
curl -b jar -X PUT .../api/_users/3/roles -d '{"role_ids":[2]}'
curl -b jar -X POST .../api/_permissions \
  -d '{"owner_type":"role","owner_id":2,"scope":"systable","systable_name":"media","effect":"allow"}'
```
