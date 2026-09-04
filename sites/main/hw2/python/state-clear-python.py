#!/usr/bin/env python3
"""state (screen 3 of 3) — Python. Delete the session file and expire the cookie.

POST-only, so a prefetch or a link preview cannot wipe someone's data.
"""

import sys

import cgilib as c

sid, session, token, headers = c.begin_session()

if c.method() == "POST":
    fields, _ = c.parsed_body()
    if not c.csrf_ok(session, fields.get("csrf")):
        c.send_headers("text/plain; charset=utf-8", status="400 Bad Request")
        sys.stdout.write("CSRF token mismatch.\n")
        sys.exit(0)
    c.destroy_session(sid)
    c.page_top("State: Clear — Python",
               extra_headers=[c.session_cookie_header(sid, expire=True)])
    sys.stdout.write('    <p class="note">Session destroyed. The server-side '
                     "file is gone and the cookie is expired.</p>\n")
else:
    c.page_top("State: Clear — Python", extra_headers=headers)
    sys.stdout.write(
        '    <form method="post" action="state-clear-python.py">\n'
        '      <input type="hidden" name="csrf" value="%s">\n'
        "      <p>This clears everything stored in the current server-side "
        "session.</p>\n"
        '      <button type="submit">Clear session</button>\n'
        "    </form>\n" % c.h(token)
    )

sys.stdout.write(
    "    <ul>\n"
    '      <li><a href="state-python.py">Back to the save screen</a></li>\n'
    '      <li><a href="state-view-python.py">View saved data</a></li>\n'
    "    </ul>\n"
)
c.page_bottom()
