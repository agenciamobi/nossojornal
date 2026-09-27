<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_AD_FORMATS = [
    ['width' => 970, 'height' => 90, 'label' => 'Large Leaderboard', 'device' => 'desktop'],
    ['width' => 728, 'height' => 90, 'label' => 'Leaderboard', 'device' => 'desktop'],
    ['width' => 468, 'height' => 60, 'label' => 'Banner', 'device' => 'desktop'],
    ['width' => 336, 'height' => 280, 'label' => 'Large Rectangle', 'device' => 'desktop'],
    ['width' => 300, 'height' => 600, 'label' => 'Half Page', 'device' => 'desktop'],
    ['width' => 300, 'height' => 250, 'label' => 'Medium Rectangle', 'device' => 'all'],
    ['width' => 160, 'height' => 600, 'label' => 'Wide Skyscraper', 'device' => 'desktop'],
    ['width' => 300, 'height' => 200, 'label' => 'Mobile Rectangle', 'device' => 'mobile'],
    ['width' => 300, 'height' => 100, 'label' => 'Large Mobile Banner', 'device' => 'mobile'],
    ['width' => 300, 'height' => 50, 'label' => 'Mobile Banner', 'device' => 'mobile'],
    ['width' => 250, 'height' => 250, 'label' => 'Square', 'device' => 'all'],
    ['width' => 200, 'height' => 200, 'label' => 'Small Square', 'device' => 'all'],
];

function nj_ads_string(array $body, string $key, int $max = 1000): string
{
    $value = trim((string) ($body[$key] ?? ''));
    if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > $max) {
        throw new NjApiHttpException(422, 'ad_field_too_large');
    }
    return $value;
}

function nj_ads_nullable_datetime(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') return null;

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new NjApiHttpException(422, 'invalid_ad_datetime');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function nj_ads_url(string $value, bool $allowRelative = false): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    if ($allowRelative && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return $value;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
        throw new NjApiHttpException(422, 'invalid_ad_url');
    }

    return $value;
}

