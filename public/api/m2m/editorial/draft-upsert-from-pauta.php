<?php
declare(strict_types=1);

require __DIR__ . '/_draft.php';

nj_m2m_run('POST', 'editorial.draft.upsert_from_pauta', static function (array $context): array {
    $body = nj_m2m_body($context);
    $allowed = [
        'pauta_id',
        'claim',
        'source_hash',
        'title',
        'summary',
        'content',
        'seo',
        'category_slugs',
        'tags',
    ];

    foreach (array_keys($body) as $key) {
        if (!in_array($key, $allowed, true)) {
            throw new NjApiHttpException(422, 'body_field_not_allowed');
        }
    }

    $pautaId = nj_m2m_pauta_id($body['pauta_id'] ?? '');
    $claim = trim((string) ($body['claim'] ?? ''));
    $sourceHash = strtolower(trim((string) ($body['source_hash'] ?? '')));

    if (!preg_match('/^[0-9a-f]{64}$/', $sourceHash)) {
        throw new NjApiHttpException(422, 'source_hash_invalid');
    }

    return nj_m2m_upsert_draft(
        $context['pdo'],
        $pautaId,
        $claim,
        $sourceHash,
        $body,
        (string) $context['requestId']
    );
});
