<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

nj_run(static function (): array {
    $slotCode = trim((string) ($_GET['slot'] ?? ''));
    $device = trim((string) ($_GET['device'] ?? 'desktop'));
    if (!preg_match('/^[a-z0-9-]{1,120}$/D', $slotCode)) {
        throw new NjApiHttpException(422, 'invalid_ad_slot');
    }
    if (!in_array($device, ['desktop', 'mobile'], true)) {
        $device = 'desktop';
    }

    $pdo = nj_db();
    $slots = nj_app_table('ad_slots');
    $placements = nj_app_table('ad_placements');
    $campaigns = nj_app_table('ad_campaigns');
    $creatives = nj_app_table('ad_creatives');
    $advertisers = nj_app_table('advertisers');
    $serves = nj_app_table('ad_serves');

    try {
        $slotStatement = $pdo->prepare(
            "SELECT id,code,name,allowed_sizes,fallback_strategy,enabled
             FROM {$slots} WHERE code=:code LIMIT 1"
        );
        $slotStatement->execute(['code' => $slotCode]);
        $slot = $slotStatement->fetch();
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '42S02') {
            return [
                'slot' => ['code' => $slotCode, 'fallbackStrategy' => $slotCode === 'header' ? 'header_message' : 'hide'],
                'ad' => null,
            ];
        }
        throw $error;
    }

    if (!$slot || !(bool) $slot['enabled']) {
        return [
            'slot' => ['code' => $slotCode, 'fallbackStrategy' => (string) ($slot['fallback_strategy'] ?? 'hide')],
            'ad' => null,
        ];
    }
    $slotInfo = [
        'code' => (string) $slot['code'],
        'name' => (string) $slot['name'],
        'allowedSizes' => array_values(array_filter(array_map('trim', explode(',', (string) $slot['allowed_sizes'])))),
        'fallbackStrategy' => (string) $slot['fallback_strategy'],
    ];

    // Eligibility is always checked on the server: statuses, dates, device, slot
    // format and optional image/HTML payload. Weight, not ORDER BY LIMIT 1,
    // prevents equal or lower-priority campaigns from being permanently starved.
    $statement = $pdo->prepare(
        "SELECT
           p.id AS placement_id,p.priority AS placement_priority,
           cr.id AS creative_id,cr.name AS creative_name,cr.kind,cr.width,cr.height,
           cr.image_url,cr.click_url,cr.alt_text,cr.html,cr.css,
           c.id AS campaign_id,c.name AS campaign_name,c.priority AS campaign_priority,
           a.id AS advertiser_id,a.name AS advertiser_name
         FROM {$placements} p
         INNER JOIN {$slots} s ON s.id=p.slot_id
         INNER JOIN {$campaigns} c ON c.id=p.campaign_id
         INNER JOIN {$creatives} cr ON cr.id=p.creative_id AND cr.campaign_id=c.id
         INNER JOIN {$advertisers} a ON a.id=c.advertiser_id
         WHERE p.slot_id=:slot_id
           AND s.enabled=1 AND p.status='active' AND p.device IN ('all', :device)
           AND (p.starts_at IS NULL OR p.starts_at <= NOW())
           AND (p.ends_at IS NULL OR p.ends_at >= NOW())
           AND c.status='active'
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at >= NOW())
           AND cr.status='active' AND a.status='active'
           AND FIND_IN_SET(CONCAT(cr.width,'x',cr.height),s.allowed_sizes)>0
           AND ((cr.kind='image' AND cr.image_url IS NOT NULL AND cr.image_url<>'')
                OR (cr.kind='html5' AND cr.html IS NOT NULL AND cr.html<>''))
         ORDER BY p.priority DESC,c.priority DESC,p.id ASC LIMIT 200"
    );
    $statement->execute(['slot_id' => (int) $slot['id'], 'device' => $device]);
    $candidates = $statement->fetchAll();
    if ($candidates === []) return ['slot' => $slotInfo, 'ad' => null];

    $total = 0;
    foreach ($candidates as &$candidate) {
        // Both priorities contribute. Priority 0 retains a small nonzero chance.
        $candidate['weight'] = (max(0, (int) $candidate['placement_priority']) + 1)
            * (max(0, (int) $candidate['campaign_priority']) + 1);
        $total += $candidate['weight'];
    }
    unset($candidate);
    $draw = random_int(1, $total);
    $row = $candidates[0];
    foreach ($candidates as $candidate) {
        $draw -= $candidate['weight'];
        if ($draw <= 0) {
            $row = $candidate;
            break;
        }
    }

    $token = bin2hex(random_bytes(16));
    $ticket = $pdo->prepare(
        "INSERT INTO {$serves}
         (token,advertiser_id,campaign_id,creative_id,placement_id,slot_id,device,click_url,expires_at)
         VALUES (:token,:advertiser_id,:campaign_id,:creative_id,:placement_id,:slot_id,:device,:click_url,DATE_ADD(NOW(),INTERVAL 2 HOUR))"
    );
    $ticket->execute([
        'token' => $token,
        'advertiser_id' => (int) $row['advertiser_id'],
        'campaign_id' => (int) $row['campaign_id'],
        'creative_id' => (int) $row['creative_id'],
        'placement_id' => (int) $row['placement_id'],
        'slot_id' => (int) $slot['id'],
        'device' => $device,
        'click_url' => $row['click_url'],
    ]);

    return [
        'slot' => $slotInfo,
        'ad' => [
            'token' => $token,
            'placementId' => (int) $row['placement_id'],
            'campaignId' => (int) $row['campaign_id'],
            'campaignName' => (string) $row['campaign_name'],
            'creativeId' => (int) $row['creative_id'],
            'name' => (string) $row['creative_name'],
            'kind' => (string) $row['kind'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'imageUrl' => (string) ($row['image_url'] ?? ''),
            'clickUrl' => (string) ($row['click_url'] ?? ''),
            'altText' => (string) ($row['alt_text'] ?? ''),
            'html' => (string) ($row['html'] ?? ''),
            'css' => (string) ($row['css'] ?? ''),
            'advertiser' => [
                'id' => (int) $row['advertiser_id'],
                'name' => (string) $row['advertiser_name'],
            ],
        ],
    ];
}, 'private, no-store, max-age=0');
