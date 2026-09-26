<?php
declare(strict_types=1);

require __DIR__ . '/api/v1/_bootstrap.php';
require __DIR__ . '/api/v1/_content.php';

const NJ_SITE_URL = 'https://nossojornal.com.br';

function nj_meta_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function nj_meta_absolute_url(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return NJ_SITE_URL . '/' . ltrim($value, '/');
}

function nj_meta_branded_title(string $title): string
{
    $title = trim($title);

    if ($title === '') {
        return 'Nosso Jornal | Hulha Negra e região';
    }

    return stripos($title, 'Nosso Jornal') !== false
        ? $title
        : $title . ' | Nosso Jornal';
}

function nj_meta_article_by_slug(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return null;
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $select = nj_content_article_select($posts, $postmeta, $users);

    $statement = $pdo->prepare($select . <<<SQL

WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.post_name = :slug
LIMIT 1
SQL);
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch();

    if (!$row) {
        return null;
    }

    $articles = nj_content_hydrate_articles($pdo, [$row], false);
    $article = $articles[0] ?? null;

    if ($article === null) {
        return null;
    }

    $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_key, meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc')
SQL);
    $metaStatement->execute(['post_id' => $article['id']]);

    $yoast = [
        'title' => '',
        'description' => '',
    ];

    foreach ($metaStatement->fetchAll() as $meta) {
        if ($meta['meta_key'] === '_yoast_wpseo_title') {
            $yoast['title'] = nj_content_clean_text_source((string) $meta['meta_value']);
        }

        if ($meta['meta_key'] === '_yoast_wpseo_metadesc') {
            $yoast['description'] = nj_content_excerpt((string) $meta['meta_value'], '', 240);
        }
    }

    $article['seoTitle'] = $yoast['title'] !== '' ? $yoast['title'] : $article['title'];
    $article['seoDescription'] = $yoast['description'] !== ''
        ? $yoast['description']
        : $article['excerpt'];

    return $article;
}

function nj_meta_category_by_slug(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return null;
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->prepare(<<<SQL
SELECT t.name, t.slug
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.slug = :slug
LIMIT 1
SQL);
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch();

    return $row ?: null;
}

