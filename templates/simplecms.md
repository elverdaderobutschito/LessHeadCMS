# Model template: simple CMS (getting started)

File: [`simplecms.puml`](simplecms.puml) – the same diagram LessHeadCMS ships as its default schema
(`backend/schema/simplecms.puml`). How to install a template: see the [overview](README.md).

## What it is for

The smallest useful content model: articles with a category, an author and tags – a blog or a news section. Start here
if you have never written a LessHeadCMS diagram; every later template builds on the same few rules.

## The model

| Class | Purpose |
|---|---|
| `Artikel` | The article: `titel`, optional `untertitel`, `inhalt` (rich text, stored as Markdown), `veroeffentlicht` (published/draft), `erstellt_am` (date). |
| `Kategorie` | One category per article, with an optional `beschreibung`. |
| `Autor` | Who wrote the article; `email` is optional. |
| `Tag` | Keywords, any number per article. |

| Relationship | Kind | Meaning |
|---|---|---|
| `Artikel` → `Kategorie` „gehört zu“ | n:1, required | every article has exactly one category |
| `Artikel` → `Autor` „geschrieben von“ | n:1, required | every article has exactly one author |
| `Artikel` – `Tag` „hat“ | n:n | an article has any number of tags, a tag belongs to any number of articles |

## Concepts it shows

* **Class → table, field → column.** `class Artikel { + titel: string }` becomes the table `artikel` with the column
  `titel`, a REST endpoint `/api/artikel` and a form in the editorial UI. The `id` is added automatically.
* **Required and optional fields.** Without an addition a field is required; `?` after the type makes it optional
  (`untertitel: string?`, `email: string?`).
* **Field types.** `string` (one line), `text` (several lines), `richtext` (editor with formatting), `date`, and
  `visibility`: a checkbox „published“ – unpublished articles are drafts and do not appear in the public API.
* **n:1 relationship.** `Artikel "n" --> "1" Kategorie` puts a column `kategorie_id` into `artikel`; the form shows a
  dropdown. Because the target side is `"1"`, the choice is required. A category that is still in use cannot be deleted
  (`409`), because there is no `{cascade}`.
* **n:n relationship.** `Artikel "n" -- "n" Tag` creates a link table; the form shows checkboxes, the API a list
  `tag_ids`. Deleting an article or a tag only removes the link.
* **Display label without `{title}`.** No field is marked `{title}`, so the UI guesses the label of a row (`titel`,
  `name`, …). The other templates show how to set it explicitly.

See the [PlantUML rules](../docs/plantuml-syntax.md) for all details.
