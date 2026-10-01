-- Nosso Jornal native advertising manager v1
-- Namespace njapp_* is reserved for the new application layer.

CREATE TABLE IF NOT EXISTS njapp_advertisers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  contact_name VARCHAR(190) NULL,
  email VARCHAR(254) NULL,
  phone VARCHAR(80) NULL,
  website_url VARCHAR(1000) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY njapp_advertisers_slug_uq (slug),
  KEY njapp_advertisers_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS njapp_ad_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  advertiser_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  status ENUM('draft','active','paused','ended') NOT NULL DEFAULT 'draft',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY njapp_ad_campaigns_advertiser_idx (advertiser_id),
  KEY njapp_ad_campaigns_delivery_idx (status, starts_at, ends_at, priority),
  CONSTRAINT njapp_ad_campaigns_advertiser_fk
    FOREIGN KEY (advertiser_id) REFERENCES njapp_advertisers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS njapp_ad_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(120) NOT NULL,
  name VARCHAR(190) NOT NULL,
  location VARCHAR(190) NOT NULL,
  description TEXT NULL,
  allowed_sizes TEXT NOT NULL,
  fallback_strategy ENUM('hide','header_message') NOT NULL DEFAULT 'hide',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY njapp_ad_slots_code_uq (code),
  KEY njapp_ad_slots_enabled_idx (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS njapp_ad_creatives (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  kind ENUM('image','html5') NOT NULL DEFAULT 'image',
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  image_url VARCHAR(1500) NULL,
  click_url VARCHAR(1500) NULL,
  alt_text VARCHAR(500) NULL,
  html MEDIUMTEXT NULL,
  css MEDIUMTEXT NULL,
  status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY njapp_ad_creatives_campaign_idx (campaign_id),
  KEY njapp_ad_creatives_status_idx (status),
  CONSTRAINT njapp_ad_creatives_campaign_fk
    FOREIGN KEY (campaign_id) REFERENCES njapp_ad_campaigns(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS njapp_ad_placements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  creative_id BIGINT UNSIGNED NOT NULL,
  slot_id BIGINT UNSIGNED NOT NULL,
  device ENUM('all','desktop','mobile') NOT NULL DEFAULT 'all',
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY njapp_ad_placements_delivery_idx (slot_id, status, device, starts_at, ends_at, priority),
  KEY njapp_ad_placements_campaign_idx (campaign_id),
  KEY njapp_ad_placements_creative_idx (creative_id),
  CONSTRAINT njapp_ad_placements_campaign_fk
    FOREIGN KEY (campaign_id) REFERENCES njapp_ad_campaigns(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT njapp_ad_placements_creative_fk
    FOREIGN KEY (creative_id) REFERENCES njapp_ad_creatives(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT njapp_ad_placements_slot_fk
    FOREIGN KEY (slot_id) REFERENCES njapp_ad_slots(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO njapp_ad_slots (
  code,
  name,
  location,
  description,
  allowed_sizes,
  fallback_strategy,
  enabled
)
VALUES (
  'header',
  'Header / Masthead',
  'Topo do portal, ao lado da marca',
  'Primeiro slot nativo. Quando não houver campanha ativa, preserva o texto institucional atual.',
  '970x90,728x90,468x60,300x100,300x50',
  'header_message',
  1
),
(
  'home-inline',
  'Capa / Entre destaques e últimas',
  'Capa, abaixo das notícias de destaque',
  'Publicidade separada dos cards editoriais da capa.',
  '728x90,468x60,300x250,250x250,300x100',
  'hide',
  1
),
(
  'article-inline',
  'Matéria / Após o conteúdo',
  'Matérias, após o texto e antes dos assuntos',
  'Publicidade após o conteúdo editorial, sem interromper a leitura.',
  '728x90,468x60,300x250,250x250,300x100',
  'hide',
  1
)
ON DUPLICATE KEY UPDATE
  code = VALUES(code); -- preserve administrator changes to existing slots
