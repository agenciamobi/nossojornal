-- Nosso Jornal Editorial M2M bridge M1
-- Technical-only persistence for replay protection and idempotent writes.
-- Editorial authority remains in njsite_posts / njsite_postmeta.

CREATE TABLE IF NOT EXISTS njapp_editorial_m2m_replay (
  request_id VARCHAR(128) NOT NULL,
  operation VARCHAR(96) NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (request_id),
  KEY idx_editorial_m2m_replay_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS njapp_editorial_m2m_idempotency (
  operation VARCHAR(96) NOT NULL,
  idempotency_key_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  state ENUM('processing','succeeded') NOT NULL DEFAULT 'processing',
  response_status SMALLINT UNSIGNED DEFAULT NULL,
  response_json MEDIUMTEXT DEFAULT NULL,
  request_id VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (operation, idempotency_key_hash),
  KEY idx_editorial_m2m_idempotency_updated (updated_at),
  KEY idx_editorial_m2m_idempotency_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
