-- Nosso Jornal advertising telemetry v2. Apply once using the Core's migration role
-- before deploying PHP that writes ad delivery tickets. Does not modify legacy ads.
CREATE TABLE IF NOT EXISTS njapp_ad_serves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  creative_id BIGINT UNSIGNED NOT NULL,
  placement_id BIGINT UNSIGNED NOT NULL,
  slot_id BIGINT UNSIGNED NOT NULL,
  device ENUM('desktop','mobile') NOT NULL,
  click_url VARCHAR(1500) NULL,
  served_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  impression_at DATETIME NULL,
  click_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY njapp_ad_serves_token_uq (token),
  KEY njapp_ad_serves_served_idx (served_at),
  KEY njapp_ad_serves_advertiser_idx (advertiser_id, served_at),
  KEY njapp_ad_serves_campaign_idx (campaign_id, served_at),
  KEY njapp_ad_serves_placement_idx (placement_id, served_at),
  KEY njapp_ad_serves_impression_idx (impression_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
