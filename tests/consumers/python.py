"""Exercise the real Python SDK in a separate consumer process."""
import json
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'sdk/python'))
from jervis_captcha import JervisCaptcha

try:
    data = json.load(sys.stdin)
    client = JervisCaptcha(data['endpoint'], data['siteKey'], data['secret'])
    if data['operation'] == 'create':
        result = client.create_session(data['action'], data['binding'], 'pow')
    else:
        if not client.verify(data['token'], data['action'], data['binding']):
            raise RuntimeError('Verification rejected')
        result = {'success': True, 'action': data['action']}
    print(json.dumps(result))
except Exception:
    print('Verification rejected', file=sys.stderr)
    sys.exit(1)
