# Deployment

The repo root is **not** the web root. Each `sites/<vhost>/` directory maps to
one Apache vhost web root on the droplet:

| Repo dir            | Droplet web root                        | Vhost                          |
| ------------------- | --------------------------------------- | ------------------------------ |
| `sites/main`        | `/var/www/ucsdwrestlingclub.com`              | `ucsdwrestlingclub.com`              |
| `sites/test`        | `/var/www/test.ucsdwrestlingclub.com`         | `test.ucsdwrestlingclub.com`         |
| `sites/collector`   | `/var/www/collector.ucsdwrestlingclub.com`    | `collector.ucsdwrestlingclub.com`    |
| `sites/reporting`   | `/var/www/reporting.ucsdwrestlingclub.com`    | `reporting.ucsdwrestlingclub.com`    |

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
   - `DOMAIN` — e.g. `ucsdwrestlingclub.com`
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
    main) root="/var/www/ucsdwrestlingclub.com" ;;
    *)    root="/var/www/$d.ucsdwrestlingclub.com" ;;
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

The remaining vhosts follow the same pattern with their own
`ServerName`/`DocumentRoot`. Enable with `a2ensite`, then run `certbot` for SSL.

Step-by-step droplet walkthroughs live in [`docs/guides/`](../docs/guides/).
