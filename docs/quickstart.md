# Quick start

## Local trial

Extract the native CI distribution for your platform. Run `jervis-captcha init --config jervis.json`, then `jervis-captcha serve --config jervis.json --demo`. On Windows, use `.\jervis-captcha.exe`.

Open `http://127.0.0.1:8090`. The demo hosts both the widget and a sample backend flow. Complete a challenge and submit the protected action. A second submission with the same proof is rejected.

The generated configuration contains a unique secret. It is not a browser site key. Never commit it, include it in a frontend bundle or share it with end users. `init` will not overwrite an existing file.

## Production configuration

Run `serve` **without** `--demo`. The default action is `signup` with an antiAI policy, 300-second sessions, 120-second challenges, 60-second proofs, three total answer attempts and four challenge generations per session. Defaults are a starting policy, not universal security recommendations.

Edit the generated JSON to specify exact application origins and per-action policies. Origin strings have no path, wildcard or trailing slash. `http://localhost:8090` and `http://127.0.0.1:8090` are different origins. Cross-origin browser use requires the actual application origin in the selected site's allowlist.

The engine reads relative database paths relative to the configuration file. Use an absolute data path for a system service. SQLite is appropriate for a single host; do not share the file over a network filesystem or run a load-balanced deployment without a tested shared-state adapter.

## Host backend

Implement a CSRF-protected session endpoint and the protected business endpoint. Both use the same trusted application session binding and expected action. The session endpoint calls `/v1/sessions` privately; the business endpoint calls `/v1/siteverify` privately and proceeds only after success.

Example private session request:

```sh
curl -X POST http://127.0.0.1:8090/v1/sessions \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $JERVIS_SITE_SECRET" \
  -d '{"siteKey":"local","action":"signup","binding":"server-owned-session-binding-at-least-16-bytes"}'
```

The example binding is illustrative; derive the real value from the host session, not from a form field. Do not log it. Do not send an Origin header on private backend calls.

## Reverse proxy

Publish the widget assets and the two public challenge endpoints only. Example location mappings inside your existing TLS-enabled Nginx virtual host:

```nginx
location /captcha/assets/ {
    proxy_pass http://127.0.0.1:8090/assets/;
}
location = /captcha/v1/challenges {
    client_max_body_size 8k;
    proxy_pass http://127.0.0.1:8090/v1/challenges;
}
location = /captcha/v1/answers {
    client_max_body_size 8k;
    proxy_pass http://127.0.0.1:8090/v1/answers;
}
location /captcha/ { return 404; }
```

Use the configured widget endpoint `/captcha/`. Keep private routes on loopback/internal ACLs. Configure trusted internal TLS certificates on client devices; never disable TLS certificate verification in the SDKs.

## Shutdown and recovery

The process handles termination with a graceful HTTP shutdown. Persist the database and configuration outside the executable directory when replacing a binary. Stop/quiesce the engine for a consistent backup or use SQLite's backup API; copying only the main database while WAL writes are active is not a safe backup procedure.

After restoring an older backup, rotate site secrets to invalidate outstanding proof bindings. Treat the engine as a security-sensitive local service and monitor disk space, errors, request limits and clock health.
