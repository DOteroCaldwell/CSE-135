package hw2lib

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"time"
)

// A CGI process exits after each request, so there is nowhere to keep an
// in-memory session map. These demos persist one JSON file per session, mode
// 0600, and hand the browser nothing but the opaque ID.

const SessionCookie = "HW2GOSESS"

const sessionMaxAge = 24 * time.Hour

// The ID arrives from a client-controlled cookie and is used to build a
// filename, so it is validated against this before it touches the disk.
var sessionIDRe = regexp.MustCompile(`^[A-Za-z0-9_-]{22,64}$`)

// SessionDir is overridable for local testing only. Apache never sets this,
// and it cannot be injected through a request header — those all arrive
// HTTP_-prefixed.
func SessionDir() string {
	if dir := os.Getenv("HW2_SESSION_DIR"); dir != "" {
		return dir
	}
	return "/var/lib/cse135/sessions"
}

type Session struct {
	ID   string            `json:"-"`
	CSRF string            `json:"csrf"`
	Data map[string]string `json:"data"`
}

func newSessionID() string {
	buf := make([]byte, 24)
	if _, err := rand.Read(buf); err != nil {
		// crypto/rand failing is not recoverable; better to stop than to hand
		// out a predictable session ID.
		panic("crypto/rand unavailable: " + err.Error())
	}
	return base64.RawURLEncoding.EncodeToString(buf)
}

func newCSRF() string {
	buf := make([]byte, 16)
	if _, err := rand.Read(buf); err != nil {
		panic("crypto/rand unavailable: " + err.Error())
	}
	return hex.EncodeToString(buf)
}

func sessionPath(id string) (string, bool) {
	if !sessionIDRe.MatchString(id) {
		return "", false
	}
	return filepath.Join(SessionDir(), id+".json"), true
}

// BeginSession resolves the session for this request, minting one if the
// cookie is missing or malformed, and always sets the cookie on the response.
func BeginSession(w http.ResponseWriter, r *http.Request) *Session {
	id := ""
	if c, err := r.Cookie(SessionCookie); err == nil && sessionIDRe.MatchString(c.Value) {
		id = c.Value
	}

	fresh := id == ""
	if fresh {
		id = newSessionID()
	}

	s := loadSession(id)
	s.ID = id

	if s.CSRF == "" {
		s.CSRF = newCSRF()
		fresh = true
	}
	if fresh {
		s.Save()
	}

	setSessionCookie(w, id, false)
	return s
}

func loadSession(id string) *Session {
	s := &Session{ID: id, Data: map[string]string{}}

	path, ok := sessionPath(id)
	if !ok {
		return s
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return s
	}
	if err := json.Unmarshal(raw, s); err != nil {
		return &Session{ID: id, Data: map[string]string{}}
	}
	if s.Data == nil {
		s.Data = map[string]string{}
	}
	return s
}

// Save writes the session atomically: a temp file, then a rename.
func (s *Session) Save() {
	path, ok := sessionPath(s.ID)
	if !ok {
		return
	}
	if err := os.MkdirAll(SessionDir(), 0o700); err != nil {
		return
	}
	sweep()

	raw, err := json.Marshal(s)
	if err != nil {
		return
	}
	tmp := path + ".tmp"
	// 0600: the store holds session contents for every visitor.
	if err := os.WriteFile(tmp, raw, 0o600); err != nil {
		return
	}
	_ = os.Rename(tmp, path)
}

func (s *Session) Destroy(w http.ResponseWriter) {
	if path, ok := sessionPath(s.ID); ok {
		_ = os.Remove(path)
	}
	// BeginSession already queued a Set-Cookie for this response; drop it so
	// the client gets one unambiguous instruction rather than two conflicting
	// headers for the same cookie name.
	w.Header().Del("Set-Cookie")
	setSessionCookie(w, "", true)
}

// CSRFOK compares in constant time.
func (s *Session) CSRFOK(sent string) bool {
	if s.CSRF == "" || sent == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(s.CSRF), []byte(sent)) == 1
}

func setSessionCookie(w http.ResponseWriter, value string, expire bool) {
	c := &http.Cookie{
		Name:     SessionCookie,
		Value:    value,
		Path:     "/hw2/",
		HttpOnly: true, // script cannot read it
		Secure:   true, // the site is HTTPS-only
		SameSite: http.SameSiteLaxMode,
	}
	if expire {
		c.MaxAge = -1
	}
	http.SetCookie(w, c)
}

// sweep occasionally drops expired session files, the way PHP's gc does.
func sweep() {
	buf := make([]byte, 1)
	if _, err := rand.Read(buf); err != nil || buf[0]%20 != 0 {
		return
	}
	entries, err := os.ReadDir(SessionDir())
	if err != nil {
		return
	}
	cutoff := time.Now().Add(-sessionMaxAge)
	for _, e := range entries {
		info, err := e.Info()
		if err != nil || info.IsDir() {
			continue
		}
		if info.ModTime().Before(cutoff) {
			_ = os.Remove(filepath.Join(SessionDir(), e.Name()))
		}
	}
}
