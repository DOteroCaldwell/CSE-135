// hello-html — Go. Greeting, language, generation time, client IP.
package main

import (
	"net/http"
	"net/http/cgi"

	"cse135/hw2/hw2lib"
)

const content = `    <p>Hello from <strong>{{.Team}}</strong>.</p>
{{template "kv" .Details}}
    <p class="note">Every value above is produced per request on the server.
      Reload and the timestamp changes — this is not a static file.</p>
`

type page struct {
	hw2lib.Base
	Details hw2lib.Table
}

func main() {
	// cgi.Serve reads the CGI environment and hands the handler a real
	// *http.Request, so the code below is ordinary Go HTTP code.
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hw2lib.Render(w, content, page{
			Base: hw2lib.NewBase("Hello HTML — Go"),
			Details: hw2lib.Table{
				Caption: "Response details",
				Rows: []hw2lib.Pair{
					{Key: "Language", Value: "Go " + hw2lib.NewBase("").GoVersion},
					{Key: "Generated at", Value: hw2lib.NowISO()},
					{Key: "Your IP address", Value: hw2lib.ClientIP(r)},
				},
			},
		})
	}))
	if err != nil {
		panic(err)
	}
}
