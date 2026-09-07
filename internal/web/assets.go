// Package web embeds built, offline-only browser assets in the native executable.
package web

import (
	"embed"
	"io/fs"
)

//go:embed dist/*
var files embed.FS

func Assets() (fs.FS, error) { return fs.Sub(files, "dist") }
