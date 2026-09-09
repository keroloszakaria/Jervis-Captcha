package captcha_test

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	"github.com/keroloszakaria/Jervis-Captcha/internal/testkit"
)

var ctx = context.Background()

const origin = "http://localhost"
const secret = "0123456789abcdef0123456789abcdef"
const bound = "test-browser-session-binding-00001"

func setup(t *testing.T) (*captcha.Engine, *testkit.Memory) {
	t.Helper()
	p := captcha.DefaultPolicy()
	p.IsAntiAI = false
	p.MinSolveMS = 0
	p.Types = append([]string{}, captcha.Kinds...)
	m := testkit.New()
	e, err := captcha.New(m, []captcha.Site{{Key: "test", Secret: secret, Origins: []string{origin}, Actions: map[string]captcha.Policy{"signup": p}}})
	if err != nil {
		t.Fatal(err)
	}
	return e, m
}
func hash(v string) string { h := sha256.Sum256([]byte(v)); return hex.EncodeToString(h[:]) }
func start(t *testing.T, e *captcha.Engine, kind string) (*captcha.SessionResult, *captcha.Challenge) {
	t.Helper()
	s, err := e.CreateSession(ctx, captcha.SessionRequest{SiteKey: "test", Action: "signup", Binding: bound, Type: kind})
	if err != nil {
		t.Fatal(err)
	}
	c, err := e.Issue(ctx, s.SessionToken, origin)
	if err != nil {
		t.Fatal(err)
	}
	return s, c
}
func nonce(w *captcha.Work) string {
	if w == nil {
		return ""
	}
	for i := 0; i < 10000000; i++ {
		n := fmt.Sprint(i)
		if captcha.WorkValid(w, n) {
			return n
		}
	}
	panic("work search failed")
}
func solve(t *testing.T, e *captcha.Engine, m captcha.Store, s *captcha.SessionResult, c *captcha.Challenge) *captcha.Proof {
	t.Helper()
	r, err := m.Load(ctx, hash(s.SessionToken))
	if err != nil {
		t.Fatal(err)
	}
	p, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: r.Expected, WorkNonce: nonce(c.Work)}, origin)
	if err != nil {
		t.Fatal(err)
	}
	return p
}
func verify(token string) captcha.VerifyRequest {
	return captcha.VerifyRequest{SiteKey: "test", Action: "signup", Binding: bound, Token: token}
}
func code(t *testing.T, err error, want string) {
	t.Helper()
	var e *captcha.Error
	if !errors.As(err, &e) || e.Code != want {
		t.Fatalf("want %s, got %v", want, err)
	}
}

