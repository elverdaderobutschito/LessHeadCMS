# Field rules (validation)

_Part of the [LessHeadCMS documentation](../README.md)._

The diagram can carry rules that the system checks and enforces before every save: length limits, value ranges and
named patterns – e.g. a teaser that always fits into 140 characters. The model is the configuration: the rules stand in
the PlantUML diagram, the regular expressions behind the patterns live in the pattern library of the admin area.

## Syntax

Rules use the same marker notation as `{title}` and `{unique}`, at the end of a field line, in any order and combinable
with all other field markers:

```plantuml
class Article {
  + title: string {title} {maxLength: 80}
  + teaser: string? {maxLength: 140}
  + code: string {minLength: 3} {maxLength: 12}
  + zip: string? {pattern: plz}
  + servings: int {min: 1, max: 20}
  + price: decimal? {min: 0.5} {max: 999.99}
  + publishedAt: date {min: 2020-01-01}
}
```

Several rules may share one marker, separated by commas (`{min: 1, max: 20}` is the same as `{min: 1} {max: 20}`); the
space after the colon is optional and rule names are case-insensitive. The PlantUML export (and the diagram editor)
always writes one marker per rule in the order `minLength`, `maxLength`, `min`, `max`, `pattern`.

| Rule | Field types | Value |
|------|-------------|-------|
| `minLength` | `string`, `text`, `richtext` | whole number ≥ 0 |
| `maxLength` | `string`, `text`, `richtext` | whole number ≥ 1 |
| `min`, `max` | `int`, `decimal`, `date` | `int`: whole number; `decimal`: number with a decimal point (`0.5`); `date`: `YYYY-MM-DD`. Limits are inclusive |
| `pattern` | `string` | name of a pattern of the [pattern library](#pattern-library) – never a regex |

* Lengths count **characters** (Unicode code points, `mb_strlen`), not bytes: `ä` and `😀` count as one character each.
* For `richtext` the **visible plain text** counts, not the Markdown syntax (`**bold** [link](https://…)` is
  `bold link`, 9 characters) – the same conversion the list view uses for filtering and sorting.
* Empty optional fields are never checked against any rule (`minLength: 3` does not make an optional field required).
  Whether a field must be filled stays the business of `?` (required).
* The rules change no table (no `CHECK` constraint): they are part of the model only, existing data is never blocked by
  the database.

**Schema errors** (the analysis and `/bootstrap` reject the diagram with a clear message, nothing is silently ignored):

* a rule that does not fit the type (`maxLength` on `int`, `min` on `string`, `pattern` on `text`, any rule on `bool`,
  `visibility`, enum, workflow or media fields),
* any rule on the `id`,
* an unknown rule (`{format: x}`), the same rule twice,
* an invalid value (`{maxLength: 0}`, `{min: 1.5}` on `int`, `{min: 2023-02-30}`, `{min: 1,5}`),
* `minLength` greater than `maxLength`, `min` greater than `max`,
* a `pattern` name that does not exist in the pattern library.

In the model JSON (visual schema editor, `POST /api/_schema/model`) every field with rules carries
`"rules": [{"id", "type", "value"}]`; the `id` is the stable ID of the field plus the rule type, so the editor and the
`.puml` exporter pass the rules through unchanged (round trip `.puml` → model JSON → `.puml`).

## Pattern library

System → Einstellungen → **Patterns** (admins only). A pattern has

* a **name** – the technical identifier written in the diagram (`{pattern: plz}`): lower-case letter first, then
  lower-case letters, digits and `_`,
* a **regex** (PCRE) – without delimiters and anchors; it always has to match the **whole** value (internally
  `\A(?:…)\z` with the modifier `u`). Inline options such as `(?i)` are possible,
* a **message** for the editors (shown at the field when a value does not match),
* optionally an **example value** – placeholder in the form and value for the test data generator; it has to match.

Built-in patterns (read-only): `email` (example value `name@example.org`), `url` (`https://example.org`), `slug`
(`my-article`), `plz` (German postcode, five digits, `10115`). They cannot be changed or
deleted, and their names cannot be taken. Whoever needs a variant chooses „Als Vorlage“ and saves it under an own name –
an update of LessHeadCMS therefore never overwrites anything the admin has adapted.

**Messages: system texts vs. content.** The messages of the built-in patterns belong to the product, not to the admin.
They are system texts like every other server message: the API sends them in English with a code, the editorial UI shows
them in the display language of the user (list of patterns, form, rule violations).

| Pattern | Code | English (API, `translations/en.csv`) |
|---|---|---|
| `email` | `msg_patterns.builtin_email` | Please enter a valid email address. |
| `url` | `msg_patterns.builtin_url` | Please enter a valid URL. |
| `slug` | `msg_patterns.builtin_slug` | Only lowercase letters, digits and hyphens. |
| `plz` | `msg_patterns.builtin_plz` | Please enter a five-digit German postal code. |

The German texts are among the fixed UI texts (group „Servermeldungen: Patterns“); like all of them they are translated
under Lokalisierung → Übersetzungen → „Feste UI-Texte“. The messages of **own** patterns are content: they are shown in the
language the admin wrote them in, or in a translation under „Pattern-Meldungen“. A translation the admin has entered
there for a built-in pattern takes precedence over its system text (in the display language, otherwise as override of
the default language).

Installations from before this change: the built-in patterns always lived in the code, so there is nothing to migrate. A
row under the name of a built-in pattern in `lh_pattern` (cannot be created via the API) is ignored – the built-in
pattern with its system text always applies.

Installations that imported the `translations/en.csv` of the previous version have its four `pattern` lines for `email`,
`url`, `slug`, `plz` stored as translations; they would hide the system text. On the first read of pattern messages after
the update they are removed once – only rows whose text is **word for word** the shipped one (in whatever language they
were imported into), i.e. never adapted by the admin. A different text stays a real translation and keeps precedence.
The removal is written to the PHP error log and remembered in `_meta` (`shipped_pattern_texts_removed`), so it runs only
once; with a read-only database nothing happens (no error) and the next request with write access does it.

* Saving checks the regex syntax; an invalid regex is not saved. The **test field** checks a value against the regex
  (match / no match) exactly as the server does when saving.
* **ReDoS protection:** every match runs with a low PCRE backtracking and recursion limit. Catastrophic backtracking
  (e.g. `(a|a)+` against a long value) aborts at the limit within milliseconds; a PCRE error never counts as a match –
  the value counts as invalid („Das Format konnte nicht geprüft werden …“) and the request never hangs.
* **Load test when saving:** JavaScript has no such limit – a regex like `(a|a)+` would freeze an editor's browser tab
  at about 30 × `a` followed by `b`. Therefore a new or changed regex is run against generated values that typically
  trigger backtracking traps (long repetitions of characters and literals from the regex itself, each ending with a
  non-matching character, in several lengths). If one of them hits a PCRE limit or the whole test takes too long, the
  pattern is not saved: „Dieser Ausdruck ist zu aufwendig … verschachtelte oder sich überschneidende Wiederholungen wie
  `(a|a)+` oder `(a+)+` vermeiden.“ The message already appears below the regex field shortly after typing pauses.
  Patterns saved before this check existed are tested once on the first access after the update; one that fails is
  **not** deleted (the schema may use it) but marked „zu aufwendig“ in the list and never checked in the browser. Editing
  only its name, message or example keeps the mark; a changed regex has to pass the test.
* A pattern used by the active schema cannot be deleted or renamed (`409` with the fields using it; the UI shows the
  fields in the column „Verwendet in“).
* Changing the regex of a used pattern first shows how many existing records would violate it per field („Auswirkung der
  Änderung“); it is only saved after „Trotzdem speichern“.
* The form checks a pattern while typing only if the regex behaves exactly the same in JavaScript (a conservative subset:
  literal characters, character classes with ranges, groups, alternation, quantifiers; not `\d`, `\w`, `\s`, `.`,
  lookarounds, inline options …) **and** it passed the load test. Otherwise the server's check when saving applies; the
  pattern form says so in a neutral hint below the regex field (also for a pattern not saved yet). In the browser the
  check runs in a web worker (`assets/patternWorker.js`) with a time limit of 300 ms: if it takes longer, the worker is
  stopped and the regex is silently left to the server for the rest of the session. The server always decides.

API (admins only): `GET /api/_patterns` (all, with `builtin`, `client`, `complex` and `used_by`; a built-in pattern with
its message as the requesting admin's editors see it – system text with `code`, or the admin's translation), `POST /api/_patterns`
(`{name, regex, message, example?}` → `201`; a regex failing the load test → `422` with
`"error_codes": {"regex": {"code": "patterns.too_complex", "params": {}}}`),
`PUT /api/_patterns/{id}`, `DELETE /api/_patterns/{id}` (`409` while used), `POST /api/_patterns/test` (`{regex, value}` →
`{valid, error?, code?, params?, match, client, complex}`), `POST /api/_patterns/check` (`{regex}` → `{valid, error?, code?,
params?, client, complex}`, the
hints below the regex field), `POST /api/_patterns/impact` (`{name, regex}` → `{fields: [{entity, table, field, now,
after}], total}`, changes nothing). None of `test`, `check` and `impact` changes anything.

**Translations:** messages of own patterns are translatable under Lokalisierung → Übersetzungen → „Pattern-Meldungen“
(kind `pattern`, `ref` = name of the pattern, also in the CSV import/export: `pattern,artikelnummer,Please enter an article
number like ABC-1234.`). The built-in patterns appear there as well (original: their system text in the display language);
a translation entered there overrides the system text. The messages for length and range and those of the built-in
patterns are produced by the system itself – in English in the API („At most 140 characters (currently 152).“, „Between 1
and 20.“, „Please enter a five-digit German postal code.“) with the code `validation.*` or `msg_patterns.builtin_*` – and
the editorial UI shows them as fixed UI texts of that code (German „Höchstens 140 Zeichen (derzeit 152).“, „Bitte eine
fünfstellige PLZ eingeben.“, translatable). The bundled `translations/en.csv` contains their English texts (as `ui_text`;
it no longer contains `pattern` lines).

## Enforcement and API error format

The server checks the rules centrally before every save, for all write paths: `POST`, `PUT` and `PATCH` of the REST API,
the editorial form, restoring a version and the test data generator. `PUT` and `PATCH` behave the same (partial: fields
not sent keep their stored value), and the **merged record** (stored state + change) is checked – a valid change of one
field fails while another stored field violates a rule.

A failed save answers `422`:

```json
{
  "error": "validation",
  "message": "Invalid input",
  "code": "msg_api.invalid_input",
  "params": {},
  "errors": {
    "teaser": "At most 140 characters (currently 152).",
    "zip": "Please enter a five-digit German postal code.",
    "sku": "Bitte eine Artikelnummer wie ABC-1234 eingeben."
  },
  "error_codes": {
    "teaser": { "code": "validation.length_max", "params": { "max": 140, "count": 152 } },
    "zip": { "code": "msg_patterns.builtin_plz", "params": { "pattern": "plz" } },
    "sku": { "code": null, "params": { "pattern": "artikelnummer" } }
  },
  "violations": [
    { "field": "teaser", "rule": "maxLength", "code": "validation.length_max", "message": "At most 140 characters (currently 152).", "params": { "max": 140, "count": 152 } },
    { "field": "zip", "rule": "pattern", "code": "msg_patterns.builtin_plz", "message": "Please enter a five-digit German postal code.", "params": { "pattern": "plz" } },
    { "field": "sku", "rule": "pattern", "message": "Bitte eine Artikelnummer wie ABC-1234 eingeben.", "params": { "pattern": "artikelnummer" } }
  ]
}
```

* `errors` stays as before (one message per field; several violations of one field joined),
* `violations` lists every problem with the rule type: `minLength`, `maxLength`, `min`, `max`, `pattern` – and for the
  other validation errors of the same request `required` (missing required value) or `invalid` (wrong type, unknown
  reference …),
* system messages (length, range, required, wrong type, the messages of the built-in patterns …) are English with
  `code` + `params`; clients translate them via the code (see
  [Error responses and messages](rest-api.md#error-responses-and-messages)). Messages of own patterns are content written
  by the admin: they come in the display language of the authenticated user and carry no code. The same applies to a
  built-in pattern for which the admin has entered a translation (it replaces the system text).

`GET /api/{entity}/_schema` delivers the rules of every field that has some: `"rules": {"maxLength": 140}`, for a
pattern `"pattern": {"name", "message", "code"?, "example", "regex"}` (`code` for the system text of a built-in pattern,
see above; `regex` only if the browser can check it exactly, otherwise `null`). Fields without rules have no `rules` key.

The form shows a character counter for fields with `maxLength` („87 / 140“, warning colour when exceeded), uses `min`/`max`
as input limits of number and date fields, the example of a pattern as placeholder, hints while typing, and server
errors (`422`) directly at the field.

**Test data generator:** keeps to the rules (texts cut or extended, numbers and dates within the limits; for richtext plain
text). Pattern fields get the example value of the pattern; without one (or with one that violates another rule of the
field) an optional field stays empty (result `pattern_empty`) and the table of a required one is skipped with a reason –
no invalid data.

## Stricter rules and existing records

Existing records are **never** changed or shortened automatically.

* **Schema analysis** (System → Schema, „Änderungen prüfen“): new, changed or removed rules of an existing field appear as
  a notice (`"kind": "rules_changed"`) with the number of existing records that would violate them and their first IDs
  (`count`, `ids`; severity `warning`, without violations `info`). It is a notice only – it neither blocks the migration
  nor needs a confirmation, and no table is rebuilt for it. A backfill value for a new required field has to satisfy the
  rules of that field.
* **Pattern library:** a changed regex of a used pattern shows the affected records per field before saving (see above).
* **Rule violations view** (System → **Regelverstöße**; admins, or users with the system area „Regelverstöße“ in the
  rights management; the sidebar entry only appears if the diagram has field rules): all records that violate the current
  rules – entity, record with its title, field, rule and message – with a link straight into the record. Computed anew on
  every call („Neu berechnen“). API: `GET /api/_violations?limit=1000` → `{violations: [{entity, table, id, title, field,
  rule, message, params}], total, limit, checked}`.
* **Opening such a record** marks the affected fields right away (`GET /api/{entity}/{id}/_violations`, session only) and
  shows a notice above the form. It can only be saved once the whole record is valid.
* **Permissions:** fields a user may not see (deny) never appear in the rule violations view, in `…/_violations` or in a
  `422` for this user. They are not checked for this user either – the user cannot change them and the save leaves them
  as they are, so a violation in a hidden field does not block the user; it stays visible to whoever may see the field.
