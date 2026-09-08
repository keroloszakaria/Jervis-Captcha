package storage_test

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	"github.com/keroloszakaria/Jervis-Captcha/internal/storage"
)

func TestSQLitePersistenceAndCrossConnectionCAS(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "data.db")
	a, err := storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	b, err := storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer b.Close()
	r := &captcha.Session{ID: "row", ExpiresMS: 200, State: "new"}
	if err = a.Create(ctx, r); err != nil {
		t.Fatal(err)
	}
	var successes atomic.Int32
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			db := a
			if i%2 == 1 {
				db = b
			}
			copy := *r
			copy.Revision = 1
			copy.State = "consumed"
			ok, er := db.CAS(ctx, &copy, 0)
			if er != nil {
				t.Error(er)
			}
			if ok {
				successes.Add(1)
			}
		}(i)
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatal("CAS accepted", successes.Load())
	}
	a.Close()
	a, err = storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer a.Close()
	saved, err := a.Load(ctx, "row")
	if err != nil || saved.State != "consumed" {
		t.Fatal("restart lost state", err)
	}
	if err = a.Cleanup(ctx, 200); err != nil {
		t.Fatal(err)
	}
	if _, err = a.Load(ctx, "row"); err != captcha.ErrNotFound {
		t.Fatal("cleanup failed")
	}
}
func TestSQLiteRateLimitSurvivesRestart(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "rate.db")
	s, err := storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 3; i++ {
		ok, er := s.Limit(ctx, "key", 1000, 60000, 3)
		if er != nil || !ok {
			t.Fatal(er)
		}
	}
	s.Close()
	s, err = storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	ok, err := s.Limit(ctx, "key", 1000, 60000, 3)
	if err != nil || ok {
		t.Fatal("limit reset on restart", err)
	}
	ok, err = s.Limit(ctx, "key", 61000, 60000, 3)
	if err != nil || !ok {
		t.Fatal("new window blocked", err)
	}
}
func TestSQLiteRealProofReplayAfterRestart(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "proof.db")
	store, err := storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	p := captcha.DefaultPolicy()
	p.IsAntiAI = false
	p.MinSolveMS = 0
	p.Types = []string{"text"}
	sites := []captcha.Site{{Key: "app", Secret: "0123456789abcdef0123456789abcdef", Origins: []string{"http://localhost"}, Actions: map[string]captcha.Policy{"signup": p}}}
	e, err := captcha.New(store, sites)
	if err != nil {
		t.Fatal(err)
	}
	session, err := e.CreateSession(ctx, captcha.SessionRequest{SiteKey: "app", Action: "signup", Binding: "binding-at-least-sixteen-bytes"})
	if err != nil {
		t.Fatal(err)
	}
	challenge, err := e.Issue(ctx, session.SessionToken, "http://localhost")
	if err != nil {
		t.Fatal(err)
	}
	h := sha256.Sum256([]byte(session.SessionToken))
	row, err := store.Load(ctx, hex.EncodeToString(h[:]))
	if err != nil {
		t.Fatal(err)
	}
	proof, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: session.SessionToken, ChallengeID: challenge.ID, Answer: row.Expected}, "http://localhost")
	if err != nil {
		t.Fatal(err)
	}
	request := captcha.VerifyRequest{SiteKey: "app", Action: "signup", Binding: "binding-at-least-sixteen-bytes", Token: proof.Token}
	if _, err = e.Consume(ctx, request); err != nil {
		t.Fatal(err)
	}
	store.Close()
	store, err = storage.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	e, err = captcha.New(store, sites)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = e.Consume(ctx, request); err == nil {
		t.Fatal("proof accepted twice across restart")
	}
}
