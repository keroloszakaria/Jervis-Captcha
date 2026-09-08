<?php
declare(strict_types=1);
require __DIR__ . '/../../sdk/php/JervisCaptcha.php';
try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $client = new \Jervis\Captcha\JervisCaptcha($input['endpoint'], $input['siteKey'], $input['secret']);
    if ($input['operation'] === 'create') {
        $result = $client->createSession($input['action'], $input['binding'], 'pow');
    } else {
        if (!$client->verify($input['token'], $input['action'], $input['binding'])) {
            throw new RuntimeException('Verification rejected');
        }
        $result = ['success' => true, 'action' => $input['action']];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Verification rejected\n");
    exit(1);
}
