#!/usr/bin/env python3
"""state (screen 1 of 3) — Python. Enter data into a server-side session.

Python CGI has no native session support, so cgilib keeps a JSON file per
session under /var/lib/cse135/sessions, mode 0600. The browser holds only the
opaque ID in the HW2PYSESS cookie.
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
    session["data"] = {
        "nickname": (fields.get("nickname") or "").strip(),
        "weight": (fields.get("weight") or "").strip(),
        "note": (fields.get("note") or "").strip(),
        "savedAt": c.now_iso(),
    }
    c.save_session(sid, session)
    # POST/redirect/GET so a refresh does not resubmit.
    c.redirect("state-python-diego.py?saved=1", extra_headers=headers)
    sys.exit(0)

saved = session.get("data", {})

c.page_top("State: Save — Python", extra_headers=headers)

if "saved" in c.query_fields():
    sys.stdout.write('    <p class="note">Saved to the server-side session. '
                     "Now open the view screen.</p>\n")

sys.stdout.write(
    "    <p>Enter something below. It is stored in a JSON file on the server; "
    "your browser receives only an opaque session ID in the "
    "<code>HW2PYSESS</code> cookie.</p>\n"
    '    <form method="post" action="state-python-diego.py">\n'
    '      <input type="hidden" name="csrf" value="%s">\n' % c.h(token)
)
for field, label in (("nickname", "Nickname"), ("weight", "Weight class"), ("note", "Note")):
    sys.stdout.write(
        '      <div class="field-row">\n'
        '        <label for="%s">%s</label>\n'
        '        <input type="text" id="%s" name="%s" value="%s">\n'
        "      </div>\n" % (field, c.h(label), field, field, c.h(saved.get(field, "")))
    )
sys.stdout.write(
    '      <button type="submit">Save to session</button>\n'
    "    </form>\n"
    "    <h2>Other screens</h2>\n"
    "    <ul>\n"
    '      <li><a href="state-view-python-diego.py">View saved data</a></li>\n'
    '      <li><a href="state-clear-python-diego.py">Clear saved data</a></li>\n'
    "    </ul>\n"
    '    <p class="note">Session ID: <code>%s…</code> (truncated)</p>\n' % c.h(sid[:8])
)
c.page_bottom()
