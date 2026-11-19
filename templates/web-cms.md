# Model template: web CMS base model

File: [`web-cms.puml`](web-cms.puml). Copy it to `schema/` and run `/bootstrap?token=…&schema=web-cms.puml` (see
[Deployment](../docs/deployment.md)); it uses only the regular [PlantUML rules](../docs/plantuml-syntax.md).

## What it is for

A starting point for agencies and developers who want to model an arbitrary website in LessHeadCMS: global settings and
navigation, a page tree, pages built from blocks, and channel-neutral content (posts, events, services) that blocks
show. It has been tried out in a real installation: a portfolio website with 34 pages in German and English, sliders,
tiles and navigation was rebuilt with it.

## The idea

* **Layers:** website (global) → page (tree) → block (in an area) → content (channel-neutral). A block belongs to exactly
  one page; content (`Beitrag`, `Termin`, `Leistung`, `Textbaustein`, …) is not tied to a page and can be shown on many
  pages, in an app or in a newsletter.
* **Role, not position:** the CMS knows *areas* such as `HAUPTINHALT` or `SEITENLEISTE` (enum `Bereich`), not positions
  such as "top left". Where an area appears is decided by the frontend's layout.
* **Fixed core, replaceable parts:** `Website`, `Navigation`, `Navigationspunkt`, `Seite` and `abstract class Block` are
  the core that every website needs. Replaceable **per project or design**: the enums `Template` and `Bereich`, the block
  types and the content types.
