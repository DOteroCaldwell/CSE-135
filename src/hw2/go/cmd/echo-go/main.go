// echo — Go. Echoes the request back over any method and either encoding.
//
// net/http/cgi turns the CGI environment into a real *http.Request, so the
// method, headers and query string are all reachable the usual way. The body
// still has to be read off the request stream, which is the part that does not
// vary by language.
package main

import (
	"net/http"
	"net/http/cgi"
	"os"
	"strings"

	"cse135/hw2/hw2lib"
)

const content = `{{template "kv" .Meta}}
{{template "kv" .Query}}
{{template "kv" .Body}}
    <h2>Raw request body</h2>
    <pre class="out">{{.RawBody}}</pre>
    <p class="note">All echoed values are HTML-escaped before output.
      Submitting <code>&lt;script&gt;</code> through the form renders it as
      visible text rather than executing it.</p>
    <p><a href="/hw2/echo-form.html">&larr; Back to the echo form</a></p>
`

type page struct {
	hw2lib.Base
	Meta    hw2lib.Table
	Query   hw2lib.Table
	Body    hw2lib.Table
	RawBody string
}

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		bodyFields, raw := hw2lib.ReadBody(r)

		queryFields := map[string]any{}
		for k, v := range r.URL.Query() {
			if len(v) > 0 {
				queryFields[k] = v[0]
			}
		}

		contentType := r.Header.Get("Content-Type")
		if contentType == "" {
			contentType = "(none)"
		}

		meta := []hw2lib.Pair{
			{Key: "method", Value: r.Method},
			{Key: "contentType", Value: contentType},
			{Key: "queryString", Value: os.Getenv("QUERY_STRING")},
			{Key: "hostname", Value: hw2lib.Hostname(r)},
			{Key: "generatedAt", Value: hw2lib.NowISO()},
			{Key: "userAgent", Value: hw2lib.UserAgent(r)},
			{Key: "clientIp", Value: hw2lib.ClientIP(r)},
		}

		if strings.Contains(strings.ToLower(r.Header.Get("Accept")), "application/json") {
			payload := map[string]any{}
			for _, p := range meta {
				payload[p.Key] = p.Value
			}
			payload["queryFields"] = queryFields
			payload["bodyFields"] = bodyFields
			payload["rawBody"] = raw
			hw2lib.SendJSON(w, payload)
			return
		}

		if raw == "" {
			raw = "(empty)"
		}

		hw2lib.Render(w, content, page{
			Base:    hw2lib.NewBase("Echo — Go"),
			Meta:    hw2lib.Table{Caption: "Request metadata", Rows: meta},
			Query:   hw2lib.Table{Caption: "Query-string fields", Rows: hw2lib.SortedPairs(queryFields)},
			Body:    hw2lib.Table{Caption: "Parsed body fields", Rows: hw2lib.SortedPairs(bodyFields)},
			RawBody: raw,
		})
	}))
	if err != nil {
		panic(err)
	}
}
