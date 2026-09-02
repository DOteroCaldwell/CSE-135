# reporting.ucsdwrestlingclub.com

The REST API and the authenticated MVC reporting dashboard. Deploys to
`/var/www/reporting.ucsdwrestlingclub.com`.

Populated starting HW4. REST semantics (strict):

- `GET /api/<resource>` → all rows; `GET /api/<resource>/{id}` → one row
- `POST /api/<resource>` → create (no id in path)
- `PUT /api/<resource>/{id}` → update (id required)
- `DELETE /api/<resource>/{id}` → delete (id required)

HW5 auth roles: **super admin** / **analyst** (section-scoped) / **viewer**.
Unauthenticated users must never reach any report.
