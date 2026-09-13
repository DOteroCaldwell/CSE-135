# UCSD Wrestling Club — CSE 135 web analytics platform

**Author:** Diego Otero-Caldwell (<doterocaldwell@ucsd.edu>), solo
**Course:** CSE 135 — Server-Side Web Applications, Fall 2026
**Repository:** <https://github.com/DOteroCaldwell/CSE-135>

A web analytics platform built across HW1–HW5, deployed to one DigitalOcean droplet
behind four Apache virtual hosts. Grader credentials are in the submitted
`GRADER.md`, not here.

## Live sites

| Host | Role |
| --- | --- |
| <https://ucsdwrestlingclub.com> | Team site; links every deliverable |
| <https://test.ucsdwrestlingclub.com> | Instrumented target site; `collector.js` runs here |
| <https://collector.ucsdwrestlingclub.com/collector.js> | Collector and `/log` ingestion endpoint |
| <https://reporting.ucsdwrestlingclub.com> | REST API and the authenticated reporting application |

HW5 deliverables on the reporting host:

- [Sign in](https://reporting.ucsdwrestlingclub.com/login.php) · [Dashboard](https://reporting.ucsdwrestlingclub.com/)
- Reports: [Page load cost](https://reporting.ucsdwrestlingclub.com/reports/page-load-cost.php) (performance) · [Engagement](https://reporting.ucsdwrestlingclub.com/reports/engagement.php) (behaviour) · [Audience](https://reporting.ucsdwrestlingclub.com/reports/audience.php) (audience)
- [Saved reports](https://reporting.ucsdwrestlingclub.com/saved/) — fixed views published by analysts, exportable to PDF; all a viewer sees
- [User management](https://reporting.ucsdwrestlingclub.com/users.php) — roles and analyst section assignments; super admin only
- REST API: `/api/{sessions,static,performance,activity,resources}`, session or HTTP Basic auth

## What it does

```
test site (browser)
  → collector.js  static / performance / activity / resources, via sendBeacon
  → POST /log     PHP; one transaction per payload; session row upserted
  → MySQL         sessions · static · performance · activity · resources · users · user_sections · saved_reports
  → /api/…        REST, authenticated, section-authorised
  → reporting app dashboard → three reports → saved reports → PDF
```

Each report opens with a question, computes the answer from the data in scope, and
states how much data it rests on. No page hardcodes which page is slow, which is
abandoned, or which device dominates.

| Section | Report | Guiding question |
| --- | --- | --- |
| Performance | Page load cost | If we could fix one thing about this site's performance, what should it be, and what is it worth? |
| Behaviour | Engagement | Do visitors engage with a page once it has loaded, and where do they give up? Does a slow load cost attention? |
| Audience | Audience | Who is visiting, and what can their devices and browsers handle? |

Every report has charts, data tables, a computed verdict, a discussion interpolated
from the data, and an authored analyst comment on what the analysis cannot see.
Every figure carries a coverage badge with its sample size and data-derived caveats.

## Technical particulars

1. **Stack.** Ubuntu 24.04, Apache 2.4 with php-fpm 8.3, MySQL 8. Plain PHP with no
   framework, build step, or Composer; vanilla JavaScript in the collector and none
   in the reporting application.
2. **Authentication.** Server-side PHP sessions with bcrypt passwords, a single
   username-or-email field, constant-time failure (a dummy hash is verified on a
   miss), and throttling by identifier and client IP. Session id regenerates on
   login; the cookie is host-only, `Secure`, `HttpOnly`, `SameSite=Lax`; every
   state-changing form carries a CSRF token; `?next=` rejects off-site targets.
3. **Authorisation.** Super admin sees everything including user management. An
   analyst is assigned sections (`user_sections`) and gets an explanatory 403
   outside them, on pages and on `/api/*` alike; a viewer reaches only saved
   reports. The nav lists only what the account may open.
4. **REST API.** Strict verb semantics over five resources, column whitelists, and
   the same `users` table behind either a session cookie or HTTP Basic, so `curl -u`
   and the browser test console both work.
5. **Saved reports.** An analyst saves any filtered view; the rendered HTML is
   stored at save time, so the saved copy is fixed while the live report moves on.
6. **PDF export.** Headless Chrome prints the saved snapshot, with CSS inlined, to a
   file outside every web root, streamed through an auth-gated URL. Chrome rather
   than dompdf or wkhtmltopdf because the charts need a current CSS engine.
7. **Charts.** [Charts.css](https://chartscss.org/): each chart is an HTML table
   drawn by CSS, so it renders without scripting and reads as a table to assistive
   tech. The reporting application ships about 12 KB of gzipped CSS and zero bytes
   of JavaScript.
8. **Statistics.** Percentiles are interpolated in PHP; stacked bars use means
   (additive), grids use medians and p90. The performance verdict ranks phases by
   recoverable time above each phase's own 10th percentile; the behaviour report
   compares engagement across four load-time cohorts of the same pageviews.
9. **Verification scripts.** `src/tools/verify/bias-test.sh` seeds datasets with a
   known bottleneck and asserts the platform finds it; `auth-matrix.sh` signs in as
   every role and asserts every gated URL and API resource. Both run before each
   deploy; the auth matrix also runs against production.
10. **Contingencies.** Styled 404 and 403 pages; application 403s state the reason
    instead of redirecting to login. Export degrades to a readable saved page when
    the renderer is unavailable. Everything works with JavaScript disabled.
11. **Deployment.** GitHub Actions rsyncs each `sites/<vhost>/` to its web root and
    `src/sql/` to a non-web directory on push to `main`; migrations are applied by
    hand. A Docker devstack in `src/tools/devstack/` rehearses everything locally,
    including export. Details in [`deploy/README.md`](deploy/README.md).

## Use of AI

Claude Code was used throughout, as a pair-programmer rather than a code generator.
Where it earned its keep: diagnosing the Charts.css cascade bug from first
principles; arguing through mean-versus-median choices until the report stopped
claiming a page would load in 0 ms; writing the bias test and the auth matrix, which
are the two things that made "the verdict is computed" and "the roles are enforced"
claims rather than hopes; and reviewing the repo against the HW5 spec, where it
found three of four "musts" missing that I had mentally filed as done.

Where it was not: it needed a running devstack to catch its own mistakes (a zsh
variable-name collision, bash 3.2 on macOS, a working directory that moved between
commands), and its first drafts of the analyst comments for the new reports were
written against seeded fixtures and had to be rewritten against the real data. Left
unsupervised it produces confident prose faster than it produces verified facts;
every number in this README and in the reports was checked by running the thing.

## Roadmap

- **Session paths.** The data is already joined on the server-minted session id;
  the natural next report is entry → path → exit, which would turn "bounce" into
  "bounced *where*".
- **Email delivery** of exports, and per-viewer subscriptions to a saved report.
- **Real traffic.** Most of what the dashboard shows is generated traffic, flagged
  on every panel and excludable with one filter. Once the club site is public the
  same platform points at it with no code change.
- **Percentiles in SQL** if row counts grow by orders of magnitude; `app/Stats.php`
  is the file to revisit.
- **Log reconciliation.** The `<noscript>` pixel records JavaScript-off visits in
  the collector's access log; counting them into the audience report is a manual
  step today.
- **Housekeeping before the site goes public:** delete the grader accounts, rotate
  the deploy key, and note that an earlier credential leak in this repository's
  history was rotated on 2026-09-05.

## Repository layout

- `sites/<vhost>/` — one directory per Apache vhost, deployed to that vhost's web root
- `sites/reporting/app/` — `Auth`, `Sections`, `Reports`, the metric layer, views
- `src/sql/` — schema and numbered migrations; `004`/`005` are generated seeds, gitignored
- `src/tools/` — devstack, fixture seeder, user-seed generator, verifiers
- `deploy/` — Apache vhost samples and deploy notes
- `docs/specs/` — assignment specifications
