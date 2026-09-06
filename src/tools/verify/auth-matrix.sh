#!/usr/bin/env bash
#
# THE AUTH MATRIX
#
# Signs in as each grading account and asserts the HTTP status of every gated URL.
# Same idea as bias-test.sh: the authorisation rules are only worth anything if a
# script can prove them, before every deploy, in a few seconds.
#
# Usage:
#   auth-matrix.sh http://localhost:8135 localdev-135
#   auth-matrix.sh https://reporting.ucsdwrestlingclub.com "$PASS"
#
# Assumes the four HW5 accounts from src/tools/seed-users/make-users.php share one
# password (they do by default). Anonymous checks need no password at all.
#
# Cookies are passed as an explicit header rather than a cookie jar: the session
# cookie is flagged Secure, and curl (correctly) refuses to send a Secure cookie
# over plain http, which is what the devstack speaks.
#
# The login throttle counts every failure here, so clear login_attempts if a run
# is repeated many times against the same host.
set -uo pipefail
BASE="${1:?usage: auth-matrix.sh <base-url> [password]}"
PASS="${2:-}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
pass=0; fail=0

cookie_of() {  # $1 headers file -> session cookie value (last Set-Cookie wins)
  sed -n 's/^[Ss]et-[Cc]ookie: cse135_reporting=\([^;]*\).*/\1/p' "$1" | tail -1
}

login() {  # $1 username -> prints cookie value; empty on failure
  curl -s -D "$TMP/h1" -o "$TMP/login.html" "$BASE/login.php"
  local c1; c1=$(cookie_of "$TMP/h1")
  local tok; tok=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$TMP/login.html")
  curl -s -D "$TMP/h2" -o /dev/null -H "Cookie: cse135_reporting=$c1" \
    --data-urlencode "_csrf=$tok" --data-urlencode "identifier=$1" \
    --data-urlencode "password=$PASS" --data-urlencode "next=/" "$BASE/login.php"
  if ! head -1 "$TMP/h2" | grep -q ' 302 '; then echo ""; return; fi
  local c2; c2=$(cookie_of "$TMP/h2")
  echo "${c2:-$c1}"
}

check() {  # $1 label  $2 expected  $3.. curl args
  local label="$1" want="$2"; shift 2
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' "$@")
  if [ "$got" = "$want" ]; then
    printf "  PASS  %-58s %s\n" "$label" "$got"; pass=$((pass+1))
  else
    printf "  FAIL  %-58s want %s got %s\n" "$label" "$want" "$got"; fail=$((fail+1))
  fi
}

echo "== anonymous"
check "anon /                        -> login"        302 "$BASE/"
check "anon /reports/page-load-cost  -> login"        302 "$BASE/reports/page-load-cost.php"
check "anon /users.php               -> login"        302 "$BASE/users.php"
check "anon /saved/                  -> login"        302 "$BASE/saved/"
check "anon /api/static              -> 401"          401 "$BASE/api/static"
check "anon /app/Auth.php            -> 403"          403 "$BASE/app/Auth.php"
check "anon /login.php               -> 200"          200 "$BASE/login.php"
check "anon /nope-404                -> 404"          404 "$BASE/this-does-not-exist"
check "anon POST /saved/save.php no csrf -> login"    302 -X POST "$BASE/saved/save.php"

if [ -z "$PASS" ]; then echo; echo "  (no password given; skipping signed-in checks)"; echo "  $pass passed, $fail failed"; exit $((fail>0)); fi

