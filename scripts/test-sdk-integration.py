"""Run real, isolated SDK consumer processes against the production SQLite engine.

Credentials are generated for this test, passed on stdin, and never printed.
No fixture answer oracle is used: this driver solves the public bounded PoW.
"""
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import hashlib
import json
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from urllib import request, error

ROOT = Path(__file__).resolve().parents[1]
OPENER = request.build_opener(request.ProxyHandler({}))


def post(base, path, body):
    req = request.Request(base + path, data=json.dumps(body).encode(), headers={
        'Content-Type': 'application/json', 'Origin': base,
    })
    with OPENER.open(req, timeout=10) as response:
        return json.load(response)


def consumer(command, config, success=True):
    result = subprocess.run(command, input=json.dumps(config), cwd=ROOT,
                            text=True, capture_output=True, timeout=30)
    if success:
        if result.returncode != 0:
            raise AssertionError('SDK consumer unexpectedly rejected the operation')
        return json.loads(result.stdout)
    if result.returncode == 0:
        raise AssertionError('SDK consumer accepted an invalid response or proof')
    return None


@contextmanager
def fake_service(payload=None, redirect=None):
    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):
            self.rfile.read(int(self.headers.get('Content-Length', 0)))
            self.server.requests += 1
            if redirect:
                self.send_response(307)
                self.send_header('Location', redirect)
                self.end_headers()
            else:
                data = json.dumps(payload).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)
        def log_message(self, *_args):
            pass
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    server.requests = 0
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield 'http://127.0.0.1:' + str(server.server_port), server
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


def main():
    binary = Path(sys.argv[1]).resolve(strict=True)
    for executable in ['node', 'php', 'dotnet']:
        if not shutil.which(executable):
            raise RuntimeError('Missing required consumer runtime: ' + executable)
    commands = {
        'node': ['node', 'tests/consumers/node.mjs'],
        'php': ['php', 'tests/consumers/php.php'],
        'python': [sys.executable, 'tests/consumers/python.py'],
        'dotnet': ['dotnet', 'tests/consumers/dotnet/bin/Release/net8.0/Consumer.dll'],
    }
    evidence = {}
    with tempfile.TemporaryDirectory(prefix='jervis sdk ') as directory:
        work = Path(directory)
        config_path = work / 'jervis.json'
        subprocess.run([str(binary), 'init', '--config', str(config_path)],
                       check=True, capture_output=True, timeout=10)
        config = json.loads(config_path.read_text())
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        config['listen'] = f'127.0.0.1:{port}'
        site = config['sites'][0]
        site['origins'] = [base]
        site['actions']['signup'].update(types=['pow'], workBits=12, minSolveMs=0)
        config_path.write_text(json.dumps(config))
        with (work / 'engine.log').open('wb') as log:
            process = subprocess.Popen([str(binary), 'serve', '--config', str(config_path)],
                                       stdout=log, stderr=subprocess.STDOUT, cwd=work)
            try:
                deadline = time.monotonic() + 15
                while True:
                    if process.poll() is not None:
                        raise RuntimeError('Production engine exited before acceptance')
                    try:
                        with OPENER.open(base + '/healthz', timeout=1) as response:
                            if response.status == 200:
                                break
                    except (OSError, error.URLError):
                        pass
                    if time.monotonic() > deadline:
                        raise RuntimeError('Production engine readiness timed out')
                    time.sleep(0.05)
                for language, command in commands.items():
                    args = {'endpoint': base, 'siteKey': site['key'], 'secret': site['secret'],
                            'action': 'signup', 'binding': secrets.token_urlsafe(32), 'operation': 'create'}
                    session = consumer(command, args)
                    challenge = post(base, '/v1/challenges', {'sessionToken': session['sessionToken']})
                    seed, bits = challenge['work']['seed'], challenge['work']['bits']
                    nonce = next(str(i) for i in range(2_000_000) if
                                 int.from_bytes(hashlib.sha256(f'{seed}:{i}'.encode()).digest(), 'big') >> (256 - bits) == 0)
                    proof = post(base, '/v1/answers', {'sessionToken': session['sessionToken'],
                                 'challengeId': challenge['id'], 'answer': {}, 'workNonce': nonce})
                    args.update(operation='verify', token=proof['token'])
                    consumer(command, {**args, 'binding': secrets.token_urlsafe(32)}, False)
                    verified = consumer(command, args)
                    assert verified['success'] is True and verified['action'] == 'signup'
                    consumer(command, args, False)
                    checks = ['create session', 'reject wrong binding', 'consume proof', 'reject replay']
                    for malformed in [{'success': 'true', 'action': 'signup'},
                                      {'success': True, 'action': 'different-action'}, None]:
                        with fake_service(malformed) as (endpoint, _server):
                            consumer(command, {**args, 'endpoint': endpoint}, False)
                    checks.append('reject malformed or wrong-action responses')
                    with fake_service({'success': True, 'action': 'signup'}) as (target, target_server):
                        with fake_service(redirect=target + '/leak') as (endpoint, _server):
                            consumer(command, {**args, 'endpoint': endpoint}, False)
                        assert target_server.requests == 0, 'Credentials followed an HTTP redirect'
                    checks.append('reject redirects without forwarding credentials')
                    evidence[language] = {'passed': True, 'checks': checks}
            finally:
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
    report = {'passed': True, 'engine': 'production SQLite executable', 'consumers': evidence}
    (ROOT / 'sdk-validation.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
