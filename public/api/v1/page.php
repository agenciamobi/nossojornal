<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

function nj_page_add_contact(
    array &$contacts,
    string $type,
    string $value,
    string $href = '',
    string $label = ''
): void {
    $value = trim($value);
    if ($value === '') {
        return;
    }

    $key = strtolower($type . ':' . $value);
    $contacts[$key] = [
        'type' => $type,
        'href' => trim($href),
        'value' => $value,
        'label' => trim($label) !== '' ? trim($label) : $value,
    ];
}

function nj_page_collect_elementor_contacts(mixed $node, array &$contacts): void
{
    if (!is_array($node)) {
        return;
    }

    $settings = isset($node['settings']) && is_array($node['settings'])
        ? $node['settings']
        : null;

    if ($settings !== null) {
        $title = nj_content_clean_text_source((string) ($settings['title'] ?? ''));
        $subtitle = nj_content_clean_text_source((string) ($settings['subtitle'] ?? ''));
        $link = '';

        if (isset($settings['link']) && is_array($settings['link'])) {
            $link = trim((string) ($settings['link']['url'] ?? ''));
        }

        if ($link !== '') {
            if (preg_match('#^https://wa\.me/([0-9]+)#i', $link, $match)) {
                nj_page_add_contact($contacts, 'whatsapp', $match[1], $link, $title);
            } elseif (preg_match('/^mailto:(.+)$/i', $link, $match)) {
                nj_page_add_contact($contacts, 'email', trim($match[1]), $link, $title);
            } elseif (preg_match('/^tel:(.+)$/i', $link, $match)) {
                nj_page_add_contact($contacts, 'phone', trim($match[1]), $link, $title);
            }
        }

        if (
            $subtitle !== ''
            && preg_match('/e-?mail/i', $title)
            && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $subtitle, $match)
        ) {
            $email = $match[0];
            nj_page_add_contact($contacts, 'email', $email, 'mailto:' . $email, $title);
        }

        if (
            $subtitle !== ''
            && preg_match('/endere[cç]o/i', $title)
        ) {
            nj_page_add_contact($contacts, 'location', $subtitle, '', $title);
        }

        if (
            $subtitle !== ''
            && preg_match('/telefone|whatsapp/i', $title)
            && preg_match('/(?:\+?55\s*)?\(?\d{2}\)?\s*\d{4,5}[-\s]?\d{4}/', $subtitle, $match)
        ) {
            $digits = preg_replace('/\D+/', '', $match[0]) ?? '';

            if (preg_match('/whatsapp/i', $title)) {
                $national = str_starts_with($digits, '55') ? $digits : '55' . $digits;
                nj_page_add_contact(
                    $contacts,
                    'whatsapp',
                    $digits,
                    'https://wa.me/' . $national,
                    $title
                );
            } else {
                nj_page_add_contact($contacts, 'phone', $digits, 'tel:+' . $digits, $title);
            }
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            nj_page_collect_elementor_contacts($value, $contacts);
        }
    }
}

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $aliases = [
        'sobre' => 'quem-somos',
    ];
    $legacySlug = $aliases[$slug] ?? $slug;

    if ($legacySlug === '' || !preg_match('/^[a-z0-9-]+$/', $legacySlug)) {
        throw new NjApiHttpException(400, 'invalid_page_slug');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    post_title AS title,
    post_name AS slug,
    post_content AS content,
    post_excerpt AS excerpt,
    post_date AS published_at,
    post_modified AS modified_at
FROM {$posts}
WHERE
    post_type = 'page'
    AND post_status = 'publish'
    AND post_name = :slug
LIMIT 1
SQL);
    $statement->execute(['slug' => $legacySlug]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'page_not_found');
    }

    $contentHtml = nj_content_sanitize_html((string) $row['content']);
    $contacts = [];

    if ($slug === 'contato') {
        preg_match_all(
            "~<a\b[^>]*href=([\"'])(https://wa\.me/[^\"']+|mailto:[^\"']+|tel:[^\"']+)\1[^>]*>(.*?)</a>~is",
            $contentHtml,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $href = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $match[3]))) ?? '';

            if (preg_match('#^https://wa\.me/([0-9]+)#i', $href, $waMatch)) {
                nj_page_add_contact($contacts, 'whatsapp', $waMatch[1], $href, $label);
            } elseif (preg_match('/^mailto:(.+)$/i', $href, $mailMatch)) {
                nj_page_add_contact($contacts, 'email', trim($mailMatch[1]), $href, $label);
            } elseif (preg_match('/^tel:(.+)$/i', $href, $phoneMatch)) {
                nj_page_add_contact($contacts, 'phone', trim($phoneMatch[1]), $href, $label);
            }
        }

        $elementorStatement = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key = '_elementor_data'
ORDER BY meta_id DESC
LIMIT 1
SQL);
        $elementorStatement->execute(['post_id' => (int) $row['id']]);
        $elementorJson = $elementorStatement->fetchColumn();

        if (is_string($elementorJson) && trim($elementorJson) !== '') {
            $elementorData = json_decode($elementorJson, true);

            if (is_array($elementorData)) {
                nj_page_collect_elementor_contacts($elementorData, $contacts);
            }
        }
    }

    return [
        'page' => [
            'id' => (int) $row['id'],
            'title' => trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'slug' => $slug,
            'legacySlug' => (string) $row['slug'],
            'contentHtml' => $contentHtml,
            'excerpt' => nj_content_excerpt((string) $row['excerpt'], (string) $row['content']),
            'modifiedAt' => (string) $row['modified_at'],
            'contacts' => array_values($contacts),
        ],
    ];
}, 'public, max-age=300, stale-while-revalidate=900');
