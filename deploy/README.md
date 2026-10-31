# Deployment

The repo root is **not** the web root. Each `sites/<vhost>/` directory maps to
one Apache vhost web root on the droplet:

| Repo dir          | Droplet web root                         | Vhost                           |
| ----------------- | ---------------------------------------- | ------------------------------- |
| `sites/main`      | `/var/www/tritonwrestling.com`           | `tritonwrestling.com`           |
| `sites/test`      | `/var/www/test.tritonwrestling.com`      | `test.tritonwrestling.com`      |
| `sites/collector` | `/var/www/collector.tritonwrestling.com` | `collector.tritonwrestling.com` |
| `sites/reporting` | `/var/www/reporting.tritonwrestling.com` | `reporting.tritonwrestling.com` |

One directory is synced to the droplet **without** being a web root:

| Repo dir    | Droplet path         | Why                                          |
| ----------- | -------------------- | -------------------------------------------- |
| `src/sql`   | `~/cse135/sql`       | schema/migrations — must never be served      |

`src/sql` holds the database schema for HW3 onward. It goes to the deploy user's
home directory rather than `/var/www`, because publishing your schema over HTTP is
a disclosure. Applying it is deliberately manual — see
[`docs/guides/hw3-part4-database-setup.md`](../docs/guides/hw3-part4-database-setup.md).

Everything else — specs (`docs/`), this deploy tooling, `src/hw2`, READMEs — never
leaves the GitHub runner. In particular **the droplet has no checkout of this
repo**, so anything else you need there (vhost config samples) must be copied up by
hand with `scp`.

## Primary path: GitHub Actions (`.github/workflows/deploy.yml`)

On every push to `main`, the workflow rsyncs each `sites/<vhost>/` to its web
root over SSH, syncs `src/sql/` to `~/cse135/sql`, and reloads Apache.

**One-time setup:**

1. On the droplet, create a deploy user (or reuse `grader`/your account) and
   make sure it can write to `/var/www/*` (e.g. `sudo chown -R $USER:www-data /var/www`)
   and run `sudo systemctl reload apache2`.
2. Generate a deploy keypair locally:
   `ssh-keygen -t ed25519 -f deploy_key -C "gh-actions-deploy"`
   Append `deploy_key.pub` to the deploy user's `~/.ssh/authorized_keys`.
3. In the GitHub repo → Settings → Secrets and variables → Actions, add:
   - `DROPLET_HOST` — droplet IP/hostname
   - `DROPLET_USER` — deploy username
   - `DROPLET_SSH_KEY` — contents of the **private** `deploy_key`
   - `DOMAIN` — e.g. `tritonwrestling.com`
4. Push to `main`. Watch the run under the Actions tab.

> For HW1's `Github-Deploy.gif` deliverable: record editing a file → committing →
> pushing → the Actions run going green → the change live on the droplet.

## Alternative: bare-repo `post-receive` hook

If you prefer the sitepoint-style git hook instead of Actions, on the droplet:

```bash
mkdir -p ~/site.git && cd ~/site.git && git init --bare
cat > hooks/post-receive <<'EOF'
#!/bin/bash
set -e
TMP=$(mktemp -d)
git --work-tree="$TMP" --git-dir="$HOME/site.git" checkout -f main
for d in main test collector reporting; do
  case "$d" in
    main) root="/var/www/tritonwrestling.com" ;;
    *)    root="/var/www/$d.tritonwrestling.com" ;;
  esac
  mkdir -p "$root"
  rsync -a --delete --exclude README.md --exclude .gitkeep "$TMP/sites/$d/" "$root/"
done
sudo systemctl reload apache2
rm -rf "$TMP"
EOF
chmod +x hooks/post-receive
```

Then locally: `git remote add production <user>@<host>:site.git` and
`git push production main`.

## Apache vhost configs

- `deploy/apache/main.conf.sample` — team site (HW1: 404, Server spoof, deflate)
- `deploy/apache/hw2-cgi.conf.sample` — CGI blocks pasted into the main vhost
- `deploy/apache/test.conf.sample` — HW3 test site: Client Hints, `mod_usertrack`
  session cookie, per-vhost logs, plus two `BUGFIX:`-marked corrections to the live
  config (relative `<Directory>` path defeating basic auth; certbot redirect naming
  the wrong host)
- `deploy/apache/cse135-logformat.conf.sample` — the `combined_ch` log format;
  installs into `conf-available/`, not a vhost (see the guide for why)
