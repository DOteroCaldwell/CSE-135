#!/usr/bin/env bash
#
# Bring up a local copy of the reporting stack: MySQL 8 + PHP 8.3 + the app.
#
# Why this exists: the alternative to a local stack is testing on the live droplet,
# which means every mistake is a deployed mistake. Everything in this project was
# built and verified here first.
#
#   ./up.sh          start, migrate, seed
#   ./up.sh --down   tear everything down
#
# Then: http://localhost:8135  (grader-admin / Wrestl3-Admin-2026)
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(cd ../../.. && pwd)"
CFG="$(pwd)/.dbini"

if [ "${1:-}" = "--down" ]; then
  docker rm -f cse135-web cse135-mysql >/dev/null 2>&1 || true
  docker network rm cse135net >/dev/null 2>&1 || true
  echo "torn down"; exit 0
fi

mkdir -p "$CFG"; cp db.ini.example "$CFG/db.ini"
docker network create cse135net >/dev/null 2>&1 || true
docker build -q -t cse135-php . >/dev/null

docker rm -f cse135-mysql >/dev/null 2>&1 || true
docker run -d --name cse135-mysql --network cse135net \
  -e MYSQL_ROOT_PASSWORD=rootpw -e MYSQL_DATABASE=cse135 \
  -e MYSQL_USER=cse135_app -e MYSQL_PASSWORD=localdevpassword mysql:8 >/dev/null

# Wait for an AUTHENTICATED query, not for `mysqladmin ping`.
# During initialisation the image runs a temporary server that answers ping while
# the root password is still unset, so ping succeeds and the very next command
# fails with "Access denied". Waiting on a real SELECT waits for the real server.
printf "waiting for mysql"
ready=0
for _ in $(seq 1 60); do
  if docker exec cse135-mysql mysql -uroot -prootpw -e "SELECT 1" >/dev/null 2>&1; then
    ready=1; break
  fi
  printf "."; sleep 3
done; echo
[ "$ready" = 1 ] || { echo "mysql did not become ready" >&2; exit 1; }

for f in schema.sql 002-host-and-resources.sql 003-users.sql 004-seed-users.sql; do
  docker exec -i cse135-mysql mysql -uroot -prootpw < "$ROOT/src/sql/$f"
  echo "  applied $f"
done

PHP=(docker run --rm --network cse135net -v "$ROOT":/app -v "$CFG":/etc/cse135:ro -w /app cse135-php php)
"${PHP[@]}" src/tools/seed-fixtures/seed.php --profile=balanced --sessions=20 --purge
"${PHP[@]}" src/tools/seed-fixtures/seed.php --profile=images   --sessions=14

docker rm -f cse135-web >/dev/null 2>&1 || true
docker run -d --name cse135-web --network cse135net -p 8135:8135 \
  -v "$ROOT/sites/reporting":/var/www/reporting \
  -v "$(pwd)/router.php":/var/www/router.php \
  -v "$CFG":/etc/cse135:ro \
  -e DOCROOT=/var/www/reporting -w /var/www/reporting \
  cse135-php php -S 0.0.0.0:8135 -t /var/www/reporting /var/www/router.php >/dev/null

echo
echo "  http://localhost:8135   grader-admin / Wrestl3-Admin-2026"
echo "  bias test:  ../verify/bias-test.sh ${PHP[*]}"
