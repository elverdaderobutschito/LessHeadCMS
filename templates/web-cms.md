# Model template: web CMS base model

File: [`web-cms.puml`](web-cms.puml). Copy it to `schema/` and run `/bootstrap?token=…&schema=web-cms.puml` (see
[Deployment](../docs/deployment.md)); it uses only the regular [PlantUML rules](../docs/plantuml-syntax.md).
A German version is available as `web-cms_de.puml` ([description](web-cms_de.md)).

## What it is for

A starting point for agencies and developers who want to model an arbitrary website in LessHeadCMS: global settings and
navigation, a page tree, pages built from blocks, and channel-neutral content (posts, events, services) that blocks
show. It has been tried out in a real installation: a portfolio website with 34 pages in German and English, sliders,
tiles and navigation was rebuilt with it.

All names in the model – classes, fields, enum values, packages, relationship labels and comments – are English.
**German-speaking editorial teams:** the labels the editorial UI shows for them (class, field and enum names, sections,
relationships) can be translated under **Lokalisierung → Übersetzungen** (see
[Multilingual support](../docs/multilingual.md)); the model itself stays unchanged.

## The idea

* **Layers:** website (global) → page (tree) → block (in an area) → content (channel-neutral). A block belongs to exactly
  one page; content (`Post`, `Event`, `Service`, `Snippet`, …) is not tied to a page and can be shown on many pages, in an
  app or in a newsletter.
* **Role, not position:** the CMS knows *areas* such as `MAIN` or `SIDEBAR` (enum `Area`), not positions such as
  "top left". Where an area appears is decided by the frontend's layout.
* **Fixed core, replaceable parts:** `Website`, `Navigation`, `NavItem`, `Page` and `abstract class Block` are the core
  that every website needs. Replaceable **per project or design**: the enums `Template` and `Area`, the block types and
  the content types.
