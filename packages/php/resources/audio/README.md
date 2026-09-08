# Embedded digit audio asset

`digits.asset` is a build-time copy of `internal/captcha/audio_samples.go`. It contains the same generated English digit PCM samples used by the native engine, encoded as a base64+gzip JSON payload. The PHP runtime parses only the generated payload and never invokes a text-to-speech service.

`scripts/copy-assets.mjs` refreshes this resource during the canonical package build so the native and embedded engines cannot silently drift.
