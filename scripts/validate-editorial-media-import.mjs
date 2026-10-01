import { readFileSync } from "node:fs";
import assert from "node:assert/strict";

const file = readFileSync("public/api/m2m/editorial/media-import.php", "utf8");
const must = [
  "nj_m2m_run('POST', 'editorial.media.import'",
  "nj_remote_image_url",
  "nj_remote_source_page",
  "media_source_mismatch",
  "NJ_MEDIA_REMOTE_LIMIT = 8 * 1024 * 1024",
  "gethostbynamel",
  "FILTER_FLAG_NO_PRIV_RANGE",
  "FILTER_FLAG_NO_RES_RANGE",
  "CURLOPT_RESOLVE",
  "CURLOPT_FOLLOWLOCATION => false",
  "CURLOPT_SSL_VERIFYPEER => true",
  "CURLOPT_PROXY => ''",
  "CURLOPT_WRITEFUNCTION",
  "finfo(FILEINFO_MIME_TYPE)",
  "getimagesize",
  "hash_file('sha256'",
  "fopen($path, 'x')",
  "wp-content/uploads",
  "_wp_attachment_metadata",
  "_wp_attachment_image_alt",
  "_nj_remote_media_source_sha256",
  "_nj_remote_media_source_page",
  "_nj_media_credit",
  "_nj_image_credit",
  "_nj_image_caption",
  "nj_remote_existing_attachment",
  "existing_media_file_missing",
  "media_import_draft_required",
  "FOR UPDATE",
  "nj_admin_log_post_activity",
  "media_attachment_readback_failed",
  "media_featured_readback_failed",
];
for (const item of must) {
  assert.ok(file.includes(item), "Remote-media contract missing: " + item);
}
assert.ok(!file.includes("editorial.publish"), "Image import must not publish.");
assert.ok(!file.includes("CURLOPT_FOLLOWLOCATION => true"), "Redirects are forbidden.");
assert.ok(!file.includes("shell_exec"), "No arbitrary shell is permitted.");
assert.ok(!file.includes("file_get_contents($url)"), "Unbounded downloads are forbidden.");
console.log("Editorial media import contract OK");
