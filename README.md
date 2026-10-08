# LessHeadCMS

A minimal headless CMS for shared hosting: **PlantUML → SQLite → Flight PHP → Preact**.
Put a class diagram into `schema/`, call `/bootstrap?token=…` once — and get a database,
a REST API and an editorial UI, ready to use.

## Features

- Define your content model as a PlantUML class diagram — no SQL, no migrations by hand
- Generic, auto-generated editorial UI: forms, lists, media library, rich text editing
- Public REST API with pagination, filtering, sorting
- Multilingual content and UI
- Fine-grained roles & permissions, including read-only API keys for headless frontends
- Workflow/approval states with tasks
- Runs on plain PHP shared hosting — no Composer or root access needed at runtime

## Quick start

1. Copy the contents of this folder to your web server.
2. Put your class diagram as a `.puml` file into `schema/` (an example, `simplecms.puml`, is already there — replace or
   remove it). If `schema/` contains more than one `.puml` file, you must say which one to use by adding
   `&schema=<filename>` to the URL in the next step.
3. Copy `config.example.php` to `config.php` and set a random `BOOTSTRAP_TOKEN`.
4. Call `https://<host>/bootstrap?token=<BOOTSTRAP_TOKEN>` (add `&schema=<filename>` if needed, see step 2).
5. Log in with the initial password and change it immediately.

## Documentation

- [Deployment & schema editing](docs/deployment.md)
- [PlantUML syntax reference](docs/plantuml-syntax.md)
- [REST API reference](docs/rest-api.md)
- [Editorial UI](docs/editorial-ui.md)
- [Roles and permissions](docs/roles-and-permissions.md)
- [Workflows](docs/workflows.md)
- [Multilingual support](docs/multilingual.md)
- [API users and API keys](docs/api-users.md)

## License

LessHeadCMS is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General
Public License, version 3 or (at your option) any later version (`AGPL-3.0-or-later`). It is distributed without
any warranty; see [LICENSE](LICENSE) for the full text. Third-party licenses: see
[third-party-licenses/](third-party-licenses/).

Copyright (C) 2026 Udo Butschinek
