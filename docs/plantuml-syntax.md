# PlantUML rules

_Part of the [LessHeadCMS documentation](../README.md)._

* `class Artikel { + titel: string }` → table `artikel`, column `titel`.
* Types: `string`→`VARCHAR(255)`, `text`/`richtext`→`TEXT`, `int`→`INTEGER`, `decimal`→`REAL`, `bool`→`BOOLEAN`, `date`→`DATE`,
  `visibility`→`BOOLEAN` (see below), plus every `enum` name declared in the diagram (see below). Other types are a
  schema error („Unbekannter Typ …“).
* **`enum` blocks** (PlantUML standard) declare a named value range, fields reference it as a type via the
  name – any number of fields in any number of classes:
  ```
  enum Status {
    OFFEN
    VERSANDT
    STORNIERT
  }
  class Bestellung {
    + status: Status {title}
  }
  class Lieferung {
    + status: Status?
  }
  ```
  One value per line (letters, digits, `_`, not starting with a digit; empty lines, `'` comments and separator lines
  like `--` are skipped), the order in the block is the order in the dropdown. As for the
  base types, the type name is case-insensitive (`status: status` works too), the values are not. Stored as
  `VARCHAR(255)` with `CHECK ("status" IN ('OFFEN', 'VERSANDT', 'STORNIERT'))`, so direct SQL cannot write any
  other value either (`NULL` stays allowed for optional fields). The API only accepts exactly one of the values, otherwise
  `422` with `errors.status = "Ungültiger Wert für 'status'. Erlaubt: OFFEN, VERSANDT, STORNIERT."`. `_schema` reports
  `"type": "enum"` plus `"enum_values": ["OFFEN", "VERSANDT", "STORNIERT"]`; the form shows a `<select>` with the
  values in raw spelling (optional ones with a selectable empty option „– bitte wählen –“; for required ones only a non-selectable
  placeholder as long as nothing has been chosen yet). `?`, `{title}` and `{unique}` as for any other field. Test data: a
  random value from the list. **Schema errors:** invalid enum name (`enum 9Status`), enum name equal to a base type
  (`enum String`) or a class, two enums of the same name, block without values (`enum Status {}` or `enum Status`
  without a block), invalid value, duplicate value (also `Offen`/`OFFEN`), block not closed with `}`. An
  enum that is declared but not used by any field is **not** an error: it discards nothing that is in the diagram (it
  stays in the stored model under `enums`) and is a normal intermediate state when working on a diagram –
  unlike the silently swallowed constructs that the project otherwise reports as errors.
* **`media`** (reference to the media library, `+ galerie: media`, optionally `+ anhang: media?`; type name case-insensitive
  like the base types): always an **ordered list** of media – "just one image" is simply a
  list with one element, there is no separate single-image syntax. Structurally like n:n, only to the built-in
  system table `media` instead of to a diagram class: no column in the table of the class but the
  link table `<table>_<field>_media` (e.g. `kurs_galerie_media`) with `kurs_id`, `media_id` and `position`
  (order), `PRIMARY KEY (kurs_id, media_id)` (a medium at most once per list), `ON DELETE CASCADE` on the
  row and an FK without cascade on `media` (a linked medium cannot be deleted). Without `?` at least
  one medium must be linked (`422` „Pflichtfeld: mindestens ein Medium auswählen“), with `?` the list may be empty.
  `_schema` reports the fields in a key of their own `"media": [{"name": "galerie", "type": "media", "required":
  true}]` – **only** for entities with a media field, the `_schema` of all others stays unchanged. The API delivers per
  field a list of media objects (see [REST API](rest-api.md)) and accepts IDs when writing. The field is inherited like any other
  (`extends`) and checked for name collisions (field of the same name, FK column, n:n list). **Schema errors:**
  `{title}` or `{unique}` on a media field, `id: media`, a class `Media`/`Media_Tags`/`Media_Tag_Links`
  (reserved), `enum Media` (name of the base type), a link table that coincides with a class, an n:n link table
  or that of another media field (e.g. class `Kurs_Galerie_Media` next to `Kurs.galerie`). Existing
  diagrams without `media` yield the same tables and the same `_schema`, unchanged.
