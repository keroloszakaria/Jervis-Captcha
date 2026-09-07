// Package captcha implements the server-authoritative verification protocol.
package captcha

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"slices"
	"strings"
	"time"
)

const Version = "0.1.0-alpha.1"

var Kinds = []string{"text", "slider", "drag", "rotate", "click", "audio", "math", "logic", "pow"}
var ErrNotFound = errors.New("not found")

// Policy is trusted server configuration, never accepted from the widget.
type Policy struct {
	Types               []string `json:"types"`
	IsAntiAI            bool     `json:"isAntiAI"`
	WorkBits            int      `json:"workBits"`
	MinSolveMS          int      `json:"minSolveMs"`
	SessionTTLSeconds   int      `json:"sessionTtlSeconds"`
	ChallengeTTLSeconds int      `json:"challengeTtlSeconds"`
	ProofTTLSeconds     int      `json:"proofTtlSeconds"`
	MaxAttempts         int      `json:"maxAttempts"`
	MaxRefreshes        int      `json:"maxRefreshes"`
	SessionsPerMinute   int      `json:"sessionsPerMinute"`
}

func DefaultPolicy() Policy {
	return Policy{Types: []string{"text", "slider", "rotate"}, IsAntiAI: true, WorkBits: 14, MinSolveMS: 400, SessionTTLSeconds: 300, ChallengeTTLSeconds: 120, ProofTTLSeconds: 60, MaxAttempts: 3, MaxRefreshes: 4, SessionsPerMinute: 120}
}
func (p Policy) Validate() error {
	if len(p.Types) == 0 || len(p.Types) > len(Kinds) {
		return errors.New("policy requires supported types")
	}
	seen := map[string]bool{}
	for _, k := range p.Types {
		if !slices.Contains(Kinds, k) || seen[k] {
			return fmt.Errorf("unsupported or duplicate type %q", k)
		}
		seen[k] = true
	}
	if p.WorkBits < 0 || p.WorkBits > 20 || ((p.IsAntiAI || seen["pow"]) && p.WorkBits < 12) {
		return errors.New("workBits must be 0..20, and at least 12 for antiAI/pow")
	}
	if p.IsAntiAI && (seen["math"] || seen["logic"]) {
		return errors.New("math/logic cannot be enabled in an antiAI policy")
	}
	if p.SessionTTLSeconds < 30 || p.SessionTTLSeconds > 900 || p.ChallengeTTLSeconds < 10 || p.ChallengeTTLSeconds > p.SessionTTLSeconds || p.ProofTTLSeconds < 5 || p.ProofTTLSeconds > 120 || p.MinSolveMS < 0 || p.MinSolveMS > 10000 || p.MaxAttempts < 1 || p.MaxAttempts > 5 || p.MaxRefreshes < 1 || p.MaxRefreshes > 10 || p.SessionsPerMinute < 1 || p.SessionsPerMinute > 10000 {
		return errors.New("policy bounds are invalid")
	}
	return nil
}

type Site struct {
	Key     string            `json:"key"`
	Secret  string            `json:"secret"`
	Origins []string          `json:"origins"`
	Actions map[string]Policy `json:"actions"`
}

func (s Site) Validate() error {
	if len(s.Key) < 1 || len(s.Key) > 64 || strings.TrimSpace(s.Key) != s.Key || len(s.Secret) < 32 || len(s.Origins) == 0 || len(s.Actions) == 0 {
		return errors.New("site requires key, secret >=32 bytes, origins and actions")
	}
	for _, o := range s.Origins {
		u, e := url.Parse(o)
		if e != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" || u.User != nil || u.Path != "" || u.RawQuery != "" || u.Fragment != "" || strings.Contains(o, "*") {
			return fmt.Errorf("invalid exact origin %q", o)
		}
	}
	for action, p := range s.Actions {
		if len(action) < 1 || len(action) > 64 {
			return errors.New("invalid action")
		}
		if e := p.Validate(); e != nil {
			return fmt.Errorf("action %s: %w", action, e)
		}
	}
	return nil
}

