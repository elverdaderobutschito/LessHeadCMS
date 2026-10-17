# Model template: WordPress-like website with an abstract base class

File: [`wordpress-like-abstract.puml`](wordpress-like-abstract.puml). How to install a template: see the
[overview](README.md).

## What it is for

The same kind of website as [WordPress-like](wordpress-like.md) – posts, pages, categories, tags, authors – but the
fields that posts and pages share are written only once, in an abstract base class.

## The model

| Class | Purpose |
|---|---|
| `abstract class Basisinhalt` | Template for the shared fields: `titel` {title}, `inhalt` (rich text), `veroeffentlicht`, optional `erstellt_am`. **No table of its own.** |
| `Beitrag extends Basisinhalt` | Blog post: the four shared fields plus `kommentare_erlaubt` (yes/no). |
| `Seite extends Basisinhalt` | Static page: the four shared fields plus `reihenfolge` (number, e.g. for the menu order). |
| `Kategorie`, `Tag`, `Autor` | As in [WordPress-like](wordpress-like.md), with explicit `{title}` fields. |

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Beitrag` → `Autor`, `Seite` → `Autor` „geschrieben von“ | n:1, required | `{cascade}`: the author's posts and pages are deleted too |
| `Beitrag` → `Kategorie` „gehört zu“ | n:1, required | restrict |
| `Beitrag` – `Tag` „hat“ | n:n | link removed |
| `Kategorie` → `Kategorie` „Unterkategorie von“ | n:1 self-reference, optional | `{cascade}`: subcategories are deleted too |

## Concepts it shows

* **Abstract base class = field copy.** `class Beitrag extends Basisinhalt` gets all fields of `Basisinhalt` first, then
  its own – exactly as if they were written in `Beitrag`. The result is two ordinary tables `beitrag` and `seite`; there
  is no table `basisinhalt`, no shared table and no „all contents“ endpoint. A change to `Basisinhalt` affects both
  classes at the next schema update.
* **Relationships are not inherited.** They belong to concrete classes, which is why the diagram draws „geschrieben
  von“ twice. A relationship to or from the abstract class is a schema error.
* **Markers are inherited.** `{title}` on `titel` in the base class applies to posts and pages alike.
* **`bool` vs. `visibility`.** `kommentare_erlaubt: bool` is an ordinary yes/no field; `veroeffentlicht: visibility`
  additionally decides whether a row is public in the API.
* **Different delete behaviour than the non-abstract variant.** Here `{cascade}` is set on the author and on the
  category tree – compare the two templates to see the effect of a single marker.

See „Inheritance“ in the [PlantUML rules](../docs/plantuml-syntax.md) for all details.
