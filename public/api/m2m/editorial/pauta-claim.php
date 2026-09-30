<?php
declare(strict_types=1);

require __DIR__ . '/_pautas.php';

nj_m2m_run('POST', 'editorial.pauta.claim', static function (array $context): array {
    $body = nj_m2m_body($context);

    foreach (array_keys($body) as $key) {
        if (!in_array($key, ['pauta_id'], true)) {
            throw new NjApiHttpException(422, 'body_field_not_allowed');
        }
    }

    $pautaId = nj_m2m_pauta_id($body['pauta_id'] ?? '');

    return nj_m2m_claim_pauta(
        $context['pdo'],
        $pautaId,
        (string) $context['requestId']
    );
});
