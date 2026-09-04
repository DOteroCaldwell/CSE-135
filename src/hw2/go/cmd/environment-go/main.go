// environment — Go. Dumps the CGI environment.
//
// HTTP_AUTHORIZATION and HTTP_COOKIE are withheld: this site sits behind HTTP
// basic auth, so the raw header carries a working credential, and the cookie
// header carries live session identifiers.
package main

import (
	"net/http"
	"net/http/cgi"
	"os"
	"sort"
	"strings"

	"cse135/hw2/hw2lib"
)

var redacted = map[string]bool{
	"HTTP_AUTHORIZATION":       true,
	"HTTP_PROXY_AUTHORIZATION": true,
	"HTTP_COOKIE":              true,
}

const content = `{{template "kv" .Env}}
    <p class="note"><code>HTTP_AUTHORIZATION</code> and <code>HTTP_COOKIE</code>
      are redacted on purpose. The site is protected by HTTP basic auth, so the
      raw header holds a usable credential, and the cookie header holds a live
      session ID.</p>
`

type page struct {
	hw2lib.Base
	Env hw2lib.Table
}

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		environ := os.Environ()
		sort.Strings(environ)

		rows := make([]hw2lib.Pair, 0, len(environ))
		for _, entry := range environ {
			key, value, found := strings.Cut(entry, "=")
			if !found {
				continue
			}
			if redacted[key] {
				value = "[redacted — see the note below]"
			}
			rows = append(rows, hw2lib.Pair{Key: key, Value: value})
		}

		hw2lib.Render(w, content, page{
			Base: hw2lib.NewBase("Environment Variables — Go"),
			Env:  hw2lib.Table{Caption: "CGI / server environment as seen by Go", Rows: rows},
		})
	}))
	if err != nil {
		panic(err)
	}
}