* **`decimal`** (floating point numbers, e.g. amounts of money, `+ preis: decimal`): API and form accept integers and
  decimal numbers (decimal point), `_schema` reports `"type": "decimal"`. Form field: `<input type="number" step="0.01">`
  – two decimal places as the default for the main case, an amount of money (deliberately not `step="any"`: this way the browser points out
  more than two decimal places on submit instead of saving them unnoticed). Same `?` syntax as any
  other type (`decimal?`). **Precision:** storage is SQLite `REAL` (IEEE 754 double) – unproblematic for individual
  field values and their display (rounding errors far below cent precision).
  If `decimal` is later aggregated/summed directly in SQL (e.g. `SUM(einzelpreis *
  menge)` over many rows), floating point rounding errors can add up – then storing
  integer cents (e.g. `18099` instead of `180.99`) would be more robust. For the current MVP scope (individual
  field values, no server-side aggregation) `REAL` is deliberately sufficient.
* `id` → `INTEGER PRIMARY KEY AUTOINCREMENT`; if it is missing, it is added. If it is given, then only as `id: int`
  (another type or `id: int?` is a schema error instead of being silently skipped).
* **Required fields:** without an addition a field is required (`NOT NULL`, the API validates), a `?` directly after the type makes it
  optional: `+ titel: string` (required), `+ untertitel: string?` (optional). Applies to all types (`text?`, `richtext?`, `int?`, `bool?`, `date?`).
  For `bool`, "required" only means that the value must be sent (`false` is valid).
* **`visibility`** (published/draft, e.g. `+ veroeffentlicht: visibility`): stored like `bool`, but `_schema` reports
  `"type": "visibility"`; the UI shows a normal checkbox. The same `?` syntax applies (`visibility?`). When required, the
  default value is `false` (`BOOLEAN NOT NULL DEFAULT 0`; the API also sets `false` if the field is missing on creation), so a new
  record is never public by accident. **At most one `visibility` field per class**, otherwise a schema error
  („Entität X hat mehrere visibility-Felder, erlaubt ist maximal eines“). Effect on the API: see [REST API](rest-api.md).
* `A "n" --> "1" B : label` → foreign key in `a` (`NOT NULL`; optional with `"0..1"`). Whether the FK is required depends only
  on the multiplicity, not on the `?` syntax. `A "1" <-- "n" B` is recognized as well: the FK belongs to the n side.
  On the n side `n`, `0..*`, `*`, `m`, `0..n` are allowed, as well as the **minimum count** `"1..*"`/`"1..n"` (see
  next item).
* **Minimum count on n:1** (`Position "1..*" --> "1" Rechnung`, likewise `1..n`, with `"0..1"`, written backwards and
  combined with `{cascade}`/`{unique}`): "every invoice has at least one line item". This is a **soft rule**: it cannot be
  enforced on creation because the invoice always exists before its line items – directly after creation it
  inevitably has none. The tables are therefore exactly the same as with `n` (no additional `NOT NULL`, no `CHECK`).
  Instead:
  * **Delete protection:** the last remaining line item of an invoice cannot be deleted → `409 min_required`
    („Kann nicht gelöscht werden: 'Rechnung #5' benötigt mindestens eine Zeile in 'Position' (Mindestanzahl).“). If the
    invoice has several line items, each of them can be deleted normally. If the invoice itself is deleted (with `{cascade}`
    including its line items), the rule no longer applies.
  * **Warning:** invoices without a line item are marked with ⚠ in the list (tooltip „Noch kein Datensatz in
    „Position“ …“), the edit form shows a notice above the fields. None of this blocks saving.
  * **API:** see [Minimum count](rest-api.md) under [REST API](rest-api.md) (`min_required`, `relations`, `_min_warnings`).
  * **Not protected** is reassigning: whoever assigns the only line item to another invoice by editing (or
    clears the assignment for an optional target) leaves an invoice without a line item – it is then marked again.
  * **Self-reference** (`A "1..*" --> "0..1" A`) is allowed but rarely useful: every row without a sub-row is
    marked, and the last sub-row of a row can only be deleted together with it (`{cascade}`).
  * In an existing installation the minimum count can be switched on and off via schema editing
    (`n` ↔ `1..*`); only the stored model changes in the process, no table is rebuilt.
  On n:n a minimum count remains a schema error (see below).
  **Column name:** without a label `b_id` (target table); **with a label** `<label>_b_id` (label + target table concatenated,
  e.g. `A "n" --> "1" B : Lieferadresse` → `lieferadresse_b_id`). For this the label is lower-cased, umlauts are normalized
  to base letters (ö→o, not "oe") and everything except `a-z0-9` becomes `_`. This deliberately applies even when it
  creates redundancy (`lieferadresse_adresse_id`) – in favour of uniqueness, no special rule for shortening.
  **Two relations from A to the same class B are allowed** as long as they lead to different column names
  (i.e. usually have different labels) – e.g. `Bestellung "n" --> "1" Adresse : Lieferadresse` and
  `Bestellung "n" --> "1" Adresse : Rechnungsadresse` → `lieferadresse_adresse_id` / `rechnungsadresse_adresse_id`.
  If two relations of the same class nevertheless lead to the same column name (two unlabelled relations to the
  same target table, or an identical label to the same target table), the parser aborts with an error message
  (no silent fallback, no overwriting) – each must get a unique label of its own.
