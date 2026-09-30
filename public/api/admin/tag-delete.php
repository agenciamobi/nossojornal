<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $tagId = filter_var(
        $body['tagId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($tagId) || $tagId <= 0) {
        throw new NjApiHttpException(422, 'invalid_tag_id');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $termmeta = nj_table('termmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT tt.term_taxonomy_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'post_tag'
WHERE t.term_id = :id
LIMIT 1
SQL);
    $statement->execute(['id' => $tagId]);
    $taxonomyId = (int) ($statement->fetchColumn() ?: 0);

    if ($taxonomyId <= 0) {
        throw new NjApiHttpException(404, 'tag_not_found');
    }

    try {
        $pdo->beginTransaction();

        $deleteRelationships = $pdo->prepare(
            "DELETE FROM {$relationships} WHERE term_taxonomy_id = :taxonomy_id"
        );
        $deleteRelationships->execute(['taxonomy_id' => $taxonomyId]);

        $deleteTaxonomy = $pdo->prepare(
            "DELETE FROM {$taxonomy} WHERE term_taxonomy_id = :taxonomy_id AND taxonomy = 'post_tag' LIMIT 1"
        );
        $deleteTaxonomy->execute(['taxonomy_id' => $taxonomyId]);

        $remainingTaxonomies = $pdo->prepare(
            "SELECT COUNT(*) FROM {$taxonomy} WHERE term_id = :term_id"
        );
        $remainingTaxonomies->execute(['term_id' => $tagId]);

        if ((int) $remainingTaxonomies->fetchColumn() === 0) {
            $deleteMeta = $pdo->prepare("DELETE FROM {$termmeta} WHERE term_id = :term_id");
            $deleteMeta->execute(['term_id' => $tagId]);

            $deleteTerm = $pdo->prepare("DELETE FROM {$terms} WHERE term_id = :term_id LIMIT 1");
            $deleteTerm->execute(['term_id' => $tagId]);
        }

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
            'id' => $tagId,
            'deleted' => true,
        ],
    ];
});
