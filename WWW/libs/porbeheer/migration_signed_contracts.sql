-- =====================================================================
-- Porbeheer - Migratie: getekende contracten op filesystem + QR-upload
-- Non-destructief. Bestaande key_contracts.contract_pdf (blob) blijft
-- bestaan en werken (backwards compatible).
--
-- Te draaien op: porbeheer (MySQL 8)
-- Veilig opnieuw uit te voeren (IF NOT EXISTS).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Eenmalige upload-tokens voor de QR-/code-gebaseerde upload
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `key_contract_upload_tokens` (
  `id`                 int           NOT NULL AUTO_INCREMENT,
  `key_contract_id`    int           NOT NULL,
  `token`              char(64)      COLLATE utf8mb4_unicode_ci NOT NULL,  -- in QR / URL
  `short_code`         varchar(12)   COLLATE utf8mb4_unicode_ci NOT NULL,  -- met de hand in te typen
  `expires_at`         datetime      NOT NULL,
  `used_at`            datetime      DEFAULT NULL,
  `created_at`         datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_user_id` int           DEFAULT NULL,
  `ip`                 varchar(45)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_uptok_contract` (`key_contract_id`),
  KEY `idx_uptok_expires` (`expires_at`),
  KEY `idx_uptok_shortcode` (`short_code`),
  CONSTRAINT `fk_uptok_contract`
    FOREIGN KEY (`key_contract_id`) REFERENCES `key_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uptok_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Documenten (gegenereerde en getekende/gescande contracten) op
--    het filesystem. stored_path is RELATIEF t.o.v. de opslagmap.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `key_contract_documents` (
  `id`                  int           NOT NULL AUTO_INCREMENT,
  `key_contract_id`     int           NOT NULL,
  `kind`                enum('GENERATED','SIGNED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SIGNED',
  `source`              enum('ADMIN_UPLOAD','QR_UPLOAD','GENERATED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ADMIN_UPLOAD',
  `original_name`       varchar(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_path`         varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,   -- relatief, bv "42/20260614_171000_ab12cd.pdf"
  `mime_type`           varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/pdf',
  `size_bytes`          int           NOT NULL DEFAULT 0,
  `sha256`              char(64)      COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_count`          int           DEFAULT NULL,
  `uploaded_at`         datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by_user_id` int           DEFAULT NULL,   -- NULL bij publieke QR-upload
  `upload_token_id`     int           DEFAULT NULL,
  `is_current`          tinyint(1)    NOT NULL DEFAULT 1,
  `deleted_at`          datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kcdoc_contract` (`key_contract_id`),
  KEY `idx_kcdoc_kind` (`kind`),
  KEY `idx_kcdoc_current` (`is_current`),
  CONSTRAINT `fk_kcdoc_contract`
    FOREIGN KEY (`key_contract_id`) REFERENCES `key_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kcdoc_token`
    FOREIGN KEY (`upload_token_id`) REFERENCES `key_contract_upload_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kcdoc_user`
    FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Klaar. Niets verwijderd; contract_pdf-blob blijft voor oude records.
-- ---------------------------------------------------------------------
