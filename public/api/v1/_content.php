<?php
declare(strict_types=1);

require_once __DIR__ . '/_category_theme.php';

function nj_content_clean_text_source(string $source): string
{
    $source = preg_replace('/\[embedyt\].*?\[\/embedyt\]/is', ' ', $source) ?? $source;
    $source = preg_replace('/\[caption[^\]]*\].*?\[\/caption\]/is', ' ', $source) ?? $source;
    $source = preg_replace('/<img\b[^>]*>/is', ' ', $source) ?? $source;
    $source = preg_replace('/\[(?:\/)?[a-z][^\]]*\]/i', ' ', $source) ?? $source;

    $text = html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

    return $text;
}

function nj_content_excerpt(string $excerpt, string $content, int $maxLength = 240): string
{
    $source = trim($excerpt) !== '' ? $excerpt : $content;
    $text = nj_content_clean_text_source($source);

    if ($text === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $maxLength) {
        return $text;
    }

    $cut = function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength - 1, 'UTF-8')
        : substr($text, 0, $maxLength - 1);

    return rtrim($cut, " \t\n\r\0\x0B,.;:!?-") . '…';
}

function nj_content_local_media_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path) && str_starts_with($path, '/wp-content/uploads/')) {
        return $path;
    }

    return $url;
}

