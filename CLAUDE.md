# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

The team repo for UCSD CSE 135 (Server-Side Web Applications), Fall 2026 — a cumulative web analytics platform deployed to a DigitalOcean droplet. Layout:

- `sites/<vhost>/` — one directory per Apache vhost; **its contents deploy to that vhost's web root** (the repo root is not the web root). `main` → team site, `test`/`collector`/`reporting` populated in later HWs.
- `deploy/` — deploy tooling and sample Apache vhost configs; see `deploy/README.md`.
- `.github/workflows/deploy.yml` — GitHub Actions auto-deploy (rsync over SSH, then reload Apache) on push to `main`.
- `docs/specs/` — the `HW1`–`HW5` assignment specifications clipped from Canvas.
- `README.md` — Gradescope submission stub (team, grader credentials, per-HW write-ups).

The five assignments are cumulative: each builds directly on the infrastructure and code from the previous one. Together they construct a single **web analytics platform** (conceptually similar to Google Analytics) deployed on a live server. When writing code for this course, treat the whole arc as one evolving system, not five disconnected exercises.

Reference material and tutorials for the whole project live at **https://cse135.site** (the "collector tutorial", CGI examples, dataviz walkthroughs are all there). The assignments deliberately underspecify later parts and expect independent research.

## Target deployment architecture

All work is deployed to a **DigitalOcean Droplet running a LAMP+Node stack** (Ubuntu, Apache, MySQL/PostgreSQL, PHP, Node), fronted by Apache virtual hosts. The system is split across **four vhosts by role** — this separation is the backbone of the whole project:

- `ucsdwrestlingclub.com` — main team site: homepage, member pages, links to every HW deliverable. This is the graders' entry point; **anything not linked from the homepage is treated as not submitted.**
- `test.ucsdwrestlingclub.com` — the instrumented "target" site (e.g. the provided "Wrecked Tech" site) that the collector script runs on.
- `collector.ucsdwrestlingclub.com` — serves `collector.js` and hosts the ingestion endpoint (`/log`) that writes collected data into the database.
- `reporting.ucsdwrestlingclub.com` — the REST API and the authenticated MVC reporting dashboard.

Apache is also configured for: SSL via Certbot, gzip/deflate compression, basic-auth on the team site, a spoofed `Server: CSE135 Server` response header, custom 404 page, and enriched access logging (COMBINED format + Client Hints). GitHub-based auto-deploy (webhook or pipeline) to the Droplet is expected rather than live editing.

## Data flow (the core of the system)

```
test site (browser)
  → collector.js  (served from collector.ucsdwrestlingclub.com)
      collects 3 data categories:
        • Static      — user agent, language, cookie/JS/CSS/image support, screen & window dims, connection type
        • Performance — Navigation Timing object, load start/end, total load ms
        • Activity    — errors, mouse move/click/scroll, keyboard, idle gaps ≥2s, page enter/leave
  → POST to /log endpoint (collector vhost, Node or PHP)
  → MySQL/PostgreSQL  (typically separate static / performance / activity tables)
  → REST API (reporting vhost)  — /api/<resource>[/<id>], strict REST semantics
  → reporting dashboard (charts + CRUD grids, behind auth)
```

Two hard problems the assignments call out explicitly, worth keeping in mind whenever touching the collector or ingestion code:
- **Sessioning**: collected data must be tied to a specific user session, and the client-collected data must be reconcilable with server-side access logs. The approach is left to the implementer.
- **sendBeacon is the suggested transport** for the collector (Fetch/XHR are alternatives), because activity data must survive page unload.

## REST endpoint conventions (HW3+)

Endpoints must follow strict REST semantics — graders check this:
- `GET /api/<resource>` → all rows; `GET /api/<resource>/{id}` → one row
- `POST /api/<resource>` → create (**no id in path**)
- `PUT /api/<resource>/{id}` → update (**id required**)
- `DELETE /api/<resource>/{id}` → delete (**id required**)

## Authorization model (HW4 → HW5)

The reporting app evolves from a simple basic/admin split (HW4) to **three roles (HW5)**:
- **super admin** — everything, including user management CRUD.
- **analyst** — can view/report, optionally scoped to specific data sections (e.g. an analyst limited to performance data only). Section-scoped authorization is expected, not just a global flag.
- **viewer** — read-only access to saved/static reports.

Unauthenticated users must never reach any report. HW5 also requires a PDF export of reports, ≥3 report categories with appropriate chart+table+"analyst comment" combos, and graceful 403/404/script-off handling.

## Key constraints that affect all code

- **Valid HTML is mandatory** — every page must pass https://validator.w3.org/nu/ cleanly.
- **Client-side JavaScript must be vanilla JS** — no frameworks or heavyweight libraries for the CGI/echo/collector work (charting/CRUD-grid libraries on the reporting dashboard are allowed). Watch bundle weight; the spec penalizes multi-megabyte JS payloads.
- **Progressive enhancement** — interactive demos (e.g. the HW2 echo form) must degrade to a reduced but functional mode with JavaScript disabled.
- **Server-side sessions** — session/state demos must use server-side sessions (cookies / dirty URLs / hidden fields), not `localStorage`.
- **Language variety (HW2)** — the "3 ways" demos must be implemented in three *different* languages from the allowed list (PHP, Node with/without Express, JSP, Ruby, Python, Go, Rust, ColdFusion, C/C++); Perl is excluded because it's provided, and you can't pick Node twice.
- **Discoverability** — every deliverable must be reachable via a link from the main `index.html`, or it won't be graded.

## Deliverables & grading

Each assignment is submitted to **Gradescope** and requires a `README.md` (team member names, server IP + grader SSH/login credentials, and per-assignment design write-ups) plus specifically-named screenshot files. The exact required filenames and README sections are listed in each `HW*.md` "Submission" section — consult the relevant file before finalizing a deliverable, since misnamed files lose points.
