-- 033: revisión versionada de garantías del sustituto gratuito.
-- Aplicar después de 032 con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.replacement_coverage_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 exchange_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('legal','satisfaction','manufacturer') NOT NULL,
 action ENUM('review','reported','confirmed','observed') NOT NULL,
 decision ENUM('pending','continues','specific','not_offered') NOT NULL,
 starts_on DATE NULL,
 ends_on DATE NULL,
 terms VARCHAR(1000) NOT NULL,
 manufacturer_name VARCHAR(150) NULL,
 activation_status ENUM('unknown','not_required','pending','reported','confirmed','observed') NOT NULL DEFAULT 'unknown',
 notified_on DATE NULL,
 confirmed_on DATE NULL,
 event_on DATE NOT NULL,
 note VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_repl_cov_request(business_id,request_id),
 KEY idx_repl_cov_latest(business_id,exchange_id,kind,id),
 CONSTRAINT fk_repl_cov_exchange FOREIGN KEY(exchange_id)
 REFERENCES maderas7_ttrazabilidad.warranty_free_exchanges(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
