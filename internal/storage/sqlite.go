// Package storage provides the production, restart-safe SQLite implementation.
package storage

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	_ "modernc.org/sqlite"
)

type SQLite struct{ db *sql.DB }

func Open(path string) (*SQLite, error) {
	if path == "" || path == ":memory:" {
		return nil, errors.New("a persistent database path is required")
	}
	abs, err := filepath.Abs(path)
	if err != nil {
		return nil, err
	}
	if err = os.MkdirAll(filepath.Dir(abs), 0700); err != nil {
		return nil, err
	}
	if st, er := os.Lstat(abs); er == nil && (!st.Mode().IsRegular() || st.Mode()&os.ModeSymlink != 0) {
		return nil, errors.New("database must be a regular file")
	}
	f, err := os.OpenFile(abs, os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	f.Close()
	u := url.URL{Scheme: "file", Path: "/" + strings.TrimPrefix(filepath.ToSlash(abs), "/")}
	q := url.Values{}
	for _, p := range []string{"busy_timeout(5000)", "journal_mode(WAL)", "synchronous(FULL)", "foreign_keys(ON)"} {
		q.Add("_pragma", p)
	}
	u.RawQuery = q.Encode()
	db, err := sql.Open("sqlite", u.String())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	s := &SQLite{db: db}
	if err = s.migrate(); err != nil {
		db.Close()
		return nil, err
	}
	return s, nil
}
func (s *SQLite) migrate() error {
	var version int
	if err := s.db.QueryRow("PRAGMA user_version").Scan(&version); err != nil {
		return err
	}
	if version > 1 {
		return errors.New("database schema is newer than this engine")
	}
	if version == 1 {
		return nil
	}
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	for _, stmt := range []string{
		`CREATE TABLE IF NOT EXISTS jervis_sessions(id TEXT PRIMARY KEY,revision INTEGER NOT NULL,expires INTEGER NOT NULL,payload BLOB NOT NULL)`,
		`CREATE INDEX IF NOT EXISTS jervis_expiry ON jervis_sessions(expires)`,
		`CREATE TABLE IF NOT EXISTS jervis_limits(id TEXT PRIMARY KEY,count INTEGER NOT NULL,expires INTEGER NOT NULL)`,
		`CREATE INDEX IF NOT EXISTS jervis_limit_expiry ON jervis_limits(expires)`,
		`PRAGMA user_version=1`,
	} {
		if _, err = tx.Exec(stmt); err != nil {
			return err
		}
	}
	return tx.Commit()
}
func (s *SQLite) Create(ctx context.Context, r *captcha.Session) error {
	b, err := json.Marshal(r)
	if err != nil {
		return err
	}
	_, err = s.db.ExecContext(ctx, `INSERT INTO jervis_sessions(id,revision,expires,payload) VALUES(?,?,?,?)`, r.ID, r.Revision, r.ExpiresMS, b)
	return err
}
func (s *SQLite) Load(ctx context.Context, id string) (*captcha.Session, error) {
	var b []byte
	err := s.db.QueryRowContext(ctx, `SELECT payload FROM jervis_sessions WHERE id=?`, id).Scan(&b)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, captcha.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	var r captcha.Session
	if err = json.Unmarshal(b, &r); err != nil {
		return nil, err
	}
	return &r, nil
}
func (s *SQLite) CAS(ctx context.Context, r *captcha.Session, revision int64) (bool, error) {
	b, err := json.Marshal(r)
	if err != nil {
		return false, err
	}
	result, err := s.db.ExecContext(ctx, `UPDATE jervis_sessions SET revision=?,expires=?,payload=? WHERE id=? AND revision=?`, r.Revision, r.ExpiresMS, b, r.ID, revision)
	if err != nil {
		return false, err
	}
	n, err := result.RowsAffected()
	return n == 1, err
}
func (s *SQLite) Limit(ctx context.Context, key string, now, window int64, limit int) (bool, error) {
	if window <= 0 || limit < 1 {
		return false, errors.New("invalid rate limit")
	}
	bucket := fmt.Sprintf("%s:%d", key, now/window)
	var count int
	err := s.db.QueryRowContext(ctx, `INSERT INTO jervis_limits(id,count,expires) VALUES(?,1,?) ON CONFLICT(id) DO UPDATE SET count=count+1 WHERE count<? RETURNING count`, bucket, (now/window+1)*window, limit).Scan(&count)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	return err == nil, err
}
func (s *SQLite) Cleanup(ctx context.Context, now int64) error {
	if _, err := s.db.ExecContext(ctx, `DELETE FROM jervis_sessions WHERE expires<=?`, now); err != nil {
		return err
	}
	_, err := s.db.ExecContext(ctx, `DELETE FROM jervis_limits WHERE expires<=?`, now)
	return err
}
func (s *SQLite) Ping(ctx context.Context) error { return s.db.PingContext(ctx) }
func (s *SQLite) Close() error                   { return s.db.Close() }
