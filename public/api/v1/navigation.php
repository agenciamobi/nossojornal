<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

function nj_navigation_text(string $value): string
{
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function nj_navigation_role(string $name, string $slug): string
{
    $value = $name . ' ' . $slug;

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = strtolower($ascii);
        }
    }

    if (preg_match('/\b(footer|rodape)\b/', $value) === 1) {
        return 'footer';
    }

    if (preg_match('/\b(utility|institucional|topo|superior|secondary|secundario)\b/', $value) === 1) {
        return 'utility';
    }

    if (preg_match('/\b(primary|principal|main|header|cabecalho|editorias)\b/', $value) === 1) {
        return 'primary';
    }

    return 'other';
}

function nj_navigation_custom_url(string $value): ?array
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '') {
        return null;
    }

    if (str_starts_with($value, '/')) {
        return [
            'url' => $value,
            'external' => false,
        ];
    }

    if (str_starts_with($value, '#')) {
        return [
            'url' => $value,
            'external' => false,
        ];
    }

    if (preg_match('/^(mailto|tel):/i', $value) === 1) {
        return [
            'url' => $value,
            'external' => true,
        ];
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return null;
    }

    if (in_array($host, ['nossojornal.com.br', 'www.nossojornal.com.br'], true)) {
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return [
            'url' => $path . $query . $fragment,
            'external' => false,
        ];
    }

    return [
        'url' => $value,
        'external' => true,
    ];
}

function nj_navigation_tree(array $items): array
{
    $validIds = [];
    foreach ($items as $item) {
        $validIds[(int) $item['id']] = true;
    }

    foreach ($items as &$item) {
        $parentId = (int) ($item['parentId'] ?? 0);
        if (
            $parentId <= 0
            || $parentId === (int) $item['id']
            || !isset($validIds[$parentId])
        ) {
            $item['parentId'] = null;
        }
    }
    unset($item);

    $byParent = [];
    foreach ($items as $item) {
        $key = (int) ($item['parentId'] ?? 0);
        $byParent[$key][] = $item;
    }

    $walk = static function (int $parentId, int $depth, array $trail) use (&$walk, $byParent): array {
        if ($depth > 5) {
            return [];
        }

        $children = [];

        foreach ($byParent[$parentId] ?? [] as $item) {
            $id = (int) $item['id'];
            if (isset($trail[$id])) {
                continue;
            }

            $nextTrail = $trail;
            $nextTrail[$id] = true;
            $item['children'] = $walk($id, $depth + 1, $nextTrail);
            $children[] = $item;
        }

        return $children;
    };

    return $walk(0, 0, []);
}

