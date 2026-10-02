// Lightweight CI regression guard for the first two optional advertising surfaces.
// This is a source-integration smoke check, not a substitute for a database/API E2E test.
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const file = (path) => readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const checks = [
  ['homepage unit is mounted', file('src/App.tsx').includes('<AdSlot slot="home-inline"')],
  ['article unit is mounted', file('src/InternalPages.tsx').includes('<AdSlot slot="article-inline"')],
  ['home default available in existing installs', file('public/api/admin/ads.php').includes("'code' => 'home-inline'")],
  ['article default available in existing installs', file('public/api/admin/ads.php').includes("'code' => 'article-inline'")],
  ['home default available in new installs', file('database/ads-v1.sql').includes("'home-inline'")],
  ['article default available in new installs', file('database/ads-v1.sql').includes("'article-inline'")],
  ['public delivery API filters active campaigns', file('public/api/v1/ads.php').includes("c.status='active'")],
  ['public delivery API filters active creatives', file('public/api/v1/ads.php').includes("cr.status='active'")],
  ['server validates placement format', file('public/api/admin/ads.php').includes('creative_size_not_allowed_for_slot')],
  ['admin filters compatible positions', file('src/AdminAds.tsx').includes("item.allowedSizes.includes(creativeSize)")],
  ['admin explains delivery readiness', file('src/AdminAds.tsx').includes('Elegível para exibição')],
  ['image chooser uses existing media API', file('src/AdminAds.tsx').includes('/api/admin/media.php')],
  ['media chooser has image MIME filter', file('src/AdminAds.tsx').includes("item.mimeType.startsWith('image/')")],
  ['native reporting route is wired', file('src/AdminAds.tsx').includes('/api/admin/ads-report.php')],
  ['delivery uses active slot and valid formats', file('public/api/v1/ads.php').includes('FIND_IN_SET')],
  ['delivery is not cached', file('public/api/v1/ads.php').includes('private, no-store')],
  ['rotating units refresh in visible tabs', file('src/AdSlot.tsx').includes('setInterval(rotate, 60000)')],
  ['impressions require client visibility', file('src/AdSlot.tsx').includes('IntersectionObserver')],
  ['impressions deduplicated by token in SQL', file('public/api/v1/ad-event.php').includes('impression_at IS NULL')],
  ['click targets read only from server', file('public/api/v1/ad-click.php').includes('SELECT click_url')],
  ['clicks deduplicated by token in SQL', file('public/api/v1/ad-click.php').includes('click_at IS NULL')],
  ['reports require admin capability', file('public/api/admin/ads-report.php').includes("nj_admin_require_capability($user, 'manage_options')")],
  ['telemetry table has unique tickets', file('database/ads-v2.sql').includes('UNIQUE KEY njapp_ad_serves_token_uq')],
  ['quick publication atomic transaction', file('public/api/admin/ads.php').includes("if ($entity === 'quick_banner')") && file('public/api/admin/ads.php').includes('$pdo->beginTransaction()')],
  ['quick publication derives image from verified library media', file('public/api/admin/ads.php').includes("p.post_type='attachment'") && file('public/api/admin/ads.php').includes('nj_media_descriptor')],
  ['quick publication rejects invalid slot', file('public/api/admin/ads.php').includes('quick_banner_slot_incompatible')],
  ['quick banner rejects extreme image ratio', file('public/api/admin/ads.php').includes('quick_banner_image_ratio') && file('src/AdminAds.tsx').includes('severeRatioMismatch')],
  ['quick publication front-end default', file('src/AdminAds.tsx').includes("('quick')")],
  ['quick form media uploader', file('src/AdminAds.tsx').includes('/api/admin/media-upload.php')],
  ['quick form avoids nested media form', file('src/AdminAds.tsx').includes('ads-media-picker__search')],
  ['header creative frame is constrained to 468x60', file('src/ads.css').includes('max-width: 468px') && file('src/ads.css').includes('height: 60px;')],
  ['header never stretches ad across available masthead width', file('src/ads.css').includes('flex: 0 1 468px')],
  ['masthead no longer reserves oversized blank space', file('src/styles.css').includes('min-height: 100px') && file('src/styles.css').includes('padding-block: 16px')],
  ['mobile creative retains own bounded height', file('src/ads.css').includes('height: min(100px, calc(var(--ad-height) * 1px))')],
  ['quick publishing defaults to compact header banner', file('src/AdminAds.tsx').includes("size: '468x60'") && file('src/AdminAds.tsx').includes("device === 'mobile' ? '300x100' : '468x60'")],


];
for (const [label, result] of checks) {
  assert.ok(result, label);
}
console.log('Advertising inventory smoke checks passed: ' + checks.length);
