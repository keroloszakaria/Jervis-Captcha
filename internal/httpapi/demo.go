package httpapi

import (
	"crypto/rand"
	"encoding/base64"
	"io/fs"
	"net/http"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
)

// Demo routes are opt-in and the CLI only enables them on a loopback listener.
// They simulate the HOST BACKEND. Production integrations implement their own
// session binding, CSRF protection, authorization and business endpoint.
func (s *Server) demoRoutes(mux *http.ServeMux) {
	for key := range s.Engine.Sites {
		s.demoSite = key
		break
	}
	bindingFor := func(w http.ResponseWriter, r *http.Request) string {
		if c, e := r.Cookie("jervis_demo"); e == nil && len(c.Value) == 43 {
			return c.Value
		}
		b := make([]byte, 32)
		if _, e := rand.Read(b); e != nil {
			return ""
		}
		value := base64.RawURLEncoding.EncodeToString(b)
		http.SetCookie(w, &http.Cookie{Name: "jervis_demo", Value: value, HttpOnly: true, SameSite: http.SameSiteStrictMode, Secure: r.TLS != nil, Path: "/", MaxAge: 3600})
		return value
	}
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		bindingFor(w, r)
		b, err := fs.ReadFile(s.Assets, "index.html")
		if err != nil {
			report(w, err)
			return
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		w.Write(b)
	})
	mux.HandleFunc("POST /demo/session", func(w http.ResponseWriter, r *http.Request) {
		if !s.Engine.OriginAllowed(s.demoSite, r.Header.Get("Origin")) {
			write(w, 403, map[string]string{"code": "origin_denied"})
			return
		}
		var req struct {
			Type string `json:"type"`
		}
		if !decode(w, r, &req) {
			return
		}
		result, err := s.Engine.CreateSession(r.Context(), captcha.SessionRequest{SiteKey: s.demoSite, Action: "demo", Binding: bindingFor(w, r), Type: req.Type})
		if err != nil {
			report(w, err)
			return
		}
		write(w, 201, result)
	})
	mux.HandleFunc("POST /demo/submit", func(w http.ResponseWriter, r *http.Request) {
		if !s.Engine.OriginAllowed(s.demoSite, r.Header.Get("Origin")) {
			write(w, 403, map[string]string{"code": "origin_denied"})
			return
		}
		var req struct {
			Token string `json:"token"`
		}
		if !decode(w, r, &req) {
			return
		}
		result, err := s.Engine.Consume(r.Context(), captcha.VerifyRequest{SiteKey: s.demoSite, Action: "demo", Binding: bindingFor(w, r), Token: req.Token})
		if err != nil {
			report(w, err)
			return
		}
		write(w, 200, result)
	})
}
