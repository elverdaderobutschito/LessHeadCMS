# Model template: WordPress-like website

File: [`wordpress-like.puml`](wordpress-like.puml). How to install a template: see the [overview](README.md). The same
idea with a shared base class: [`wordpress-like-abstract.puml`](wordpress-like-abstract.md).

## What it is for

The content structure most website owners know from WordPress: blog posts (`Beitrag`) with categories and tags, static
pages (`Seite`), and authors. A good starting point for a company website or a blog that is rendered by a static site
generator or a frontend framework via the REST API.

## The model

| Class | Purpose |
|---|---|
| `Beitrag` | Blog post: `titel`, `inhalt` (rich text), `veroeffentlicht`, `erstellt_am`, optional `beitragsbild` (media library). |
| `Seite` | Static page: `titel`, optional `kurzfassung`, `inhalt`, `veroeffentlicht`, `erstellt_am`. |
| `Kategorie` | Category tree for posts. |
| `Tag` | Keywords for posts. |
| `Autor` | Author of posts and pages, with `rolle` (free text, e.g. „Redaktion“). |

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Beitrag` → `Autor`, `Seite` → `Autor` „geschrieben von“ | n:1, required | restrict: an author with posts or pages cannot be deleted |
| `Beitrag` → `Kategorie` „gehört zu“ | n:1, required | restrict |
| `Beitrag` – `Tag` „hat“ | n:n | link removed |
| `Kategorie` → `Kategorie` „Unterkategorie von“ | n:1 self-reference, optional | restrict: a category with subcategories cannot be deleted |

## Concepts it shows

* **Media field.** `beitragsbild: media?` links images from the built-in media library. A media field is always an
  ordered list („just one image“ is a list with one element); with `?` it may stay empty. The file stays in the media
  library when the post is deleted.
* **Restrict everywhere.** No relationship has `{cascade}`: nothing is ever deleted along by accident. Compare the
  [Kursverwaltung](kursverwaltung.md) template, which uses `{cascade}`.
* **Two classes with the same fields.** `Beitrag` and `Seite` repeat `titel`, `inhalt`, `veroeffentlicht` and
  `erstellt_am`. That works, but every change has to be made twice – the
  [abstract variant](wordpress-like-abstract.md) shows how to write those fields once.
* **Relationships are per class.** `Seite` has an author but no category and no tags – each class gets exactly the
  relationships drawn for it.

Note: in `deploy/schema/` there is an older copy of this diagram with an additional placeholder class `Test`; it was
left out of the template.

See the [PlantUML rules](../docs/plantuml-syntax.md) for all details.
