// state (screen 2 of 3) — Go. A different URL reading the same session.
package main

import (
	"net/http"
	"net/http/cgi"
	"sort"

	"cse135/hw2/hw2lib"
)

const content = `{{if .Empty}}    <p>Nothing is saved in this session yet.</p>
{{else}}{{template "kv" .Saved}}
{{end}}    <p class="note">This page never received the values in the request —
      it looked them up server-side from the session ID in the cookie.</p>

    <ul>
      <li><a href="state-go-diego.cgi">Back to the save screen</a></li>
      <li><a href="state-clear-go-diego.cgi">Clear saved data</a></li>
    </ul>
`

type page struct {
	hw2lib.Base
	Empty bool
	Saved hw2lib.Table
}

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s := hw2lib.BeginSession(w, r)

		keys := make([]string, 0, len(s.Data))
		for k := range s.Data {
			keys = append(keys, k)
		}
		sort.Strings(keys)

		rows := make([]hw2lib.Pair, 0, len(keys))
		for _, k := range keys {
			rows = append(rows, hw2lib.Pair{Key: k, Value: s.Data[k]})
		}

		hw2lib.Render(w, content, page{
			Base:  hw2lib.NewBase("State: View — Go"),
			Empty: len(rows) == 0,
			Saved: hw2lib.Table{Caption: "Data read back from the server-side session", Rows: rows},
		})
	}))
	if err != nil {
		panic(err)
	}
}
