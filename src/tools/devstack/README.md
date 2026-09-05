# Local dev stack

MySQL 8 + PHP 8.3 + the reporting app, in Docker. Used to build and verify HW4
without deploying to the droplet.

```bash
./up.sh            # start, migrate, seed
./up.sh --down     # tear down
```

Then <http://localhost:8135>, sign in as `grader-admin` / `Wrestl3-Admin-2026`.

## What it is not

`router.php` emulates two things the real Apache vhost does — the `/api` front-controller
rewrite, and the `app/` deny — because PHP's built-in server has no `.htaccess` and no
`mod_rewrite`. It is close enough to develop against and **is not a test of the Apache
config**. Anything touching `deploy/apache/*.conf.sample` has to be verified on the
droplet.

Also absent locally: HTTPS, so the `Secure` session-cookie flag means the cookie is
still set (PHP does not enforce it) but the transport is not what production uses.

## Running the bias test

```bash
../verify/bias-test.sh docker run --rm --network cse135net \
  -v "$(cd ../../.. && pwd)":/app -v "$(pwd)/.dbini":/etc/cse135:ro \
  -w /app cse135-php php
```
