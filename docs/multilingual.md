# Multilingual support (phase 1: labels of the schema)

_Part of the [LessHeadCMS documentation](../README.md)._

The labels from the diagram – entity names, field and relationship labels, enum values – can be translated into further
languages (`src/Languages.php`). Every user chooses their display language themselves. Fixed texts of the UI
are translated by phase 2 (see below, all fixed frontend texts). **Not** translated are messages from the server
(validation errors, 409/422 texts – they stay German), the stored data and the public API
(`GET /api/{entity}`, `/_schema` still deliver the original names and raw values).

* **Date and number format per language** (System → Sprachen, on creation and via „Bearbeiten“; also for the
  default language): four explicitly set values instead of a locale code – **date format** and
  **date+time format** following the convention of PHP `date()`, **decimal separator** (one character) and
  **thousands separator** (one character, empty = no grouping). Default values `d.m.Y`, `d.m.Y, H:i`, `,` and `.`
  (German representation: `31.12.2026`, `1.234,56`) – they also apply as long as no language has been set up at all.
  * **Where they take effect** (display only): list cells of `date` and
    `decimal` fields, the label of referenced rows (FK column, dropdown, n:n list: `{title}` fields with a date
    or number), the hint of the filtered selection, "uploaded at" in the media library, the times in the
    backup list (System → Schema, also in the restore dialog) and the number before `KB`/`MB` (the units themselves
    stay fixed). `int` fields (year, postcode, …), the `id` and FK numbers are never grouped – they
    appear as a plain sequence of digits; the thousands grouping only applies to `decimal`. Sorting and range filters keep working with the
    stored values.
  * **Where not:** Editable native input fields (`<input type="date">`, `<input type="number">`) still show the
    browser's format; the API delivers and expects `YYYY-MM-DD` and numbers with a decimal point, unchanged.
  * **Supported placeholders:** `d` `j` (day with/without leading zero), `m` `n` (month), `Y` `y` (year, four/two digits),
    `H` `G` (hour 0–23), `h` `g` (hour 1–12), `i` (minute), `s` (second), `A` `a` (AM/PM or am/pm). `\x` outputs the
    character `x` literally. All other characters appear unchanged – including letters that would be further
    placeholders in PHP (`D`, `l`, `M`, `F`, `N`, `w`, `z`, `t`, `L`, `e`, `T`, `P`, `U`, …): weekday and month names
    are deliberately not replicated. The language management lists such letters as a hint.
  * **Presets:** Next to each of the two format text fields there is a dropdown with common formats (German,
    US/UK English, ISO 8601, French/Spanish; „– eigenes Format –“). A choice enters the format string into the
    text field – purely a filling aid, only the text is stored. The dropdown follows the text field: after manual
    input it shows „– eigenes Format –“ or the preset that exactly matches the text.
  * **Live preview** next to the fields: current date, date+time and the number 1234567.89 in the format just
    entered.
  * **Empty or unusable:** An empty date format or decimal separator is stored as the default value. A
    format without any placeholder, more than one character as a separator, a digit or two identical separators are rejected
    by the server with `422` at the field. The display itself always falls back to the default values for unusable input
    (the preview then says for which field) – there is no broken rendering.
  * Times: "uploaded at" is converted from UTC to the local time of the browser; the backup list shows the
    local time of the server as it appears in the file name.
  * Installations from before this feature: the four columns of the table `languages` are created the next time
    a language is saved; until then the default values apply.
* **System → Lokalisierung** (localization) – one sidebar entry, one page with the tabs **Sprachen** | **Übersetzungen**
  (built like the rights management). Every tab
  can be linked directly: `#/_localization/languages`, `#/_localization/translations`. The former addresses
  (`#/_languages`, `#/_translations`) redirect there. Each tab is visible for admins, otherwise only with the
  permission on the system area `languages` or `translations`.
* **Tab Sprachen** (languages; admin only): first set the **default language** – the language the diagram is written
  in (e.g. „Deutsch“/„de“). This is only its label in the switcher, nothing changes about the diagram texts. Then
  add further languages (code freely chosen, 1–20 characters of letters, digits, `-`, `_`, unique regardless of
  case; name up to 60 characters) and delete them – including all translations of this language; users who
  had chosen it see the default language again. The default language cannot be deleted (`409`), there is
  always exactly one.
