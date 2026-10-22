<?php
/**
 * Legacy (non-Laravel-router) challenge endpoint.
 *
 * The login page used to call /api/challenge, which depends on the Laravel
 * route being reachable.  This plain PHP entry point works on any server
 * configuration where public/*.php is served and keeps the same response
 * format, so the login button no longer breaks when /api is not rewritten.
 */
require_once(__DIR__ . '/../include/bittorrent.php');
dbconn();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function challenge_response(int $ret, string $msg, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ret' => $ret,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Very small IP based rate limit: 30 requests per minute.
    $rateKey = 'nexus_challenge_rate:' . md5(getip());
    $rate = \Nexus\Database\NexusDB::redis()->incr($rateKey);
    if ($rate === 1) {
        \Nexus\Database\NexusDB::redis()->expire($rateKey, 60);
    }
    if ($rate > 30) {
        challenge_response(1, 'Too many requests, please try again later.', [], 429);
    }

    $raw = file_get_contents('php://input');
    $input = json_decode((string)$raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $username = isset($input['username']) && is_string($input['username']) ? trim($input['username']) : '';
    if ($username === '' || mb_strlen($username) > 64) {
        challenge_response(1, 'username is required.', [], 400);
    }

    $challenge = mksecret();
    \Nexus\Database\NexusDB::cache_put(get_challenge_key($username), $challenge, 300);

    $user = \App\Models\User::query()->where('username', $username)->first(['secret']);
    $secret = $user->secret ?? mksecret();

    challenge_response(0, 'authenticate.challenge', [
        'challenge' => $challenge,
        'secret' => $secret,
    ]);
} catch (\Throwable $throwable) {
    do_log('challenge.php error: ' . $throwable->getMessage() . "\n" . $throwable->getTraceAsString(), 'error');
    challenge_response(1, 'challenge failed, please try again.', [], 500);
}