func TestAllChallengeRoundTrips(t *testing.T) {
	for _, kind := range captcha.Kinds {
		t.Run(kind, func(t *testing.T) {
			e, m := setup(t)
			s, c := start(t, e, kind)
			if c.Type != kind || c.ID == "" || c.ExpiresInMS <= 0 {
				t.Fatal("invalid public challenge")
			}
			p := solve(t, e, m, s, c)
			v, err := e.Consume(ctx, verify(p.Token))
			if err != nil || !v.Success {
				t.Fatalf("consume: %v", err)
			}
			_, err = e.Consume(ctx, verify(p.Token))
			code(t, err, "invalid_proof")
		})
	}
}
func TestConcurrentSolveIssuesExactlyOneProof(t *testing.T) {
	e, m := setup(t)
	s, c := start(t, e, "text")
	row, _ := m.Load(ctx, hash(s.SessionToken))
	var successes atomic.Int32
	var wg sync.WaitGroup
	for range 24 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: row.Expected}, origin); err == nil {
				successes.Add(1)
			}
		}()
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatalf("issued %d proofs", successes.Load())
	}
}
func TestConcurrentConsumeExactlyOnce(t *testing.T) {
	e, m := setup(t)
	s, c := start(t, e, "text")
	p := solve(t, e, m, s, c)
	var successes atomic.Int32
	var wg sync.WaitGroup
	for range 64 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := e.Consume(ctx, verify(p.Token)); err == nil {
				successes.Add(1)
			}
		}()
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatalf("consumed %d times", successes.Load())
	}
}
func TestContextBinding(t *testing.T) {
	for _, field := range []string{"binding", "action", "site", "signature"} {
		t.Run(field, func(t *testing.T) {
			e, m := setup(t)
			s, c := start(t, e, "text")
			p := solve(t, e, m, s, c)
			r := verify(p.Token)
			switch field {
			case "binding":
				r.Binding = strings.Repeat("z", 32)
			case "action":
				r.Action = "transfer"
			case "site":
				r.SiteKey = "other"
			case "signature":
				r.Token = p.Token[:65] + strings.Repeat("x", 43)
			}
			_, err := e.Consume(ctx, r)
			code(t, err, "invalid_proof")
			if _, err = e.Consume(ctx, verify(p.Token)); err != nil {
				t.Fatal("wrong context burned legitimate proof", err)
			}
		})
	}
}
func TestRefreshInvalidatesPriorChallenge(t *testing.T) {
	e, m := setup(t)
	s, c := start(t, e, "text")
	old, _ := m.Load(ctx, hash(s.SessionToken))
	fresh, err := e.Issue(ctx, s.SessionToken, origin)
	if err != nil {
		t.Fatal(err)
	}
	_, err = e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: old.Expected}, origin)
	code(t, err, "invalid_challenge")
	solve(t, e, m, s, fresh)
}
func TestRefreshDoesNotResetAttempts(t *testing.T) {
	e, _ := setup(t)
	s, c := start(t, e, "text")
	for i := 0; i < 3; i++ {
		_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: captcha.Answer{Text: "WRONG"}}, origin)
		code(t, err, "incorrect_answer")
		if i == 0 {
			var er error
			c, er = e.Issue(ctx, s.SessionToken, origin)
			if er != nil {
				t.Fatal(er)
			}
		}
	}
	_, err := e.Issue(ctx, s.SessionToken, origin)
	code(t, err, "session_locked")
}
func TestRefreshLimit(t *testing.T) {
	e, _ := setup(t)
	s, _ := start(t, e, "text")
	for i := 0; i < 3; i++ {
		if _, err := e.Issue(ctx, s.SessionToken, origin); err != nil {
			t.Fatal(err)
		}
	}
	_, err := e.Issue(ctx, s.SessionToken, origin)
	code(t, err, "session_locked")
}
func TestExpiryAndServerTiming(t *testing.T) {
	for _, which := range []string{"challenge", "proof", "session", "backwards"} {
		t.Run(which, func(t *testing.T) {
			e, m := setup(t)
			now := time.Unix(1800000000, 0)
			e.Now = func() time.Time { return now }
			s, c := start(t, e, "text")
			row, _ := m.Load(ctx, hash(s.SessionToken))
			var p *captcha.Proof
			if which == "proof" {
				p = solve(t, e, m, s, c)
			}
			switch which {
			case "challenge":
				now = now.Add(120 * time.Second)
				_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: row.Expected}, origin)
				code(t, err, "challenge_expired")
			case "proof":
				now = now.Add(60 * time.Second)
				_, err := e.Consume(ctx, verify(p.Token))
				code(t, err, "invalid_proof")
			case "session":
				now = now.Add(300 * time.Second)
				_, err := e.Issue(ctx, s.SessionToken, origin)
				code(t, err, "session_expired")
			case "backwards":
				now = now.Add(-time.Second)
				_, err := e.Issue(ctx, s.SessionToken, origin)
				code(t, err, "session_expired")
			}
		})
	}
}
func TestMinimumTimeCannotBeForged(t *testing.T) {
	e, m := setup(t)
	site := e.Sites["test"]
	p := site.Actions["signup"]
	p.MinSolveMS = 500
	site.Actions["signup"] = p
	now := time.Unix(1800000000, 0)
	e.Now = func() time.Time { return now }
	s, c := start(t, e, "text")
	row, _ := m.Load(ctx, hash(s.SessionToken))
	_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: row.Expected}, origin)
	code(t, err, "too_early")
	now = now.Add(500 * time.Millisecond)
	solve(t, e, m, s, c)
}
func TestOriginEnforcedPerSite(t *testing.T) {
	e, _ := setup(t)
	s, _ := start(t, e, "text")
	for _, o := range []string{"", "null", "https://evil.example", origin + ".evil.example"} {
		_, err := e.Issue(ctx, s.SessionToken, o)
		code(t, err, "origin_denied")
	}
}
func TestHardenedPolicyRequiresWork(t *testing.T) {
	e, m := setup(t)
	site := e.Sites["test"]
	p := captcha.DefaultPolicy()
	p.MinSolveMS = 0
	site.Actions["signup"] = p
	s, c := start(t, e, "slider")
	if c.Work == nil {
		t.Fatal("work missing")
	}
	row, _ := m.Load(ctx, hash(s.SessionToken))
	_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: row.Expected}, origin)
	code(t, err, "incorrect_answer")
	solve(t, e, m, s, c)
}
func TestPolicyChangesRevokeSessionsAndProofs(t *testing.T) {
	for _, phase := range []string{"challenge", "proof"} {
		t.Run(phase, func(t *testing.T) {
			e, m := setup(t)
			s, c := start(t, e, "text")
			var p *captcha.Proof
			if phase == "proof" {
				p = solve(t, e, m, s, c)
			}
			site := e.Sites["test"]
			policy := site.Actions["signup"]
			policy.MaxAttempts = 1
			site.Actions["signup"] = policy
			if phase == "proof" {
				_, err := e.Consume(ctx, verify(p.Token))
				code(t, err, "invalid_proof")
			} else {
				_, err := e.Issue(ctx, s.SessionToken, origin)
				code(t, err, "session_expired")
			}
		})
	}
}
func TestArabicAndPersianDigits(t *testing.T) {
	for _, alphabet := range []string{"٠١٢٣٤٥٦٧٨٩", "۰۱۲۳۴۵۶۷۸۹"} {
		e, m := setup(t)
		s, c := start(t, e, "math")
		row, _ := m.Load(ctx, hash(s.SessionToken))
		runes := []rune(alphabet)
		row.Expected.Text = strings.Map(func(r rune) rune { return runes[r-'0'] }, row.Expected.Text)
		_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: row.Expected}, origin)
		if err != nil {
			t.Fatal(err)
		}
	}
}
func TestNonfiniteCoordinatesRejected(t *testing.T) {
	for _, n := range []float64{math.NaN(), math.Inf(1), -1, 361} {
		e, _ := setup(t)
		s, c := start(t, e, "rotate")
		_, err := e.Solve(ctx, captcha.SolveRequest{SessionToken: s.SessionToken, ChallengeID: c.ID, Answer: captcha.Answer{Angle: n}}, origin)
		code(t, err, "incorrect_answer")
	}
}
func TestNoPrivateFieldsInPublicChallenge(t *testing.T) {
	e, _ := setup(t)
	_, c := start(t, e, "text")
	b, _ := json.Marshal(c)
	var public map[string]any
	json.Unmarshal(b, &public)
	for _, key := range []string{"Expected", "expected", "answer", "secret", "binding", "sessionToken"} {
		if _, ok := public[key]; ok {
			t.Fatal("leaked", key)
		}
	}
}
func TestAudioIsStandaloneWAV(t *testing.T) {
	e, _ := setup(t)
	_, c := start(t, e, "audio")
	b, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(c.Audio, "data:audio/wav;base64,"))
	if err != nil || len(b) < 10000 || string(b[:4]) != "RIFF" || string(b[8:12]) != "WAVE" {
		t.Fatal("invalid WAV")
	}
}
func TestBindingRateLimit(t *testing.T) {
	e, _ := setup(t)
	for i := 0; i < 12; i++ {
		if _, err := e.CreateSession(ctx, captcha.SessionRequest{SiteKey: "test", Action: "signup", Binding: bound, Type: "text"}); err != nil {
			t.Fatal(err)
		}
	}
	_, err := e.CreateSession(ctx, captcha.SessionRequest{SiteKey: "test", Action: "signup", Binding: bound, Type: "text"})
	code(t, err, "rate_limited")
}
func TestInvalidTokens(t *testing.T) {
	e, _ := setup(t)
	for _, s := range []string{"", strings.Repeat("x", 44), "../secret", strings.Repeat("?", 43)} {
		_, err := e.Issue(ctx, s, origin)
		code(t, err, "invalid_session")
	}
}
func TestAuthentication(t *testing.T) {
	e, _ := setup(t)
	if !e.Authenticate("test", secret) || e.Authenticate("test", "") || e.Authenticate("test", secret+"x") || e.Authenticate("missing", secret) {
		t.Fatal("authentication mismatch")
	}
}
func TestPoliciesValidate(t *testing.T) {
	for _, name := range []string{"empty", "unsupported", "duplicate", "text-hard", "weak", "work", "ttl", "attempts", "refresh", "timing", "rate"} {
		t.Run(name, func(t *testing.T) {
			p := captcha.DefaultPolicy()
			switch name {
			case "empty":
				p.Types = nil
			case "unsupported":
				p.Types = []string{"biometric"}
			case "duplicate":
				p.Types = []string{"text", "text"}
			case "text-hard":
				p.Types = []string{"text"}
			case "weak":
				p.Types = []string{"math"}
			case "work":
				p.WorkBits = 25
			case "ttl":
				p.ProofTTLSeconds = 999
			case "attempts":
				p.MaxAttempts = 0
			case "refresh":
				p.MaxRefreshes = 0
			case "timing":
				p.MinSolveMS = -1
			case "rate":
				p.SessionsPerMinute = 0
			}
			if p.Validate() == nil {
				t.Fatal("invalid policy accepted")
			}
		})
	}
}
func TestTextPolicyAllowedWhenAntiAIDisabled(t *testing.T) {
	p := captcha.DefaultPolicy()
	p.IsAntiAI = false
	p.Types = []string{"text"}
	p.WorkBits = 0
	if err := p.Validate(); err != nil {
		t.Fatalf("non-antiAI text policy rejected: %v", err)
	}
}
func TestWorkRejectsUnboundedNonce(t *testing.T) {
	if captcha.WorkValid(&captcha.Work{Seed: "x", Bits: 12}, strings.Repeat("1", 65)) || captcha.WorkValid(&captcha.Work{Seed: "x", Bits: 12}, "-1") || captcha.WorkValid(nil, "1") {
		t.Fatal("invalid work accepted")
	}
}
