import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = (name) => readFileSync(name, 'utf8');
const providerRuntime = source('public/api/m2m/editorial/_m2m.php');
const providerStatus = source('public/api/m2m/editorial/status.php');
assert.ok(providerRuntime.includes("const NJ_EDITORIAL_M2M_VERSION = 'm2m-editorial@2026-10-02-r2'"), 'M3 must advertise its own provider revision');
assert.ok(providerStatus.includes("'version' => NJ_EDITORIAL_M2M_VERSION"), 'integration.status must expose actual provider version');
const upload = source('public/api/m2m/editorial/media-generated-upload.php');
const featured = source('public/api/m2m/editorial/post-featured-set.php');
const publish = source('public/api/m2m/editorial/post-publish.php');
const review = source('public/api/admin/post-review-approve.php');
const adminPost = source('public/api/admin/post.php');
const adminStatus = source('public/api/admin/post-status.php');
const adminUi = source('src/AdminApp.tsx');

for (const [file, body, required] of [
  ['generated upload', upload, [
    "nj_m2m_run('POST', 'editorial.media.generated.upload'",
    'NJ_AI_CHUNK_BYTES = 65536', 'NJ_AI_MAX_BYTES = 8 * 1024 * 1024',
    'nj_ai_stage_root()', "sys_get_temp_dir()", "mkdir($dir, 0700)",
    'flock($lock, LOCK_EX)', "base64_decode($encoded, true)",
    "hash('sha256', $bytes)", "hash_file('sha256', $assembled)",
    "new finfo(FILEINFO_MIME_TYPE)", "getimagesize($assembled)",
    "fopen($path, 'x')", "'_wp_attached_file'", "'_wp_attachment_image_alt'",
    "'_nj_ai_generated_model'", "'_nj_ai_prompt_summary'",
    "'_nj_ai_media_file_sha256'", "'_nj_media_credit'",
    "'_thumbnail_id'", "'generated_featured_readback_failed'",
    "post_status = 'draft' LIMIT 1 FOR UPDATE",
  ]],
  ['featured set', featured, [
    "nj_m2m_run('POST', 'editorial.post.featured.set'",
    "post_parent = :post", "post_status = 'draft'",
    "'_thumbnail_id'", "'featured_readback_failed'",
  ]],
  ['guarded publication', publish, [
    "nj_m2m_run('POST', 'editorial.post.publish'",
    "'confirm_publish'", "'publish_human_review_required'",
    "'_nj_mobi_human_review_required'", "'publish_category_required'",
    "'publish_featured_image_required'", "post_status = 'draft'",
    "nj_admin_recount_categories", "'publish_readback_failed'",
  ]],
  ['human approval', review, [
    'nj_admin_require_csrf()', "nj_admin_require_capability($user, 'publish_posts')",
    "'confirmReviewed'", "'_nj_mobi_human_review_required'",
    "'_nj_mobi_reviewed_by'", "'review_readback_failed'",
  ]],
]) {
  for (const marker of required) {
    assert.ok(body.includes(marker), `${file}: missing ${marker}`);
  }
}
for (const marker of ["'_nj_mobi_human_review_required'", "'reviewRequired'"]) {
  assert.ok(adminPost.includes(marker), `post detail lacks ${marker}`);
}
for (const marker of ["'human_review_required'", "'publish', 'schedule'"]) {
  assert.ok(adminStatus.includes(marker), `admin status lacks review gate: ${marker}`);
}
for (const marker of [
  'approveHumanReview()', 'post-review-approve.php', 'post.reviewRequired',
  'Confirmar revisão editorial', 'publicationActionDisabled || !user.permissions.publishPosts || post.reviewRequired',
]) {
  assert.ok(adminUi.includes(marker), `editor missing explicit review control: ${marker}`);
}
const finalReceipt = upload.slice(upload.indexOf("$finish = true;"));
assert.ok(finalReceipt.includes("'upload_id' => $uploadId"),
  'finalized generated upload must return the upload_id expected by MOBI Core');
assert.ok(!upload.includes('CURLOPT_'), 'generated media must not download arbitrary remote URLs');
assert.ok(!upload.includes('shell_exec('), 'generated media must not execute shell');
assert.ok(!publish.includes('nj_admin_run('), 'M2M publishing must not borrow browser admin sessions');
console.log('M3 provider contract: private chunked media, review, featured attachment and publication gates OK');