function nj_meta_block(array $meta): string
{
    $title = nj_meta_branded_title((string) ($meta['title'] ?? ''));
    $description = trim((string) ($meta['description'] ?? ''));
    $canonical = nj_meta_absolute_url((string) ($meta['canonical'] ?? '/'));
    $robots = (string) ($meta['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1');
    $type = (string) ($meta['type'] ?? 'website');
    $image = nj_meta_absolute_url((string) ($meta['image'] ?? ''));
    $imageAlt = trim((string) ($meta['imageAlt'] ?? ''));
    $publishedAt = trim((string) ($meta['publishedAt'] ?? ''));
    $modifiedAt = trim((string) ($meta['modifiedAt'] ?? ''));
    $section = trim((string) ($meta['section'] ?? ''));

    $tags = [];
    $tags[] = '<meta name="description" content="' . nj_meta_escape($description) . '" />';
    $tags[] = '<meta name="robots" content="' . nj_meta_escape($robots) . '" />';
    $tags[] = '<link rel="canonical" href="' . nj_meta_escape($canonical) . '" />';
    $tags[] = '<title>' . nj_meta_escape($title) . '</title>';
    $tags[] = '<meta property="og:title" content="' . nj_meta_escape($title) . '" />';
    $tags[] = '<meta property="og:description" content="' . nj_meta_escape($description) . '" />';
    $tags[] = '<meta property="og:url" content="' . nj_meta_escape($canonical) . '" />';
    $tags[] = '<meta property="og:type" content="' . nj_meta_escape($type) . '" />';
    $tags[] = '<meta property="og:site_name" content="Nosso Jornal" />';
    $tags[] = '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '" />';
    $tags[] = '<meta name="twitter:title" content="' . nj_meta_escape($title) . '" />';
    $tags[] = '<meta name="twitter:description" content="' . nj_meta_escape($description) . '" />';

    if ($image !== '') {
        $tags[] = '<meta property="og:image" content="' . nj_meta_escape($image) . '" />';
        $tags[] = '<meta name="twitter:image" content="' . nj_meta_escape($image) . '" />';
    }

    if ($imageAlt !== '') {
        $tags[] = '<meta property="og:image:alt" content="' . nj_meta_escape($imageAlt) . '" />';
        $tags[] = '<meta name="twitter:image:alt" content="' . nj_meta_escape($imageAlt) . '" />';
    }

    if ($type === 'article') {
        if ($publishedAt !== '') {
            $tags[] = '<meta property="article:published_time" content="' . nj_meta_escape($publishedAt) . '" />';
        }

        if ($modifiedAt !== '') {
            $tags[] = '<meta property="article:modified_time" content="' . nj_meta_escape($modifiedAt) . '" />';
        }

        if ($section !== '') {
            $tags[] = '<meta property="article:section" content="' . nj_meta_escape($section) . '" />';
        }
    }

    if (isset($meta['jsonLd']) && is_array($meta['jsonLd'])) {
        $json = json_encode(
            $meta['jsonLd'],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if (is_string($json)) {
            $tags[] = '<script type="application/ld+json" data-nj-server-jsonld="true">' . $json . '</script>';
        }
    }

    return "<!--NJ_META_START-->\n    "
        . implode("\n    ", $tags)
        . "\n    <!--NJ_META_END-->";
}

$defaultDescription = 'Nosso Jornal: notícias de Hulha Negra, da região e do Rio Grande do Sul.';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
$path = $path !== '/' ? rtrim($path, '/') : '/';

$meta = [
    'title' => 'Nosso Jornal | Hulha Negra e região',
    'description' => $defaultDescription,
    'canonical' => $path,
    'type' => 'website',
];

$status = 200;

try {
    if ($path === '/') {
        $meta['canonical'] = '/';
    } elseif ($path === '/sistema' || str_starts_with($path, '/sistema/')) {
        $meta['title'] = 'Sistema';
        $meta['description'] = 'Administração editorial do Nosso Jornal.';
        $meta['canonical'] = '/sistema';
        $meta['robots'] = 'noindex,nofollow';
    } elseif ($path === '/ultimas' || $path === '/noticias') {
        $meta['title'] = 'Últimas notícias';
        $meta['description'] = 'As notícias mais recentes publicadas pelo Nosso Jornal.';
        $meta['canonical'] = '/ultimas';
    } elseif ($path === '/sobre' || $path === '/quem-somos') {
        $meta['title'] = 'Quem Somos';
        $meta['description'] = 'Conheça a história do Nosso Jornal, fundado em Hulha Negra e hoje dedicado ao jornalismo local e regional.';
        $meta['canonical'] = '/sobre';
    } elseif ($path === '/contato') {
        $meta['title'] = 'Contato';
        $meta['description'] = 'Fale com a redação e com o setor comercial do Nosso Jornal.';
        $meta['canonical'] = '/contato';
    } elseif ($path === '/sistema' || str_starts_with($path, '/sistema/')) {
        $meta['title'] = 'Sistema';
        $meta['description'] = 'Painel administrativo do Nosso Jornal.';
        $meta['canonical'] = '/sistema';
        $meta['robots'] = 'noindex,nofollow';
    } elseif ($path === '/busca') {
        $meta['title'] = 'Buscar no Nosso Jornal';
        $meta['description'] = 'Pesquisa no acervo de notícias do Nosso Jornal.';
        $meta['canonical'] = '/busca';
        $meta['robots'] = 'noindex,follow';
    } elseif ($path === '/classificados' || $path === '/comunicados') {
        $label = $path === '/classificados' ? 'Classificados' : 'Comunicados';
        $meta['title'] = $label;
        $meta['description'] = $label . ' do Nosso Jornal.';
        $meta['canonical'] = $path;
        $meta['robots'] = 'noindex,follow';
    } elseif (preg_match('#^/categoria/([a-z0-9-]+)$#', $path, $match)) {
        $category = nj_meta_category_by_slug($match[1]);

        if ($category === null) {
            $status = 404;
            $meta['title'] = 'Editoria não encontrada';
            $meta['description'] = 'A editoria solicitada não foi encontrada no Nosso Jornal.';
            $meta['robots'] = 'noindex,follow';
        } else {
            $meta['title'] = (string) $category['name'];
            $meta['description'] = 'Notícias da editoria ' . $category['name'] . ' no Nosso Jornal.';
            $meta['canonical'] = '/categoria/' . rawurlencode((string) $category['slug']);
        }
    } else {
        $slug = null;

        if (preg_match('#^/noticia/([a-z0-9-]+)$#', $path, $match)) {
            $slug = $match[1];
        } elseif (preg_match('#^/([a-z0-9-]+)$#', $path, $match)) {
            $slug = $match[1];
        }

        if ($slug !== null) {
            $article = nj_meta_article_by_slug($slug);

            if ($article === null) {
                $status = 404;
                $meta['title'] = 'Página não encontrada';
                $meta['description'] = 'A página solicitada não foi encontrada no Nosso Jornal.';
                $meta['robots'] = 'noindex,follow';
            } else {
                $articleUrl = nj_meta_absolute_url((string) $article['url']);
                $category = $article['primaryCategory'];
                $categoryUrl = is_array($category)
                    ? nj_meta_absolute_url((string) $category['url'])
                    : null;
                $image = is_array($article['featuredImage'])
                    ? (string) $article['featuredImage']['url']
                    : '';
                $imageAlt = is_array($article['featuredImage'])
                    ? (string) $article['featuredImage']['alt']
                    : '';

                $meta = [
                    'title' => (string) $article['seoTitle'],
                    'description' => (string) $article['seoDescription'],
                    'canonical' => (string) $article['url'],
                    'type' => 'article',
                    'image' => $image,
                    'imageAlt' => $imageAlt,
                    'publishedAt' => (string) $article['publishedAt'],
                    'modifiedAt' => (string) $article['modifiedAt'],
                    'section' => is_array($category) ? (string) $category['name'] : '',
                    'jsonLd' => [
                        '@context' => 'https://schema.org',
                        '@graph' => [
                            [
                                '@type' => 'NewsArticle',
                                '@id' => $articleUrl . '#article',
                                'headline' => (string) $article['title'],
                                'description' => (string) $article['seoDescription'],
                                'datePublished' => (string) $article['publishedAt'],
                                'dateModified' => (string) $article['modifiedAt'],
                                'mainEntityOfPage' => $articleUrl,
                                'image' => $image !== '' ? [nj_meta_absolute_url($image)] : null,
                                'articleSection' => is_array($category) ? (string) $category['name'] : null,
                                'inLanguage' => 'pt-BR',
                                'author' => trim((string) $article['author']['name']) !== ''
                                    ? [
                                        '@type' => 'Person',
                                        'name' => (string) $article['author']['name'],
                                    ]
                                    : [
                                        '@type' => 'Organization',
                                        'name' => 'Nosso Jornal',
                                    ],
                                'publisher' => [
                                    '@type' => 'Organization',
                                    'name' => 'Nosso Jornal',
                                    'url' => NJ_SITE_URL,
                                    'logo' => [
                                        '@type' => 'ImageObject',
                                        'url' => NJ_SITE_URL . '/nosso-jornal-hulha-negra-bage.png',
                                    ],
                                ],
                            ],
                            [
                                '@type' => 'BreadcrumbList',
                                '@id' => $articleUrl . '#breadcrumb',
                                'itemListElement' => array_values(array_filter([
                                    [
                                        '@type' => 'ListItem',
                                        'position' => 1,
                                        'name' => 'Capa',
                                        'item' => NJ_SITE_URL . '/',
                                    ],
                                    is_array($category) && $categoryUrl !== null
                                        ? [
                                            '@type' => 'ListItem',
                                            'position' => 2,
                                            'name' => (string) $category['name'],
                                            'item' => $categoryUrl,
                                        ]
                                        : null,
                                    [
                                        '@type' => 'ListItem',
                                        'position' => is_array($category) ? 3 : 2,
                                        'name' => (string) $article['title'],
                                        'item' => $articleUrl,
                                    ],
                                ])),
                            ],
                        ],
                    ],
                ];
            }
        } else {
            $status = 404;
            $meta['title'] = 'Página não encontrada';
            $meta['description'] = 'A página solicitada não foi encontrada no Nosso Jornal.';
            $meta['robots'] = 'noindex,follow';
        }
    }
} catch (Throwable $error) {
    error_log('[nossojornal-meta] error=' . get_class($error));
}

$shellPath = __DIR__ . '/index.html';
$shell = is_file($shellPath) ? file_get_contents($shellPath) : false;

if (!is_string($shell) || $shell === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Portal temporariamente indisponível.';
    exit;
}

$metaBlock = nj_meta_block($meta);
$output = preg_replace(
    '#<!--NJ_META_START-->.*?<!--NJ_META_END-->#s',
    $metaBlock,
    $shell,
    1
);

if (!is_string($output)) {
    $output = $shell;
}

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=30, stale-while-revalidate=120');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Vary: Accept-Encoding');

echo $output;
