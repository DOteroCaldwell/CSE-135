#!/usr/bin/env python3
"""state (screen 2 of 3) — Python. A different URL reading the same session."""

import sys

import cgilib as c

sid, session, token, headers = c.begin_session()
saved = session.get("data", {})

c.page_top("State: View — Python", extra_headers=headers)

if not saved:
    sys.stdout.write("    <p>Nothing is saved in this session yet.</p>\n")
else:
    c.kv_table(saved, "Data read back from the server-side session")

sys.stdout.write(
    '    <p class="note">This page never received the values in the request — '
    "it looked them up server-side from the session ID in the cookie.</p>\n"
    "    <ul>\n"
    '      <li><a href="state-python.py">Back to the save screen</a></li>\n'
    '      <li><a href="state-clear-python.py">Clear saved data</a></li>\n'
    "    </ul>\n"
)
c.page_bottom()
