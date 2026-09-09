<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

$root = rtrim((string) (getenv('JERVIS_CONSUMER_ROOT') ?: ''), DIRECTORY_SEPARATOR);
$base = rtrim((string) ($argv[1] ?? ''), '/') . '/';
if (!is_file($root . '/vendor/autoload.php') || !preg_match('#^http://127\.0\.0\.1:\d+/$#', $base)) {
    fwrite(STDERR, "Usage: JERVIS_CONSUMER_ROOT=<laravel> php RealHttpAcceptance.php http://127.0.0.1:<port>\n");
    exit(64);
}
require $root . '/vendor/autoload.php';

$client = new Client([
    'base_uri' => $base,
    'cookies' => new CookieJar(),
    'http_errors' => false,
    'timeout' => 10,
    'allow_redirects' => false,
]);

$csrfResponse = $client->get('__jervis/csrf');
assertStatus($csrfResponse, 200, 'csrf bootstrap');
$csrf = decode($csrfResponse)['token'] ?? '';
if (!is_string($csrf) || strlen($csrf) < 20) fail('invalid CSRF token');
$headers = ['X-CSRF-TOKEN' => $csrf, 'Accept' => 'application/json'];

$sessionResponse = $client->post('jervis-captcha/session/signup', [
    'headers' => $headers,
    'json' => ['variant' => 'standard'],
]);
assertStatus($sessionResponse, 200, 'managed session');
$session = decode($sessionResponse);
$token = $session['sessionToken'] ?? '';
$field = $session['fieldName'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', (string) $token)) fail('invalid session token');
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', (string) $field)) fail('invalid proof field');

$challengeResponse = $client->post('jervis-captcha/v1/challenges', [
    'headers' => $headers,
    'json' => ['sessionToken' => $token],
]);
assertStatus($challengeResponse, 200, 'challenge');
$challenge = decode($challengeResponse);
if (($challenge['type'] ?? null) !== 'pow') fail('HTTP acceptance policy must issue PoW');
$seed = (string) ($challenge['work']['seed'] ?? '');
$bits = (int) ($challenge['work']['bits'] ?? 0);
$nonce = solveWork($seed, $bits);

$proofResponse = $client->post('jervis-captcha/v1/answers', [
    'headers' => $headers,
    'json' => ['sessionToken' => $token, 'challengeId' => $challenge['id'], 'answer' => [], 'workNonce' => $nonce],
]);
assertStatus($proofResponse, 200, 'answer');
$proof = (string) (decode($proofResponse)['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/D', $proof)) fail('invalid proof');

$protected = $client->post('__jervis/protected', ['headers' => $headers, 'json' => [$field => $proof]]);
assertStatus($protected, 200, 'protected action');
if ((decode($protected)['accepted'] ?? false) !== true) fail('protected action not accepted');

$replay = $client->post('__jervis/protected', ['headers' => $headers, 'json' => [$field => $proof]]);
assertStatus($replay, 422, 'proof replay');

$forced = $client->post('jervis-captcha/session/signup', [
    'headers' => $headers,
    'json' => ['variant' => 'standard', 'type' => 'audio'],
]);
assertStatus($forced, 422, 'type forcing rejection');

$accessible = $client->post('jervis-captcha/session/signup', [
    'headers' => $headers,
    'json' => ['variant' => 'accessible'],
]);
assertStatus($accessible, 200, 'accessible session');
$accessibleToken = (string) (decode($accessible)['sessionToken'] ?? '');
$audio = $client->post('jervis-captcha/v1/challenges', [
    'headers' => $headers,
    'json' => ['sessionToken' => $accessibleToken],
]);
assertStatus($audio, 200, 'accessible challenge');
$audioBody = decode($audio);
if (($audioBody['type'] ?? null) !== 'audio' || !preg_match('#^data:audio/wav;base64,#', (string) ($audioBody['audio'] ?? ''))) {
    fail('accessible challenge was not local audio');
}
if (array_key_exists('expected', $audioBody)) fail('expected answer leaked');

fwrite(STDOUT, json_encode([
    'ok' => true,
    'fieldName' => $field,
    'replayRejected' => true,
    'accessibleAudio' => true,
], JSON_UNESCAPED_SLASHES) . PHP_EOL);

function decode($response): array
{
    $data = json_decode((string) $response->getBody(), true);
    if (!is_array($data)) fail('invalid JSON response');
    return $data;
}

function assertStatus($response, int $expected, string $label): void
{
    $actual = $response->getStatusCode();
    if ($actual !== $expected) fail("{$label}: expected {$expected}, got {$actual}: " . (string) $response->getBody());
}

function solveWork(string $seed, int $bits): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $seed) || $bits < 12 || $bits > 20) fail('invalid work challenge');
    for ($nonce = 0; $nonce < 10_000_000; $nonce++) {
        $hash = hash('sha256', $seed . ':' . $nonce, true); $valid = true;
        for ($bit = 0; $bit < $bits; $bit++) {
            if ((ord($hash[intdiv($bit, 8)]) & (1 << (7 - ($bit % 8)))) !== 0) { $valid = false; break; }
        }
        if ($valid) return (string) $nonce;
    }
    fail('work not solved');
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
