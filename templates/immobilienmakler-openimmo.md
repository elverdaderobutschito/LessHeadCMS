# Model template: real estate agency (OpenImmo)

File: [`immobilienmakler-openimmo.puml`](immobilienmakler-openimmo.puml). Copy it to `schema/` and run
`/bootstrap?token=…&schema=immobilienmakler-openimmo.puml` (see [Deployment](../docs/deployment.md)); it uses only the
regular [PlantUML rules](../docs/plantuml-syntax.md).

## What it is for

The website of a real estate agency: listings with prices, areas, features, energy certificate, texts, images and floor
plans, each with a contact person of the agency. The property data usually comes from the agency's real estate software,
which exports it in the German exchange format **OpenImmo** (XML in a ZIP). The field names, value ranges and grouping
of this template follow OpenImmo 1.2.7, so an import has a fixed target to map to; the website reads the listings via
the REST API.

**Not part of the template:** the import of OpenImmo ZIP files itself. LessHeadCMS does not read OpenImmo; an import
script (or a manual transfer) has to unpack the export, map it to these fields and write the records via the REST API,
including uploading the attachments to the media library.

It is also the largest example of **form sections**: the separator lines in the class body (`-- Preise --`) split the
form of `Immobilie` into collapsible blocks with a jump bar (see "Separator lines = form sections" in the
[PlantUML rules](../docs/plantuml-syntax.md)).

## The model

| Class | Purpose |
|---|---|
| `Immobilie` | One listing, about 110 fields in a head area and nine sections (below). Display label `objekttitel`; `objektnr_extern` (the object number of the real estate software) is unique and the key for updating an imported listing; `veroeffentlicht` is published/draft for the public API. |
| `Kontaktperson` | Contact person for listings: name and position in the head area, phone numbers, e-mail addresses and photo in the section „Kontakt“. |
| `Anbieter` | The agency (OpenImmo "Anbieter") with company name, OpenImmo provider ID (unique), licence identifier, imprint and logo. |

Sections of the `Immobilie` form:

| Section | Contents |
|---|---|
| head area | title, object numbers, OpenImmo object ID, date of the data, published, `slug` for the URL |
| Kategorie & Status | usage, marketing type (buy/rent/…), property type and subtype, sales status, `status_detail`, available from |
| Lage | address, floor, coordinates, whether the address may be published, distances to public transport and motorway |
| Preise | purchase price or rents, ancillary costs, deposit, house money, commission, garage/underground parking prices |
| Flächen | living/usable/plot area, rooms, bedrooms, bathrooms, balconies, parking spaces |
| Ausstattung | features as yes/no fields (bath, flooring, kitchen, heating, lift, barrier-free, …), basement |
| Zustand | year of construction, last modernisation, condition |
| Energieausweis | type, edition, building type, dates, energy source, consumption/demand, efficiency class |
| Texte | short text („Dreizeiler“), description of property, features, location, other information |
| Medien | title image, images, floor plans, documents |
| Verknüpfungen | provider and contact person (added automatically by the form for the relation fields) |

Relationships and their delete behaviour:

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Immobilie` → `Anbieter` | n:1, required | `{cascade}`: deleting the agency deletes its listings |
| `Immobilie` → `Kontaktperson` „Ansprechpartner“ | n:1, optional | restrict: a contact person still named on a listing cannot be deleted (`409`) |
| `Kontaktperson` → `Anbieter` | n:1, required | `{cascade}`: the contact persons go with the agency |
| `titelbild`, `bilder`, `grundrisse`, `dokumente`, `foto`, `logo` | media fields | link removed with the record; the file stays in the media library |

## Deliberate simplifications

* **A selection of the fields.** OpenImmo defines well over 600 fields for every kind of property. The template contains
  about 110 of them – what a typical agency website shows in the list, on the detail page, in the energy certificate
  and for the contact. More fields can be added to `Immobilie` at any time; adding or moving fields never rebuilds a
  table (see [PlantUML rules](../docs/plantuml-syntax.md)).
* **Almost every field is optional.** Exports of real estate software are incomplete: what one listing has, the next one
  lacks. Required are only `objektnr_extern` (needed to recognise a listing on the next import), `veroeffentlicht`, the
  provider and the name of a contact person – otherwise an import would fail on the first gap.
* **Attachments as media fields by group.** OpenImmo describes attachments as a list with a group per file (title image,
  images, floor plans, documents, …). Here every group is a media field of its own; the order inside a field is the
  order of the media list.
* **Normalised enum values.** Enum values must be identifiers, so OpenImmo values that are not are written differently,
  e.g. the energy certificate edition `2014` → `V_2014` (likewise `2008` → `V_2008`). An import maps them back and
  forth. Some value ranges contain only the values typical for a website (e.g. `ObjektartTyp`).
* **`status_detail`** holds the status of the listing as plain text, as the real estate software shows it (e.g.
  „reserviert ab 01.06.“), in addition to the coarse `verkaufsstatus` (open/reserved/sold).
* **Yes/no features** (`bad_wanne`, `heizung_fussboden`, …) are single `bool` fields instead of OpenImmo's nested
  attribute groups; texts such as the type of heating stay free text (`heizungsart_text`).
* **No multilingual content, no price history, no inquiries/leads.** Those belong to the real estate software; the
  template only holds what the website displays.

## Labels in the editorial UI

Field names must be ASCII, so the UI derives labels like „Wohnflaeche“ or „Epass art“. They can be overridden for the
default language in System → Lokalisierung → Übersetzungen („Wohnfläche“, „Energieausweis: Art“) without changing the
diagram (see [Multilingual support](../docs/multilingual.md)).
