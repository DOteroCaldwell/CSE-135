# CSE 135 HW3 — REST API Routes

**Base URL:** `https://reporting.ucsdwrestlingclub.com/api`
**Auth:** HTTP Basic (`grader`) on every route — the reporting vhost is private.
**Content type:** `application/json` in and out.

Four resources, one per table in the analytics schema. They map directly onto the
three data categories the assignment names, plus the session they all belong to.

| Resource | Table | One row is… |
| --- | --- | --- |
| `sessions` | `sessions` | one visit, keyed by the `mod_usertrack` session id |
| `static` | `static` | one pageview's static data (UA, language, screen, support flags) |
| `performance` | `performance` | one pageview's Navigation Timing + total load ms |
| `activity` | `activity` | one interaction event (click, key, scroll, idle, error…) |

---

## Routes

Every resource supports the same five routes. `static` is shown in full; the other
three are identical with the resource name swapped.

| HTTP Method | Example Route | Description |
| --- | --- | --- |
| GET | `/api/static` | Retrieve every entry logged in the static table |
| GET | `/api/static/{id}` | Retrieve a specific entry logged in the static table (that matches the given id) |
| POST | `/api/static` | Add a new entry to the static table |
| DELETE | `/api/static/{id}` | Delete a specific entry from the static table (that matches the given id) |
| PUT | `/api/static/{id}` | Update a specific entry from the static table (that matches the given id) |

### Full route list

| Method | Route | Description |
| --- | --- | --- |
| GET | `/api` | Index of available resources |
| GET | `/api/sessions` | All sessions |
| GET | `/api/sessions/{id}` | One session (`id` is the session id string) |
| POST | `/api/sessions` | Create a session |
| PUT | `/api/sessions/{id}` | Update a session |
| DELETE | `/api/sessions/{id}` | Delete a session **and all of its data** (cascade) |
| GET | `/api/static` | All static rows |
| GET | `/api/static/{id}` | One static row |
| POST | `/api/static` | Create a static row |
| PUT | `/api/static/{id}` | Update a static row |
| DELETE | `/api/static/{id}` | Delete a static row |
| GET | `/api/performance` | All performance rows |
| GET | `/api/performance/{id}` | One performance row |
| POST | `/api/performance` | Create a performance row |
| PUT | `/api/performance/{id}` | Update a performance row |
| DELETE | `/api/performance/{id}` | Delete a performance row |
| GET | `/api/activity` | All activity rows |
| GET | `/api/activity/{id}` | One activity row |
| POST | `/api/activity` | Create an activity row |
| PUT | `/api/activity/{id}` | Update an activity row |
| DELETE | `/api/activity/{id}` | Delete an activity row |

`{id}` is the numeric `id` column for `static`, `performance` and `activity`, and
the `session_id` string for `sessions`.

---

## Strict verb/id semantics

The id is required exactly where REST says it should be, and rejected where it
should not be. Each of these returns **405** with an `Allow` header:

| Request | Result |
| --- | --- |
| `POST /api/static/5` | 405 — POST must not carry an id; the server assigns it |
| `PUT /api/static` | 405 — PUT requires an id |
| `DELETE /api/static` | 405 — DELETE requires an id |

## Status codes

| Code | When |
| --- | --- |
| 200 | `GET` and `PUT` succeeded |
| 201 | `POST` created a row; `Location` header points at the new resource |
| 204 | `DELETE` succeeded (no body) |
| 400 | Body missing or not valid JSON |
| 404 | Unknown resource, unknown id, or too many path segments |
| 405 | Verb/id combination not allowed, or an unsupported method |
| 409 | Constraint violation — unknown `session_id`, or duplicate key |
| 413 | Body over 1 MB |
| 422 | Unknown/read-only field, missing required field, or attempt to change an id |
| 500 | Server fault; detail goes to the error log, never to the client |

## Response shape

Collections are wrapped so a count travels with the rows:

```json
{ "count": 2, "data": [ { "id": 2, "...": "..." }, { "id": 1, "...": "..." } ] }
```

Single items use the same envelope with an object:

```json
{ "data": { "id": 2, "session_id": "srv1a06b55d18a", "language": "en-US" } }
```

Errors:

```json
{ "error": "PUT requires an id: /api/static/{id}" }
```

The `JSON` columns — `static.raw`, `performance.nav_timing`, `activity.detail` —
are returned as nested objects, not strings of JSON, and accept nested objects on
write.

## Optional query parameters

`?limit=` and `?offset=` page a collection. Both are optional; the default is the
full table, as the assignment specifies.

```
GET /api/activity?limit=50&offset=100
```

---

## Examples

```bash
BASE=https://reporting.ucsdwrestlingclub.com/api
AUTH='-u grader:PASSWORD'

# every static row
curl $AUTH $BASE/static

# one row
curl $AUTH $BASE/static/1

# create (no id in the path)
curl $AUTH -X POST -H 'Content-Type: application/json' \
     -d '{"session_id":"srv1a06b55d18a","page":"/index.html","language":"en-US"}' \
     $BASE/static

# update (id required)
curl $AUTH -X PUT -H 'Content-Type: application/json' \
     -d '{"page":"/checkout.html"}' $BASE/static/1

# delete (id required)
curl $AUTH -X DELETE $BASE/static/1
```

## Try it in a browser

`https://reporting.ucsdwrestlingclub.com/api-test.html` is a vanilla-JS console
that exercises every verb, including the 404/405 cases, and renders results in a
grid. With JavaScript off it degrades to plain links to each collection.
