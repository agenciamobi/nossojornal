<?php
declare(strict_types=1);

function nj_author_external_url(string $value, array $allowedHosts = []): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 1000) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }

    if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
        return '';
    }

    return $value;
}

function nj_author_profile(PDO $pdo, string $slug): ?array
{
    $slug = trim($slug);

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        return null;
    }

    $users = nj_table('users');
    $usermeta = nj_table('usermeta');
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    u.ID AS id,
    u.user_nicename AS slug,
    u.display_name,
    u.user_url,
    (
        SELECT COUNT(*)
        FROM {$posts} p
        WHERE
            (
                p.post_author = u.ID
                OR EXISTS (
                    SELECT 1
                    FROM {$postmeta} coauthor_meta
                    WHERE
                        coauthor_meta.post_id = p.ID
                        AND coauthor_meta.meta_key = '_nj_coauthors'
                        AND FIND_IN_SET(
                            CAST(u.ID AS CHAR),
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(
                                                REPLACE(coauthor_meta.meta_value, '[', ''),
                                                ']', ''
                                            ),
                                            ' ',
                                            ''
                                        ),
                                        CHAR(10),
                                        ''
                                    ),
                                    CHAR(13),
                                    ''
                                ),
                                CHAR(9),
                                ''
                            )
                        ) > 0
                )
            )
            AND p.post_type = 'post'
            AND p.post_status = 'publish'
            AND p.post_password = ''
            AND p.post_title <> ''
            AND p.post_name <> ''
    ) AS published_count,
    (
        SELECT MAX(p.post_date)
        FROM {$posts} p
        WHERE
            (
                p.post_author = u.ID
                OR EXISTS (
                    SELECT 1
                    FROM {$postmeta} coauthor_meta
                    WHERE
                        coauthor_meta.post_id = p.ID
                        AND coauthor_meta.meta_key = '_nj_coauthors'
                        AND FIND_IN_SET(
                            CAST(u.ID AS CHAR),
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(
                                                REPLACE(coauthor_meta.meta_value, '[', ''),
                                                ']', ''
                                            ),
                                            ' ',
                                            ''
                                        ),
                                        CHAR(10),
                                        ''
                                    ),
                                    CHAR(13),
                                    ''
                                ),
                                CHAR(9),
                                ''
                            )
                        ) > 0
                )
            )
            AND p.post_type = 'post'
            AND p.post_status = 'publish'
            AND p.post_password = ''
            AND p.post_title <> ''
            AND p.post_name <> ''
    ) AS latest_published_at
FROM {$users} u
WHERE
    u.user_nicename = :slug
    AND u.user_status = 0
LIMIT 1
SQL);
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch();

    if (!$row || (int) $row['published_count'] < 1) {
        return null;
    }

    // Deliberately narrow allowlist. Email, login, roles, capabilities and
    // arbitrary plugin metadata never enter this public contract.
    $allowedMetaKeys = [
        'description',
        '_nj_public_bio',
        '_nj_public_role',
        '_nj_public_avatar_id',
        '_nj_public_instagram',
        '_nj_public_facebook',
        '_nj_public_linkedin',
        '_nj_public_x',
    ];
    $placeholders = implode(',', array_fill(0, count($allowedMetaKeys), '?'));

    $metaStatement = $pdo->prepare(
        "SELECT meta_key, meta_value
         FROM {$usermeta}
         WHERE user_id = ? AND meta_key IN ({$placeholders})
         ORDER BY umeta_id DESC"
    );
    $metaStatement->execute(array_merge([(int) $row['id']], $allowedMetaKeys));

    $meta = [];
    foreach ($metaStatement->fetchAll() as $metaRow) {
        $key = (string) $metaRow['meta_key'];
        if (!array_key_exists($key, $meta)) {
            $meta[$key] = (string) $metaRow['meta_value'];
        }
    }

    $name = trim((string) $row['display_name']);
    if ($name === '') {
        $name = (string) $row['slug'];
    }

    $bioSource = trim((string) ($meta['_nj_public_bio'] ?? '')) !== ''
        ? (string) $meta['_nj_public_bio']
        : (string) ($meta['description'] ?? '');
    $bio = nj_content_clean_text_source($bioSource);
    $role = nj_content_clean_text_source((string) ($meta['_nj_public_role'] ?? ''));

    $avatar = null;
    $avatarId = max(0, (int) ($meta['_nj_public_avatar_id'] ?? 0));

    if ($avatarId > 0) {
        $avatarStatement = $pdo->prepare(<<<SQL
SELECT
    a.guid,
    COALESCE((
        SELECT file.meta_value
        FROM {$postmeta} file
        WHERE file.post_id = a.ID
          AND file.meta_key = '_wp_attached_file'
        ORDER BY file.meta_id DESC
        LIMIT 1
    ), '') AS attached_file,
    COALESCE((
        SELECT metadata.meta_value
        FROM {$postmeta} metadata
        WHERE metadata.post_id = a.ID
          AND metadata.meta_key = '_wp_attachment_metadata'
        ORDER BY metadata.meta_id DESC
        LIMIT 1
    ), '') AS attachment_metadata,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} alt
        WHERE alt.post_id = a.ID
          AND alt.meta_key = '_wp_attachment_image_alt'
        ORDER BY alt.meta_id DESC
        LIMIT 1
    ), '') AS alt_text
FROM {$posts} a
WHERE
    a.ID = :id
    AND a.post_type = 'attachment'
    AND a.post_mime_type LIKE 'image/%'
LIMIT 1
SQL);
        $avatarStatement->execute(['id' => $avatarId]);
        $avatarRow = $avatarStatement->fetch();

        if ($avatarRow) {
            $avatar = nj_media_descriptor(
                (string) $avatarRow['guid'],
                (string) $avatarRow['attached_file'],
                (string) $avatarRow['attachment_metadata'],
                (string) $avatarRow['alt_text'],
                'Foto de ' . $name
            );
        }
    }

    $socialDefinitions = [
        'instagram' => [
            'label' => 'Instagram',
            'hosts' => ['instagram.com', 'www.instagram.com'],
        ],
        'facebook' => [
            'label' => 'Facebook',
            'hosts' => ['facebook.com', 'www.facebook.com'],
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'hosts' => ['linkedin.com', 'www.linkedin.com'],
        ],
        'x' => [
            'label' => 'X',
            'hosts' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'],
        ],
    ];

    $social = [];
    foreach ($socialDefinitions as $key => $definition) {
        $url = nj_author_external_url(
            (string) ($meta['_nj_public_' . $key] ?? ''),
            $definition['hosts']
        );

        if ($url === '') {
            continue;
        }

        $social[] = [
            'service' => $key,
            'label' => $definition['label'],
            'url' => $url,
        ];
    }

    $website = nj_author_external_url((string) $row['user_url']);

    return [
        'id' => (int) $row['id'],
        'name' => $name,
        'slug' => (string) $row['slug'],
        'url' => '/autor/' . rawurlencode((string) $row['slug']),
        'bio' => $bio,
        'role' => $role,
        'website' => $website,
        'social' => $social,
        'avatar' => $avatar,
        'publishedCount' => (int) $row['published_count'],
        'latestPublishedAt' => $row['latest_published_at'] !== null
            ? nj_content_iso8601((string) $row['latest_published_at'])
            : '',
    ];
}