function nj_content_iso8601(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $date = new DateTimeImmutable($value, $timezone);
        return $date->format(DATE_ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function nj_content_media_key(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $basename = basename(is_string($path) ? $path : $url);

    return strtolower((string) preg_replace(
        '/-\d+x\d+(?=\.[a-z0-9]+$)/i',
        '',
        $basename
    ));
}

function nj_content_heading_id(string $text, int $fallbackIndex): string
{
    $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (function_exists('iconv')) {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($transliterated) && $transliterated !== '') {
            $normalized = $transliterated;
        }
    }

    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $normalized = trim($normalized, '-');

    return $normalized !== '' ? $normalized : 'secao-' . $fallbackIndex;
}

function nj_content_youtube_ids(string $html): array
{
    preg_match_all(
        '#(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,20})#i',
        $html,
        $matches
    );

    $ids = [];
    foreach ($matches[1] ?? [] as $id) {
        $ids[$id] = true;
    }

    return array_keys($ids);
}

function nj_content_sanitize_inline_style(string $style): string
{
    $safe = [];

    foreach (explode(';', $style) as $declaration) {
        $parts = explode(':', $declaration, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        if ($property === 'text-align' && preg_match('/^(left|right|center|justify)$/i', $value)) {
            $safe[] = 'text-align:' . strtolower($value);
            continue;
        }

        if (
            in_array($property, ['color', 'background-color'], true)
            && (
                preg_match('/^#[0-9a-f]{3,8}$/i', $value)
                || preg_match('/^rgba?\(\s*[0-9.]+%?\s*,\s*[0-9.]+%?\s*,\s*[0-9.]+%?(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value)
            )
        ) {
            $safe[] = $property . ':' . $value;
            continue;
        }

        if ($property === 'font-weight' && preg_match('/^(normal|bold|bolder|[1-9]00)$/i', $value)) {
            $safe[] = 'font-weight:' . strtolower($value);
            continue;
        }

        if ($property === 'font-style' && preg_match('/^(normal|italic)$/i', $value)) {
            $safe[] = 'font-style:' . strtolower($value);
            continue;
        }

        if (
            $property === 'text-decoration'
            && preg_match('/^(none|underline|line-through|underline line-through|line-through underline)$/i', $value)
        ) {
            $safe[] = 'text-decoration:' . strtolower($value);
        }
    }

    return implode(';', $safe);
}

function nj_content_sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $html = preg_replace('#<(script|style|form|input|button|textarea|select|object|embed|iframe|svg)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|form|input|button|textarea|select|object|embed|iframe|svg)[^>]*/?>#is', '', $html) ?? $html;
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace_callback(
        '/\sstyle\s*=\s*(["\'])(.*?)\1/i',
        static function (array $matches): string {
            $safeStyle = nj_content_sanitize_inline_style((string) $matches[2]);

            return $safeStyle !== ''
                ? ' style="' . htmlspecialchars($safeStyle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                : '';
        },
        $html
    ) ?? $html;
    $html = preg_replace('/\s(?:data-elementor-[a-z0-9_-]+|data-e-[a-z0-9_-]+)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
    $html = preg_replace_callback(
        '/\s(href|src)\s*=\s*(["\'])(.*?)\2/i',
        static function (array $matches): string {
            $attribute = strtolower($matches[1]);
            $quote = $matches[2];
            $value = trim(html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (preg_match('#^(javascript|data):#i', $value)) {
                return '';
            }

            if ($attribute === 'src') {
                $value = nj_content_local_media_url($value);
            }

            return ' ' . $attribute . '=' . $quote . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $quote;
        },
        $html
    ) ?? $html;

    return $html;
}

function nj_content_caption_shortcode_to_html(string $html): string
{
    return preg_replace_callback(
        '/\[caption[^\]]*\](.*?)\[\/caption\]/is',
        static function (array $matches): string {
            $inner = (string) $matches[1];

            if (!preg_match('/<img\b[^>]*>/is', $inner, $imageMatch)) {
                return nj_content_clean_text_source($inner);
            }

            $image = $imageMatch[0];
            $captionSource = preg_replace('/<img\b[^>]*>/is', ' ', $inner) ?? '';
            $caption = nj_content_clean_text_source($captionSource);

            return '<figure class="article-legacy-figure">'
                . $image
                . ($caption !== ''
                    ? '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</figcaption>'
                    : '')
                . '</figure>';
        },
        $html
    ) ?? $html;
}

function nj_content_dom_inner_html(DOMElement $element): string
{
    $html = '';
    foreach ($element->childNodes as $child) {
        $html .= $element->ownerDocument?->saveHTML($child) ?? '';
    }

    return $html;
}

function nj_content_dom_replace_text_nodes(DOMDocument $dom, DOMElement $root): void
{
    $children = [];
    foreach ($root->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if (!$child instanceof DOMText) {
            continue;
        }

        $raw = str_replace("\xc2\xa0", ' ', $child->wholeText);
        $blocks = preg_split('/\n\s*\n+/u', trim($raw)) ?: [];
        $replacementNodes = [];

        foreach ($blocks as $block) {
            $text = preg_replace('/\s+/u', ' ', trim($block)) ?? trim($block);
            if ($text === '') {
                continue;
            }

            $paragraph = $dom->createElement('p');
            $paragraph->appendChild($dom->createTextNode($text));
            $replacementNodes[] = $paragraph;
        }

        foreach ($replacementNodes as $node) {
            $root->insertBefore($node, $child);
        }

        $root->removeChild($child);
    }
}

function nj_content_dom_promote_headings(DOMDocument $dom, DOMElement $root): array
{
    $toc = [];
    $headingIndex = 0;
    $usedIds = [];

    $children = [];
    foreach ($root->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        $text = preg_replace('/\s+/u', ' ', trim($child->textContent)) ?? '';

        if ($text === '') {
            continue;
        }

        $shouldPromote = false;

        if ($tag === 'strong' && strlen($text) <= 220) {
            $shouldPromote = true;
        }

        if ($tag === 'p' && strlen($text) <= 220) {
            $elements = [];
            foreach ($child->childNodes as $inner) {
                if ($inner instanceof DOMElement) {
                    $elements[] = strtolower($inner->tagName);
                } elseif ($inner instanceof DOMText && trim($inner->wholeText) !== '') {
                    $elements[] = '#text';
                }
            }

            $allowed = array_values(array_filter(
                $elements,
                static fn (string $name): bool => in_array($name, ['strong', 'b', 'span'], true)
            ));

            if ($elements !== [] && count($allowed) === count($elements)) {
                $shouldPromote = true;
            }
        }

        if (!$shouldPromote) {
            continue;
        }

        $headingIndex++;
        $baseId = nj_content_heading_id($text, $headingIndex);
        $id = $baseId;
        $suffix = 2;

        while (isset($usedIds[$id])) {
            $id = $baseId . '-' . $suffix;
            $suffix++;
        }

        $usedIds[$id] = true;

        $heading = $dom->createElement('h2');
        $heading->setAttribute('id', $id);
        $heading->appendChild($dom->createTextNode($text));
        $root->replaceChild($heading, $child);

        $toc[] = [
            'id' => $id,
            'label' => $text,
        ];
    }

    return $toc;
}

function nj_content_dom_extract_gallery(
    DOMElement $root,
    string $featuredImageUrl
): array {
    $gallery = [];
    $featuredKey = $featuredImageUrl !== '' ? nj_content_media_key($featuredImageUrl) : '';
    $galleryOpen = true;
    $leadRemoved = false;

    $children = [];
    foreach ($root->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->tagName);
        $text = preg_replace('/\s+/u', ' ', trim($child->textContent)) ?? '';

        if (
            !$leadRemoved
            && $tag === 'p'
            && $text !== ''
            && strlen($text) <= 220
            && $child->getElementsByTagName('strong')->length > 0
        ) {
            $root->removeChild($child);
            $leadRemoved = true;
            continue;
        }

        if ($galleryOpen && in_array($tag, ['img', 'figure'], true)) {
            $image = $tag === 'img'
                ? $child
                : $child->getElementsByTagName('img')->item(0);

            if ($image instanceof DOMElement) {
                $src = nj_content_local_media_url($image->getAttribute('src'));
                $key = nj_content_media_key($src);

                $caption = '';
                if ($tag === 'figure') {
                    $figcaptions = $child->getElementsByTagName('figcaption');
                    if ($figcaptions->length > 0) {
                        $caption = preg_replace(
                            '/\s+/u',
                            ' ',
                            trim($figcaptions->item(0)?->textContent ?? '')
                        ) ?? '';
                    }
                }

                if ($src !== '' && ($featuredKey === '' || $key !== $featuredKey)) {
                    $gallery[$key !== '' ? $key : $src] = [
                        'url' => $src,
                        'alt' => trim($image->getAttribute('alt')) !== ''
                            ? trim($image->getAttribute('alt'))
                            : $caption,
                        'caption' => $caption,
                    ];
                }
            }

            $root->removeChild($child);
            continue;
        }

        if ($text === '') {
            continue;
        }

        if ($tag === 'p' && strlen($text) <= 220 && $gallery === []) {
            continue;
        }

        $galleryOpen = false;
    }

    return array_values($gallery);
}

function nj_content_dom_enhance_media(DOMElement $root): void
{
    foreach ($root->getElementsByTagName('img') as $image) {
        $image->setAttribute('loading', 'lazy');
        $image->setAttribute('decoding', 'async');

        $src = nj_content_local_media_url($image->getAttribute('src'));
        if ($src !== '') {
            $image->setAttribute('src', $src);
        }
    }

    foreach ($root->getElementsByTagName('a') as $link) {
        $href = trim($link->getAttribute('href'));

        if (
            preg_match('#^https?://#i', $href)
            && !str_starts_with($href, 'https://nossojornal.com.br')
        ) {
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer external');
        }
    }
}

function nj_content_normalize_article(string $html, string $featuredImageUrl = ''): array
{
    if (trim($html) === '') {
        return [
            'html' => '',
            'toc' => [],
            'gallery' => [],
            'videos' => [],
        ];
    }

    $youtubeIds = nj_content_youtube_ids($html);
    $html = preg_replace('/\[embedyt\].*?\[\/embedyt\]/is', '', $html) ?? $html;
    $html = nj_content_caption_shortcode_to_html($html);
    $html = preg_replace('/\[(?:\/)?[a-z][^\]]*\]/i', '', $html) ?? $html;
    $html = str_replace(['&nbsp;', "\xc2\xa0"], ["\n\n", ' '], $html);
    $html = nj_content_sanitize_html($html);

    if (!class_exists('DOMDocument')) {
        return [
            'html' => $html,
            'toc' => [],
            'gallery' => [],
            'videos' => array_map(
                static fn (string $id): array => [
                    'provider' => 'youtube',
                    'id' => $id,
                    'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id),
                ],
                $youtubeIds
            ),
        ];
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previousLibxml = libxml_use_internal_errors(true);

    $loaded = $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div id="nj-article-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxml);

    if (!$loaded) {
        return [
            'html' => $html,
            'toc' => [],
            'gallery' => [],
            'videos' => [],
        ];
    }

    $root = $dom->getElementById('nj-article-root');
    if (!$root instanceof DOMElement) {
        return [
            'html' => $html,
            'toc' => [],
            'gallery' => [],
            'videos' => [],
        ];
    }

    nj_content_dom_replace_text_nodes($dom, $root);
    $gallery = nj_content_dom_extract_gallery($root, $featuredImageUrl);
    $toc = nj_content_dom_promote_headings($dom, $root);
    nj_content_dom_enhance_media($root);

    $normalizedHtml = nj_content_dom_inner_html($root);
    $normalizedHtml = preg_replace('/<p>\s*<\/p>/i', '', $normalizedHtml) ?? $normalizedHtml;

    return [
        'html' => trim($normalizedHtml),
        'toc' => $toc,
        'gallery' => $gallery,
        'videos' => array_map(
            static fn (string $id): array => [
                'provider' => 'youtube',
                'id' => $id,
                'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id),
            ],
            $youtubeIds
        ),
    ];
}

function nj_content_categories_for_posts(PDO $pdo, array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $sql = <<<SQL
SELECT
    tr.object_id AS post_id,
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.parent AS parent_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
INNER JOIN {$terms} t
    ON t.term_id = tt.term_id
WHERE tr.object_id IN ({$placeholders})
ORDER BY t.name ASC
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute($postIds);
    $rows = $statement->fetchAll();
    $colorOverrides = nj_category_color_overrides(
        $pdo,
        array_map(static fn (array $row): int => (int) $row['id'], $rows)
    );

    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['post_id']][] = [
            'id' => (int) $row['id'],
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'parentId' => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
            'url' => '/categoria/' . rawurlencode((string) $row['slug']),
            'color' => nj_category_color_for(
                (int) $row['id'],
                (string) $row['slug'],
                $colorOverrides
            ),
            'colorSource' => nj_category_color_source_for(
                (int) $row['id'],
                $colorOverrides
            ),
        ];
    }

    return $result;
}

function nj_content_tags_for_posts(PDO $pdo, array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $sql = <<<SQL
SELECT
    tr.object_id AS post_id,
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'post_tag'
INNER JOIN {$terms} t
    ON t.term_id = tt.term_id
WHERE tr.object_id IN ({$placeholders})
ORDER BY t.name ASC
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute($postIds);

    $result = [];
    foreach ($statement->fetchAll() as $row) {
        $result[(int) $row['post_id']][] = [
            'id' => (int) $row['id'],
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'url' => '/tag/' . rawurlencode((string) $row['slug']),
        ];
    }

    return $result;
}

function nj_content_primary_category(array $categories, int $primaryId): ?array
{
    $technical = [
        'capa' => true,
        'geral' => true,
        'outros' => true,
        'eleicoes-2024' => true,
        'cobertura-regional' => true,
    ];

    foreach ($categories as $category) {
        if ($category['id'] === $primaryId && !isset($technical[$category['slug']])) {
            return $category;
        }
    }

    foreach ($categories as $category) {
        if (!isset($technical[$category['slug']])) {
            return $category;
        }
    }

    return $categories[0] ?? null;
}

function nj_content_hydrate_articles(PDO $pdo, array $rows, bool $includeBody = false): array
{
    if ($rows === []) {
        return [];
    }

    $postIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $categoriesByPost = nj_content_categories_for_posts($pdo, $postIds);
    $tagsByPost = nj_content_tags_for_posts($pdo, $postIds);
    $articles = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $categories = $categoriesByPost[$id] ?? [];
        $primary = nj_content_primary_category($categories, (int) ($row['primary_category_id'] ?? 0));
        $title = trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $featuredImageUrl = trim((string) ($row['featured_image_url'] ?? '')) !== ''
            ? nj_content_local_media_url((string) $row['featured_image_url'])
            : '';

        $article = [
            'id' => $id,
            'title' => $title,
            'slug' => (string) $row['slug'],
            'url' => '/noticia/' . rawurlencode((string) $row['slug']),
            'excerpt' => nj_content_excerpt((string) ($row['excerpt'] ?? ''), (string) ($row['content'] ?? '')),
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) ($row['author_id'] ?? 0),
                'name' => trim((string) ($row['author_name'] ?? '')),
            ],
            'featuredImage' => $featuredImageUrl !== ''
                ? [
                    'url' => $featuredImageUrl,
                    'alt' => trim((string) ($row['featured_image_alt'] ?? '')) !== ''
                        ? (string) $row['featured_image_alt']
                        : $title,
                ]
                : null,
            'views' => (int) ($row['views'] ?? 0),
            'primaryCategory' => $primary,
            'categories' => $categories,
            'tags' => $tagsByPost[$id] ?? [],
        ];

        if ($includeBody) {
            $normalized = nj_content_normalize_article(
                (string) ($row['content'] ?? ''),
                $featuredImageUrl
            );

            $article['contentHtml'] = $normalized['html'];
            $article['toc'] = $normalized['toc'];
            $article['gallery'] = $normalized['gallery'];
            $article['videos'] = $normalized['videos'];
        }

        $articles[] = $article;
    }

    return $articles;
}

