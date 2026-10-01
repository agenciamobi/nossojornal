<?php
declare(strict_types=1);
require __DIR__ . '/_admin.php';

// Aggregated advertiser-facing numbers only: no visitor identifiers are retained.
nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');
    $from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
    $to = (string) ($_GET['to'] ?? date('Y-m-d'));
    $parse = static function (string $value): DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new NjApiHttpException(422, 'invalid_report_range');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new NjApiHttpException(422, 'invalid_report_range');
        }
        return $date;
    };
    $first = $parse($from);
    $last = $parse($to);
    $days = (int) $first->diff($last)->format('%r%a');
    if ($days < 0 || $days > 91) {
        throw new NjApiHttpException(422, 'report_range_max_92_days');
    }
    $pdo = nj_db();
    $serves = nj_app_table('ad_serves');
    $advertisers = nj_app_table('advertisers');
    $campaigns = nj_app_table('ad_campaigns');
    $slots = nj_app_table('ad_slots');

    // The report groups on ticket delivery day. An impression is only counted
    // after IntersectionObserver reports visibility, not when the API selects an ad.
    $where = "sv.served_at >= :from_date AND sv.served_at < DATE_ADD(:to_date, INTERVAL 1 DAY)";
    $params = ['from_date' => $from, 'to_date' => $to];
    $sum = "COALESCE(SUM(CASE WHEN sv.impression_at IS NOT NULL THEN 1 ELSE 0 END), 0)";
    $clicks = "COALESCE(SUM(CASE WHEN sv.click_at IS NOT NULL THEN 1 ELSE 0 END), 0)";
    $query = static function (string $sql, array $parameters) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->fetchAll();
    };
    $summaryRows = $query(
        "SELECT {$sum} AS impressions, {$clicks} AS clicks
         FROM {$serves} sv WHERE {$where}",
        $params
    );
    $advertiserRows = $query(
        "SELECT a.id,a.name,{$sum} AS impressions,{$clicks} AS clicks
         FROM {$advertisers} a
         LEFT JOIN {$serves} sv ON sv.advertiser_id=a.id AND {$where}
         GROUP BY a.id,a.name ORDER BY impressions DESC,a.name ASC",
        $params
    );
    $campaignRows = $query(
        "SELECT c.id,c.name,c.status,a.name AS advertiser_name,
                {$sum} AS impressions,{$clicks} AS clicks
         FROM {$campaigns} c
         INNER JOIN {$advertisers} a ON a.id=c.advertiser_id
         LEFT JOIN {$serves} sv ON sv.campaign_id=c.id AND {$where}
         GROUP BY c.id,c.name,c.status,a.name ORDER BY impressions DESC,c.name ASC",
        $params
    );
    $slotRows = $query(
        "SELECT s.code,s.name,{$sum} AS impressions,{$clicks} AS clicks
         FROM {$slots} s
         LEFT JOIN {$serves} sv ON sv.slot_id=s.id AND {$where}
         GROUP BY s.id,s.code,s.name ORDER BY impressions DESC,s.name ASC",
        $params
    );
    $dailyRows = $query(
        "SELECT DATE(sv.served_at) AS day,{$sum} AS impressions,{$clicks} AS clicks
         FROM {$serves} sv WHERE {$where}
         GROUP BY DATE(sv.served_at) ORDER BY day ASC",
        $params
    );

    $metrics = static fn (array $row): array => [
        'impressions' => (int) $row['impressions'],
        'clicks' => (int) $row['clicks'],
    ];
    return [
        'from' => $from,
        'to' => $to,
        'summary' => $metrics($summaryRows[0] ?? ['impressions' => 0, 'clicks' => 0]),
        'advertisers' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ] + $metrics($row), $advertiserRows),
        'campaigns' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'advertiserName' => (string) $row['advertiser_name'],
        ] + $metrics($row), $campaignRows),
        'slots' => array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ] + $metrics($row), $slotRows),
        'daily' => array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
        ] + $metrics($row), $dailyRows),
    ];
});
