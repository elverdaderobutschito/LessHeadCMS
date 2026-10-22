# Model template: course management (Kursverwaltung)

File: [`kursverwaltung.puml`](kursverwaltung.puml). How to install a template: see the [overview](README.md). The same
model with English names and sidebar groups: [`course-management.puml`](course-management.md).

## What it is for

A club, a school or an adult education centre offers courses; members book them. The model is a typical small business
application – more relationships than a blog, and it has to answer the question „what happens to the bookings when a
course is deleted?“.

## The model

| Class | Purpose |
|---|---|
| `Kurs` | The course: `titel`, `beschreibung` (rich text), `preis` (decimal), `aktiv` (published/draft). |
| `Kategorie` | Category tree (e.g. Sport → Yoga): a category without a parent is a root category. |
| `Kursleiter` | Who teaches the course. |
| `Mitglied` | A member: `name`, `email`, `mitglied_seit` (date). |
| `Buchung` | A member books a course: `gebucht_am` (date) and `status` (`GEBUCHT`, `BEZAHLT` or `OFFEN`, see below). |

| Relationship | Kind | When the target is deleted |
|---|---|---|
| `Kurs` → `Kategorie` „gehört zu“ | n:1, required | restrict: a category with courses cannot be deleted (`409`) |
| `Kurs` → `Kursleiter` „geleitet von“ | n:1, required | `{cascade}`: the instructor's courses are deleted too |
| `Kategorie` → `Kategorie` „Unterkategorie von“ | n:1 self-reference, optional | `{cascade}`: subcategories are deleted too, to any depth |
| `Buchung` → `Mitglied` „gebucht von“ | n:1, required | `{cascade}`: the member's bookings are deleted too |
| `Buchung` → `Kurs` „für Kurs“ | n:1, required | `{cascade}`: the course's bookings are deleted too |
| `Mitglied` – `Kurs` „interessiert an“ | n:n | link removed |

## Concepts it shows

* **Delete behaviour.** Without a marker a row that is still referenced cannot be deleted (`409`, restrict). With
  `{cascade}` the dependent rows go along – and **recursively**: deleting a `Kursleiter` deletes their courses, and with
  them all bookings of those courses. That is convenient but powerful; the template uses it deliberately to show the
  chain. In a real application, think about each `{cascade}` – often restrict is the safer choice.
* **Self-reference as a tree.** `Kategorie "n" --> "0..1" Kategorie` gives every category an optional parent. `"0..1"`
  makes the relationship optional (otherwise even the first category would need a parent). The UI prevents cycles.
* **Labels become column names.** „geleitet von“ → column `geleitet_von_kursleiter_id`; the label is also the field
  label in the form.
* **`{title}`.** It decides how a row is shown in dropdowns and lists. `Buchung` has two `{title}` fields, so a booking
  appears as „23.09.2026, BEZAHLT“ (the date in the date format of the display language).
* **n:n with `{label}`.** „interessiert an“ is shown in the form instead of the plain class name „Kurs“.
* **`enum` as a value range.** `enum Status { GEBUCHT, BEZAHLT, OFFEN }` limits `status` to these three values: the form
  shows a dropdown in this order, the API rejects other values (`422`), and the database checks them as well. An enum
  field can carry `{title}` like a text field. The values can be given readable labels per language in System →
  Lokalisierung → Übersetzungen („bezahlt“) without changing the diagram.
* **Types `decimal` and `date`.** `preis` accepts decimal numbers, `gebucht_am` and `mitglied_seit` a date picker.

See the [PlantUML rules](../docs/plantuml-syntax.md) for all details.
