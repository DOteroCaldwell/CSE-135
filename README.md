# UCSD Wrestling Club — CSE 135 web analytics platform

**Author:** Diego Otero-Caldwell (<doterocaldwell@ucsd.edu>), solo
**Course:** CSE 135 — Server-Side Web Applications, Fall 2026
**Repository:** <https://github.com/DOteroCaldwell/CSE-135>

A web analytics platform built across HW1–HW5 and deployed to one DigitalOcean
droplet behind four Apache virtual hosts. Grader credentials are **not** in this
file; they are in the `GRADER.md` submitted with the assignment.

## Live sites

| Host | Role |
| --- | --- |
| <https://ucsdwrestlingclub.com> | Team site. Every deliverable from every assignment is linked from this page. |
| <https://test.ucsdwrestlingclub.com> | The instrumented target site ("Wrecked Tech"); `collector.js` runs here. |
| <https://collector.ucsdwrestlingclub.com/collector.js> | The collector and its `/log` ingestion endpoint. |
| <https://reporting.ucsdwrestlingclub.com> | The REST API and the authenticated reporting application. |

HW5 deliverables on the reporting host:

- [Sign in](https://reporting.ucsdwrestlingclub.com/login.php) · [Dashboard](https://reporting.ucsdwrestlingclub.com/)
- Reports, one per category: [Page load cost](https://reporting.ucsdwrestlingclub.com/reports/page-load-cost.php) (performance) · [Engagement](https://reporting.ucsdwrestlingclub.com/reports/engagement.php) (behaviour) · [Audience](https://reporting.ucsdwrestlingclub.com/reports/audience.php) (audience)
- [Saved reports](https://reporting.ucsdwrestlingclub.com/saved/) — fixed views published by analysts, each exportable to PDF; the only thing a viewer sees
- [User management](https://reporting.ucsdwrestlingclub.com/users.php) — roles and analyst section assignments (super admin only)
- REST API: `/api/{sessions,static,performance,activity,resources}`, strict REST verbs, session or HTTP Basic auth

## What it does

```
test site (browser)
  → collector.js  static / performance / activity / resources, via sendBeacon
  → POST /log     PHP, one transaction per payload, session row upserted
  → MySQL         sessions · static · performance · activity · resources · users · user_sections · saved_reports
  → /api/…        REST, authenticated, section-authorised
  → reporting app dashboard → three reports → saved reports → PDF
```

The platform is built around questions rather than metrics. Each report opens with
a question, computes its answer from whatever data is in scope, ranks the
candidates, and says how much data the answer rests on. Nothing on any page
hardcodes which page is slow, which page is abandoned, or which device dominates.

| Section | Report | Guiding question |
| --- | --- | --- |
| Performance | Page load cost | If we could fix one thing about this site's performance, what should it be, and what is it worth? |
| Behaviour | Engagement | Do visitors engage with a page once it has loaded, and where do they give up? Does a slow load actually cost attention? |
| Audience | Audience | Who is visiting, and what can their devices and browsers actually handle? |

Every report has charts, data tables, a computed verdict, a written discussion
interpolated from the data, and an authored analyst comment that says what the
analysis cannot see. A coverage badge on every figure states the sample size and
any caveats, derived from the data rather than written for it.

## Technical particulars

**Stack.** Ubuntu 24.04, Apache 2.4 with php-fpm 8.3, MySQL 8. PHP throughout the
reporting application; no framework, no build step, no Composer. Vanilla JavaScript
in the collector, and **no JavaScript at all** in the reporting application.

**Authentication.** Server-side PHP sessions; bcrypt via `password_hash()`; one
identifier field that accepts a username or an email; failed logins take the same
time whether or not the account exists (a dummy hash is verified on a miss);
throttling keyed on the identifier as typed and on the client IP; session id
regenerated on login; host-only `Secure`/`HttpOnly`/`SameSite=Lax` cookie;
synchroniser-token CSRF on every state-changing form; open-redirect guard on
`?next=`. The REST API accepts the same session cookie or HTTP Basic checked against
the same `users` table, so `curl -u` works and the browser test console works.

**Authorisation.** Three roles. A **super admin** can do anything, including user
management. An **analyst** is assigned a set of sections (`user_sections` table);
they see the dashboard cards, live reports and API resources for those sections and
get an explanatory 403 elsewhere. A **viewer** never reaches a live report or the
API; their whole application is the saved-reports list. The nav is built from what
the account may open, so it never offers a link that leads to a 403.

**Saved reports and export.** An analyst can save any filtered view of a report. The
rendered HTML is captured at save time, so the saved copy is a fixed view that
survives data changes; the live version stays one click away. The PDF export prints
that snapshot with **headless Chrome** into a file outside every web root, streamed
through an auth-gated URL. Chrome rather than dompdf or wkhtmltopdf because the
charts are Charts.css (CSS custom properties, grid, flexbox), which those renderers
do not support; Chrome prints the page the reader already saw, with no second
stylesheet to maintain.

**Charts.** [Charts.css](https://chartscss.org/): each chart is an HTML `<table>`
drawn by CSS. It renders with scripting disabled, a screen reader gets a real data
table, and the numbers are in the markup. The whole reporting application ships
about **12 KB of gzipped CSS and zero bytes of JavaScript**; the dashboard page is
under 5 KB gzipped.

**Statistics.** Percentiles are linear-interpolated in PHP (MySQL 8 has no
percentile aggregate). Stacked bars use means because means are additive; grids use
medians and p90 so an outlier cannot describe a page. The performance verdict scores
each load phase on *recoverable* time above its own 10th percentile, so it needs no
borrowed benchmark and self-calibrates when the site changes. The behaviour report
splits the same pageviews into four load-time cohorts and compares their engagement,
the question the performance report could not answer on its own.

**Honesty checks that run as scripts.** `src/tools/verify/bias-test.sh` seeds
datasets whose bottleneck is chosen in advance and asserts the platform finds each
one (a report that always says "images" is not measuring anything).
`src/tools/verify/auth-matrix.sh` signs in as every role and asserts the status of
every gated URL and API resource. Both run against the local devstack before every
deploy and the auth matrix runs against production after.

**Contingencies.** Styled 404 and 403 pages on the reporting host; the
application's own 403s say *why* (viewer on a live report, analyst outside their
section) rather than redirecting to a login the visitor has already passed. Export
degrades to "renderer unavailable" with the saved page still readable. Every form
works with JavaScript disabled because there is none to disable.

**Deployment.** GitHub Actions rsyncs each `sites/<vhost>/` to its web root and
`src/sql/` to a non-web directory on push to `main`; migrations are applied by hand
on purpose. The droplet has no checkout of this repository. Layout and deploy
details: [`deploy/README.md`](deploy/README.md). A Docker devstack
(`src/tools/devstack/`) rehearses everything locally, including the PDF export.

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

- `sites/<vhost>/` — one directory per Apache vhost; its contents deploy to that vhost's web root
- `sites/reporting/app/` — the application: `Auth`, `Sections`, `Reports`, the metric layer (`Metrics/`, `PageviewSet`, `ActivitySet`, `AudienceSet`), views
- `src/sql/` — schema and numbered migrations (`004`/`005` are generated seeds and are gitignored)
- `src/tools/` — devstack, fixture seeder, user-seed generator, the two verifiers
- `deploy/` — Apache vhost samples and deploy notes
- `docs/specs/` — the assignment specifications
