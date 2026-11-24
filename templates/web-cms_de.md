# Modellvorlage: Web-CMS-Grundmodell (deutsche Fassung)

Datei: [`web-cms_de.puml`](web-cms_de.puml). Nach `schema/` kopieren und `/bootstrap?token=…&schema=web-cms_de.puml`
aufrufen (siehe [Vorlagen ausprobieren](README.md#trying-a-template)).

Das ist die deutsche Fassung von [`web-cms.puml`](web-cms.puml) für deutschsprachige Projekte: Klassen, Felder,
Enum-Werte, Packages, Beziehungs-Labels und Kommentare sind deutsch, das Modell ist **strukturell identisch** mit der
englischen Fassung (gleiche Klassen, Felder, Typen, Pflichtfelder, Feldregeln, Abschnitte, Enums, Beziehungen,
Kompositionen, `{translations}`, `{unique}`, `{filter_by}`, `sort` und `<<media_meta>>`). Ein Test vergleicht die beiden
Fassungen bei jedem Lauf.

**Beschreibung, Anpassung, Leseweg für Frontends und Grenzen:** siehe [web-cms.md](web-cms.md). Die Namen dort
entsprechen diesen hier:

| Englisch | Deutsch |
|---|---|
| Packages Website / Pages / Blocks / Content | Website / Seiten / Bloecke / Inhalte |
| `Page`, `NavItem`, `ImageBlock`, `FaqItem`, `TileBlock`, `Tile`, `ListBlock`, `SnippetBlock`, `ImageMeta` | `Seite`, `Navigationspunkt`, `BildBlock`, `FaqEintrag`, `KachelBlock`, `Kachel`, `ListenBlock`, `TextbausteinBlock`, `Bildangabe` |
| `Content`, `Post`, `Event`, `Service`, `Category`, `Snippet` | `Inhalt`, `Beitrag`, `Termin`, `Leistung`, `Kategorie`, `Textbaustein` |
| Enums `Language`, `NavLocation`, `Area`, `ContentType`, `ListSort` | `Sprache`, `Navigationsort`, `Bereich`, `Inhaltstyp`, `Listensortierung` |
| Labels „on“, „belongs to“, „child of“, „translation of“, „links to“, „home page“ | „auf“, „gehoert zu“, „Unterseite von“ / „Unterpunkt von“, „Original von“, „verlinkt auf“, „Startseite“ |
| Spalten `on_page_id`, `child_of_page_id`, `translation_of_page_id` | `auf_seite_id`, `unterseite_von_seite_id`, `original_von_seite_id` |

Der Kommentarblock **„Mehrere Websites“** am Ende der Datei entspricht dem Block „Multiple websites“ der englischen
Fassung (Beschreibung: [web-cms.md, „Multiple websites“](web-cms.md#multiple-websites)); seine Zeilen sind ohne das
führende `'` gültig. Die benannte Gruppe `slug_per_site` (Slug eindeutig je Website und Sprache) heißt in beiden Fassungen
gleich – Gruppennamen sind technische Schlüssel und werden nicht übersetzt.

**Welche Fassung?** Die Namen werden zu Tabellen, Spalten und API-Pfaden (`/api/seite`, `eq[sprache]=DE`). Wer ein
englisches Frontend oder eine internationale Redaktion hat, nimmt `web-cms.puml` und übersetzt die Beschriftungen der
Oberfläche unter Lokalisierung → Übersetzungen; die deutsche Fassung passt, wenn auch Code und API deutsch sein sollen.
Ein späterer Wechsel zwischen den Fassungen ist eine Umbenennung (`{renamed_from:…}` je Klasse, Feld und Beziehung).
