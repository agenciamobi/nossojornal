<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_authors.php';

nj_admin_run(['GET'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'list_users');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_user_id');
    }

    $pdo = nj_db();
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    user_nicename,
    user_url,
    display_name
FROM {$users}
WHERE ID = :id
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'user_not_found');
    }

    $definitions = nj_admin_role_definitions($pdo);
    $roles = [];

    foreach ($definitions as $key => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $roles[] = [
            'key' => (string) $key,
            'name' => (string) ($definition['name'] ?? $key),
        ];
    }

    $usermeta = nj_table('usermeta');
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $profileKeys = [
        'description',
        '_nj_public_bio',
        '_nj_public_role',
        '_nj_public_instagram',
        '_nj_public_facebook',
        '_nj_public_linkedin',
        '_nj_public_x',
    ];
    $placeholders = implode(',', array_fill(0, count($profileKeys), '?'));
    $profileStatement = $pdo->prepare(
        "SELECT meta_key, meta_value
         FROM {$usermeta}
         WHERE user_id = ? AND meta_key IN ({$placeholders})
         ORDER BY umeta_id DESC"
    );
    $profileStatement->execute(array_merge([$id], $profileKeys));

    $profileMeta = [];
    foreach ($profileStatement->fetchAll() as $profileRow) {
        $key = (string) $profileRow['meta_key'];
        if (!array_key_exists($key, $profileMeta)) {
            $profileMeta[$key] = (string) $profileRow['meta_value'];
        }
    }

    $publishedStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM {$posts} p
         WHERE
             (
                 p.post_author = :primary_author_id
                 OR EXISTS (
                     SELECT 1
                     FROM {$postmeta} coauthor_meta
                     WHERE
                         coauthor_meta.post_id = p.ID
                         AND coauthor_meta.meta_key = '_nj_coauthors'
                         AND FIND_IN_SET(
                             CAST(:coauthor_id AS CHAR) COLLATE utf8mb4_unicode_ci,
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
             AND p.post_name <> ''"
    );
    $publishedStatement->execute([
        'primary_author_id' => $id,
        'coauthor_id' => $id,
    ]);
    $publishedCount = (int) $publishedStatement->fetchColumn();
    $publicSlug = trim((string) ($row['user_nicename'] ?? ''));

    return [
        'user' => nj_admin_user_payload($pdo, $row),
        'publicProfile' => [
            'slug' => $publicSlug,
            'url' => $publicSlug !== '' && $publishedCount > 0
                ? '/autor/' . rawurlencode($publicSlug)
                : null,
            'publishedCount' => $publishedCount,
            'bio' => trim((string) ($profileMeta['_nj_public_bio'] ?? '')) !== ''
                ? (string) $profileMeta['_nj_public_bio']
                : (string) ($profileMeta['description'] ?? ''),
            'bioSource' => trim((string) ($profileMeta['_nj_public_bio'] ?? '')) !== ''
                ? 'nossojornal'
                : (isset($profileMeta['description']) ? 'wordpress' : 'empty'),
            'role' => (string) ($profileMeta['_nj_public_role'] ?? ''),
            'website' => (string) ($row['user_url'] ?? ''),
            'instagram' => (string) ($profileMeta['_nj_public_instagram'] ?? ''),
            'facebook' => (string) ($profileMeta['_nj_public_facebook'] ?? ''),
            'linkedin' => (string) ($profileMeta['_nj_public_linkedin'] ?? ''),
            'x' => (string) ($profileMeta['_nj_public_x'] ?? ''),
        ],
        'roles' => $roles,
        'canChangeRole' => in_array('promote_users', $currentUser['capabilities'], true)
            && (int) $currentUser['id'] !== $id
            && (string) $row['user_login'] !== NJ_PAUTAS_OWNER_LOGIN,
    ];
});
