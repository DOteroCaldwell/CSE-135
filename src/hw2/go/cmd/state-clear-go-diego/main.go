// state (screen 3 of 3) — Go. Delete the session file and expire the cookie.
//
// POST-only, so a prefetch or a link preview cannot wipe someone's data.
package main

import (
	"net/http"
	"net/http/cgi"

	"cse135/hw2/hw2lib"
)

const content = `{{if .Cleared}}    <p class="note">Session destroyed. The server-side file is gone and the cookie is expired.</p>
{{else}}    <form method="post" action="state-clear-go-diego.cgi">
      <input type="hidden" name="csrf" value="{{.CSRF}}">
      <p>This clears everything stored in the current server-side session.</p>
      <button type="submit">Clear session</button>
    </form>
{{end}}
    <ul>
      <li><a href="state-go-diego.cgi">Back to the save screen</a></li>
      <li><a href="state-view-go-diego.cgi">View saved data</a></li>
    </ul>
`

type page struct {
	hw2lib.Base
	Cleared bool
	CSRF    string
}

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s := hw2lib.BeginSession(w, r)
		cleared := false

		if r.Method == http.MethodPost {
			body, _ := hw2lib.ReadBody(r)
			if !s.CSRFOK(hw2lib.Stringify(body["csrf"])) {
				http.Error(w, "CSRF token mismatch.", http.StatusBadRequest)
				return
			}
			s.Destroy(w)
			cleared = true
		}

		hw2lib.Render(w, content, page{
			Base:    hw2lib.NewBase("State: Clear — Go"),
			Cleared: cleared,
			CSRF:    s.CSRF,
		})
	}))
	if err != nil {
		panic(err)
	}
}
