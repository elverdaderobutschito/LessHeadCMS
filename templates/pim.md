# Model template: product information management (PIM)

File: [`pim.puml`](pim.puml). Copy it to `schema/` and run `/bootstrap?token=…&schema=pim.puml` (see
[Deployment](../docs/deployment.md)); it uses only the regular [PlantUML rules](../docs/plantuml-syntax.md).

## What it is for

A starting point for maintaining product data centrally and delivering it to a webshop, a print catalogue or a
marketplace via the REST API: products with variants, translated texts, images, a category tree, brands, a product type
and the channels a product appears in. It is also a teaching example for "how do I model product data" with the means
LessHeadCMS has: fixed, typed fields instead of a runtime attribute system.

## The model

| Class | Purpose |
|---|---|
| `Produkt` | A product or a variant. Fixed fields: `titel` (display label), `sku` (unique), `ean`, short and long description, `preis`, `menge` + `einheit` (e.g. `0.8` `kg`), `farbe`/`groesse` (the variant attributes), `bilder` (media library, ordered list – the first image is the main image), `aktiv` (published/draft for the public API). |
| `Produkttext` | Translation of a product's texts into another language: `sprache`, `titel`, short and long description. |
| `Kategorie` | Category tree via the self-reference „Unterkategorie von“; a category without a parent is a root category. |
| `Marke` | Brand/manufacturer with website and logo. |
| `Klassifikation` | Product type (`TEX` „Textil“, `ELE` „Elektronik“, …), at most one per product, independent of the category tree. |
| `Kanal` | Output channel (webshop, print, marketplace). |

Relationships and their delete behaviour:

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Produkt` → `Produkt` „Variante von“ | n:1 self-reference, optional | `{cascade}`: deleting a master product deletes its variants |
| `Produkt` – `Produkt` „verwandt mit“ | n:n self-reference, `{symmetric}` | link removed |
| `Produkttext` → `Produkt` „Text von“ | n:1, required | `{cascade}`: the translations go with the product |
| `Produkt` – `Kategorie` „eingeordnet in“ | n:n | link removed |
| `Kategorie` → `Kategorie` „Unterkategorie von“ | n:1 self-reference, optional | restrict: a category with subcategories cannot be deleted (`409`) |
| `Produkt` → `Marke`, `Produkt` → `Klassifikation` | n:1, optional | restrict: in use → `409` |
| `Produkt` – `Kanal` „veröffentlicht in“ | n:n | link removed |
| `bilder`, `bild`, `logo` | media fields | link removed with the record; the file stays in the media library |

Rules enforced by the database and the API: `sku` is unique, brand and channel names are unique, the classification
`code` is unique, and every product has at most one `Produkttext` per language (`UNIQUE (sprache, text_von_produkt_id)`).

## Deliberate simplifications

* **No dynamic attribute system.** Many PIM systems let administrators define attributes, attribute groups and
  attribute sets at runtime and store the values generically. Here a product has fixed fields like every other
  LessHeadCMS model. If you need more attributes (material, battery capacity, …), add fields to `Produkt` – or, if they
  differ strongly between product types, separate classes per product type with an `abstract class` holding the common
  fields (see "Inheritance" in the [PlantUML rules](../docs/plantuml-syntax.md)).
* **Variants** are plain products with a self-reference „Variante von“ instead of variant axes and option sets. A
  variant is a record of its own with its own `titel` and `sku`; it does **not** inherit values from its master
  product – fill in what differs (`farbe`, `groesse`, …) and what the channel needs.
* **Associations** (cross-sell, up-sell, replacement product) are one generic, symmetric relationship „verwandt mit“
  instead of several typed ones. If you need the types, add a second n:n self-reference per type (with `{label}`).
* **Units** are a plain text field `einheit` next to `menge`, without a conversion table.
* **Images** use the built-in media library (`media` fields); there is no crop, rights or rendition management.
* **Channels** are a simple entity (product ↔ channel), without per-channel completeness or required-field rules.
* **No classification standards** (eCl@ss, UNSPSC, GS1), **no price lists or multiple currencies** (they belong to
  commerce/ERP rather than PIM; `preis` is a single list price), **no import/export configuration or search index**.

## Multilingual content

The diagram is written in the default language: `titel`, `kurzbeschreibung` and `beschreibung` of `Produkt` hold the
original texts. Translations are records of `Produkttext` with the same means as the documented
[modelling pattern for multilingual data content](../docs/multilingual.md#multilingual-data-content-modelling-pattern):
an `enum Sprache`, `{unique}` on the language and on the relationship to the original, `{cascade}` for deleting along.

The difference to the documented pattern: the pattern links translations as further records of the **same** class via
a self-reference. For products that would copy all language-independent data (SKU, price, EAN, brand, categories,
images) into every language version, and the SKU could no longer be unique – a class has only one `{unique}` group, so
`sku` would become part of the rule „language once per original“, and two originals with the same SKU would no longer
be rejected (the original has `NULL` in the relationship column). The texts therefore live in a separate class that
holds only what is translated.

Reading via the API: all translations of product 1 with `GET /api/produkttext?eq[text_von_produkt_id]=1`, one language
additionally with `&eq[sprache]=EN`. Categories, brands and channels are not translated in this template; add a text
class for them the same way if you need it.

## Labels in the editorial UI

Field names must be ASCII, so the UI derives labels like „Sku“, „Ean“ or „Groesse“. They can be overridden for the
default language in System → Lokalisierung → Übersetzungen („SKU“, „EAN“, „Größe“) without changing the diagram (see
[Multilingual support](../docs/multilingual.md)).

## Origin

This model is inspired by concepts from OpenDXP (github.com/open-dxp/opendxp, GPLv3 fork of Pimcore) and from general
PIM literature – it is not a copy of any particular product.
