<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $name = trim((string) ($body['name'] ?? ''));
    $slug = nj_admin_slugify((string) ($body['slug'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));

    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 200) {
        throw new NjApiHttpException(422, 'invalid_tag_name');
    }

    if ($slug === '') {
        $slug = nj_admin_slugify($name);
    }

    if ($slug === '') {
        throw new NjApiHttpException(422, 'invalid_tag_slug');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 20000) {
        throw new NjApiHttpException(422, 'tag_description_too_large');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $duplicate = $pdo->prepare("SELECT term_id FROM {$terms} WHERE slug = :slug LIMIT 1");
    $duplicate->execute(['slug' => $slug]);

    if ($duplicate->fetchColumn()) {
        throw new NjApiHttpException(409, 'tag_slug_exists');
    }

    try {
        $pdo->beginTransaction();

        $insertTerm = $pdo->prepare(
            "INSERT INTO {$terms} (name, slug, term_group) VALUES (:name, :slug, 0)"
        );
        $insertTerm->execute([
            'name' => $name,
            'slug' => $slug,
        ]);

        $termId = (int) $pdo->lastInsertId();
        if ($termId <= 0) {
            throw new RuntimeException('tag_insert_missing_id');
        }

        $insertTaxonomy = $pdo->prepare(<<<SQL
INSERT INTO {$taxonomy} (term_id, taxonomy, description, parent, count)
VALUES (:term_id, 'post_tag', :description, 0, 0)
SQL);
        $insertTaxonomy->execute([
            'term_id' => $termId,
            'description' => $description,
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
        'tag' => [
            'id' => $termId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'count' => 0,
        ],
    ];
});
