// hello-json — Go. The same payload as hello-html, serialised as JSON.
package main

import (
	"net/http"
	"net/http/cgi"

	"cse135/hw2/hw2lib"
)

func main() {
	err := cgi.Serve(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hw2lib.SendJSON(w, map[string]string{
			"greeting":    "Hello from " + hw2lib.TeamName,
			"language":    hw2lib.LangName,
			"version":     hw2lib.NewBase("").GoVersion,
			"generatedAt": hw2lib.NowISO(),
			"clientIp":    hw2lib.ClientIP(r),
		})
	}))
	if err != nil {
		panic(err)
	}
}
