<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_category_theme.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $name = trim((string) ($body['name'] ?? ''));
    $slug = nj_admin_slugify((string) ($body['slug'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $parentRaw = $body['parentId'] ?? null;
    $parentId = $parentRaw === null || $parentRaw === '' ? null : (int) $parentRaw;
    $color = nj_category_validate_color((string) ($body['color'] ?? ''));

    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 200) {
        throw new NjApiHttpException(422, 'invalid_category_name');
    }

    if ($slug === '') {
        $slug = nj_admin_slugify($name);
    }

    if ($slug === '') {
        throw new NjApiHttpException(422, 'invalid_category_slug');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 20000) {
        throw new NjApiHttpException(422, 'category_description_too_large');
    }

    if ($color === null) {
        throw new NjApiHttpException(422, 'invalid_category_color');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $termmeta = nj_table('termmeta');

    $duplicate = $pdo->prepare("SELECT term_id FROM {$terms} WHERE slug = :slug LIMIT 1");
    $duplicate->execute(['slug' => $slug]);

    if ($duplicate->fetchColumn()) {
        throw new NjApiHttpException(409, 'category_slug_exists');
    }

    nj_admin_validate_category_parent($pdo, 0, $parentId);

    try {
        $pdo->beginTransaction();

        $insertTerm = $pdo->prepare(<<<SQL
INSERT INTO {$terms} (name, slug, term_group)
VALUES (:name, :slug, 0)
SQL);
        $insertTerm->execute([
            'name' => $name,
            'slug' => $slug,
        ]);

        $termId = (int) $pdo->lastInsertId();

        if ($termId <= 0) {
            throw new RuntimeException('category_insert_missing_id');
        }

        $insertTaxonomy = $pdo->prepare(<<<SQL
INSERT INTO {$taxonomy} (term_id, taxonomy, description, parent, count)
VALUES (:term_id, 'category', :description, :parent_id, 0)
SQL);
        $insertTaxonomy->execute([
            'term_id' => $termId,
            'description' => $description,
            'parent_id' => $parentId ?? 0,
        ]);

        $insertColor = $pdo->prepare(<<<SQL
INSERT INTO {$termmeta} (term_id, meta_key, meta_value)
VALUES (:term_id, :meta_key, :color)
SQL);
        $insertColor->execute([
            'term_id' => $termId,
            'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
            'color' => $color,
        ]);

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string) $error->getCode() === '42000') {
            throw new NjApiHttpException(409, 'database_write_unavailable');
        }

        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return [
        'category' => [
            'id' => $termId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parentId' => $parentId,
            'count' => 0,
            'color' => $color,
            'colorSource' => 'termmeta',
            'publicUrl' => '/categoria/' . rawurlencode($slug),
            'adminUrl' => '/sistema/categorias/' . $termId,
        ],
    ];
});
