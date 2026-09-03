#!/usr/bin/env python3
"""environment — Python. Dumps the CGI environment.

HTTP_AUTHORIZATION and HTTP_COOKIE are withheld: this site sits behind HTTP
basic auth, so the raw header carries a working credential, and the cookie
header carries live session identifiers.
"""

import os
import sys

import cgilib as c

REDACTED = {"HTTP_AUTHORIZATION", "HTTP_PROXY_AUTHORIZATION", "HTTP_COOKIE"}

rows = {}
for key in sorted(os.environ):
    rows[key] = "[redacted — see the note below]" if key in REDACTED else os.environ[key]

c.page_top("Environment Variables — Python")
c.kv_table(rows, "CGI / server environment as seen by Python")
sys.stdout.write(
    '    <p class="note"><code>HTTP_AUTHORIZATION</code> and '
    "<code>HTTP_COOKIE</code> are redacted on purpose. The site is protected by "
    "HTTP basic auth, so the raw header holds a usable credential, and the "
    "cookie header holds a live session ID.</p>\n"
)
c.page_bottom()
