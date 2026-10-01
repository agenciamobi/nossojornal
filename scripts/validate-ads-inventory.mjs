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
];
for (const [label, result] of checks) {
  assert.ok(result, label);
}
console.log('Advertising inventory smoke checks passed: ' + checks.length);
