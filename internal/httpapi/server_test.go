package httpapi_test

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	"github.com/keroloszakaria/Jervis-Captcha/internal/httpapi"
	"github.com/keroloszakaria/Jervis-Captcha/internal/testkit"
)

const secret = "0123456789abcdef0123456789abcdef"

func handler(t *testing.T, store captcha.Store) http.Handler {
	t.Helper()
	if store == nil {
		store = testkit.New()
	}
	p := captcha.DefaultPolicy()
	p.IsAntiAI = false
	p.MinSolveMS = 0
	e, err := captcha.New(store, []captcha.Site{{Key: "local", Secret: secret, Origins: []string{"http://localhost"}, Actions: map[string]captcha.Policy{"signup": p}}})
	if err != nil {
		t.Fatal(err)
	}
	return httpapi.New(e, nil, false).Handler()
}
func call(h http.Handler, path, body, origin, auth string) *httptest.ResponseRecorder {
	r := httptest.NewRequest("POST", path, strings.NewReader(body))
	r.Header.Set("Content-Type", "application/json")
	if origin != "" {
		r.Header.Set("Origin", origin)
	}
	if auth != "" {
		r.Header.Set("Authorization", "Bearer "+auth)
	}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	return w
}

const sessionBody = `{"siteKey":"local","action":"signup","binding":"this-is-a-trusted-host-binding","type":"text"}`

func TestPrivateEndpointAuthentication(t *testing.T) {
	h := handler(t, nil)
	for _, s := range []string{"", "wrong"} {
		w := call(h, "/v1/sessions", sessionBody, "", s)
		if w.Code != 401 {
			t.Fatal(w.Code)
		}
	}
	if w := call(h, "/v1/sessions", sessionBody, "http://localhost", secret); w.Code != 401 {
		t.Fatal("browser accessed private API")
	}
	if w := call(h, "/v1/sessions", sessionBody, "", secret); w.Code != 201 {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestStrictJSON(t *testing.T) {
	h := handler(t, nil)
	for _, b := range []string{sessionBody + `{}`, strings.TrimSuffix(sessionBody, "}") + `,"isAntiAI":false}`, `{"siteKey":1}`, strings.Repeat("x", 9000), `null`} {
		w := call(h, "/v1/sessions", b, "", secret)
		if w.Code < 400 {
			t.Fatal("accepted", b)
		}
	}
}
func TestPublicIssueHeadersAndNoSecret(t *testing.T) {
	h := handler(t, nil)
	w := call(h, "/v1/sessions", sessionBody, "", secret)
	var s captcha.SessionResult
	if err := json.Unmarshal(w.Body.Bytes(), &s); err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(map[string]string{"sessionToken": s.SessionToken})
	w = call(h, "/v1/challenges", string(b), "http://localhost", "")
	if w.Code != 200 {
		t.Fatal(w.Code, w.Body.String())
	}
	if w.Header().Get("Access-Control-Allow-Origin") != "http://localhost" || w.Header().Get("Cache-Control") != "no-store" || w.Header().Get("X-Content-Type-Options") != "nosniff" {
		t.Fatal("missing headers")
	}
	if bytes.Contains(w.Body.Bytes(), []byte(secret)) {
		t.Fatal("secret leaked")
	}
	var c map[string]any
	json.Unmarshal(w.Body.Bytes(), &c)
	if c["answer"] != nil || c["Expected"] != nil {
		t.Fatal("answer leaked")
	}
}
func TestOriginAndCORS(t *testing.T) {
	h := handler(t, nil)
	for _, origin := range []string{"", "null", "https://attacker.example"} {
		w := call(h, "/v1/challenges", `{}`, origin, "")
		if w.Code != 403 || w.Header().Get("Access-Control-Allow-Origin") != "" {
			t.Fatal("origin accepted", origin)
		}
	}
	r := httptest.NewRequest(http.MethodOptions, "/v1/challenges", nil)
	r.Header.Set("Origin", "http://localhost")
	r.Header.Set("Access-Control-Request-Method", "POST")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 204 || w.Header().Get("Access-Control-Allow-Origin") != "http://localhost" {
		t.Fatal("preflight failed")
	}
}
func TestNoProductionDemoOrAnswerEndpoint(t *testing.T) {
	h := handler(t, nil)
	for _, path := range []string{"/demo/session", "/demo/submit", "/__test/answer", "/debug/vars"} {
		w := call(h, path, `{}`, "http://localhost", secret)
		if w.Code != 404 {
			t.Fatal("unexpected route", path, w.Code)
		}
	}
}
func TestRejectQuerySecretsAndWrongContentType(t *testing.T) {
	h := handler(t, nil)
	if w := call(h, "/v1/sessions?secret=leak", sessionBody, "", secret); w.Code != 400 {
		t.Fatal(w.Code)
	}
	r := httptest.NewRequest("POST", "/v1/sessions", strings.NewReader(sessionBody))
	r.Header.Set("Content-Type", "text/plain")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 415 {
		t.Fatal(w.Code)
	}
}

type brokenStore struct{ captcha.Store }

func (b brokenStore) Ping(context.Context) error { return errors.New("sensitive database details") }
func (b brokenStore) Create(context.Context, *captcha.Session) error {
	return errors.New("sensitive database details")
}
func TestFailClosedAndSanitizeInternalErrors(t *testing.T) {
	h := handler(t, brokenStore{testkit.New()})
	w := call(h, "/v1/sessions", sessionBody, "", secret)
	if w.Code != 503 || strings.Contains(w.Body.String(), "sensitive") {
		t.Fatal(w.Code, w.Body.String())
	}
	r := httptest.NewRequest("GET", "/healthz", nil)
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 503 {
		t.Fatal("unhealthy accepted")
	}
}
func TestRealHTTPService(t *testing.T) {
	s := httptest.NewServer(handler(t, nil))
	defer s.Close()
	r, err := http.NewRequest("POST", s.URL+"/v1/sessions", strings.NewReader(sessionBody))
	if err != nil {
		t.Fatal(err)
	}
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("Authorization", "Bearer "+secret)
	res, err := s.Client().Do(r)
	if err != nil {
		t.Fatal(err)
	}
	defer res.Body.Close()
	b, err := io.ReadAll(res.Body)
	if err != nil || res.StatusCode != 201 {
		t.Fatal(string(b), err)
	}
}
