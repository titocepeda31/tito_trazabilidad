-- 036: configuración tributaria versionada y borradores internos DTE.
-- Aplicar después de 035 con respaldo. No emite documentos ni modifica ventas.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.dte_config_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 provider ENUM('openfactura') NOT NULL DEFAULT 'openfactura',
 issuer_json TEXT NOT NULL,
 price_basis ENUM('unknown','gross','net') NOT NULL DEFAULT 'unknown',
 evidence_reference VARCHAR(250) NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_dte_config_request(business_id,request_id),
 KEY idx_dte_config_latest(business_id,id),
 CONSTRAINT fk_dte_config_business FOREIGN KEY(business_id)
 REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_dte_config_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.dte_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 active_sale_id BIGINT UNSIGNED NULL,
 config_id BIGINT UNSIGNED NOT NULL,
 document_type SMALLINT UNSIGNED NOT NULL,
 status ENUM('draft','discarded') NOT NULL DEFAULT 'draft',
 snapshot_json MEDIUMTEXT NOT NULL,
 commercial_total_clp BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 discarded_by BIGINT UNSIGNED NULL,
 discarded_at DATETIME NULL,
 discard_reason VARCHAR(500) NULL,
 UNIQUE KEY uq_dte_active_sale(business_id,active_sale_id),
 UNIQUE KEY uq_dte_draft_request(business_id,request_id),
 KEY idx_dte_documents_history(business_id,id),
 CONSTRAINT fk_dte_doc_business FOREIGN KEY(business_id)
 REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_dte_doc_sale FOREIGN KEY(sale_id)
 REFERENCES maderas7_ttrazabilidad.sales(id),
 CONSTRAINT fk_dte_doc_config FOREIGN KEY(config_id)
 REFERENCES maderas7_ttrazabilidad.dte_config_versions(id),
 CONSTRAINT fk_dte_doc_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
