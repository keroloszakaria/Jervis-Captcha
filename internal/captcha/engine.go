package captcha

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"math"
	"math/big"
	"reflect"
	"slices"
	"strings"
)

func randomToken() (string, error) {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		return "", e
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}
func randomInt(n int) (int, error) {
	v, e := rand.Int(rand.Reader, big.NewInt(int64(n)))
	if e != nil {
		return 0, e
	}
	return int(v.Int64()), nil
}
func digest(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }
func binding(secret, value string) string {
	h := hmac.New(sha256.New, []byte(secret))
	h.Write([]byte("jervis.binding.v1\x00" + value))
	return hex.EncodeToString(h.Sum(nil))
}
func equal(a, b string) bool { return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1 }
func validToken(t string) bool {
	b, e := base64.RawURLEncoding.DecodeString(t)
	return e == nil && len(b) == 32 && len(t) == 43
}
func (e *Engine) Authenticate(key, secret string) bool {
	s, ok := e.Sites[key]
	return ok && equal(digest(s.Secret), digest(secret))
}
func (e *Engine) OriginAllowed(site, origin string) bool {
	s, ok := e.Sites[site]
	return ok && slices.Contains(s.Origins, origin)
}
func (e *Engine) AnyOriginAllowed(origin string) bool {
	for _, s := range e.Sites {
		if slices.Contains(s.Origins, origin) {
			return true
		}
	}
	return false
}
func (e *Engine) limit(ctx context.Context, key string, count int) error {
	ok, err := e.Store.Limit(ctx, key, e.Now().UnixMilli(), 60000, count)
	if err != nil {
		return err
	}
	if !ok {
		return fail("rate_limited", 429)
	}
	return nil
}
func (e *Engine) CreateSession(ctx context.Context, r SessionRequest) (*SessionResult, error) {
	site, ok := e.Sites[r.SiteKey]
	if !ok {
		return nil, fail("invalid_site", 400)
	}
	p, ok := site.Actions[r.Action]
	if !ok || len(r.Binding) < 16 || len(r.Binding) > 256 {
		return nil, fail("invalid_context", 400)
	}
	kind := r.Type
	if kind == "" {
		i, err := randomInt(len(p.Types))
		if err != nil {
			return nil, err
		}
		kind = p.Types[i]
	}
	if !slices.Contains(p.Types, kind) {
		return nil, fail("type_not_allowed", 400)
	}
	if err := e.limit(ctx, "site:"+site.Key+":"+r.Action, p.SessionsPerMinute); err != nil {
		return nil, err
	}
	bound := binding(site.Secret, r.Binding)
	if err := e.limit(ctx, "binding:"+site.Key+":"+r.Action+":"+bound, min(12, p.SessionsPerMinute)); err != nil {
		return nil, err
	}
	token, err := randomToken()
	if err != nil {
		return nil, err
	}
	now := e.Now().UnixMilli()
	ttl := int64(p.SessionTTLSeconds) * 1000
	s := &Session{ID: digest(token), Site: site.Key, Action: r.Action, Binding: bound, Type: kind, Policy: p, CreatedMS: now, ExpiresMS: now + ttl, State: "new"}
	if err = e.Store.Create(ctx, s); err != nil {
		return nil, err
	}
	return &SessionResult{token, ttl}, nil
}
func (e *Engine) load(ctx context.Context, token, origin string) (*Session, error) {
	if !validToken(token) {
		return nil, fail("invalid_session", 400)
	}
	s, err := e.Store.Load(ctx, digest(token))
	if errors.Is(err, ErrNotFound) {
		return nil, fail("session_expired", 410)
	}
	if err != nil {
		return nil, err
	}
	if !e.OriginAllowed(s.Site, origin) {
		return nil, fail("origin_denied", 403)
	}
	now := e.Now().UnixMilli()
	if !e.activePolicy(s) {
		return nil, fail("session_expired", 410)
	}
	if now >= s.ExpiresMS || now < s.CreatedMS {
		return nil, fail("session_expired", 410)
	}
	if err = e.limit(ctx, "request:"+s.ID, 60); err != nil {
		return nil, err
	}
	return s, nil
}
func (e *Engine) save(ctx context.Context, s *Session) error {
	rev := s.Revision
	s.Revision++
	ok, err := e.Store.CAS(ctx, s, rev)
	if err != nil {
		return err
	}
	if !ok {
		return fail("state_conflict", 409)
	}
	return nil
}
func (e *Engine) Issue(ctx context.Context, token, origin string) (*Challenge, error) {
	s, err := e.load(ctx, token, origin)
	if err != nil {
		return nil, err
	}
	if s.State == "solved" || s.State == "consumed" {
		return nil, fail("session_finished", 409)
	}
	if s.Attempts >= s.Policy.MaxAttempts || s.Refreshes >= s.Policy.MaxRefreshes {
		return nil, fail("session_locked", 429)
	}
	c, a, err := generate(s.Type, s.Policy.IsAntiAI)
	if err != nil {
		return nil, err
	}
	if s.Policy.IsAntiAI || s.Type == "pow" {
		seed, er := randomToken()
		if er != nil {
			return nil, er
		}
		c.Work = &Work{Seed: seed, Bits: s.Policy.WorkBits}
	}
	now := e.Now().UnixMilli()
	if now >= s.ExpiresMS {
		return nil, fail("session_expired", 410)
	}
	s.State = "challenge"
	s.Refreshes++
	s.ChallengeCreatedMS = now
	s.ChallengeExpiresMS = min(s.ExpiresMS, now+int64(s.Policy.ChallengeTTLSeconds)*1000)
	c.ExpiresInMS = s.ChallengeExpiresMS - now
	c.MinimumDelayMS = s.Policy.MinSolveMS
	s.Challenge = c
	s.Expected = a
	if err = e.save(ctx, s); err != nil {
		return nil, err
	}
	return &c, nil
}
func WorkValid(work *Work, nonce string) bool {
	if work == nil {
		return nonce == ""
	}
	if len(nonce) < 1 || len(nonce) > 64 || work.Bits < 1 || work.Bits > 20 {
		return false
	}
	for _, r := range nonce {
		if r < '0' || r > '9' {
			return false
		}
	}
	h := sha256.Sum256([]byte(work.Seed + ":" + nonce))
	for i := 0; i < work.Bits; i++ {
		if h[i/8]&(1<<uint(7-i%8)) != 0 {
			return false
		}
	}
	return true
}
func normalize(s string) string {
	return strings.ToUpper(strings.TrimSpace(strings.Map(func(r rune) rune {
		if r >= '٠' && r <= '٩' {
			return '0' + r - '٠'
		}
		if r >= '۰' && r <= '۹' {
			return '0' + r - '۰'
		}
		return r
	}, s)))
}
func finite(n float64) bool { return !math.IsNaN(n) && !math.IsInf(n, 0) }
func checkAnswer(kind string, want, got Answer) bool {
	switch kind {
	case "pow":
		return true
	case "text", "math", "logic", "audio":
		return len(got.Text) <= 64 && equal(normalize(want.Text), normalize(got.Text))
	case "slider":
		return finite(got.X) && got.X >= 0 && got.X <= 260 && math.Abs(want.X-got.X) <= 5
	case "drag":
		return finite(got.X) && finite(got.Y) && got.X >= 0 && got.X <= 260 && got.Y >= 0 && got.Y <= 120 && math.Hypot(want.X-got.X, want.Y-got.Y) <= 6
	case "rotate":
		if !finite(got.Angle) || got.Angle < 0 || got.Angle >= 360 {
			return false
		}
		d := math.Abs(want.Angle - got.Angle)
		return math.Min(d, 360-d) <= 5
	case "click":
		if len(want.Points) != len(got.Points) {
			return false
		}
		for i, p := range got.Points {
			if !finite(p.X) || !finite(p.Y) || math.Hypot(p.X-want.Points[i].X, p.Y-want.Points[i].Y) > 19 {
				return false
			}
		}
		return true
	}
	return false
}
func (e *Engine) Solve(ctx context.Context, r SolveRequest, origin string) (*Proof, error) {
	s, err := e.load(ctx, r.SessionToken, origin)
	if err != nil {
		return nil, err
	}
	if s.State != "challenge" || !equal(s.Challenge.ID, r.ChallengeID) {
		return nil, fail("invalid_challenge", 409)
	}
	now := e.Now().UnixMilli()
	if now >= s.ChallengeExpiresMS {
		return nil, fail("challenge_expired", 410)
	}
	if s.Attempts >= s.Policy.MaxAttempts {
		return nil, fail("session_locked", 429)
	}
	if now-s.ChallengeCreatedMS < int64(s.Policy.MinSolveMS) {
		return nil, fail("too_early", 425)
	}
	s.Attempts++
	if !checkAnswer(s.Type, s.Expected, r.Answer) || !WorkValid(s.Challenge.Work, r.WorkNonce) {
		if err = e.save(ctx, s); err != nil {
			return nil, err
		}
		return nil, fail("incorrect_answer", 422)
	}
	proof, err := randomToken()
	if err != nil {
		return nil, err
	}
	s.State = "solved"
	s.ProofHash = digest(proof)
	s.ProofExpiresMS = min(s.ExpiresMS, now+int64(s.Policy.ProofTTLSeconds)*1000)
	s.Expected = Answer{}
	s.Challenge = Challenge{}
	if err = e.save(ctx, s); err != nil {
		return nil, err
	}
	// The token contains an opaque session locator, not the answer or session credential.
	return &Proof{Token: s.ID + "." + proof, ExpiresInMS: s.ProofExpiresMS - now}, nil
}
func (e *Engine) Consume(ctx context.Context, r VerifyRequest) (*Verified, error) {
	site, ok := e.Sites[r.SiteKey]
	if !ok {
		return nil, fail("invalid_proof", 403)
	}
	parts := strings.Split(r.Token, ".")
	if len(parts) != 2 || len(parts[0]) != 64 || !validToken(parts[1]) || len(r.Binding) < 16 || len(r.Binding) > 256 {
		return nil, fail("invalid_proof", 403)
	}
	if _, er := hex.DecodeString(parts[0]); er != nil {
		return nil, fail("invalid_proof", 403)
	}
	s, err := e.Store.Load(ctx, parts[0])
	if errors.Is(err, ErrNotFound) {
		return nil, fail("invalid_proof", 403)
	}
	if err != nil {
		return nil, err
	}
	now := e.Now().UnixMilli()
	if !e.activePolicy(s) || s.State != "solved" || s.Site != r.SiteKey || s.Action != r.Action || !equal(s.Binding, binding(site.Secret, r.Binding)) || !equal(s.ProofHash, digest(parts[1])) || now >= s.ProofExpiresMS || now >= s.ExpiresMS || now < s.CreatedMS {
		return nil, fail("invalid_proof", 403)
	}
	s.State = "consumed"
	s.ProofHash = ""
	if err = e.save(ctx, s); err != nil {
		return nil, err
	}
	return &Verified{Success: true, Action: s.Action}, nil
}

func (e *Engine) activePolicy(s *Session) bool {
	site, ok := e.Sites[s.Site]
	if !ok {
		return false
	}
	p, ok := site.Actions[s.Action]
	return ok && reflect.DeepEqual(p, s.Policy)
}
