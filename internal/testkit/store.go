// Package testkit is test-only infrastructure. Production never imports it.
package testkit

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
)

type Memory struct {
	mu     sync.Mutex
	rows   map[string][]byte
	limits map[string]int
}

func New() *Memory { return &Memory{rows: map[string][]byte{}, limits: map[string]int{}} }
func (m *Memory) Create(_ context.Context, s *captcha.Session) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, ok := m.rows[s.ID]; ok {
		return fmt.Errorf("duplicate")
	}
	b, e := json.Marshal(s)
	m.rows[s.ID] = b
	return e
}
func (m *Memory) Load(_ context.Context, id string) (*captcha.Session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	b, ok := m.rows[id]
	if !ok {
		return nil, captcha.ErrNotFound
	}
	var s captcha.Session
	e := json.Unmarshal(b, &s)
	return &s, e
}
func (m *Memory) CAS(_ context.Context, s *captcha.Session, revision int64) (bool, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	b, ok := m.rows[s.ID]
	if !ok {
		return false, nil
	}
	var old captcha.Session
	if e := json.Unmarshal(b, &old); e != nil {
		return false, e
	}
	if old.Revision != revision {
		return false, nil
	}
	b, e := json.Marshal(s)
	m.rows[s.ID] = b
	return e == nil, e
}
func (m *Memory) Limit(_ context.Context, key string, now, window int64, limit int) (bool, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	k := fmt.Sprintf("%s:%d", key, now/window)
	if m.limits[k] >= limit {
		return false, nil
	}
	m.limits[k]++
	return true, nil
}
func (m *Memory) Cleanup(_ context.Context, now int64) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	for k, b := range m.rows {
		var s captcha.Session
		json.Unmarshal(b, &s)
		if s.ExpiresMS <= now {
			delete(m.rows, k)
		}
	}
	return nil
}
func (m *Memory) Ping(context.Context) error { return nil }