* **Multilingual content** with the documented [modelling pattern](../docs/multilingual.md#multilingual-data-content-modelling-pattern):
  enum `Sprache`, a self-reference `Original von {cascade} {unique}` and `sprache {unique}` – for `Seite`, the content
  types, `Kategorie` and `Textbaustein`. `Navigation` has a language too, but no original: it is unique per place and
  language. Blocks have no language of their own: they belong to the language version of their page.
* **Manual order everywhere** with the field type [`sort`](../docs/plantuml-syntax.md): menu items per navigation and
  parent item, pages per parent page, all blocks of a page in **one** order across all block types, FAQ entries and
  tiles per block.
* **Parts are edited in their whole** ([composition](../docs/plantuml-syntax.md), `*--`): the blocks of a page, the FAQ
  entries of an FAQ block, the tiles of a tile block and the items of a navigation. The editor opens a page and finds its
  blocks in the form – one mixed list in the page order with „+ Block hinzufügen“ (menu of the block types), ↑/↓ across the
  types, and from a block the path „Seite › KachelBlock › Kachel“ back. The block classes are not in the sidebar for
  editors (admins: group „Teile“).

## The model

| Package | Class | Purpose |
|---|---|---|
| Website | `Website` | Global settings: name, base URL, logo, copyright, SEO defaults. Meant as exactly one record (not enforced). |
| | `Navigation` | One navigation per place (`Navigationsort`: main, footer, meta) and language – `{unique}` on both. |
| | `Navigationspunkt` | Menu item: internal target (relationship to `Seite`) or external URL, nested via „Unterpunkt von“. |
| Seiten | `Seite` | A page in one language: title, slug, `template` (layout of the frontend), visibility, SEO fields. Page tree via „Unterseite von“, language versions via „Original von“. |
| Bloecke | `Block` (abstract) | What all blocks share: `bezeichnung` (required display label, see below), `bereich`, the shared `position`, an optional anchor, visibility. |
| | `HeroBlock`, `TextBlock`, `BildBlock`, `CtaBlock` | Stage with button, running text with image and link, image/gallery/slider, call to action. |
| | `FaqBlock` + `FaqEintrag` | Questions and answers, entries in their own order per block. |
| | `KachelBlock` + `Kachel` | Image tiles with link (teasers), tiles in their own order per block. |
| | `ListenBlock` | Dynamic list: "the 3 newest posts of category X" (`inhaltstyp`, `anzahl`, `sortierung`, optional category). |
| | `TextbausteinBlock` | Embeds a shared `Textbaustein` (e.g. a contact box used on many pages). |
| | `Bildangabe` (`<<media_meta>>`) | Caption per image in `BildBlock.bilder`. |
| Inhalte | `Inhalt` (abstract) | Shared fields of the content types: title, slug, language, teaser, image, visibility. |
| | `Beitrag`, `Termin`, `Leistung` | Post (date, text), event (start, end, place), service (description, price). |
| | `Person`, `Kategorie`, `Textbaustein` | Author, category (per language), reusable text block. |

**Links:** an internal link target is an optional relationship to `Seite` („Button-Ziel“, „Link-Ziel“, „verlinkt auf“),
next to it an optional external URL. The frontend uses the page if set, otherwise the URL.

**`Block.bezeichnung {title}` is required.** Many blocks have no heading of their own; without this field the block lists
would only show „–“. It is an internal name for the editors („Hero Startseite“, „Kontaktbox“), not shown on the website.

Relationships and their delete behaviour:

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Seite *-- "n" XyBlock : auf` (every block type) | composition | the blocks go with the page (implicit cascade) |
| `FaqBlock *-- "1..*" FaqEintrag`, `KachelBlock *-- "1..*" Kachel` „gehoert zu“ | composition, minimum count `"1..*"` | entries go with their block; a block without entries shows the soft "at least one" warning in its section |
| `Navigation *-- "n" Navigationspunkt` „gehoert zu“ | composition | items go with their navigation |
| `Navigationspunkt` → `Navigationspunkt` „Unterpunkt von“ | self-reference, optional (no composition: a part has one whole, the navigation) | `{cascade}`: sub-items go with their parent item |
| `Seite`, `Beitrag`, `Termin`, `Leistung`, `Kategorie`, `Textbaustein` → itself „Original von“ | self-reference, `{unique}` with `sprache` | `{cascade}`: translations go with the original |
| `Seite` → `Seite` „Unterseite von“ | self-reference, optional | restrict: a page with subpages cannot be deleted (`409`) |
| link targets → `Seite`, `Website` „Startseite“ | n:1, optional | restrict: a page that is still a link target cannot be deleted (`409`) – clear the link first |
| `TextbausteinBlock` → `Textbaustein`, `ListenBlock` → `Kategorie`, content → `Kategorie`/`Person` | n:1 | restrict: in use → `409` |

`Beitrag`, `Termin` and `Leistung` → `Kategorie` use `{filter_by:sprache}`: the form offers only categories in the
language of the record.

## Adapting it to a project

* **`Template` and `Bereich`** describe the layouts and areas of *your* frontend – rename, add or remove values.
* **A new block type:** `class XyBlock extends Block { … }` plus `Seite *-- "n" XyBlock : auf`.
  Relationships are not inherited, so every block type needs its own „auf“ composition – with exactly this label, because
  the shared order is `sort {by: "auf"}` (the label is needed because some blocks have a second relationship to `Seite`).
  It then appears in the menu „+ Block hinzufügen“ of the page form by itself.
* **A new content type:** `class Xy extends Inhalt { … }`, its own `Xy "n" --> "0..1" Xy : Original von {cascade} {unique}`
  and a value in enum `Inhaltstyp` (for `ListenBlock`).
* **Languages:** values of enum `Sprache`. `Navigation` can exist once per place and language: with 3 places and 2
  languages that is 6 records (the test data generator stops there with a reason).

## Reading it from a frontend

* A page: `GET /api/seite?eq[slug]=kontakt&eq[sprache]=DE`; its language versions with
  `GET /api/seite?eq[original_von_seite_id]=<id>`.
* Its blocks in one request: `GET /api/seite/<id>/parts/auf?expand=1` – all block types in the page order, each record
  with `entity` (block type); the frontend groups them by `bereich`, keeping the order. Alternatively one request per
  block type (`GET /api/textblock?eq[auf_seite_id]=<id>`) and merging by the shared `position`.
* Navigation: `GET /api/navigation?eq[ort]=HAUPTNAVIGATION&eq[sprache]=DE`, then
  `GET /api/navigationspunkt?eq[gehoert_zu_navigation_id]=<id>` (in manual order, sub-items via
  `unterpunkt_von_navigationspunkt_id`).

## Known limits

* **Ordering blocks** works with ↑/↓ (one place per click, across the block types); there is no drag and drop, and parts
  are edited in their own form, not inline in the page form.
* **Top-level pages** form one group across all languages (`sort {by: "Unterseite von"}` has no language in it): the
  German and English top-level pages share one numbering. Grouping by language is planned.
* `Website` is meant as a single record; the model cannot enforce that.
