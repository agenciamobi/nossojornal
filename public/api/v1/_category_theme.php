<?php
declare(strict_types=1);

const NJ_CATEGORY_COLOR_META_KEY = 'nj_editorial_color';
const NJ_CATEGORY_DEFAULT_COLOR = '#0B57D0';

function nj_category_palette(): array
{
    return [
        'capa' => '#123B8C',
        'geral' => '#475569',
        'outros' => '#64748B',
        'eleicoes-2024' => '#9333EA',
        'cobertura-regional' => '#1D4ED8',

        'hulha-negra' => '#0B57D0',
        'politica' => '#6D28D9',
        'seguranca' => '#C2410C',
        'economia' => '#0F766E',
        'esportes' => '#15803D',
        'cultura' => '#BE185D',
        'educacao' => '#0369A1',
        'rural' => '#4D7C0F',
        'saude' => '#0891B2',
        'rio-grande-do-sul' => '#B45309',
        'brasil' => '#166534',
        'internacional' => '#334155',

        'acegua' => '#4338CA',
        'bage' => '#2563EB',
        'candiota' => '#0284C7',
        'dom-pedrito' => '#0E7490',
        'herval' => '#0369A1',
        'pedras-altas' => '#4F46E5',
        'pinheiro-machado' => '#1E40AF',
        'piratini' => '#075985',
    ];
}

function nj_category_validate_color(string $value): ?string
{
    $value = strtoupper(trim($value));

    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : null;
}

function nj_category_default_color(string $slug): string
{
    $palette = nj_category_palette();

    return $palette[$slug] ?? NJ_CATEGORY_DEFAULT_COLOR;
}

function nj_category_color_overrides(PDO $pdo, array $termIds): array
{
    $termIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $id): int => (int) $id,
        $termIds
    ))));

    if ($termIds === []) {
        return [];
    }

    $termmeta = nj_table('termmeta');
    $placeholders = implode(',', array_fill(0, count($termIds), '?'));

    try {
        $statement = $pdo->prepare(<<<SQL
SELECT term_id, meta_value
FROM {$termmeta}
WHERE
    meta_key = ?
    AND term_id IN ({$placeholders})
ORDER BY meta_id DESC
SQL);
        $statement->execute(array_merge([NJ_CATEGORY_COLOR_META_KEY], $termIds));
        $rows = $statement->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $colors = [];
    foreach ($rows as $row) {
        $termId = (int) $row['term_id'];

        if (isset($colors[$termId])) {
            continue;
        }

        $color = nj_category_validate_color((string) $row['meta_value']);
        if ($color !== null) {
            $colors[$termId] = $color;
        }
    }

    return $colors;
}

function nj_category_color_for(
    int $termId,
    string $slug,
    array $overrides
): string {
    return $overrides[$termId] ?? nj_category_default_color($slug);
}


function nj_category_color_source_for(int $termId, array $overrides): string
{
    return isset($overrides[$termId]) ? 'termmeta' : 'palette';
}
