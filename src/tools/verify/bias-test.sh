#!/usr/bin/env bash
#
# THE BIAS TEST
#
# The platform is built to name whichever phase actually dominates, not the one its
# author expected. That claim is only worth anything if it is tested, so:
#
#   for each profile, seed a dataset whose bottleneck was chosen in advance,
#   ask the platform what it concludes, and require the verdict to match.
#
# A report that always says "images" is not measuring anything. This is the check
# that would catch that.
#
# Usage:  bias-test.sh <php-runner...>
#   e.g.  bias-test.sh docker run --rm --network cse135net -v "$PWD":/app \
#                      -v /path/etc-cse135:/etc/cse135:ro -w /app cse135-php php
set -uo pipefail
RUN=("$@")
[ ${#RUN[@]} -eq 0 ] && { echo "usage: bias-test.sh <php runner...>" >&2; exit 2; }

# profile -> the phase the platform must independently arrive at
declare -a CASES=(
  "images:tail"
  "ttfb:ttfb"
  "render-block:dom"
)

pass=0; fail=0
for case in "${CASES[@]}"; do
  profile="${case%%:*}"; expected="${case##*:}"
  "${RUN[@]}" src/tools/seed-fixtures/seed.php --profile="$profile" --sessions=25 --purge >/dev/null
  got=$("${RUN[@]}" src/tools/verify/verdict.php --json | sed -n 's/.*"winner": "\([^"]*\)".*/\1/p')
  if [ "$got" = "$expected" ]; then
    printf "  PASS  profile=%-13s expected=%-6s got=%s\n" "$profile" "$expected" "$got"; pass=$((pass+1))
  else
    printf "  FAIL  profile=%-13s expected=%-6s got=%s\n" "$profile" "$expected" "$got"; fail=$((fail+1))
  fi
done

echo
echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
