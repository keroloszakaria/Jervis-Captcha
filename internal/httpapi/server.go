// Package httpapi exposes public challenge and private integration endpoints.
package httpapi

import (
	"encoding/json"
	"errors"
	"io"
	"io/fs"
	"log/slog"
	"mime"
	"net/http"
	"strings"
	"time"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
)

type Server struct {
	Engine   *captcha.Engine
	Assets   fs.FS
	Demo     bool
	demoSite string
	work     chan struct{}
}

func New(engine *captcha.Engine, assets fs.FS, demo bool) *Server {
	s := &Server{Engine: engine, Assets: assets, Demo: demo, work: make(chan struct{}, 8)}
	return s
}
func write(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
func report(w http.ResponseWriter, err error) {
	var e *captcha.Error
	if errors.As(err, &e) {
		if e.Status == 429 {
			w.Header().Set("Retry-After", "60")
		}
		write(w, e.Status, e)
		return
	}
	slog.Error("verification operation failed", "category", "internal")
	write(w, 503, map[string]string{"code": "service_unavailable"})
}
func decode(w http.ResponseWriter, r *http.Request, v any) bool {
	media, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || media != "application/json" {
		write(w, 415, map[string]string{"code": "json_required"})
		return false
	}
	r.Body = http.MaxBytesReader(w, r.Body, 8192)
	d := json.NewDecoder(r.Body)
	d.DisallowUnknownFields()
	if err = d.Decode(v); err != nil {
		write(w, 400, map[string]string{"code": "invalid_request"})
		return false
	}
	var extra any
	if err = d.Decode(&extra); err != io.EOF {
		write(w, 400, map[string]string{"code": "invalid_request"})
		return false
	}
	return true
}
func (s *Server) private(w http.ResponseWriter, r *http.Request, site string) bool {
	auth := r.Header.Get("Authorization")
	if r.Header.Get("Origin") != "" || !strings.HasPrefix(auth, "Bearer ") || !s.Engine.Authenticate(site, strings.TrimPrefix(auth, "Bearer ")) {
		write(w, 401, map[string]string{"code": "unauthorized"})
		return false
	}
	return true
}
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		if err := s.Engine.Store.Ping(r.Context()); err != nil {
			report(w, err)
			return
		}
		write(w, 200, map[string]string{"status": "ok", "version": captcha.Version})
	})
	mux.HandleFunc("POST /v1/sessions", func(w http.ResponseWriter, r *http.Request) {
		var req captcha.SessionRequest
		if !decode(w, r, &req) || !s.private(w, r, req.SiteKey) {
			return
		}
		result, err := s.Engine.CreateSession(r.Context(), req)
		if err != nil {
			report(w, err)
			return
		}
		write(w, 201, result)
	})
	mux.HandleFunc("POST /v1/challenges", func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			SessionToken string `json:"sessionToken"`
		}
		if !decode(w, r, &req) {
			return
		}
		result, err := s.Engine.Issue(r.Context(), req.SessionToken, r.Header.Get("Origin"))
		if err != nil {
			report(w, err)
			return
		}
		write(w, 200, result)
	})
	mux.HandleFunc("POST /v1/answers", func(w http.ResponseWriter, r *http.Request) {
		var req captcha.SolveRequest
		if !decode(w, r, &req) {
			return
		}
		result, err := s.Engine.Solve(r.Context(), req, r.Header.Get("Origin"))
		if err != nil {
			report(w, err)
			return
		}
		write(w, 200, result)
	})
	mux.HandleFunc("POST /v1/siteverify", func(w http.ResponseWriter, r *http.Request) {
		var req captcha.VerifyRequest
		if !decode(w, r, &req) || !s.private(w, r, req.SiteKey) {
			return
		}
		result, err := s.Engine.Consume(r.Context(), req)
		if err != nil {
			report(w, err)
			return
		}
		write(w, 200, result)
	})
	if s.Assets != nil {
		mux.Handle("GET /assets/", http.StripPrefix("/assets/", http.FileServer(http.FS(s.Assets))))
	}
	if s.Demo {
		s.demoRoutes(mux)
	} else {
		mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
			write(w, 200, map[string]string{"product": "Jervis Captcha", "version": captcha.Version, "mode": "production"})
		})
	}
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("Referrer-Policy", "no-referrer")
		w.Header().Set("Content-Security-Policy", "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self' data: blob:; connect-src 'self'; worker-src 'self' blob:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'")
		w.Header().Set("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
		if len(r.URL.RawQuery) > 0 && strings.HasPrefix(r.URL.Path, "/v1/") {
			write(w, 400, map[string]string{"code": "query_not_allowed"})
			return
		}
		origin := r.Header.Get("Origin")
		public := r.URL.Path == "/v1/challenges" || r.URL.Path == "/v1/answers"
		if public {
			w.Header().Add("Vary", "Origin")
			if !s.Engine.AnyOriginAllowed(origin) {
				write(w, 403, map[string]string{"code": "origin_denied"})
				return
			}
			w.Header().Set("Access-Control-Allow-Origin", origin)
			if r.Method == http.MethodOptions {
				w.Header().Set("Access-Control-Allow-Methods", "POST")
				w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
				w.WriteHeader(204)
				return
			}
		}
		select {
		case s.work <- struct{}{}:
			defer func() { <-s.work }()
		default:
			write(w, 503, map[string]string{"code": "busy"})
			return
		}
		mux.ServeHTTP(w, r)
	})
}
func HTTPServer(addr string, h http.Handler) *http.Server {
	return &http.Server{Addr: addr, Handler: h, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 15 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16384}
}
