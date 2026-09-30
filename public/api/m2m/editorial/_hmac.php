<?php
declare(strict_types=1);

const NJ_EDITORIAL_M2M_DIRECTION_CONTEXT = 'nosso_jornal_editorial:core_to_provider:v1';

function nj_m2m_directional_secret(string $sharedSecret): string
{
    return hash_hmac(
        'sha256',
        NJ_EDITORIAL_M2M_DIRECTION_CONTEXT,
        $sharedSecret
    );
}

function nj_m2m_body_hash(string $rawBody): string
{
    return hash('sha256', $rawBody);
}

function nj_m2m_manifest(
    int $timestamp,
    string $requestId,
    string $method,
    string $pathQuery,
    string $bodyHash
): string {
    return implode('.', [
        (string) $timestamp,
        $requestId,
        strtoupper($method),
        $pathQuery,
        $bodyHash,
    ]);
}

function nj_m2m_expected_signature(
    string $sharedSecret,
    int $timestamp,
    string $requestId,
    string $method,
    string $pathQuery,
    string $rawBody
): string {
    $bodyHash = nj_m2m_body_hash($rawBody);
    $manifest = nj_m2m_manifest(
        $timestamp,
        $requestId,
        $method,
        $pathQuery,
        $bodyHash
    );

    return hash_hmac(
        'sha256',
        $manifest,
        nj_m2m_directional_secret($sharedSecret)
    );
}
