"""Accept a packaged executable, without Go/Node/Python in its process PATH.

This is native functional acceptance, not a packet-level egress audit.
Only this TEST DRIVER uses Python; the child product executable does not.
"""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import hashlib
import json
import os
import platform
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

binary = Path(sys.argv[1]).resolve(strict=True)
if os.name != "nt":
    binary.chmod(binary.stat().st_mode | 0o100)
checks = []
env = {**os.environ, "PATH": "", "HTTP_PROXY": "http://127.0.0.1:1", "HTTPS_PROXY": "http://127.0.0.1:1"}
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
process = None
with tempfile.TemporaryDirectory(prefix="jervis-native-") as directory:
    work = Path(directory)
    config_path = work / "jervis.json"
    subprocess.run([str(binary), "init", "--config", str(config_path)], check=True, env=env, cwd=work, capture_output=True)
    existing = config_path.read_bytes()
    second = subprocess.run([str(binary), "init", "--config", str(config_path)], env=env, cwd=work, capture_output=True)
    assert second.returncode != 0 and config_path.read_bytes() == existing, "init overwrote secrets"
    version = subprocess.check_output([str(binary), "version"], env=env, cwd=work, text=True).strip()
    checks.append("init creates configuration and refuses overwrite")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    config = json.loads(existing)
    config["listen"] = f"127.0.0.1:{port}"
    site = config["sites"][0]
    site["origins"] = [base]
    policy = site["actions"]["signup"]
    policy.update(types=["pow"], isAntiAI=True, workBits=12, minSolveMs=0)
    config_path.write_text(json.dumps(config), encoding="utf-8")
    binding = secrets.token_urlsafe(24)

    def request(path, body=None, *, private=False, public=False):
        headers = {"Content-Type": "application/json"}
        if private:
            headers["Authorization"] = "Bearer " + site["secret"]
        if public:
            headers["Origin"] = base
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(base + path, data=data, headers=headers)
        try:
            with opener.open(req, timeout=5) as response:
                raw = response.read(1000000)
                return response.status, raw
        except urllib.error.HTTPError as error:
            return error.code, error.read(8192)

    def json_request(path, body, **kwargs):
        status, raw = request(path, body, **kwargs)
        return status, json.loads(raw)

    def start():
        global process
        process = subprocess.Popen([str(binary), "serve", "--config", str(config_path)], cwd=work, env=env, stdout=log, stderr=subprocess.STDOUT)
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            if process.poll() is not None:
                raise RuntimeError("Packaged engine exited during startup")
            try:
                if request("/healthz")[0] == 200:
                    return
            except (OSError, urllib.error.URLError):
                pass
            time.sleep(0.05)
        raise RuntimeError("Packaged engine did not become healthy")

    def stop():
        global process
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        process = None

    def mint_proof():
        status, session = json_request("/v1/sessions", {"siteKey": site["key"], "action": "signup", "binding": binding}, private=True)
        assert status == 201, (status, session)
        status, challenge = json_request("/v1/challenges", {"sessionToken": session["sessionToken"]}, public=True)
        assert status == 200 and challenge["type"] == "pow", (status, challenge)
        seed, bits = challenge["work"]["seed"], challenge["work"]["bits"]
        nonce = next(str(i) for i in range(2000000) if int.from_bytes(hashlib.sha256(f"{seed}:{i}".encode()).digest()[:4], "big") >> (32 - bits) == 0)
        status, proof = json_request("/v1/answers", {"sessionToken": session["sessionToken"], "challengeId": challenge["id"], "answer": {}, "workNonce": nonce}, public=True)
        assert status == 200, (status, proof)
        return {"siteKey": site["key"], "action": "signup", "binding": binding, "token": proof["token"]}

    with (work / "engine.log").open("wb") as log:
        try:
            start()
            checks.append("production executable starts with empty PATH")
            status, script = request("/assets/jervis-captcha.js")
            assert status == 200 and len(script) > 10000, "embedded widget unavailable"
            checks.append("embedded browser assets served on first boot")
            for route in ["/demo/session", "/demo/submit", "/__test/answer", "/debug/vars"]:
                assert request(route, {})[0] == 404, f"development route exposed: {route}"
            checks.append("demo and test-oracle endpoints absent from production")
            assert request("/v1/sessions", {})[0] == 401, "private API accepted unauthenticated request"
            assert request("/v1/challenges", {})[0] == 403, "public API accepted missing origin"
            checks.append("private authentication and public origin enforced")
            proof = mint_proof()
            wrong = {**proof, "binding": secrets.token_urlsafe(24)}
            assert json_request("/v1/siteverify", wrong, private=True)[0] == 403
            checks.append("wrong session binding rejected without burning proof")
            stop()
            start()
            status, result = json_request("/v1/siteverify", proof, private=True)
            assert status == 200 and result["success"] is True
            checks.append("unconsumed proof persists across restart")
            stop()
            start()
            assert json_request("/v1/siteverify", proof, private=True)[0] == 403
            checks.append("consumed proof remains rejected after restart")
            proof = mint_proof()
            with ThreadPoolExecutor(max_workers=8) as pool:
                results = list(pool.map(lambda _: json_request("/v1/siteverify", proof, private=True)[0], range(8)))
            assert results.count(200) == 1, f"concurrent proof acceptance: {results}"
            checks.append("eight concurrent consumers have exactly one winner")
        finally:
            stop()
report = {"platform": platform.platform(), "machine": platform.machine(), "version": version, "checks": checks, "passed": True, "networkIsolationClaim": "none; separate packet-level acceptance required"}
output = Path(os.environ.get("NATIVE_REPORT", "native-validation.json"))
output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
print(json.dumps(report, indent=2))
