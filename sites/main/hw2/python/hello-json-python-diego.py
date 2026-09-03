#!/usr/bin/env python3
"""hello-json — Python. Same payload as hello-html, serialised as JSON."""

import sys

import cgilib as c

c.send_json({
    "greeting": "Hello from " + c.TEAM_NAME,
    "language": c.LANG_NAME,
    "version": "%d.%d.%d" % sys.version_info[:3],
    "generatedAt": c.now_iso(),
    "clientIp": c.client_ip(),
})
