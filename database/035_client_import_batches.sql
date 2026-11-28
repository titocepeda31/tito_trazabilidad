-- 035: auditoría e idempotencia de importación comercial de clientes.
-- Aplicar después de 034 con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.client_import_batches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 total_rows INT UNSIGNED NOT NULL,
 created_count INT UNSIGNED NOT NULL,
 skipped_count INT UNSIGNED NOT NULL,
 result_json MEDIUMTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_client_import_request(business_id,request_id),
 KEY idx_client_import_history(business_id,id),
 CONSTRAINT fk_client_import_business FOREIGN KEY(business_id)
 REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_client_import_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
