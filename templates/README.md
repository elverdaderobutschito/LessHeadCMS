# Model templates

Small, documented example models for LessHeadCMS – to read, to learn a modelling pattern, or to try out as the starting
point of your own diagram. Each template is a `.puml` file with a `.md` next to it that explains what the model stands
for and which LessHeadCMS concept it shows.

| Template | In one sentence | Concept |
|---|---|---|
| [simplecms](simplecms.md) | Articles with category, author and tags – the default schema of LessHeadCMS. | Getting started: types, required/optional, n:1, n:n |
| [kursverwaltung](kursverwaltung.md) | Courses, instructors, members and bookings of a club or school. | Delete behaviour (`{cascade}` vs. restrict), category tree, `{title}` |
| [course-management](course-management.md) | The same course model with English names, grouped into two sidebar areas. | `package` blocks |
| [wordpress-like](wordpress-like.md) | Posts, pages, categories, tags and authors of a website. | Media field, restrict everywhere |
| [wordpress-like-abstract](wordpress-like-abstract.md) | The same website with the shared fields of posts and pages written once. | `abstract class` and `extends` |
| [pim](pim.md) | Products with variants, translations, images, categories, brands and channels. | Product data, multilingual content, `{symmetric}`, `{unique}` |

## Trying a template

Copy the template to the schema folder, then install it with `/bootstrap`:

* **In the repository:** copy `templates/<name>.puml` to `backend/schema/` and run `./build.sh assemble`, which copies it
  to `deploy/schema/`. Remove it from `backend/schema/` again afterwards – every `.puml` there is shipped with each
  build.
* **On an installation** (the contents of `deploy/` on your server): copy `templates/<name>.puml` to `schema/`.

Then call

```
https://<host>/bootstrap?token=<BOOTSTRAP_TOKEN>&schema=<name>.puml
```

The parameter `schema` is the **file name including `.puml`** (e.g. `schema=pim.puml`); without it, `/bootstrap` only
works while `schema/` contains a single file. `/bootstrap` creates the tables of a new installation (see
[Deployment](../docs/deployment.md)); an installation that is already running changes its model via System → Schema.

Or simply read the diagrams – each `.md` explains the model without requiring knowledge of the diagram syntax. All rules
are in the [PlantUML rules](../docs/plantuml-syntax.md).