* **Multilingual content** with the documented [modelling pattern](../docs/multilingual.md#multilingual-data-content-modelling-pattern):
  enum `Language`, a self-reference `translation of {translations} {cascade} {unique}` and `language {unique}` – for
  `Page`, the content types, `Category` and `Snippet`. `Navigation` has a language too, but no original: it is unique per
  location and language. Blocks have no language of their own: they belong to the language version of their page.
  Thanks to `{translations}` the form of these classes shows the language bar (`DE · EN`) and **„Übersetzung anlegen ▸“**
  (create translation): a page is copied into the other language with all its blocks, FAQ items and tiles in the same
  order, page and blocks as drafts („Alle veröffentlichen“ – publish all – in the section of the blocks publishes them in
  one step); link targets point at the page in the target language if it already exists
  ([Create translation](../docs/multilingual.md#create-translation)).
* **Manual order everywhere** with the field type [`sort`](../docs/plantuml-syntax.md): nav items per navigation and
  parent item, pages per parent page, all blocks of a page in **one** order across all block types, FAQ items and tiles
  per block.
* **Parts are edited in their whole** ([composition](../docs/plantuml-syntax.md), `*--`): the blocks of a page, the FAQ
  items of an FAQ block, the tiles of a tile block and the items of a navigation. The editor opens a page and finds its
  blocks in the form – one mixed list in the page order with „+ Block hinzufügen“ (add block: menu of the block types),
  ↑/↓ across the types, and from a block the path „Page › TileBlock › Tile“ back. The block classes are not in the
  sidebar for editors (admins: group „Teile“ – parts).

## The model

| Package | Class | Purpose |
|---|---|---|
| Website | `Website` | Global settings: name, base URL, logo, copyright, SEO defaults. Meant as exactly one record (not enforced). |
| | `Navigation` | One navigation per location (`NavLocation`: main, footer, meta) and language – `{unique}` on both. |
| | `NavItem` | Menu item: internal target (relationship „links to“ `Page`) or external URL, nested via „child of“. |
| Pages | `Page` | A page in one language: title, slug, `template` (layout of the frontend), visibility (`published`), SEO fields. Page tree via „child of“, language versions via „translation of“. |
| Blocks | `Block` (abstract) | What all blocks share: `name` (required display label, see below), `area`, the shared `position`, an optional anchor, visibility. |
| | `HeroBlock`, `TextBlock`, `ImageBlock`, `CtaBlock` | Stage with button, running text with image and link, image/gallery/slider, call to action. |
| | `FaqBlock` + `FaqItem` | Questions and answers, items in their own order per block. |
| | `TileBlock` + `Tile` | Image tiles with link (teasers), tiles in their own order per block. |
| | `ListBlock` | Dynamic list: "the 3 newest posts of category X" (`content_type`, `item_count`, `sort_order`, optional category). |
| | `SnippetBlock` | Embeds a shared `Snippet` (e.g. a contact box used on many pages). |
| | `ImageMeta` (`<<media_meta>>`) | Caption per image in `ImageBlock.images`. |
| Content | `Content` (abstract) | Shared fields of the content types: title, slug, language, teaser, image, visibility. |
| | `Post`, `Event`, `Service` | Post (date, text), event (start, end, location), service (description, price). |
| | `Person`, `Category`, `Snippet` | Author, category (per language), reusable text block. |

**Links:** an internal link target is an optional relationship to `Page` („button target“, „link target“, „links to“),
next to it an optional external URL. The frontend uses the page if set, otherwise the URL.

**`Block.name {title}` is required.** Many blocks have no heading of their own; without this field the block lists would
only show „–“. It is an internal name for the editors („Hero home page“, „Contact box“), not shown on the website.

Relationships and their delete behaviour:

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Page *-- "n" XyBlock : on` (every block type) | composition | the blocks go with the page (implicit cascade) |
| `FaqBlock *-- "1..*" FaqItem`, `TileBlock *-- "1..*" Tile` „belongs to“ | composition, minimum count `"1..*"` | items go with their block; a block without items shows the soft "at least one" warning in its section |
| `Navigation *-- "n" NavItem` „belongs to“ | composition | items go with their navigation |
| `NavItem` → `NavItem` „child of“ | self-reference, optional (no composition: a part has one whole, the navigation) | `{cascade}`: sub-items go with their parent item |
| `Page`, `Post`, `Event`, `Service`, `Category`, `Snippet` → itself „translation of“ | self-reference with `{translations}`, `{unique}` with `language` | `{cascade}`: translations go with the original |
| `Page` → `Page` „child of“ | self-reference, optional | restrict: a page with subpages cannot be deleted (`409`) |
| link targets → `Page`, `Website` „home page“ | n:1, optional | restrict: a page that is still a link target cannot be deleted (`409`) – clear the link first |
| `SnippetBlock` → `Snippet` „shows“, `ListBlock` → `Category` „filtered by“, content → `Category`/`Person` | n:1 | restrict: in use → `409` |

`Post`, `Event` and `Service` → `Category` („belongs to“) use `{filter_by:language}`: the form offers only categories in
the language of the record.

The column names follow from the labels (see [PlantUML rules](../docs/plantuml-syntax.md)): e.g. `on_page_id` (block →
page), `child_of_page_id`, `translation_of_page_id`, `belongs_to_navigation_id`, `child_of_navitem_id`,
`home_page_page_id`.

## Adapting it to a project

* **`Template` and `Area`** describe the layouts and areas of *your* frontend – rename, add or remove values.
* **A new block type:** `class XyBlock extends Block { … }` plus `Page *-- "n" XyBlock : on`.
  Relationships are not inherited, so every block type needs its own „on“ composition – with exactly this label, because
  the shared order is `sort {by: "on"}` (the label is needed because some blocks have a second relationship to `Page`).
  It then appears in the menu „+ Block hinzufügen“ of the page form by itself.
* **A new content type:** `class Xy extends Content { … }`, its own
  `Xy "n" --> "0..1" Xy : translation of {translations} {cascade} {unique}` and a value in enum `ContentType` (for
  `ListBlock`).
* **Languages:** values of enum `Language`. `Navigation` can exist once per location and language: with 3 locations and
  2 languages that is 6 records (the test data generator stops there with a reason).

## Reading it from a frontend

* A page: `GET /api/page?eq[slug]=contact&eq[language]=EN`; its language versions (for `hreflang`) with
  `GET /api/page/<id>/translations` (original, every published version, missing languages) or
  `GET /api/page?eq[translation_of_page_id]=<id>`.
* Its blocks in one request: `GET /api/page/<id>/parts/on?expand=1` – all block types in the page order, each record
  with `entity` (block type); the frontend groups them by `area`, keeping the order. With `?expand=2` every block also
  brings its own parts (`"parts": {"belongs_to": [...]}` – the FAQ items of an FAQ block, the tiles of a tile block), so
  a page needs one request. Alternatively one request per
  block type (`GET /api/textblock?eq[on_page_id]=<id>`) and merging by the shared `position`.
* Navigation: `GET /api/navigation?eq[location]=MAIN&eq[language]=EN`, then
  `GET /api/navigation/<id>/parts/belongs_to?expand=1` or `GET /api/navitem?eq[belongs_to_navigation_id]=<id>` (in manual
  order, sub-items via `child_of_navitem_id`).

## Multiple websites

The model is the standard case: **one website**. Pages and navigations belong to no website, and `Website` has exactly
one record. The end of `web-cms.puml` documents, as a commented-out block, how to run several websites in one CMS; its
lines are valid as they are once the leading quote is removed. Only existing means are used:

* **Pages and navigations become parts of their website:** `Website *-- "n" Page : belongs to {unique: slug_per_site}` and
  `Website *-- "n" Navigation : belongs to {unique}`. The website form then lists its pages and navigations; deleting a
  website deletes them with it. Both compositions have the label „belongs to“, so their keys for `/parts/` carry the
  table of the part: `belongs_to_navigation` and `belongs_to_page` – `GET /api/website/<id>/parts/belongs_to_page` lists
  the pages of a website in their order.
* **Order per website and parent page:** `Page.position` becomes `sort {by: "belongs to", "child of"}` – the top-level
  pages of each website have their own numbering, subpages are ordered per parent page as before.
* **One navigation per location, language and website:** the `{unique}` on the new „belongs to“ relationship adds it
  to the `{unique}` group of `Navigation` (location + language + website).
* **Page keeps its `{unique}` group** (language + „translation of“) for the language versions. `{translations}` allows
  nothing else in that group (otherwise it would not be clear what makes a language version). A translation is created in
  the website of its original, because „Übersetzung anlegen“ copies the relationship.
* **Slug unique per website and language:** the named group `slug_per_site` – `slug` and `language` get
  `{unique: slug_per_site}` (language stays in the unnamed group as well: `{unique} {unique: slug_per_site}`), and so does
  the „belongs to“ composition. The same slug may then occur in every website and in every language, but only once per
  website and language; a second one gets `409` („Die Kombination aus Slug, Language und Belongs to ist bereits
  vergeben“, with the labels in the display language). A translation stays free: its new language is part of the group.
* **Content types** (`Post`, `Event`, `Service`, `Category`, `Snippet`) can stay shared across the websites, or each gets
  its own composition (`Website *-- "n" Post : belongs to`) – a project decision.
* **Switching later:** existing pages and navigations have to be assigned to a website. „Änderungen prüfen“ (check
  changes) asks for a backfill value for the new required columns – the id of an existing website; data and page order
  are kept. If two existing pages share slug and language, the new group `slug_per_site` is a blocker with the list of
  these rows until the duplicates are cleaned up.

## Known limits

* **Ordering blocks** works with ↑/↓ (one place per click, across the block types); there is no drag and drop, and parts
  are edited in their own form, not inline in the page form.
* **Top-level pages** form one group across all languages (`sort {by: "child of"}` has no language in it): the German and
  English top-level pages share one numbering. Grouping by language is planned.
* `Website` is meant as a single record; the model cannot enforce that.
* **„Übersetzung anlegen“ copies once:** later changes to the original are not carried over into the translations. The
  `slug` is copied unchanged (it is not `{unique}`; in another language it usually should be translated by hand).
  Navigations are not copied – they have a language but no original.