nj_run(static function (): array {
    $pdo = nj_db();

    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');

    $menuStatement = $pdo->query(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug,
    tt.term_taxonomy_id AS taxonomy_id,
    tt.count AS legacy_count
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'nav_menu'
ORDER BY t.name ASC, t.term_id ASC
SQL);

    $menuRows = $menuStatement->fetchAll();

    if ($menuRows === []) {
        return [
            'menus' => [],
            'summary' => [
                'totalMenus' => 0,
                'totalItems' => 0,
                'primaryAvailable' => false,
                'utilityAvailable' => false,
                'footerAvailable' => false,
            ],
        ];
    }

    $itemStatement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title,
    p.menu_order,
    tt.term_id AS menu_id,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_type'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS item_type,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_object'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS item_object,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_object_id'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS item_object_id,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_url'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS item_url,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_target'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS item_target,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_menu_item_menu_item_parent'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS item_parent
FROM {$posts} p
INNER JOIN {$relationships} tr
    ON tr.object_id = p.ID
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'nav_menu'
WHERE
    p.post_type = 'nav_menu_item'
    AND p.post_status = 'publish'
ORDER BY tt.term_id ASC, p.menu_order ASC, p.ID ASC
SQL);

    $itemRows = $itemStatement->fetchAll();

    $postObjectIds = [];
    $termObjectIds = [];

    foreach ($itemRows as $row) {
        $objectId = max(0, (int) $row['item_object_id']);

        if ((string) $row['item_type'] === 'post_type' && $objectId > 0) {
            $postObjectIds[$objectId] = true;
        }

        if ((string) $row['item_type'] === 'taxonomy' && $objectId > 0) {
            $termObjectIds[$objectId] = true;
        }
    }

    $postObjects = [];
    if ($postObjectIds !== []) {
        $ids = array_keys($postObjectIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(<<<SQL
SELECT ID, post_type, post_status, post_title, post_name
FROM {$posts}
WHERE ID IN ({$placeholders})
SQL);
        $statement->execute($ids);

        foreach ($statement->fetchAll() as $row) {
            $postObjects[(int) $row['ID']] = $row;
        }
    }

    $termObjects = [];
    if ($termObjectIds !== []) {
        $ids = array_keys($termObjectIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(<<<SQL
SELECT
    t.term_id,
    t.name,
    t.slug,
    tt.taxonomy
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
WHERE t.term_id IN ({$placeholders})
SQL);
        $statement->execute($ids);

        foreach ($statement->fetchAll() as $row) {
            $termObjects[(int) $row['term_id']][] = $row;
        }
    }

    $itemsByMenu = [];
    $totalItems = 0;

    foreach ($itemRows as $row) {
        $id = (int) $row['id'];
        $menuId = (int) $row['menu_id'];
        $type = (string) $row['item_type'];
        $object = (string) $row['item_object'];
        $objectId = max(0, (int) $row['item_object_id']);
        $title = nj_navigation_text((string) $row['post_title']);
        $url = null;
        $external = false;
        $kind = 'custom';

        if ($type === 'custom') {
            $resolved = nj_navigation_custom_url((string) $row['item_url']);
            if ($resolved === null) {
                continue;
            }

            $url = (string) $resolved['url'];
            $external = (bool) $resolved['external'];
        } elseif ($type === 'post_type') {
            $target = $postObjects[$objectId] ?? null;
            if (!is_array($target) || (string) $target['post_status'] !== 'publish') {
                continue;
            }

            $slug = trim((string) $target['post_name']);
            if ($slug === '') {
                continue;
            }

            $postType = (string) $target['post_type'];
            if ($postType === 'post') {
                $url = '/noticia/' . rawurlencode($slug);
                $kind = 'post';
            } elseif ($postType === 'page') {
                $url = '/' . rawurlencode($slug);
                $kind = 'page';
            } else {
                continue;
            }

            if ($title === '') {
                $title = nj_navigation_text((string) $target['post_title']);
            }
        } elseif ($type === 'taxonomy') {
            $termCandidates = $termObjects[$objectId] ?? [];
            $term = null;

            foreach ($termCandidates as $candidate) {
                if ((string) $candidate['taxonomy'] === $object) {
                    $term = $candidate;
                    break;
                }
            }

            if (!is_array($term)) {
                continue;
            }

            $slug = trim((string) $term['slug']);
            if ($slug === '') {
                continue;
            }

            if ($object === 'category') {
                $url = '/categoria/' . rawurlencode($slug);
                $kind = 'category';
            } elseif ($object === 'post_tag') {
                $url = '/tag/' . rawurlencode($slug);
                $kind = 'tag';
            } else {
                continue;
            }

            if ($title === '') {
                $title = nj_navigation_text((string) $term['name']);
            }
        } else {
            continue;
        }

        if ($title === '' || !is_string($url) || $url === '') {
            continue;
        }

        $itemsByMenu[$menuId][] = [
            'id' => $id,
            'parentId' => max(0, (int) $row['item_parent']) ?: null,
            'title' => $title,
            'url' => $url,
            'target' => (string) $row['item_target'] === '_blank' ? '_blank' : '',
            'external' => $external,
            'kind' => $kind,
            'object' => $object,
            'objectId' => $objectId > 0 ? $objectId : null,
            'order' => (int) $row['menu_order'],
        ];
        $totalItems++;
    }

    $menus = [];
    foreach ($menuRows as $row) {
        $id = (int) $row['id'];
        $items = $itemsByMenu[$id] ?? [];

        $menus[] = [
            'id' => $id,
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => nj_navigation_text((string) $row['name']),
            'slug' => (string) $row['slug'],
            'role' => nj_navigation_role((string) $row['name'], (string) $row['slug']),
            'itemCount' => count($items),
            'items' => nj_navigation_tree($items),
        ];
    }

    $primaryExists = false;
    foreach ($menus as $menu) {
        if ($menu['role'] === 'primary' && $menu['itemCount'] > 0) {
            $primaryExists = true;
            break;
        }
    }

    if (!$primaryExists) {
        $nonEmptyIndexes = [];
        foreach ($menus as $index => $menu) {
            if ($menu['itemCount'] >= 3) {
                $nonEmptyIndexes[] = $index;
            }
        }

        if (count($nonEmptyIndexes) === 1) {
            $menus[$nonEmptyIndexes[0]]['role'] = 'primary';
            $primaryExists = true;
        }
    }

    $roleAvailable = static function (array $menus, string $role): bool {
        foreach ($menus as $menu) {
            if ($menu['role'] === $role && $menu['itemCount'] > 0) {
                return true;
            }
        }

        return false;
    };

    return [
        'menus' => $menus,
        'summary' => [
            'totalMenus' => count($menus),
            'totalItems' => $totalItems,
            'primaryAvailable' => $primaryExists,
            'utilityAvailable' => $roleAvailable($menus, 'utility'),
            'footerAvailable' => $roleAvailable($menus, 'footer'),
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
