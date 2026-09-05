# UCSD Wrestling Club — CSE 135

**Author:** Diego Otero-Caldwell (<doterocaldwell@ucsd.edu>)
**Course:** CSE 135 — Server-Side Web Applications
**Live site:** https://ucsdwrestlingclub.com

A cumulative web analytics platform built across HW1–HW5, deployed to a
DigitalOcean droplet behind four Apache vhosts.

## The analytics platform (HW4)

The reporting vhost hosts an authenticated dashboard and a detailed report, both
built around a single question:

> **If we could fix one thing about this site's performance, what should it be, and
> what is it worth?**

- Dashboard — <https://reporting.ucsdwrestlingclub.com/>
- Report — <https://reporting.ucsdwrestlingclub.com/reports/page-load-cost.php>
- REST API — `/api/{sessions,static,performance,activity,resources}`

The verdict is computed rather than authored: no page hardcodes which part of the
load is slow. `src/tools/verify/bias-test.sh` proves it by generating datasets whose
bottleneck is known in advance and asserting the platform independently finds each
one.

Zero JavaScript, ~9 KB gzipped of CSS. Charts are [Charts.css](https://chartscss.org/)
tables, so every page renders with scripting disabled.

---

- Repo layout & deployment — see [`deploy/README.md`](deploy/README.md)
- Assignment specs — see [`docs/specs/`](docs/specs/)
- Grader credentials & per-assignment write-ups — kept out of version control in
  `docs/writeups/` (git-ignored; used for the Gradescope upload)