* **Tab Übersetzungen** (translations; admin only): at the top the language (all except the default language), below it all translatable
  elements of the **active** schema grouped by class, per row kind, original text (as form and list show it,
  i.e. with `capitalize()`) and input field; empty = "not translated yet" (the placeholder shows the original). Saving is done
  **collectively** via „Speichern“ (one transaction, changed rows are marked; an empty field removes the
  translation). Translatable are: concrete classes; fields and media fields (without `id`; inherited fields in their abstract
  base class, they apply to all subclasses); n:1/1:1 relationships (label, or without a label the column name); n:n relationships
  with `{label}` (without `{label}` the UI shows the name of the target class, which is translated via its class); enums
  (name) and every enum value.
* **Groups and filter (both tabs):** Every group (class or area) can be expanded/collapsed via its header with an arrow, like
  the package groups of the sidebar. The header shows „(n von m übersetzt)“ and, for unsaved changes, a ●, also when
  collapsed. Default: all collapsed so that the page does not start with a long, fully unfolded list. Expanded groups
  are remembered by `sessionStorage` (`lhc.translations.open`, per tab): this survives tab and language switches, leaving the page and reloading, but only applies to this browser tab.
  „Nur unübersetzte anzeigen“ (applies to the active tab) only shows rows without a stored translation in the chosen
  language, hides groups without a gap and expands the others. A group disappears after saving as soon as
  no gap is left. Switching it off restores the remembered collapse state.
* **Display override of the default language:** Names in the diagram must stay ASCII-safe (column names), for instance
  `veroeffentlicht` → derived „Veroeffentlicht“. In System → Übersetzungen the default language can therefore be selected as well
  („Deutsch (de) – Original“). There you override the derived label („Veröffentlicht“), only for
  schema elements, not for fixed UI texts. It is stored in `translations` with the `language_id` of the default language.
  **Fallback chain of the display** (`Languages::labels()`): translation of the display language → override of the default language →
  derived label. For other languages the column „Original“ therefore shows the override (below it „abgeleitet:
  …“). Diagram, database, `_schema` and data API stay unchanged.
* **Language switcher** at the bottom of the sidebar (any role), only visible once there is another
  language besides the default language – without that the UI looks exactly as before. The choice is stored per user
  (`user_language_prefs`) and applies immediately to sidebar, headings, form labels, column headers, filters,
  column selection, enum dropdowns and cells as well as the label of referenced records ("Course #3 – …"). If a
  translation is missing, the original appears. An enum dropdown shows the translated text, the original value is still
  what is stored. The name of an enum itself currently appears nowhere in the UI; its translation is prepared for later
  phases.
* **Stable IDs:** Translations are bound to `schema_ids` (`translations.ref_id`; enum values: ID of the enum + value in plain text).
  A rename by migration (`{renamed_from}`) therefore keeps them; if a migration removes an element, its
  translations are deleted along with it (like the layout position), as are those of a removed enum value.
* **Tables:** `languages` (`id`, `code`, `name`, `is_default`), `translations` (`id`, `language_id`, `kind` =
  `entity`/`field`/`relation`/`enum`/`enum_value`/`ui_text`, `ref_id`, `value`, `text`), `user_language_prefs` (`user_id`,
  `language_id`). They are created when the first language is created – no further bootstrap needed.

## Phase 2: fixed UI texts (2a: core area, 2b: form widgets, 2c: media library, 2d: user management, 2e: test data/languages/translations, 2f: schema/diagram editor)

* **The source of truth** for the German texts is a list of keys built into the UI (key → text,
  grouped by area), e.g. `form.save` or, with placeholders, `list.count` with `{shown}` and `{total}`.
  The UI shows the translation of the display language, otherwise
  the German text. Without a translation – so also for every newly created language – everything stays German.
* **Core area (2a):** login, forced and voluntary password change, sidebar (group „Sonstige“, heading and
  entries of the section „System“, user area), frame („Lade Schema …“, „Kein Zugriff.“), basic structure of the form
  (heading „… anlegen/bearbeiten“, Speichern und Schließen, Speichern und weiter, Speichern und neu, Abbrechen) and of the list (Neu anlegen,
  counter, Filter zurücksetzen, filter row, column selection, Bearbeiten/Löschen including the prompt, empty texts).