# user | path | expected   (plain arrays: macOS ships bash 3.2, no declare -A)
ROWS=(
  "grader-admin  /                             200"
  "grader-admin  /reports/page-load-cost.php   200"
  "grader-admin  /reports/engagement.php       200"
  "grader-admin  /reports/audience.php         200"
  "grader-admin  /users.php                    200"
  "grader-admin  /saved/                       200"
  "grader-admin  /api/static?limit=1           200"
  "grader-admin  /api/activity?limit=1         200"
  "grader-basic  /                             200"
  "grader-basic  /reports/page-load-cost.php   200"
  "grader-basic  /reports/engagement.php       200"
  "grader-basic  /reports/audience.php         200"
  "grader-basic  /users.php                    403"
  "grader-basic  /saved/                       200"
  "grader-basic  /api/activity?limit=1         200"
  "grader-perf   /                             200"
  "grader-perf   /reports/page-load-cost.php   200"
  "grader-perf   /reports/engagement.php       403"
  "grader-perf   /reports/audience.php         403"
  "grader-perf   /users.php                    403"
  "grader-perf   /saved/                       200"
  "grader-perf   /api/performance?limit=1      200"
  "grader-perf   /api/resources?limit=1        200"
  "grader-perf   /api/activity?limit=1         403"
  "grader-perf   /api/static?limit=1           403"
  "grader-perf   /api/sessions?limit=1         200"
  "grader-viewer /                             302"
  "grader-viewer /reports/page-load-cost.php   403"
  "grader-viewer /reports/engagement.php       403"
  "grader-viewer /users.php                    403"
  "grader-viewer /saved/                       200"
  "grader-viewer /api/static?limit=1           403"
  "grader-viewer /api/sessions?limit=1         403"
  "grader-viewer /api/                         200"
)
ADMIN_COOKIE=""
for u in grader-admin grader-basic grader-perf grader-viewer; do
  echo; echo "== $u"
  c=$(login "$u")
  if [ -z "$c" ]; then printf "  FAIL  login as %s\n" "$u"; fail=$((fail+1)); continue; fi
  printf "  PASS  %-58s %s\n" "login as $u (302, session cookie issued)" 302; pass=$((pass+1))
  [ "$u" = grader-admin ] && ADMIN_COOKIE="$c"
  for row in "${ROWS[@]}"; do
    read -r ru rp rw <<< "$row"
    [ "$ru" = "$u" ] || continue
    check "$u $rp" "$rw" -H "Cookie: cse135_reporting=$c" "$BASE$rp"
  done
done

echo; echo "== HTTP Basic against the API (curl -u), same section rules"
check "basic  grader-perf   /api/performance  -> 200"  200 -u "grader-perf:$PASS"   "$BASE/api/performance?limit=1"
check "basic  grader-perf   /api/activity     -> 403"  403 -u "grader-perf:$PASS"   "$BASE/api/activity?limit=1"
check "basic  grader-viewer /api/static       -> 403"  403 -u "grader-viewer:$PASS" "$BASE/api/static?limit=1"
check "basic  grader-admin  /api/resources    -> 200"  200 -u "grader-admin:$PASS"  "$BASE/api/resources?limit=1"
check "basic  bad password  /api/static       -> 401"  401 -u "grader-admin:wrong-$RANDOM" "$BASE/api/static"

echo; echo "== session hygiene"
c="$ADMIN_COOKIE"
check "admin  POST /users.php without csrf    -> 400"  400 -H "Cookie: cse135_reporting=$c" --data "action=create" "$BASE/users.php"
check "admin  GET /logout.php                 -> 200"  200 -H "Cookie: cse135_reporting=$c" "$BASE/logout.php"
check "admin  same cookie after logout        -> login" 302 -H "Cookie: cse135_reporting=$c" "$BASE/users.php"
# open redirect: ?next=//evil must land on /, not on evil
loc=$(curl -s -D - -o /dev/null "$BASE/login.php?next=//evil.example/" | sed -n 's/^[Ll]ocation: //p' | tr -d '\r')
[ -z "$loc" ] && { printf "  PASS  %-58s %s\n" "login form with next=//evil renders (no redirect)" 200; pass=$((pass+1)); } \
              || { printf "  FAIL  %-58s %s\n" "login form redirected on ?next=//evil" "$loc"; fail=$((fail+1)); }

echo; echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
