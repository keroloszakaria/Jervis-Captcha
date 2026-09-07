// This executable is exclusively an E2E test fixture. It is NEVER built into
// release archives. The answer oracle does not exist in cmd/jervis-captcha.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	"github.com/keroloszakaria/Jervis-Captcha/internal/httpapi"
	"github.com/keroloszakaria/Jervis-Captcha/internal/storage"
	"github.com/keroloszakaria/Jervis-Captcha/internal/web"
)

func main() {
	dir, err := os.MkdirTemp("", "jervis-e2e-")
	if err != nil {
		log.Fatal(err)
	}
	defer os.RemoveAll(dir)
	store, err := storage.Open(filepath.Join(dir, "test.db"))
	if err != nil {
		log.Fatal(err)
	}
	defer store.Close()
	p := captcha.DefaultPolicy()
	p.IsAntiAI = false
	p.Types = append([]string{}, captcha.Kinds...)
	p.WorkBits = 12
	p.MinSolveMS = 50
	p.ProofTTLSeconds = 5
	p.SessionsPerMinute = 10000
	e, err := captcha.New(store, []captcha.Site{{Key: "fixture", Secret: "fixture-secret-not-for-production-000000", Origins: []string{"http://127.0.0.1:8099"}, Actions: map[string]captcha.Policy{"demo": p}}})
	if err != nil {
		log.Fatal(err)
	}
	assets, err := web.Assets()
	if err != nil {
		log.Fatal(err)
	}
	mux := http.NewServeMux()
	mux.Handle("/", httpapi.New(e, assets, true).Handler())
	mux.HandleFunc("POST /__test/answer", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer fixture-oracle-only" {
			http.Error(w, "denied", 401)
			return
		}
		var req struct {
			Token string `json:"token"`
		}
		r.Body = http.MaxBytesReader(w, r.Body, 1024)
		if json.NewDecoder(r.Body).Decode(&req) != nil {
			http.Error(w, "invalid", 400)
			return
		}
		h := sha256.Sum256([]byte(req.Token))
		s, err := store.Load(r.Context(), hex.EncodeToString(h[:]))
		if err != nil {
			http.Error(w, "missing", 404)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(s.Expected)
	})
	log.Fatal(httpapi.HTTPServer("127.0.0.1:8099", mux).ListenAndServe())
}
