<?php
declare(strict_types=1);

require __DIR__ . '/../public/api/m2m/editorial/_hmac.php';

$secret = 'mobi-test-shared-secret-2026';
$timestamp = 1790745600;
$requestId = 'test-request-0001';
$method = 'GET';
$pathQuery = '/api/m2m/editorial/status.php';
$rawBody = '';

$expectedDirectional = 'b4a11f74ff6f7631807913ebdbb59274ea0cf71ecba6d0084b9ff7091b5a8ff4';
$expectedBodyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$expectedManifest = '1790745600.test-request-0001.GET./api/m2m/editorial/status.php.e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$expectedSignature = 'a27775179d495f19656c7819e3ac1bbf3946541ceabf9aae38408abf8c7a7f9b';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$directional = nj_m2m_directional_secret($secret);
$bodyHash = nj_m2m_body_hash($rawBody);
$manifest = nj_m2m_manifest($timestamp, $requestId, $method, $pathQuery, $bodyHash);
$signature = nj_m2m_expected_signature(
    $secret,
    $timestamp,
    $requestId,
    $method,
    $pathQuery,
    $rawBody
);

$assert(hash_equals($expectedDirectional, $directional), 'Directional HMAC vector mismatch');
$assert(hash_equals($expectedBodyHash, $bodyHash), 'Body SHA-256 vector mismatch');
$assert(hash_equals($expectedManifest, $manifest), 'Manifest vector mismatch');
$assert(hash_equals($expectedSignature, $signature), 'Signature vector mismatch');

fwrite(STDOUT, "Editorial HMAC contract OK" . PHP_EOL);