- `deploy/apache/collector.conf.sample` — HW3 collector vhost; narrows HW1's basic
  auth so `collector.js`, `px.gif` and `/log` are reachable anonymously
- `deploy/apache/reporting.conf.sample` — HW3 reporting vhost; `/api` front-controller
  rewrite, basic auth left on everything
- `deploy/apache/default.conf.sample` — `000-default`: catch-all :80/:443 vhost that
  redirects bare-IP / unknown-Host requests to `https://tritonwrestling.com/`
- `deploy/apache/legacy-redirect.conf.sample` — the old `ucsdwrestlingclub.com` names,
  path-preserving redirects to their `tritonwrestling.com` counterparts (301 for
  pages, 308 for collector/reporting so POST/PUT/DELETE keep their method)

The remaining vhosts follow the same pattern with their own
`ServerName`/`DocumentRoot`. Enable with `a2ensite`, then run `certbot` for SSL.

Step-by-step droplet walkthroughs live in [`docs/guides/`](../docs/guides/).

## TODO: finish the domain migration (Phase 5 — old-domain cutover)

`tritonwrestling.com` is live and deployed (DNS on DigitalOcean, one 5-name
Let's Encrypt cert, new vhosts, `DOMAIN` secret switched, PTR set). The old
`ucsdwrestlingclub.com` vhosts are **still serving their own frozen copy** of the
pre-migration code from `/var/www/*ucsdwrestlingclub.com`. These steps turn them
into redirects. Run them in order on the droplet.

- [ ] **Back up** first:
      `sudo tar czf ~/pre-cutover-$(date +%F).tgz /etc/apache2 /etc/letsencrypt`
- [ ] **Fold the old test cert into the main old cert** — must happen *before*
      disabling the old vhosts, since certbot's apache authenticator needs them:
      ```bash
      sudo certbot certonly --apache --cert-name ucsdwrestlingclub.com --expand \
        -d ucsdwrestlingclub.com -d www.ucsdwrestlingclub.com -d test.ucsdwrestlingclub.com \
        -d collector.ucsdwrestlingclub.com -d reporting.ucsdwrestlingclub.com
      ```
- [ ] **Swap the old vhosts for the redirect vhost** (`apache/legacy-redirect.conf.sample`;
      full commands in its header): `a2dissite` the 8 old files (keep them in
      `sites-available` for rollback), `a2ensite legacy-redirect`, configtest, reload.
- [ ] `sudo certbot delete --cert-name test.ucsdwrestlingclub.com` (superseded).
- [ ] **Make tritonwrestling.com the server default** (`apache/default.conf.sample`):
      install as `000-default.conf`, add global `ServerName tritonwrestling.com`
      (`conf-available/servername.conf` + `a2enconf`), configtest, reload, then
      `sudo apache2ctl -S` must list `000-default` as default on `*:80` and `*:443`.
- [ ] **Droplet hostname:** `sudo hostnamectl set-hostname tritonwrestling.com`,
      fix the `127.0.1.1` line in `/etc/hosts`; `/etc/mailname` and Postfix
      `myhostname` too if present.
- [ ] **Rewrite stored hosts in the DB** — only after the redirects are live:
      ```bash
      sudo mysqldump cse135 > ~/cse135-pre-008-$(date +%F).sql
      sudo mysql < ~/cse135/sql/008-domain-rename.sql   # ends with a check; all counts 0
      ```
- [ ] **Verify** (from a laptop):
      - each old name, http and https, redirects with path + query kept, e.g.
        `curl -sI 'https://test.ucsdwrestlingclub.com/x?y=1'` →
        `Location: https://test.tritonwrestling.com/x?y=1` (301; collector/reporting 308)
      - `curl -si -X POST https://collector.ucsdwrestlingclub.com/log` → 308
      - `curl -sI http://165.22.133.154` and `curl -skI https://165.22.133.154` →
        redirect to `https://tritonwrestling.com/`
      - `sudo certbot certificates` lists exactly 2 certs (5 names each);
        `sudo certbot renew --dry-run` passes for both
      - `src/tools/verify/auth-matrix.sh https://reporting.tritonwrestling.com "$PASS"` passes;
        dashboard host filter shows only `tritonwrestling.com` hosts
- [ ] **After ~a week of clean `legacy_redirect_*.log`:** delete the 4
      `/var/www/*ucsdwrestlingclub.com` roots and the 8 disabled old vhost files.

Rollback any time before the last step: `a2dissite legacy-redirect`, `a2ensite`
the old files, reload. Keep `ucsdwrestlingclub.com` registered and auto-renewing
for as long as the redirects should work.
