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
const phpRead = (path) => readFileSync(path, "utf8");
const video = phpRead("public/api/m2m/editorial/video-attach.php");
const security = phpRead("public/api/m2m/editorial/_video.php");
const library = phpRead("public/api/admin/media-item.php");
const librarySave = phpRead("public/api/admin/media-save.php");
const article = phpRead("public/api/v1/article.php");
for(const token of [
  "nj_m2m_run('POST', 'editorial.video.attach'",
  "video_draft_required",
  "video_limit_reached",
  "_nj_editorial_video_attachment_ids",
  "video/x-embed",
  "nj_admin_log_post_activity",
  "video_link_readback_failed"
]) assert.ok(video.includes(token), "Missing metadata-only video contract: " + token);
for(const token of [
  "nj_video_youtube_id", "youtube-nocookie.com",
  "video_source_mismatch", "RQUMlfUnPhc"
]) assert.ok(security.includes(token), "Missing allowed video source rule: " + token);
assert.ok(!video.includes("curl_exec") && !video.includes("file_get_contents"), "Video attachment must never download bytes");
for(const token of ["_nj_media_credit", "_nj_media_license", "_nj_media_seo_title", "_nj_media_seo_description"]) {
  assert.ok(library.includes(token) && librarySave.includes(token),
    "Both media editor endpoints must expose metadata: " + token);
}
assert.ok(article.includes("_nj_editorial_video_attachment_ids"), "Public article must hydrate video attachments");
assert.ok(article.includes("youtube-nocookie.com"), "Embed player must be canonical");
assert.ok(article.includes("featuredCredit"), "Featured image must use editable library credit");
console.log("Editorial media and URL-only video contracts OK");
