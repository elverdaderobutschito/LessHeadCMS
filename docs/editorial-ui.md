# Editorial UI

_Part of the [LessHeadCMS documentation](../README.md)._

* **n:n self-reference:** The checkbox list and the search dialog do not offer the edited row itself (see
  PlantUML rules).
* **Self-references without cycles:** For an FK select that references the same table (e.g. "parent category"), the
  form does not offer the edited row **and all its descendants** (children, grandchildren, … to any depth) for selection. When creating,
  everything can be selected. An already stored, invalid parent value (legacy cycle) is cleared when the form is opened instead of being silently
  sent on. The filtering happens in the browser from the rows loaded anyway and applies to every entity with a self-reference.

* Forms are generated generically from `/api/{entity}/_schema` (text, textarea, Toast editor, checkbox, date, number, select,
  media tiles).
* **Media library** („Mediathek“, system section; admins, otherwise only with the system area `media`, see [Roles and permissions](roles-and-permissions.md)): global collection, independent of the
  diagram, of all uploaded files (images, videos, audio, documents) as a table with preview (image or symbol with
  extension – for raster images the thumbnail generated on the server, a click opens the original), title/file name/kind/size, alt text, description, dimensions (read from the file),
  tags, uploaded by/at and „Verwendet“ (number of links in records). At the top an upload area (files by
  click or drag & drop, several one after the other, status per file incl. the server's error message; files that are too large are
  already rejected by the browser with the same limit), below it a search (title, description, alt text, file name), filters by kind
  and tag. „Bearbeiten“ changes title, alt text, description and tags (comma-separated, new tags are created in the process) and
  can replace the file itself via **„Datei austauschen …“**: the medium keeps its `id`, metadata, tags and all
  links – every record that has embedded it automatically shows the new file afterwards. The transfer only happens
  on saving, with the same checks as for the upload; the previous file including its thumbnail is deleted.
  **„Tags verwalten“** (in the filter bar) lists all tags with the number of their media: „Umbenennen“ affects all
  media with this tag, „Löschen“ removes the tag and its assignments after a prompt with the number of affected media
  (the media stay);
  „Löschen“ removes entry and file – as long as a record uses the medium, the 409 message with the places of use
  appears instead („… wird noch verwendet: 1× in Kurs.galerie (#1)“). **Uploaded files are immediately
  publicly retrievable via their URL**, even from within drafts (see [Media files are publicly reachable](deployment.md#important-notes)).
* **Media field** (type `media`, see [PlantUML rules](plantuml-syntax.md)): shows the selected media as tiles
  (image preview or file symbol, title/description as a tooltip) in their order, per tile ← / → for moving
  and × for removing. „Mediathek durchsuchen“ opens a selection dialog as a tile grid with
  search, kind filter and integrated upload: a click on a tile adds the medium or removes it again (the dialog
  stays open), freshly uploaded files are selected immediately. In the form, media fields come after the normal
  fields and before the n:n selections; in the list view they do not appear as a column.
* **Display label of referenced rows** (FK select options, n:n selection, list view): `<entity> #<id> – <text
  of the title_field>`, e.g. „Bestellung #3 – verpackt“, with several `{title}` fields combined comma-separated (e.g.
  „Bestellung #3 – 2026-09-23, verpackt“, see [PlantUML rules](plantuml-syntax.md)). If the row has no text (yet) in any
  `title_field` field (empty/`null`, or the row is not loaded yet), the part after the dash is dropped: „Bestellung
  #3“. The entity name comes from the table name, capitalized via the same `capitalize()` helper as every
  other field label. With two FK fields to the same target type (e.g.
  delivery address/billing address → both `Adresse`), it is still the field label that distinguishes the two selects,
  not the individual options in them.
* **„Duplizieren“** (duplicate; actions column of the list, between „Bearbeiten“ and „Löschen“, for every entity and every logged-in
  role): opens the form „… anlegen“ (`#/<entity>/new/<id>`), prefilled with all values of the row – simple
  fields, FK references, n:n selection and media galleries; not the `id`. Nothing is saved by this yet (notice
  „Kopie von Artikel #5 – noch nicht gespeichert.“); from then on the form behaves exactly like „Neu anlegen“ (same
  validation, same `{unique}` check, „Speichern und neu“). There is no server endpoint of its own: loading is done
  via `GET /api/{entity}/{id}`, saving via `POST /api/{entity}`. Special cases: a `visibility` field is always
  set to draft, independent of the original. A **1:1** relationship is cleared and mentioned in the notice because the target row
  already belongs to the original (the copy could never keep it). Whoever leaves a `{unique}` field unchanged gets the
  usual 409 message on saving.
* **Filtered selection** (`{filter_by:field}` on the relationship, see [PlantUML rules](plantuml-syntax.md)): dropdown (n:1, 1:1), checkbox list
  (n:n) and the search dialog only show target rows whose filter field has the same value as the field in the form currently
  being edited – e.g. only English tags for the English article; below the field it says „Auswahl eingegrenzt auf Sprache: EN“.
  * If the filter field is still empty, all target rows appear with the hint „Wählen Sie zuerst „Sprache“, um die Auswahl
    einzugrenzen.“
  * **What is already chosen stays chosen**, even if it no longer matches after the filter field has changed (no silent
    data change): it stays in the list, with the addition „passt nicht zu „Sprache““, and can be deselected –
    afterwards it can no longer be selected as long as it does not match. Saving also leaves such assignments unchanged.
  * **"+" (inline creation)** in a filtered selection prefills the filter field of the new row with the current value
    (a new tag gets `sprache = EN`); for this the field appears in the inline form even if it is not a required field,
    and stays changeable.
  * The filter is purely a selection aid of the UI: the API does not check it. Media fields cannot be filtered.
* **Three save buttons:** „Speichern und Schließen“ (the former „Speichern“: saves and jumps to the list),
  „Speichern und weiter“ and – only when creating – „Speichern und neu“, then „Abbrechen“. Enter in the form triggers
  „Speichern und Schließen“.
* **„Speichern und weiter“** (save and continue; creating and editing): saves with the same logic and validation but stays in the
  form. When creating, the address switches to the new record (`#/<entity>/<id>`, without an entry in the
  browser history) and the form becomes the edit form – all values stay visible, a workflow block becomes
  operable. When editing, everything stays as it was saved. In both cases „Gespeichert: Artikel #12.“ briefly appears above the
  form (`role="status"`, disappears after 4 seconds). In case of an error the form stays with
  the error display as with the other buttons.
* **„Speichern und neu“** (save and new; only in the form „… anlegen“, not when editing): saves with exactly the same logic and
  validation as „Speichern und Schließen“, but afterwards does not jump to the list and instead immediately shows the **empty** form
  of the same entity again – with a short confirmation („Gespeichert: Person #3. Bereit für den nächsten Datensatz.“, `role="status"`,
  disappears by itself after 4 seconds) and the focus in the first field (for rich text in the editor), so that you can keep
  typing right away. Deliberately **no** values are carried over, not even an FK selection. In case of an error (browser
  required-field validation or `422`/`409` from the server) everything stays as with „Speichern und Schließen“. With a self-reference, the record just saved is immediately available in the dropdown of the next one.
* **Inline creation:** Next to every foreign key select and every n:n selection there is a **+**. It opens a dialog with the
  **required fields** of the target entity (rendering via the same components as the main form). After saving, the
  new record is selected or ticked immediately – without a page reload. If the target entity has required FKs itself, there is a + there as well.
* **List view: paginated, sorting/filtering on the server** (for the API see [Lists:
  paging, sorting, filtering](rest-api.md#lists-paging-sorting-filtering)): the list only ever loads one page (default 50 rows, selectable 25/50/100/200). Below
  the table is the navigation „Seite 3 von 247“ with first/back/next/last and a jump to a page. Paging,
  sorting and filtering each trigger a new, small request; text input in the filter row is collected for 0.3 s.
  The column selection only affects the display. A click on a
  column header sorts ascending, another click descending (arrow ▲/▼, `aria-sort`). Numbers numerically, texts/dates
  with German collation (numbers in texts numerically), bool/visibility false/draft first, FK by the text part
  of the display label (not by ID), richtext by plain text without Markdown; empty values always come last;
  n:n columns cannot be sorted. A filter row below the headers: text field (substring, case-insensitive) for
  string/text/richtext/FK/n:n, "from/to" for int/decimal/date, selection Alle/Ja/Nein or Alle/Veröffentlicht/Entwurf
  for bool/visibility; all filters are ANDed, „Filter zurücksetzen“ clears them, with an active filter „x von y
  Einträgen“ appears above the table. For this, text/richtext columns appear in the list view (shortened to one line, full
  text as a tooltip). The selection dialog (🔍) deliberately does not have this (`EntityTable` prop `sortableFilterable`).
  Sorting and filters apply to the whole table, not just to the loaded page; „x von y Einträgen“ states the
  total number of matches.
  The column „Bearbeiten/Duplizieren/Löschen“ is fixed (`position: sticky; right: 0`) and stays visible when scrolling
  horizontally: separator line on the left, plus a shadow as long as there is still content beneath it on the right; below 720 px width
  the buttons are stacked. The list view uses the whole window width next to the sidebar (`.view-wide`);
  forms, users and test data stay at a content width of at most 1044 px.
* **Column selection of the list view**: without a selection of your own, every list shows
  at most **5 columns** (plus actions): `id`, then the `title_field` fields (`{title}` markers in
  diagram order, without a marker the one field of the guessing heuristic), then the remaining columns in
  diagram order (fields incl. FK, then n:n, then media fields) until 5 are reached; if the entity has
  fewer, all appear. **Media fields** (`media`) are preview columns: up to 3 thumbnails side by side (images
  as a thumbnail, video/audio/document as a symbol with the extension), beyond that „+n weitere“; they count like any other
  column but can be neither sorted nor filtered.
  The columns are in this order with a selection of your own as well. The button **„Spalten (x/y)“** next to „Filter
  zurücksetzen“ opens a list of all columns with checkboxes; any number can be shown or hidden,
  `id` and the actions column always stay visible. „Standard wiederherstellen“ discards your own selection. Every
  change is saved on the server immediately, **per user and entity** (`/api/_prefs/columns/{entity}`, table
  `user_column_prefs`), so it applies on every device/browser and is separate between users. Sorting and filters
  only affect visible columns; when a column is hidden, its filter and sorting are dropped. The selection dialog
  (🔍) still shows all columns.
* **Search/select (🔍):** Next to it, same size/same placement, a second button opens a selection dialog with
  the rows of the target table (page by page, 25 per page, with the same navigation as the list view) and **all** its fields as columns (not just `title_field`) – the same
  column/cell logic as the list view,
  FK columns in it likewise shown with the display label of the referenced row instead of as a raw ID. A search field above it searches
  on the server (parameter `q`) across all columns except long text and yes/no (incl. the FK and n:n labels).
  **Large target tables:** dropdown and checkbox list of a form only preload the first 100 rows of the
  target table (plus those chosen in the record); if it has more, the field says „Die Auswahl zeigt 100 von 12.345
  Einträgen – alle weiteren über die Suche (Lupe)“. For a self-reference and 1:1 these are the first 100 **selectable**
  rows: whatever cannot be selected (the row itself and its descendants, or target rows already taken) is excluded by the
  server in the query already (`for`/`for_id`, see API) – regardless of where in the table it
  is; the selection dialog therefore always shows full pages as well. A row chosen in the dialog appears in the selection afterwards. A click on a row selects
  it: for a single FK select the dialog closes immediately afterwards; for n:n it stays open so that
  several rows can be ticked one after the other (the checkbox list remains the actual multiple selection, the
  dialog is only an aid for finding rows). For a self-reference the same exclusions apply (row itself + its
  descendants) as in the normal dropdown.
* **Sidebar:** at the top the data tables from the diagram (diagram order; grouped with `package` blocks, see
  next item), below – set apart by a separator line,
  smaller and in a muted colour – the section **„System“** with the system functions (for admins all of them; for
  editors only the areas granted to them via [Roles and permissions](roles-and-permissions.md) – none by default, „Schema“ never).
  The routes of the system pages are reserved (`PumlParser::RESERVED`) so that no entity occupies them. If
  no entry is allowed for a user, the section is dropped entirely (editor without a granted system area).
* **Sidebar groups** (only if the diagram uses `package` blocks, see [PlantUML rules](plantuml-syntax.md)): one group per package with
  the package name as its heading (look of the „System“ heading, plus an arrow ▸/▾), collapsible by click.
  Packages in the order of their first occurrence, entities in them in diagram order; classes without a package
  come last in the group **„Sonstige“**. On the first visit the first group is open, all others are closed;
  the groups toggle independently of each other (no accordion). If the active entity is in a collapsed group
  (reload, direct link), this group is opened. The open/closed state applies to the current login in the tab
  (`sessionStorage`, survives switching entities and reloading) and is discarded on logout – no storage on
  the server. Without a `package` block the sidebar stays exactly as before (flat list without heading/arrow). The
  section „System“ stays below it and cannot be collapsed.
* **Test data** („Testdaten“, system section, `role = 'admin'` or system area `testdata`): choose a count per entity (default 10, 0 = skip,
  at most 1000), „Generieren“ creates **additional** records with random content and shows a summary.
  Nothing is changed or deleted; for an empty database still delete `data/cms.sqlite` + `/bootstrap`.
  For details see `POST /api/_testdata/generate` under [User management](api-users.md).
* **Users** („Nutzer“): tab „Nutzer“ of System → Rechteverwaltung, only visible for `role = 'admin'` or with the system area `users` (`GET /api/me` delivers the role; the server-side check in
  `/api/users` is the actual protection, hiding it in the menu is UX only). List with name/login name/e-mail/role/status
  and an edit dialog; „Neuer Nutzer“ additionally has an unmasked password field with a generator button (16 characters,
  cryptographic randomness via `crypto.getRandomValues`, with upper/lower-case letters, digits and special characters) – the admin
  can overwrite the suggestion. After creation a notice with the password stays visible so that it can be passed on.
