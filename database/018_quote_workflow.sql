-- 018: estados comerciales, revision optimista e historial de cotizaciones.
-- Ejecutar despues de 017, previo respaldo. Conserva draft y converted existentes.
ALTER TABLE maderas7_ttrazabilidad.quotes
 MODIFY COLUMN status ENUM('draft','converted','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
 ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 1;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.quote_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 quote_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 event_type VARCHAR(24) NOT NULL,
 from_status VARCHAR(24) NULL,
 to_status VARCHAR(24) NOT NULL,
 note VARCHAR(500) NULL,
 details_json LONGTEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_quote_event_request(quote_id,request_id),
 KEY idx_quote_events_business(business_id,quote_id,id),
 CONSTRAINT fk_quote_events_quote FOREIGN KEY(quote_id) REFERENCES maderas7_ttrazabilidad.quotes(id),
 CONSTRAINT fk_quote_events_actor FOREIGN KEY(actor_id) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
