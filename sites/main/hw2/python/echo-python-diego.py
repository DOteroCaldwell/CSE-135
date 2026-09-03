#!/usr/bin/env python3
"""echo — Python. Echoes the request back over any method and either encoding.

CGI hands the query string over in QUERY_STRING, but the body always has to be
read off stdin using CONTENT_LENGTH — that is true for GET, POST, PUT and
DELETE alike, and is the part that does not vary by language.
"""

import sys

import cgilib as c

body_fields, raw = c.parsed_body()
query = c.query_fields()

request = {
    "method": c.method(),
    "contentType": c.env("CONTENT_TYPE", "(none)"),
    "queryString": c.env("QUERY_STRING"),
    "hostname": c.server_hostname(),
    "generatedAt": c.now_iso(),
    "userAgent": c.user_agent(),
    "clientIp": c.client_ip(),
}

if "application/json" in c.env("HTTP_ACCEPT").lower():
    payload = dict(request)
    payload["queryFields"] = query
    payload["bodyFields"] = body_fields
    payload["rawBody"] = raw
    c.send_json(payload)
    sys.exit(0)

c.page_top("Echo — Python")
c.kv_table(request, "Request metadata")
c.kv_table(query, "Query-string fields")
c.kv_table(body_fields, "Parsed body fields")
sys.stdout.write(
    "    <h2>Raw request body</h2>\n"
    '    <pre class="out">%s</pre>\n' % c.h(raw if raw else "(empty)")
)
sys.stdout.write(
    '    <p class="note">All echoed values are HTML-escaped before output. '
    "Submitting <code>&lt;script&gt;</code> through the form renders it as "
    "visible text rather than executing it.</p>\n"
    '    <p><a href="/hw2/echo-form.html">&larr; Back to the echo form</a></p>\n'
)
c.page_bottom()