* **Form widgets (2b):** „– bitte wählen –“ (enum, FK), magnifier/+ (`title` and `aria-label`), „Noch keine Einträge
  vorhanden.“ for n:n, selection dialog (title also with n:n label, search field, empty texts), inline creation (title, hint without
  required fields, Anlegen/Abbrechen), close button of the dialogs and the toolbar of the rich text editor: tooltips,
  headings menu, link dialog, table context menu. Toast UI brings its own language packs; the UI maps the
  visible texts to keys `richtext.*` and overrides the German pack before the editor is created.
  An open editor is recreated with its content when the UI texts change.
* **Media library (2c):** overview (heading, columns, search field, kind/tag filter, kind names,
  „Alt-Text: …“, „nein“/„n×“, Bearbeiten/Löschen, delete prompt, success messages), edit dialog
  (title, field labels, accessibility hint), upload area (button, size hint, status per file, client-side
  size check – the status texts are only translated when rendering and change along with a language switch), media field in the
  form („Mediathek durchsuchen“, ←/→/× with `title` and `aria-label`, „Keine Medien ausgewählt.“) and media selection dialog.
  Errors from the server (rejected uploads, 409 on deletion, required field) stay German; dates and numbers are still
  formatted the German way.
* **User management (2d):** list (heading, „Neuer Nutzer“, columns, role and status names, Bearbeiten, notice with
  the initial password after creation), dialog „Neuer Nutzer“ (title, labels, role selection, hint about the first login,
  Anlegen/Abbrechen; generator button and rule hint via the existing keys `password.generate`/`password.hint`) and
  dialog „Nutzer bearbeiten“ (title, labels, „Aktiv“, for admins a hint about the self-lockout protection). The
  rejections by the server (own account, last active admin, validation, password rule) stay German.
* **Test data, languages, translations (2e):** test data generator (explanatory text, columns, Generieren, result including
  „n erzeugt“/„n Verknüpfungen“, hint about empty media fields), language management (initial setup and „Neue Sprache
  hinzufügen“, columns, badge, Löschen including the prompt, success messages) and the translation UI itself (tabs,
  language selection, counter, filter, group headers, columns, placeholders, hints, placeholder warning, Speichern including
  the message). Like everything, the translation page follows the display language of the sidebar, not the language it is currently
  editing. New texts for your own display language also take effect on the page itself immediately after saving. Not
  translatable are the area titles in the tab „Feste UI-Texte“ (they name the key prefixes) and the column „Art“
  of the schema elements (comes from the server).
* **Schema editing and diagram editor (2f, completion):** frame of the schema page (introduction, buttons, lock hint,
  backup hints), check result (category badges, legends, rename dropdown „… (Zeile n) – ist das eine
  Umbenennung von …?“, backfill frame, Anwenden), backups and restore dialog, diagram modal (zoom, fit,
  Neu anordnen, Speichern/Schließen/Abbrechen, status line, legend), properties panel of all element types incl.
  delete prompts and blockers, the client-side inline check (it returns keys, the translation
  happens on display) and the text preview. Plus the error frame of the column selection and the start texts of the app.
  `<html lang>` follows the display language (set when the UI texts are loaded). Deliberately fixed are: diagram syntax
  (`{title}`, `{unique}`, `{cascade}`, `{label}`, `{symmetric}`, `«abstract»`, `«enumeration»`, type names, multiplicities),
  the confirmation text `WIEDERHERSTELLEN` (the server checks exactly that) and the suggested names of new elements
  (`NeueEntitaet`, `neues_feld`, `WERT1` – they become identifiers). All texts from server responses (check, conflicts,
  suggestion options, success messages) stay German – follow-up ticket.
* **System → Übersetzungen → tab „Feste UI-Texte“:** all keys by area, per row key, German text
  and input field; counter, marking and collective saving as for the schema elements („Speichern“ takes the
  changes of both tabs). If a translation differs from the original in its placeholders (`{entity}`, `{id}` …),
  a hint appears (it is saved nevertheless).
