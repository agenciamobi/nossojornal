import { readFileSync } from "node:fs";

const read = (path) => readFileSync(path, "utf8");
const expect = (condition, message) => {
  if (!condition) throw new Error(message);
};

const m2m = read("public/api/m2m/editorial/_m2m.php");
const pautas = read("public/api/m2m/editorial/_pautas.php");
const draft = read("public/api/m2m/editorial/_draft.php");
const categoryPolicy = read("public/api/admin/_post_categories.php");
const taxonomyEndpoint = read("public/api/m2m/editorial/taxonomy.php");
const migration = read("database/editorial-m2m-m1.sql");

const endpoints = [
  "status.php",
  "pautas.php",
  "pauta.php",
  "pauta-claim.php",
  "pauta-resolve.php",
  "taxonomy.php",
  "draft-upsert-from-pauta.php",
];

for (const endpoint of endpoints) {
  read(`public/api/m2m/editorial/${endpoint}`);
}

expect(
  m2m.includes("nosso_jornal_editorial:core_to_provider:v1"),
  "Directional HMAC context must match MOBI Core.",
);
for (const header of [
  "X-Request-ID",
  "X-MOBI-Editorial-Timestamp",
  "X-MOBI-Editorial-Signature",
  "Idempotency-Key",
]) {
  expect(m2m.includes(header), `Missing M2M header contract: ${header}`);
}
expect(m2m.includes("request_replay"), "Replay protection is required.");
expect(m2m.includes("idempotency_conflict"), "Idempotency conflict protection is required.");
expect(
  migration.includes("njapp_editorial_m2m_replay")
    && migration.includes("njapp_editorial_m2m_idempotency"),
  "Technical M2M persistence must use njapp_* tables.",
);
expect(pautas.includes("_nj_mobi_claim_hash"), "Claims must be stored hashed.");
expect(pautas.includes("_nj_pauta_source_hash"), "Pauta source hash must remain authoritative.");
expect(draft.includes("source_hash_mismatch"), "Draft upsert must fail closed on source hash drift.");
expect(draft.includes("NJ_PROVENANCE_META_PAUTA_ID"), "Draft must inherit pauta provenance.");
expect(draft.includes("_nj_mobi_human_review_required"), "AI draft must require human review.");
expect(draft.includes("'ember'") && draft.includes("'mobi_core'"), "AI draft audit attribution is required.");
expect(\n  draft.includes("_post_categories.php") && draft.includes("nj_post_category_policy"),\n  "M2M drafts must use the same post category policy as the human editor.",\n);\nfor (const code of [\n  "technical_category_not_allowed",\n  "multiple_editorial_categories",\n  "multiple_regional_categories",\n]) {\n  expect(categoryPolicy.includes(code), `Missing category policy guard: ${code}`);\n}\nexpect(\n  taxonomyEndpoint.includes("nj_post_selectable_taxonomy"),\n  "M2M taxonomy must expose only selectable post categories.",\n);

const all = [m2m, pautas, draft, ...endpoints.map((name) => read(`public/api/m2m/editorial/${name}`))].join("\n");
expect(!all.includes("editorial.publish"), "M1 must not expose editorial publishing.");
expect(!all.includes("tasks"), "Editorial bridge must not create or depend on MOBI tasks.");

console.log("Editorial M2M contract OK");
