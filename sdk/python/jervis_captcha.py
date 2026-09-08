"""Synchronous server-only client. Python standard library; no cloud SDK required."""
import json
import re
from urllib import request, error, parse


class VerificationError(RuntimeError):
    pass


class _NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class JervisCaptcha:
    def __init__(self, endpoint: str, site_key: str, secret: str, timeout: float = 5.0):
        url = parse.urlsplit(endpoint)
        if url.scheme not in ("http", "https") or not url.hostname or url.username or url.password or url.query or url.fragment or len(secret) < 32 or not site_key:
            raise ValueError("Invalid Jervis Captcha configuration")
        self._endpoint = endpoint.rstrip("/") + "/"
        self._site_key, self._secret, self._timeout = site_key, secret, timeout
        # Do not inherit a workstation's outbound proxy for internal credentials.
        self._opener = request.build_opener(request.ProxyHandler({}), _NoRedirect())

    def _post(self, path: str, body: dict) -> dict:
        payload = json.dumps({**body, "siteKey": self._site_key}).encode()
        req = request.Request(self._endpoint + path, data=payload, method="POST", headers={"Content-Type": "application/json", "Authorization": "Bearer " + self._secret})
        try:
            with self._opener.open(req, timeout=self._timeout) as response:
                raw = response.read(16385)
                if len(raw) > 16384:
                    raise VerificationError("Invalid verification response")
                value = json.loads(raw)
                if not isinstance(value, dict):
                    raise VerificationError("Invalid verification response")
                return value
        except (error.URLError, error.HTTPError, TimeoutError, ValueError, OSError) as exc:
            raise VerificationError("Verification rejected or service unavailable") from None

    def create_session(self, action: str, binding: str, kind: str | None = None) -> dict:
        body = {"action": action, "binding": binding}
        if kind is not None:
            body["type"] = kind
        value = self._post("v1/sessions", body)
        if not isinstance(value.get("sessionToken"), str) or not re.fullmatch(r"[A-Za-z0-9_-]{43}", value["sessionToken"]) or not isinstance(value.get("expiresInMs"), (int, float)) or value["expiresInMs"] <= 0:
            raise VerificationError("Invalid verification response")
        return value

    def verify(self, token: str, action: str, binding: str) -> bool:
        value = self._post("v1/siteverify", {"token": token, "action": action, "binding": binding})
        return value.get("success") is True and value.get("action") == action
