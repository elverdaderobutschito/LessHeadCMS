# Model template: course management with sidebar groups (packages)

File: [`course-management.puml`](course-management.puml). How to install a template: see the [overview](README.md).

## What it is for

The same model as [Kursverwaltung](kursverwaltung.md) – courses, categories, instructors, members, bookings – with
English names and with the classes grouped into two areas. Use it when your model grows beyond a handful of classes and
the editorial sidebar should show them in meaningful groups.

## The model

| Package (sidebar group) | Classes |
|---|---|
| „Course Offering“ | `Course` (`title`, `description`, `price`, `active`), `Instructor`, `Category` (tree) |
| „Member Management“ | `Member` (`name`, `email`, `member_since`), `Booking` (`booked_on`, `status`: `BOOKED`, `PAID` or `OPEN`) |

The relationships and their delete behaviour are the same as in [Kursverwaltung](kursverwaltung.md): `{cascade}` from
instructor to course and from course and member to booking, a category tree with `{cascade}`, and an n:n „interested in“
with `{label}`. The booking status is an `enum Status` here as well (`BOOKED`, `PAID`, `OPEN` – in Kursverwaltung
`GEBUCHT`, `BEZAHLT`, `OFFEN`); the enum block stands outside the packages, since enums are not grouped in the sidebar.

## Concepts it shows

* **`package` blocks.** `package "Course Offering" { … }` groups classes in the sidebar of the editorial UI. It is purely
  a display grouping: table, column and link table names do not change, and class names must still be unique across all
  packages.
* **Relationships inside and outside packages.** A relationship between two classes of the same package can stand inside
  the block; one that connects two packages (`Booking` → `Course`, `Member` – `Course`) stands after the blocks. Both
  work the same way.
* **English names.** Class, field and relationship names may be in any language; labels may also contain spaces and
  umlauts. Labels in the UI can be translated later (System → Lokalisierung, see
  [Multilingual support](../docs/multilingual.md)).

Everything else (delete behaviour, self-reference, `{title}`) is explained in the [Kursverwaltung](kursverwaltung.md)
template.
