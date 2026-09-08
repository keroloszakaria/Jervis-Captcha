package httpapi_test

import (
	"io"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestEarlyRejectionsConsumeBoundedBodies(t *testing.T) {
	h := handler(t, nil)
	for _, tc := range []struct {
		path, media string
		status      int
	}{
		{"/demo/session", "application/json", 404},
		{"/v1/challenges", "application/json", 403},
		{"/v1/sessions?secret=blocked", "application/json", 400},
		{"/v1/sessions", "text/plain", 415},
	} {
		t.Run(tc.path, func(t *testing.T) {
			body := strings.NewReader(`{}`)
			req := httptest.NewRequest("POST", tc.path, body)
			req.Close = true
			req.Header.Set("Content-Type", tc.media)
			w := httptest.NewRecorder()
			h.ServeHTTP(w, req)
			if w.Code != tc.status || body.Len() != 0 {
				t.Fatalf("status=%d, unread body=%d", w.Code, body.Len())
			}
		})
	}
}

type countedBody struct {
	io.Reader
	bytes int
}

func (r *countedBody) Read(p []byte) (int, error) {
	n, err := r.Reader.Read(p)
	r.bytes += n
	return n, err
}
func TestEarlyBodyReadRemainsBounded(t *testing.T) {
	body := &countedBody{Reader: strings.NewReader(strings.Repeat("x", 100_000))}
	req := httptest.NewRequest("POST", "/v1/sessions", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	handler(t, nil).ServeHTTP(w, req)
	if w.Code != 413 || body.bytes > 8193 {
		t.Fatalf("status=%d, bytes read=%d", w.Code, body.bytes)
	}
}