* **Storage:** `translations` with `kind = 'ui_text'`, `ref_id` = key. The server only checks the form of the key
  (`area.name`); only the frontend knows the list. Renaming a key means losing its translations.
  A `translations` table from phase 1 (CHECK without `ui_text`, `ref_id` with a foreign key to `schema_ids`) is rebuilt once on
  the first save; the existing translations are kept.
* **English is included:** `translations/en.csv` contains all fixed UI texts in English. Create the language "English" (code `en`),
  then under System → Übersetzungen → „CSV importieren“ choose the file `en.csv` and apply it (see
  "Translations as CSV").
* **Login page:** Without a login the server does not know the user. The browser therefore remembers the code of the
  display language used last (`localStorage` `lhc.language`, only for a non-default language) and requests
  `GET /api/_ui_texts?lang=<code>`. A browser without a remembered language shows the default language. After the login (already
  during the mandatory password change) the language of the user applies.

## Translations as CSV (export, import, `translations/`)

System → Übersetzungen can download the translations of a language as CSV and import them again – from your own
hard disk or from the directory `translations/`, which is filled via FTP like `schema/`. The product ships with
`translations/en.csv` (all fixed UI texts in English).

* **Format:** UTF-8, header line `kind,ref,text`, standard CSV quoting (quotes only where necessary; `"` is
  doubled). One file per language, fixed UI texts and schema elements mixed:

  ```csv
  kind,ref,text
  ui_text,form.save,Save
  entity,Kurs,Course
  field,Kurs.titel,Title
  relation,Kurs->Autor:Verfasser,Written by
  enum,Status,State
  enum_value,Status.OFFEN,Open
  ```

* **`ref` is a readable identifier, not the ID.** Fixed UI texts: the key of the text as the tab „Feste UI-Texte“ lists it – the same on every
  installation, so the file is portable. Schema elements are bound in the database to the stable ID from
  `schema_ids`, and that differs per installation. The CSV therefore names them via the names of the diagram: entity `Kurs`,
  field/media field `Kurs.titel`, relationship `Source->Target:Label` (without a label the column name without `_id`; n:n only with
  `{label}`), enum `Status`, enum value `Status.OFFEN`. Two relationships with the same identifier are numbered
  (`…#2`). Resolution uses the same list the translation page is built from (`Languages::elements()`, i.e.
  model key → `SchemaIds::lookup()`); the catalog delivers the identifier per element as `ref`.
* **Export:** „Herunterladen (CSV)“ next to the language selection downloads `<code>.csv` with all **stored** translations
  of the chosen language (unsaved input is not included). For the default language these are the display overrides.
* **Import:** area „CSV importieren“ – choose a file from the hard disk or one from `translations/`, plus the
  target language. If the file name matches a language code (`en.csv` with an existing language "en"), that language is preselected,
  otherwise the language chosen above; changeable. A **preview** appears immediately: new / changed (existing translation
  is overwritten, old → new expandable) / unchanged / skipped, every skipped line with line number and
  reason. Only „Übernehmen“ saves, in one transaction.
* **Skipped instead of aborted:** element not found in the active schema (different project, field renamed), unknown
  kind, invalid UI text key, empty text, text over 500 characters, duplicate line, fixed UI text for the
  default language. The rest is imported. An import adds and overwrites, it **deletes nothing**.
* **Lenient when reading:** a BOM at the beginning, `;` instead of `,` as the separator (this is how Excel saves with German settings),
  Windows line endings and empty lines are allowed. Line breaks in the text become a single space, as when saving
  by hand. Without the header line `kind,ref,text`, with a character set other than UTF-8 or above 2 MB → `422`.
* **The server does not know the list of UI text keys** (it only exists in the frontend) and only checks the form. An
  outdated key is therefore imported but never displayed.
* **`translations/` is not public:** only the admin API reads the files. The `.htaccess` in the main directory routes
  everything to `index.php` anyway; the separate `translations/.htaccess` additionally blocks any retrieval (`403`), the listing and
  any script execution. Only files with the extension `.csv` and names made of letters, digits, `.`, `_`, `-` are listed.