// Store must implement linearizable compare-and-swap and persistent rate limits.
type Store interface {
	Create(context.Context, *Session) error
	Load(context.Context, string) (*Session, error)
	CAS(context.Context, *Session, int64) (bool, error)
	Limit(context.Context, string, int64, int64, int) (bool, error)
	Cleanup(context.Context, int64) error
	Ping(context.Context) error
}
type Point struct {
	X float64 `json:"x"`
	Y float64 `json:"y"`
}
type Answer struct {
	Text   string  `json:"text,omitempty"`
	X      float64 `json:"x,omitempty"`
	Y      float64 `json:"y,omitempty"`
	Angle  float64 `json:"angle,omitempty"`
	Points []Point `json:"points,omitempty"`
}
type Challenge struct {
	ID             string `json:"id"`
	Type           string `json:"type"`
	Prompt         string `json:"prompt"`
	Image          string `json:"image,omitempty"`
	Piece          string `json:"piece,omitempty"`
	Audio          string `json:"audio,omitempty"`
	Width          int    `json:"width,omitempty"`
	Height         int    `json:"height,omitempty"`
	PieceSize      int    `json:"pieceSize,omitempty"`
	PieceTop       int    `json:"pieceTop,omitempty"`
	ExpiresInMS    int64  `json:"expiresInMs"`
	MinimumDelayMS int    `json:"minimumDelayMs"`
	Work           *Work  `json:"work,omitempty"`
}
type Work struct {
	Seed string `json:"seed"`
	Bits int    `json:"bits"`
}

// Session is private persistence. Never serialize it into a public response.
type Session struct {
	ID                                     string
	Revision                               int64
	Site, Action, Binding, Type            string
	Policy                                 Policy
	ExpiresMS                              int64
	CreatedMS                              int64
	State                                  string
	Refreshes, Attempts                    int
	Challenge                              Challenge
	Expected                               Answer
	ChallengeCreatedMS, ChallengeExpiresMS int64
	ProofHash                              string
	ProofExpiresMS                         int64
}
type SessionRequest struct {
	SiteKey string `json:"siteKey"`
	Action  string `json:"action"`
	Binding string `json:"binding"`
	Type    string `json:"type,omitempty"`
}
type SessionResult struct {
	SessionToken string `json:"sessionToken"`
	ExpiresInMS  int64  `json:"expiresInMs"`
}
type SolveRequest struct {
	SessionToken string `json:"sessionToken"`
	ChallengeID  string `json:"challengeId"`
	Answer       Answer `json:"answer"`
	WorkNonce    string `json:"workNonce,omitempty"`
}
type Proof struct {
	Token       string `json:"token"`
	ExpiresInMS int64  `json:"expiresInMs"`
}
type VerifyRequest struct {
	SiteKey string `json:"siteKey"`
	Token   string `json:"token"`
	Action  string `json:"action"`
	Binding string `json:"binding"`
}
type Verified struct {
	Success bool   `json:"success"`
	Action  string `json:"action"`
}
type Error struct {
	Code   string `json:"code"`
	Status int    `json:"-"`
}

func (e *Error) Error() string           { return e.Code }
func fail(code string, status int) error { return &Error{Code: code, Status: status} }

type Engine struct {
	Store Store
	Sites map[string]Site
	Now   func() time.Time
}

func New(store Store, sites []Site) (*Engine, error) {
	e := &Engine{Store: store, Sites: map[string]Site{}, Now: time.Now}
	if store == nil || len(sites) == 0 {
		return nil, errors.New("store and sites are required")
	}
	secrets := map[string]bool{}
	for _, s := range sites {
		if err := s.Validate(); err != nil {
			return nil, err
		}
		if _, ok := e.Sites[s.Key]; ok || secrets[s.Secret] {
			return nil, errors.New("duplicate site key or secret")
		}
		e.Sites[s.Key] = s
		secrets[s.Secret] = true
	}
	return e, nil
}