function nj_ads_validate_markup(string $html, string $css): void
{
    if ((function_exists('mb_strlen') ? mb_strlen($html, 'UTF-8') : strlen($html)) > 120000) {
        throw new NjApiHttpException(422, 'ad_html_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($css, 'UTF-8') : strlen($css)) > 120000) {
        throw new NjApiHttpException(422, 'ad_css_too_large');
    }

    if (preg_match('/<\s*(script|iframe|object|embed|form|meta|link|base)\b/i', $html)) {
        throw new NjApiHttpException(422, 'unsafe_ad_html');
    }

    if (preg_match('/\bon[a-z]+\s*=|javascript\s*:/i', $html)) {
        throw new NjApiHttpException(422, 'unsafe_ad_html');
    }

    if (preg_match('/@import|expression\s*\(|javascript\s*:/i', $css)) {
        throw new NjApiHttpException(422, 'unsafe_ad_css');
    }
}

function nj_ads_slug(string $value): string
{
    $slug = nj_admin_slugify($value);
    if ($slug === '') {
        throw new NjApiHttpException(422, 'invalid_advertiser_slug');
    }
    return $slug;
}

function nj_ads_ensure_default_slots(PDO $pdo): void
{
    $slots = nj_app_table('ad_slots');
    $statement = $pdo->prepare("SELECT id FROM {$slots} WHERE code = :code LIMIT 1");
    $statement->execute(['code' => 'header']);

    if ($statement->fetchColumn() !== false) {
        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO {$slots}
            (code,name,location,description,allowed_sizes,fallback_strategy,enabled)
         VALUES
            (:code,:name,:location,:description,:allowed_sizes,:fallback_strategy,1)"
    );

    try {
        $insert->execute([
            'code' => 'header',
            'name' => 'Header / Masthead',
            'location' => 'Topo do portal, ao lado da marca',
            'description' => 'Primeiro slot nativo. Quando não houver campanha ativa, preserva o texto institucional atual.',
            'allowed_sizes' => '970x90,728x90,468x60,300x100,300x50',
            'fallback_strategy' => 'header_message',
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() !== '23000') {
            throw $error;
        }
    }
}

function nj_ads_snapshot(PDO $pdo): array
{
    $advertisers = nj_app_table('advertisers');
    $campaigns = nj_app_table('ad_campaigns');
    $slots = nj_app_table('ad_slots');
    $creatives = nj_app_table('ad_creatives');
    $placements = nj_app_table('ad_placements');

    $advertiserRows = $pdo->query(
        "SELECT id,name,slug,contact_name,email,phone,website_url,status,created_at,updated_at
         FROM {$advertisers} ORDER BY name ASC"
    )->fetchAll();

    $campaignRows = $pdo->query(
        "SELECT c.id,c.advertiser_id,c.name,c.status,c.starts_at,c.ends_at,c.priority,c.notes,c.created_at,c.updated_at,
                a.name AS advertiser_name
         FROM {$campaigns} c
         INNER JOIN {$advertisers} a ON a.id=c.advertiser_id
         ORDER BY c.updated_at DESC,c.id DESC"
    )->fetchAll();

    $slotRows = $pdo->query(
        "SELECT id,code,name,location,description,allowed_sizes,fallback_strategy,enabled,created_at,updated_at
         FROM {$slots} ORDER BY id ASC"
    )->fetchAll();

    $creativeRows = $pdo->query(
        "SELECT cr.id,cr.campaign_id,cr.name,cr.kind,cr.width,cr.height,cr.image_url,cr.click_url,cr.alt_text,
                cr.html,cr.css,cr.status,cr.created_at,cr.updated_at,c.name AS campaign_name
         FROM {$creatives} cr
         INNER JOIN {$campaigns} c ON c.id=cr.campaign_id
         ORDER BY cr.updated_at DESC,cr.id DESC"
    )->fetchAll();

    $placementRows = $pdo->query(
        "SELECT p.id,p.campaign_id,p.creative_id,p.slot_id,p.device,p.status,p.starts_at,p.ends_at,p.priority,
                p.created_at,p.updated_at,c.name AS campaign_name,cr.name AS creative_name,s.name AS slot_name,s.code AS slot_code
         FROM {$placements} p
         INNER JOIN {$campaigns} c ON c.id=p.campaign_id
         INNER JOIN {$creatives} cr ON cr.id=p.creative_id
         INNER JOIN {$slots} s ON s.id=p.slot_id
         ORDER BY p.priority DESC,p.updated_at DESC,p.id DESC"
    )->fetchAll();

    return [
        'advertisers' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'contactName' => (string) ($row['contact_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'websiteUrl' => (string) ($row['website_url'] ?? ''),
            'status' => (string) $row['status'],
        ], $advertiserRows),
        'campaigns' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'advertiserId' => (int) $row['advertiser_id'],
            'advertiserName' => (string) $row['advertiser_name'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'startsAt' => $row['starts_at'] ? (string) $row['starts_at'] : null,
            'endsAt' => $row['ends_at'] ? (string) $row['ends_at'] : null,
            'priority' => (int) $row['priority'],
            'notes' => (string) ($row['notes'] ?? ''),
        ], $campaignRows),
        'slots' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'location' => (string) $row['location'],
            'description' => (string) ($row['description'] ?? ''),
            'allowedSizes' => array_values(array_filter(array_map('trim', explode(',', (string) $row['allowed_sizes'])))),
            'fallbackStrategy' => (string) $row['fallback_strategy'],
            'enabled' => (bool) $row['enabled'],
        ], $slotRows),
        'creatives' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'campaignId' => (int) $row['campaign_id'],
            'campaignName' => (string) $row['campaign_name'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'imageUrl' => (string) ($row['image_url'] ?? ''),
            'clickUrl' => (string) ($row['click_url'] ?? ''),
            'altText' => (string) ($row['alt_text'] ?? ''),
            'html' => (string) ($row['html'] ?? ''),
            'css' => (string) ($row['css'] ?? ''),
            'status' => (string) $row['status'],
        ], $creativeRows),
        'placements' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'campaignId' => (int) $row['campaign_id'],
            'campaignName' => (string) $row['campaign_name'],
            'creativeId' => (int) $row['creative_id'],
            'creativeName' => (string) $row['creative_name'],
            'slotId' => (int) $row['slot_id'],
            'slotName' => (string) $row['slot_name'],
            'slotCode' => (string) $row['slot_code'],
            'device' => (string) $row['device'],
            'status' => (string) $row['status'],
            'startsAt' => $row['starts_at'] ? (string) $row['starts_at'] : null,
            'endsAt' => $row['ends_at'] ? (string) $row['ends_at'] : null,
            'priority' => (int) $row['priority'],
        ], $placementRows),
        'formats' => NJ_AD_FORMATS,
    ];
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');

    $pdo = nj_db();
    nj_ads_ensure_default_slots($pdo);

    if ($method === 'GET') {
        return nj_ads_snapshot($pdo);
    }

    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $entity = trim((string) ($body['entity'] ?? ''));
    $id = max(0, (int) ($body['id'] ?? 0));

    $advertisers = nj_app_table('advertisers');
    $campaigns = nj_app_table('ad_campaigns');
    $slots = nj_app_table('ad_slots');
    $creatives = nj_app_table('ad_creatives');
    $placements = nj_app_table('ad_placements');

    if ($entity === 'advertiser') {
        $name = nj_ads_string($body, 'name', 190);
        if ($name === '') throw new NjApiHttpException(422, 'advertiser_name_required');

        $slug = nj_ads_slug(nj_ads_string($body, 'slug', 190) ?: $name);
        $status = in_array(($body['status'] ?? ''), ['active', 'inactive'], true) ? (string) $body['status'] : 'active';
        $values = [
            'name' => $name,
            'slug' => $slug,
            'contact_name' => nj_ads_string($body, 'contactName', 190) ?: null,
            'email' => nj_ads_string($body, 'email', 254) ?: null,
            'phone' => nj_ads_string($body, 'phone', 80) ?: null,
            'website_url' => nj_ads_url(nj_ads_string($body, 'websiteUrl', 1000)),
            'status' => $status,
        ];

        if ($id > 0) {
            $statement = $pdo->prepare("UPDATE {$advertisers} SET name=:name,slug=:slug,contact_name=:contact_name,email=:email,phone=:phone,website_url=:website_url,status=:status WHERE id=:id");
            $statement->execute($values + ['id' => $id]);
        } else {
            $statement = $pdo->prepare("INSERT INTO {$advertisers} (name,slug,contact_name,email,phone,website_url,status) VALUES (:name,:slug,:contact_name,:email,:phone,:website_url,:status)");
            $statement->execute($values);
        }
    } elseif ($entity === 'campaign') {
        $advertiserId = max(0, (int) ($body['advertiserId'] ?? 0));
        $name = nj_ads_string($body, 'name', 190);
        if ($advertiserId <= 0 || $name === '') throw new NjApiHttpException(422, 'invalid_campaign');

        $status = in_array(($body['status'] ?? ''), ['draft', 'active', 'paused', 'ended'], true) ? (string) $body['status'] : 'draft';
        $priority = min(1000, max(0, (int) ($body['priority'] ?? 100)));
        $values = [
            'advertiser_id' => $advertiserId,
            'name' => $name,
            'status' => $status,
            'starts_at' => nj_ads_nullable_datetime($body['startsAt'] ?? null),
            'ends_at' => nj_ads_nullable_datetime($body['endsAt'] ?? null),
            'priority' => $priority,
            'notes' => nj_ads_string($body, 'notes', 10000) ?: null,
        ];

        if ($values['starts_at'] && $values['ends_at'] && $values['ends_at'] < $values['starts_at']) {
            throw new NjApiHttpException(422, 'invalid_campaign_window');
        }

        if ($id > 0) {
            $statement = $pdo->prepare("UPDATE {$campaigns} SET advertiser_id=:advertiser_id,name=:name,status=:status,starts_at=:starts_at,ends_at=:ends_at,priority=:priority,notes=:notes WHERE id=:id");
            $statement->execute($values + ['id' => $id]);
        } else {
            $values['created_by'] = (int) $user['id'];
            $statement = $pdo->prepare("INSERT INTO {$campaigns} (advertiser_id,name,status,starts_at,ends_at,priority,notes,created_by) VALUES (:advertiser_id,:name,:status,:starts_at,:ends_at,:priority,:notes,:created_by)");
            $statement->execute($values);
        }
    } elseif ($entity === 'slot') {
        $code = nj_ads_slug(nj_ads_string($body, 'code', 120));
        $name = nj_ads_string($body, 'name', 190);
        $location = nj_ads_string($body, 'location', 190);
        $allowedSizes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $size): string => preg_match('/^\d{2,4}x\d{2,4}$/', trim((string) $size)) ? trim((string) $size) : '',
            is_array($body['allowedSizes'] ?? null) ? $body['allowedSizes'] : []
        ))));

        if ($name === '' || $location === '' || $allowedSizes === []) throw new NjApiHttpException(422, 'invalid_ad_slot');

        $fallback = in_array(($body['fallbackStrategy'] ?? ''), ['hide', 'header_message'], true)
            ? (string) $body['fallbackStrategy']
            : 'hide';
        $values = [
            'code' => $code,
            'name' => $name,
            'location' => $location,
            'description' => nj_ads_string($body, 'description', 10000) ?: null,
            'allowed_sizes' => implode(',', $allowedSizes),
            'fallback_strategy' => $fallback,
            'enabled' => !empty($body['enabled']) ? 1 : 0,
        ];

        if ($id > 0) {
            $statement = $pdo->prepare("UPDATE {$slots} SET code=:code,name=:name,location=:location,description=:description,allowed_sizes=:allowed_sizes,fallback_strategy=:fallback_strategy,enabled=:enabled WHERE id=:id");
            $statement->execute($values + ['id' => $id]);
        } else {
            $statement = $pdo->prepare("INSERT INTO {$slots} (code,name,location,description,allowed_sizes,fallback_strategy,enabled) VALUES (:code,:name,:location,:description,:allowed_sizes,:fallback_strategy,:enabled)");
            $statement->execute($values);
        }
    } elseif ($entity === 'creative') {
        $campaignId = max(0, (int) ($body['campaignId'] ?? 0));
        $name = nj_ads_string($body, 'name', 190);
        $kind = in_array(($body['kind'] ?? ''), ['image', 'html5'], true) ? (string) $body['kind'] : 'image';
        $width = min(2000, max(1, (int) ($body['width'] ?? 0)));
        $height = min(2000, max(1, (int) ($body['height'] ?? 0)));
        $status = in_array(($body['status'] ?? ''), ['draft', 'active', 'paused'], true) ? (string) $body['status'] : 'draft';

        if ($campaignId <= 0 || $name === '' || $width <= 0 || $height <= 0) {
            throw new NjApiHttpException(422, 'invalid_ad_creative');
        }

        $imageUrl = nj_ads_url(nj_ads_string($body, 'imageUrl', 1500), true);
        $clickUrl = nj_ads_url(nj_ads_string($body, 'clickUrl', 1500));
        $html = (string) ($body['html'] ?? '');
        $css = (string) ($body['css'] ?? '');

        if ($kind === 'image' && $imageUrl === null) {
            throw new NjApiHttpException(422, 'creative_image_required');
        }

        if ($kind === 'html5') {
            nj_ads_validate_markup($html, $css);
            if (trim($html) === '') throw new NjApiHttpException(422, 'creative_html_required');
        }

        $values = [
            'campaign_id' => $campaignId,
            'name' => $name,
            'kind' => $kind,
            'width' => $width,
            'height' => $height,
            'image_url' => $kind === 'image' ? $imageUrl : null,
            'click_url' => $clickUrl,
            'alt_text' => nj_ads_string($body, 'altText', 500) ?: null,
            'html' => $kind === 'html5' ? $html : null,
            'css' => $kind === 'html5' ? $css : null,
            'status' => $status,
        ];

        if ($id > 0) {
            $statement = $pdo->prepare("UPDATE {$creatives} SET campaign_id=:campaign_id,name=:name,kind=:kind,width=:width,height=:height,image_url=:image_url,click_url=:click_url,alt_text=:alt_text,html=:html,css=:css,status=:status WHERE id=:id");
            $statement->execute($values + ['id' => $id]);
        } else {
            $statement = $pdo->prepare("INSERT INTO {$creatives} (campaign_id,name,kind,width,height,image_url,click_url,alt_text,html,css,status) VALUES (:campaign_id,:name,:kind,:width,:height,:image_url,:click_url,:alt_text,:html,:css,:status)");
            $statement->execute($values);
        }
    } elseif ($entity === 'placement') {
        $campaignId = max(0, (int) ($body['campaignId'] ?? 0));
        $creativeId = max(0, (int) ($body['creativeId'] ?? 0));
        $slotId = max(0, (int) ($body['slotId'] ?? 0));
        $device = in_array(($body['device'] ?? ''), ['all', 'desktop', 'mobile'], true) ? (string) $body['device'] : 'all';
        $status = in_array(($body['status'] ?? ''), ['active', 'paused'], true) ? (string) $body['status'] : 'active';
        $priority = min(1000, max(0, (int) ($body['priority'] ?? 100)));

        if ($campaignId <= 0 || $creativeId <= 0 || $slotId <= 0) {
            throw new NjApiHttpException(422, 'invalid_ad_placement');
        }

        $check = $pdo->prepare(
            "SELECT cr.width,cr.height,cr.campaign_id,s.allowed_sizes
             FROM {$creatives} cr CROSS JOIN {$slots} s
             WHERE cr.id=:creative_id AND s.id=:slot_id LIMIT 1"
        );
        $check->execute(['creative_id' => $creativeId, 'slot_id' => $slotId]);
        $match = $check->fetch();
        if (!$match || (int) $match['campaign_id'] !== $campaignId) {
            throw new NjApiHttpException(422, 'placement_campaign_mismatch');
        }

        $size = ((int) $match['width']) . 'x' . ((int) $match['height']);
        $allowed = array_map('trim', explode(',', (string) $match['allowed_sizes']));
        if (!in_array($size, $allowed, true)) {
            throw new NjApiHttpException(422, 'creative_size_not_allowed_for_slot');
        }

        $values = [
            'campaign_id' => $campaignId,
            'creative_id' => $creativeId,
            'slot_id' => $slotId,
            'device' => $device,
            'status' => $status,
            'starts_at' => nj_ads_nullable_datetime($body['startsAt'] ?? null),
            'ends_at' => nj_ads_nullable_datetime($body['endsAt'] ?? null),
            'priority' => $priority,
        ];

        if ($values['starts_at'] && $values['ends_at'] && $values['ends_at'] < $values['starts_at']) {
            throw new NjApiHttpException(422, 'invalid_placement_window');
        }

        if ($id > 0) {
            $statement = $pdo->prepare("UPDATE {$placements} SET campaign_id=:campaign_id,creative_id=:creative_id,slot_id=:slot_id,device=:device,status=:status,starts_at=:starts_at,ends_at=:ends_at,priority=:priority WHERE id=:id");
            $statement->execute($values + ['id' => $id]);
        } else {
            $statement = $pdo->prepare("INSERT INTO {$placements} (campaign_id,creative_id,slot_id,device,status,starts_at,ends_at,priority) VALUES (:campaign_id,:creative_id,:slot_id,:device,:status,:starts_at,:ends_at,:priority)");
            $statement->execute($values);
        }
    } else {
        throw new NjApiHttpException(422, 'invalid_ad_entity');
    }

    return nj_ads_snapshot($pdo);
});