function nj_content_article_select(string $posts, string $postmeta, string $users): string
{
    return <<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_excerpt AS excerpt,
    p.post_content AS content,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, '') AS author_name,
    COALESCE((
        SELECT a.guid
        FROM {$postmeta} thumb
        INNER JOIN {$posts} a
            ON a.ID = CAST(thumb.meta_value AS UNSIGNED)
            AND a.post_type = 'attachment'
        WHERE thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_url,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} thumb2
        INNER JOIN {$postmeta} alt
            ON alt.post_id = CAST(thumb2.meta_value AS UNSIGNED)
            AND alt.meta_key = '_wp_attachment_image_alt'
        WHERE thumb2.post_id = p.ID AND thumb2.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_alt,
    COALESCE((
        SELECT CAST(pm_views.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_views
        WHERE pm_views.post_id = p.ID AND pm_views.meta_key = 'views'
        ORDER BY pm_views.meta_id DESC
        LIMIT 1
    ), 0) AS views,
    COALESCE((
        SELECT CAST(pm_primary.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_primary
        WHERE pm_primary.post_id = p.ID AND pm_primary.meta_key = '_yoast_wpseo_primary_category'
        ORDER BY pm_primary.meta_id DESC
        LIMIT 1
    ), 0) AS primary_category_id
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
SQL;
}
