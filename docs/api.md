# HTTP API v1

The machine-readable contract is `openapi.json` in this directory. All POST bodies are JSON, limited to 8 KiB, and reject unknown fields or trailing JSON. Tokens must be in the body, never query strings. Responses are `Cache-Control: no-store`.

| Endpoint | Caller | Authentication / result |
|---|---|---|
| `POST /v1/sessions` | Host backend | Per-site Bearer secret; 201 + sessionToken |
| `POST /v1/challenges` | Widget | Opaque sessionToken + allowed Origin; public raster/audio challenge |
| `POST /v1/answers` | Widget | sessionToken, challengeId, typed answer, workNonce if required; proof |
| `POST /v1/siteverify` | Host backend | Per-site Bearer secret + expected action/binding; consume once |
| `GET /healthz` | Operator | 200 if persistent store responds, 503 otherwise |

Private routes reject an Origin header. Public routes require an exact configured Origin (including scheme/port). An Origin header is not a substitute for authentication. CORS does not protect against a caller that can forge HTTP headers.

## Session creation

Body: `{ "siteKey": "local", "action": "signup", "binding": "trusted-host-session-identifier", "type": "text" }`. Type is optional and must be in the trusted action allowlist. A binding is 16–256 bytes, backend-owned, stable between creation and consumption and not forwarded to the browser.

Response: `{ "sessionToken": "<43-character-opaque-token>", "expiresInMs": 300000 }`.

## Issue and refresh

Body: `{ "sessionToken": "..." }`.

Response contains `id`, `type`, `prompt`, `expiresInMs`, `minimumDelayMs`, and type-specific raster `image`, `piece`, `audio`, dimensions, or public PoW `{seed,bits}`. Images are PNG data URLs; audio is WAV. Answers are never included. The public PoW seed cannot reconstruct a human challenge answer.

Calling again refreshes within the same attempt/refresh budget and invalidates the old challenge. Solved sessions cannot mint another proof; ask the host backend for a new session.

## Solve

```json
{
  "sessionToken": "...",
  "challengeId": "...",
  "answer": { "text": "ABCDE" },
  "workNonce": "12345"
}
```

Omit `workNonce` when there is no work object. Use `text` for text/math/logic/audio, `x` for slider, `x` and `y` for free drag, `angle` for rotation, or an ordered `points` array for click. Coordinates are in the original raster coordinate space, not CSS screen pixels. Rotation is a clockwise correction in [0,360). PoW uses SHA-256 of UTF-8 `seed + ':' + nonce` with the required number of zero leading bits. Nonce is decimal ASCII, at most 64 characters; the browser worker uses a stricter device budget.

The engine owns expiry/minimum delay. Do not send elapsed time, event-trust flags, policies or expected answers. Arabic-Indic and Persian digits are normalized to ASCII in text answers. Answers are otherwise case-insensitive and trim outer whitespace.

Success: `{ "token": "<opaque-proof>", "expiresInMs": 60000 }`. Store it only transiently and submit it with the protected form. A `jervis-success` event is not the business authorization decision.

## Consume

Body: `{ "siteKey": "local", "token": "...", "action": "signup", "binding": "same-trusted-host-session-identifier" }` with the site's Bearer secret.

Success: `{ "success": true, "action": "signup" }`. Any other status/shape is a rejection or indeterminate failure. A wrong action or binding does not consume a legitimate proof. Concurrent valid attempts have at most one winner. Never automatically retry after a timeout; the proof may already have been consumed.

## Error handling

Errors use `{ "code": "..." }`. Important codes: `invalid_request`/`invalid_context` (400), `unauthorized` (401), `origin_denied`/`invalid_proof` (403), `state_conflict`/`invalid_challenge`/`session_finished` (409), `session_expired`/`challenge_expired` (410), `json_required` (415), `incorrect_answer` (422), `too_early` (425), `session_locked`/`rate_limited` (429), and `service_unavailable`/`busy` (503).

Do not implement a generic retry interceptor for verification APIs. A network error must not be converted into success or a weaker challenge without a separately approved host policy.
