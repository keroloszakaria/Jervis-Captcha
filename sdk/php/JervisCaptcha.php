<?php
declare(strict_types=1);
namespace Jervis\Captcha;

use RuntimeException;

/** Server-only integration. Transport failures reject verification. No retries. */
final class JervisCaptcha
{
    private string $endpoint;
    public function __construct(string $endpoint, private string $siteKey, private string $secret)
    {
        $url = parse_url($endpoint);
        if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || strlen($secret) < 32 || $siteKey === '') {
            throw new RuntimeException('Invalid Jervis Captcha configuration');
        }
        $this->endpoint = rtrim($endpoint, '/') . '/';
    }
    public function createSession(string $action, string $binding, ?string $type = null): array
    {
        $body = ['action' => $action, 'binding' => $binding];
        if ($type !== null) { $body['type'] = $type; }
        $result = $this->request('v1/sessions', $body);
        if (!is_string($result['sessionToken'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $result['sessionToken']) || !is_numeric($result['expiresInMs'] ?? null) || $result['expiresInMs'] <= 0) {
            throw new RuntimeException('Invalid verification service response');
        }
        return $result;
    }
    public function verify(string $token, string $action, string $binding): bool
    {
        $result = $this->request('v1/siteverify', compact('token', 'action', 'binding'));
        return ($result['success'] ?? false) === true && ($result['action'] ?? null) === $action;
    }
    private function request(string $path, array $body): array
    {
        $handle = curl_init($this->endpoint . $path);
        if ($handle === false) { throw new RuntimeException('Verification transport unavailable'); }
        $body['siteKey'] = $this->siteKey;
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 5000, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->secret],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        try {
            $raw = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($raw === false || $status < 200 || $status >= 300 || strlen($raw) > 16384) {
                throw new RuntimeException('Verification rejected or service unavailable');
            }
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new RuntimeException('Invalid verification response'); }
            return $value;
        } finally { curl_close($handle); }
    }
}