* **Delete behaviour, `{cascade}` marker** at the end of an n:1 relation, e.g.
  `Bestellposition "n" --> "1" Bestellung : gehört zu {cascade}` (without a label: `A "n" --> "1" B {cascade}`):
  * **Without the marker (default): restrict.** As long as rows still reference a row via FK, `DELETE` rejects it with
    `409` and states the count per table: „Kann nicht gelöscht werden: 3 abhängige Zeile(n) in 'produkt'.“
  * **With `{cascade}`:** deleting the parent row deletes all dependent rows along with it, recursively (also their children with
    `{cascade}`), likewise for self-references (`Kategorie "n" --> "0..1" Kategorie : Unterkategorie von {cascade}` →
    deleting a category deletes all subcategories, to any depth). If a row that would be deleted along
    references a row outside via a restrict FK in turn, **nothing** is deleted (`409`, all or nothing).
  * The marker is not part of the label (column name and `_schema` `label` stay the same). On n:n `{cascade}` is a
    schema error (links are always deleted along there anyway), as is any other `{...}` marker on a relation.
  * The marker changes the text of the `.puml` and thus the hash → `migration_needed` on an existing database
    (see [Important notes](deployment.md#important-notes)). Upload the code **before** a `.puml` with `{cascade}`: the old parser otherwise
    silently takes `{cascade}` into the label and thus into the column name.
* **`{label}` marker** at the end of an n:n relation (`A "n" -- "n" B : interessiert an {label}`): show the label in the
  UI instead of the table name (see "n:n label" under [REST API](rest-api.md)). Schema errors: `{label}` without a
  label before it (`A "n" -- "n" B {label}` – the marker explicitly requires a label, a silent fallback would hide a
  forgotten text), `{label}` on an n:1 relation (the label is always displayed there anyway) and
  `{cascade}` together with `{label}` on one line (`{cascade}` is only possible on n:1, `{label}` only on n:n; in addition
  `{unique}` or `{symmetric}` may appear at most once, see below). Like every diagram change, adding `{label}` changes the
  hash → `migration_needed` on existing installations.
* **`{unique}` marker** (composite uniqueness) on fields (`+ code: string {unique}`, combinable with
  `{title}` in any order) and at the end of n:1 relations (`Bestellposition "n" --> "1" Variante {unique}`,
  combinable with `{cascade}`: `… : gehört zu {cascade} {unique}`). **All** columns of a class marked this way together form
  **one** rule: this combination may occur only once. Example: `{unique}` on the relations
  `Bestellposition → Bestellung` and `Bestellposition → Variante` → the same variant at most once per order;
  a ternary assignment (student, course, teacher) accordingly with three marked relations. A single marked
  column yields simple uniqueness. Implemented as a `UNIQUE (…)` constraint in the table; the API checks beforehand itself
  and answers `POST`/`PUT` with `409` (see [REST API](rest-api.md)). If the combination contains `NULL` (optional field or
  optional FK empty), it never counts as a duplicate, as in SQL. **Deliberate restriction:** per class there is exactly one
  `{unique}` group; several independent rules in one class (e.g. `code` unique **and** separately `name`
  unique) cannot be expressed at present – if you mark both, the rule "combination of `code` and
  `name`" results. Schema errors: `{unique}` on an n:n relation (the link table is unique per pair anyway), on the field
  `id` (unique anyway) and twice on one line. Example with a field and a self-reference in one group:
  [Multilingual data content (modelling pattern)](multilingual.md#multilingual-data-content-modelling-pattern).
* `A "n" -- "n" B : label` → link table `a_b` (`a_id`, `b_id`, `ON DELETE CASCADE`: deleting one of the two
  rows only removes the link, it never blocks), API list `b_ids`. With **one** n:n relationship from A to B
  these names do not depend on the label.
* **Several n:n relationships from A to the same class B** (`Artikel "n" -- "n" Tag : Haupttags` and
  `Artikel "n" -- "n" Tag : Zusatztags`): every labelled relationship of this pair gets names from its label, following
  the same pattern as the FK columns (label normalized, then target table): link table `artikel_haupttags_tag` /
  `artikel_zusatztags_tag`, API list `haupttags_tag_ids` / `zusatztags_tag_ids`. An unlabelled relationship in the
  same pair keeps `artikel_tag` / `tag_ids`. Two unlabelled or identically labelled relationships (also "identical
  after normalizing", e.g. `Haupt-Tags` and `haupt tags`) are a schema error („Zwischentabelle … würde doppelt
  entstehen“). **Why only for multiple relationships:** if every n:n label determined the name, table and API field of all
  existing diagrams with a labelled n:n (`Artikel "n" -- "n" Tag : hat`) would suddenly be called
  `artikel_hat_tag` / `hat_tag_ids`. Consequence of the rule: if a second n:n to the same target is added to an existing
  labelled one, the first one is renamed as well (`migration_needed` anyway with a diagram change). `{label}`
  stays independent of this and only controls the display: without `{label}` both selections are called „Tag“ in the UI,
  so set `{label}` for multiple relationships.
* Names: only `A–Z a–z 0–9 _`, not starting with a digit (no umlauts in class and field names). An
  invalid class name (e.g. `class 9Leben { … }`) is a schema error „Ungültiger Klassenname“. Labels may
  contain umlauts.
* **Self-reference** (`Kategorie "n" --> "0..1" Kategorie : Unterkategorie von`, likewise e.g. `Mitarbeiter … : Vorgesetzter von`):
  a relation of a class to itself, column name like any other relation (see above) – i.e. with a label
  `unterkategorie_von_kategorie_id`, without a label `kategorie_id`. Works the same for every class; nothing is tied to
  a name. **Several self-references per class** are possible as long as they have different labels
  (one column each, e.g. `Kategorie : Unterkategorie von` and `Kategorie : verwandt mit` →
  `unterkategorie_von_kategorie_id` / `verwandt_mit_kategorie_id`); two without a label or with the same label collide like
  any other duplicate relation (see above). Cycle protection: see [Editorial UI](editorial-ui.md) / [REST API](rest-api.md).
  A practical application of self-reference, `{unique}` and `{cascade}` together: language versions of a record, see
  [Multilingual data content (modelling pattern)](multilingual.md#multilingual-data-content-modelling-pattern).
* **`{filter_by:field}` on an n:1, 1:1 or n:n relation** (`Artikel "n" -- "n" Tag : hat {filter_by:sprache}`):
  filtered selection in the form (see [Editorial UI](editorial-ui.md)). The source is the class in whose form the selection is made
  (the one with the FK column or, for n:n, the one named first), the target the class whose rows are offered for selection.
  * If the fields have different names: `{filter_by:sourcefield=targetfield}`, e.g. `{filter_by:sprache=lang}`.
  * Combinable with `{cascade}`, `{unique}`, `{label}`, `{symmetric}`, `{renamed_from:…}` and the minimum count; at most
    once per relation. Also on self-references and with inherited fields.
  * Tables and API fields stay the same as without the marker; `_schema` reports it as
    `"filter_by": {"field": "sprache", "target_field": "sprache"}` on the `foreign_key` or on the `many_to_many` entry (only
    if set). In an existing installation it can be set and removed via schema editing – only
    the stored model changes.
  * **Schema errors** (bootstrap and „Änderungen prüfen“):
    * Field missing: „{filter_by:slug} an der Relation 'Artikel' -- 'Tag': Klasse 'Tag' hat kein Feld 'slug' (Quell- und
      Zielklasse brauchen beide das Filterfeld; id, Beziehungs-Spalten und Medien-Felder sind dafür nicht möglich).“
    * Types differ: „… Die Filterfelder haben unterschiedliche Typen ('Artikel.sprache': Enum Sprache, 'Tag.name':
      string) - beide brauchen denselben Typ (bei Enum denselben Enum).“ Required/optional (`?`) may differ.
    * Value not understood (`{filter_by}`, `{filter_by:}`, `{filter_by:a b}`, `{filter_by:a=}`): „… nicht verständlich
      (erwartet: {filter_by:feld} oder {filter_by:quellfeld=zielfeld}).“
    * Given twice: „Nur eine Markierung pro Relation möglich …“.
* **1:1 relations** (both sides `"1"` or `"0..1"`, also `"1..1"`), e.g. `Profil "1" --> "1" Nutzer`: FK column
  (`nutzer_id`, name following the same rules as for n:1, i.e. with a label `<label>_nutzer_id`) in the class **named first**;
  with an arrow pointing left (`Nutzer "1" <-- "1" Profil`) in the class at the tail of the arrow, so the arrow
  always points at the target. The column is **automatically unique** (own `UNIQUE` constraint, without `{unique}`, independent
  of a `{unique}` group of the class): every Nutzer row assigned at most once; violation → `409` (see
  REST API). **Required/optional as for n:1 according to the multiplicity on the target side:** `Profil "…" --> "1" Nutzer` →
  `NOT NULL` (every profile has exactly one user), `Profil "…" --> "0..1" Nutzer` → optional. The multiplicity on the
  column-carrying side (`"1"` or `"0..1"` before `Profil`) says how many profiles a user has; "at most one"
  is enforced by the `UNIQUE` constraint, "at least one" (`"1"`) cannot be enforced from the Nutzer table and
  technically acts like `"0..1"`. `{cascade}` and `{unique}` are possible as for n:1,
  `{label}` is not. **1:1 self-reference:** allowed when optional (`Aufgabe "0..1" --> "0..1" Aufgabe : Nachfolger von`
  yields a chain, every row has at most one predecessor and is a predecessor at most once; cycle protection as for
  every self-reference); with a required target side (`A "…" --> "1" A`) it is a **schema error** because every row would need
  another, still free row and even the first one could never be created.
* **n:n self-reference** (`Nutzer "n" -- "n" Nutzer : folgt`), **directed** by default: "A follows B" and "B follows A"
  are two independent entries. The label is required (without a label the schema error „n:n-Selbstreferenz … braucht ein
  Label“), because the names are built from it, following the same pattern as FK columns and multiple n:n:
  link table `nutzer_folgt_nutzer` with the columns `nutzer_id` (the row whose list it is: "who follows") and
  `folgt_nutzer_id` (the linked row: "who is followed"), API list `folgt_nutzer_ids`. The list of a row
  (form, list view, API) shows **only the outgoing direction** ("whom I follow"). The opposite direction ("who follows
  me") is deliberately not part of it; it can be added later as a view of its own, the data for it is available in the
  link table.
  **`{symmetric}`** (`Nutzer "n" -- "n" Nutzer : befreundet mit {symmetric}`): undirected, "A is friends with B" ⇔ "B
  is friends with A". Every pair is stored **exactly once**, canonically with the smaller ID in `nutzer_id` (database:
  `CHECK ("nutzer_id" < "befreundet_mit_nutzer_id")` + primary key). The list of a row contains all pairs in
  which it is on either side; on saving all its pairs are replaced. The same pair once more
  (from A or from B, also twice in one list) is therefore not a second entry but stays one (the lists
  have set semantics, hence no `409`). `{symmetric}` can be combined with `{label}` (any order).
  **Both variants:** a row cannot be linked to itself → `422` with
  `errors.<list> = "Ein Datensatz kann nicht mit sich selbst verknüpft werden"` (a data error, not a schema error;
  the link table additionally has a `CHECK`); the form does not offer the edited row in the first place.
  Several n:n self-references per class need different labels. Schema errors: `{symmetric}` on anything but
  an n:n self-reference (n:n to another class, n:1, 1:1), `{symmetric}` twice. When a row is deleted,
  its links disappear in both directions (as with every n:n).
* **Inheritance = field copy mechanism** (not a database concept: no shared table, no polymorphism):
  ```
  abstract class Basisinhalt {
    + titel: string {title}
    + inhalt: richtext
    + veroeffentlicht: visibility
    + erstellt_am: date?
  }
  class Beitrag extends Basisinhalt {
    + kategorie: string
  }
  class Seite extends Basisinhalt {
  }
  ```
  `abstract class` is a pure template **without a table of its own** (no API, no sidebar entry). Note: until
  2026-09-30 an `abstract class` got a normal table. `class B extends A` first gets all fields of A (in
  A's order), then its own, exactly as if they were written directly in B: `?`, `{title}`, `{unique}` and
  enum types apply unchanged, `title_field`/`unique_fields` start with the inherited fields. `_schema` and
  UI know nothing about inheritance, B is a perfectly normal entity. A change to A takes effect on all subclasses at the
  next bootstrap. Nesting works too (`abstract class Mitte extends Basis`, `class B extends Mitte`: B
  gets the Basis fields, then the Mitte fields, then its own). The order of the declarations in the diagram does not matter. An
  abstract class without a subclass is not an error (like an unused `enum`). **Schema errors:** extends on a
  non-abstract class (it would otherwise have to be table and template at once) or on an unknown class, several
  base classes (`extends A, B`, `extends A extends B`), anything else after the base name (`extends A implements I`),
  `extends` without a name, cyclic inheritance, an own field with the same name as an inherited one (case-insensitive,
  the message states the origin), two `visibility` fields after copying, a relation from or to an
  abstract class („Beziehung auf abstrakte Klasse 'Basisinhalt' nicht möglich …“; relations are not inherited,
  they belong on every subclass), an extends line without `{` on the same line (the class would otherwise silently
  disappear). Only the keyword `extends` is supported, PlantUML arrows like `B --|> A` remain an error
  („Relation nicht verständlich“). The `id` stays a single column at the front.
* **Further unsupported constructs are schema errors** (instead of being silently discarded as before):
  * Stereotype on a class (`class Kunde <<Entity>> {`, any stereotype, also on `abstract class`): „Stereotypen
    ('<<...>>') werden aktuell nicht unterstützt …“ – remove the stereotype. On `package` and `enum` it stays allowed.
  * `implements` (`class B implements A {`): take the fields of `A` directly into `B` or use `extends` with an
    `abstract class`.
  * `interface X { … }`: message right at the interface line – use a normal class instead.
  * Class line without a body (`class X` alone): „Klassenzeile 'X' ohne Körper ('{ ... }') nicht verständlich …“; likewise
    every other class line whose body cannot be recognized (`class X {}` on one line, closing `}` missing or not
    standing alone): „Klassenzeile nicht verständlich …“.
  * Minimum count on n:n (`A "1..*" -- "1..*" B`, likewise `1..n`): only `n`/`0..*` (or `*`, `m`, `0..n`) allowed.
    On the n side of an n:1 relationship, in contrast, the minimum count has been supported since 2026-10-03 (soft rule, see
    above); before that it was a schema error, until 2026-10-01 it was silently read like `n`.
  * `id` with a type other than `int` or with `?` (see above).
  * A field named like the generated n:n list (`A` has `+ b_ids: …` and `A "n" -- "n" B`): the API
    could not tell the two apart; rename the field.
* **`package` blocks** (PlantUML standard) group classes in the sidebar of the editorial UI:
  ```
  package "Katalog" {
    class Produkt {
      + name: string
    }
  }
  ```
  Purely a display grouping (`_schema.package`), no structural separation: table, column and
  link table names do not change, class names must still be unique project-wide. Names in
  quotes may contain spaces/umlauts; `as Alias`, `<<Stereotype>>` and `#colour` are allowed.
  Relations and `skinparam`/`together` blocks may be inside the package. Several blocks with the same name result in
  one group. Classes outside any package: `package = null` („Sonstige“). **Schema errors:** nested
  packages, package without a name, package not closed, `package` line without `{` (e.g. `package X … end package`).
* **`{title}` marker** (`+ status: string {title}`, directly after type and optional `?`): determines which field(s)
  serve as the display label of a row (FK select options, n:n list, list view – see "Display label
  of referenced rows" in the [Editorial UI](editorial-ui.md)) instead of the automatic guessing heuristic (prefers `titel`/`title`/`name`/`bezeichnung`/
  `label`, otherwise the first `string` field, otherwise `id`). **Without** `{title}` in the class: unchanged
  heuristic behaviour, existing diagrams need no adaptation. **With at least one** `{title}` field in the
  class: the heuristic is switched off completely for this class, only the marked fields count. Several
  `{title}` fields are combined comma-separated in diagram order (`+ bestelldatum: date {title}` +
  `+ status: string {title}` → "2026-09-23, verpackt"); if a single marked field is missing/empty for a concrete
  row, only that one is skipped (no empty comma). Only allowed for `string`, `int`, `date`, `decimal` and enum types – on
  `text`, `richtext`, `bool`, `visibility` or on the `id` line `{title}` is a **schema error** – not silently
  ignored, consistent with how the parser reacts to schema errors elsewhere (e.g. unknown type, duplicate
  FK column names).
