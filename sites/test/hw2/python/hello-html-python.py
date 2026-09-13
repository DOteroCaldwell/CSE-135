#!/usr/bin/env python3
"""hello-html — Python. Greeting, language, generation time, client IP."""

import sys

import cgilib as c

c.page_top("Hello HTML — Python")
sys.stdout.write(
    "    <p>Hello from <strong>%s</strong>.</p>\n" % c.h(c.TEAM_NAME)
)
c.kv_table(
    {
        "Language": "Python %d.%d.%d" % sys.version_info[:3],
        "Generated at": c.now_iso(),
        "Your IP address": c.client_ip(),
    },
    "Response details",
)
sys.stdout.write(
    '    <p class="note">Every value above is produced per request on the '
    "server. Reload and the timestamp changes — this is not a static file.</p>\n"
)
c.page_bottom()
