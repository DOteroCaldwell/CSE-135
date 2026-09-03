// state (screen 1 of 3) — Go. Enter data into a server-side session.
//
// A CGI process exits after every request, so there is no in-memory session
// map to use. hw2lib keeps a JSON file per session under
// /var/lib/cse135/sessions; the browser holds only the opaque ID.
package main

import (
	"net/http"
	"net/http/cgi"

	"cse135/hw2/hw2lib"
)

const content = `{{if .Saved}}    <p class="note">Saved to the server-side session. Now open the view screen.</p>
{{end}}    <p>Enter something below. It is stored in a JSON file on the server;
      your browser receives only an opaque session ID in the
      <code>HW2GOSESS</code> cookie.</p>

    <form method="post" action="state-go-diego.cgi">
      <input type="hidden" name="csrf" value="{{.CSRF}}">
{{range .Fields}}      <div class="field-row">
        <label for="{{.Key}}">{{.Value}}</label>
        <input type="text" id="{{.Key}}" name="{{.Key}}" value="{{index $.Current .Key}}">
      </div>
{{end}}      <button type="submit">Save to session</button>
    </form>

    <h2>Other screens</h2>
    <ul>
      <li><a href="state-view-go-diego.cgi">View saved data</a></li>
      <li><a href="state-clear-go-diego.cgi">Clear saved data</a></li>
    </ul>
    <p class="note">Session ID: <code>{{.ShortID}}…</code> (truncated)</p>
`

type page struct {
	hw2lib.Base
	Saved   bool
	CSRF    string
	ShortID string
	Fields  []hw2lib.Pair
	Current map[string]string
}

var fields = []hw2lib.Pair{
	{Key: "nickname", Value: "Nickname"},
	{Key: "weight", Value: "Weight class"},
	{Key: "note", Value: "Note"},
}

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s := hw2lib.BeginSession(w, r)

		if r.Method == http.MethodPost {
			body, _ := hw2lib.ReadBody(r)
			if !s.CSRFOK(hw2lib.Stringify(body["csrf"])) {
				http.Error(w, "CSRF token mismatch.", http.StatusBadRequest)
				return
			}
			for _, f := range fields {
				s.Data[f.Key] = hw2lib.Stringify(body[f.Key])
			}
			s.Data["savedAt"] = hw2lib.NowISO()
			s.Save()
			// POST/redirect/GET so a refresh does not resubmit.
			http.Redirect(w, r, "state-go-diego.cgi?saved=1", http.StatusSeeOther)
			return
		}

		short := s.ID
		if len(short) > 8 {
			short = short[:8]
		}

		hw2lib.Render(w, content, page{
			Base:    hw2lib.NewBase("State: Save — Go"),
			Saved:   r.URL.Query().Has("saved"),
			CSRF:    s.CSRF,
			ShortID: short,
			Fields:  fields,
			Current: s.Data,
		})
	}))
	if err != nil {
		panic(err)
	}
}
