package main

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/keroloszakaria/Jervis-Captcha/internal/captcha"
	"github.com/keroloszakaria/Jervis-Captcha/internal/httpapi"
	"github.com/keroloszakaria/Jervis-Captcha/internal/storage"
	"github.com/keroloszakaria/Jervis-Captcha/internal/web"
)

type config struct {
	Listen   string         `json:"listen"`
	Database string         `json:"database"`
	Sites    []captcha.Site `json:"sites"`
}

func run() error {
	if len(os.Args) < 2 {
		return errors.New("usage: jervis-captcha init|serve|version [--config jervis.json] [--demo]")
	}
	if os.Args[1] == "version" {
		fmt.Println(captcha.Version)
		return nil
	}
	flags := flag.NewFlagSet("jervis-captcha", flag.ContinueOnError)
	path := flags.String("config", "jervis.json", "local configuration path")
	demo := flags.Bool("demo", false, "enable loopback-only integration playground")
	if err := flags.Parse(os.Args[2:]); err != nil {
		return err
	}
	if flags.NArg() > 0 {
		return errors.New("unexpected arguments")
	}
	if os.Args[1] == "init" {
		b := make([]byte, 32)
		if _, err := rand.Read(b); err != nil {
			return err
		}
		c := config{Listen: "127.0.0.1:8090", Database: "data/jervis.db", Sites: []captcha.Site{{Key: "local", Secret: base64.RawURLEncoding.EncodeToString(b), Origins: []string{"http://127.0.0.1:8090", "http://localhost:8090"}, Actions: map[string]captcha.Policy{"signup": captcha.DefaultPolicy()}}}}
		f, err := os.OpenFile(*path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if err != nil {
			return err
		}
		defer f.Close()
		encoder := json.NewEncoder(f)
		encoder.SetIndent("", "  ")
		if err = encoder.Encode(c); err != nil {
			return err
		}
		fmt.Println("Created local configuration. Keep it private; back up configuration and database together.")
		return nil
	}
	if os.Args[1] != "serve" {
		return errors.New("unknown command")
	}
	f, err := os.Open(*path)
	if err != nil {
		return err
	}
	defer f.Close()
	d := json.NewDecoder(io.LimitReader(f, 65536))
	d.DisallowUnknownFields()
	var c config
	if err = d.Decode(&c); err != nil {
		return err
	}
	var extra any
	if d.Decode(&extra) != io.EOF {
		return errors.New("invalid trailing configuration")
	}
	host, _, err := net.SplitHostPort(c.Listen)
	if err != nil {
		return err
	}
	ip := net.ParseIP(host)
	if *demo {
		if ip == nil || !ip.IsLoopback() || len(c.Sites) != 1 {
			return errors.New("demo requires a literal loopback listener and exactly one site")
		}
		p := captcha.DefaultPolicy()
		p.IsAntiAI = false
		p.Types = append([]string{}, captcha.Kinds...)
		p.WorkBits = 12
		p.SessionsPerMinute = 300
		c.Sites[0].Actions = map[string]captcha.Policy{"demo": p}
	}
	if !filepath.IsAbs(c.Database) {
		c.Database = filepath.Join(filepath.Dir(*path), c.Database)
	}
	store, err := storage.Open(c.Database)
	if err != nil {
		return err
	}
	defer store.Close()
	engine, err := captcha.New(store, c.Sites)
	if err != nil {
		return err
	}
	assets, err := web.Assets()
	if err != nil {
		return err
	}
	if _, err = fs.ReadFile(assets, "jervis-captcha.js"); err != nil {
		return errors.New("browser assets missing: run npm run build before go build")
	}
	server := httpapi.HTTPServer(c.Listen, httpapi.New(engine, assets, *demo).Handler())
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err = store.Cleanup(ctx, time.Now().UnixMilli()); err != nil {
		return err
	}
	go func() {
		ticker := time.NewTicker(time.Minute)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if er := store.Cleanup(ctx, time.Now().UnixMilli()); er != nil {
					slog.Error("cleanup failed")
				}
			}
		}
	}()
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	slog.Info("Jervis Captcha listening", "address", c.Listen, "demo", *demo, "version", captcha.Version)
	if err = server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}
func main() {
	if err := run(); err != nil {
		slog.Error("startup failed", "error", err)
		os.Exit(1)
	}
}
