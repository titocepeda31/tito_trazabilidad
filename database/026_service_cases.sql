-- 026: garantías comerciales y casos de servicio técnico por venta/serie.
-- Aplicar después de 025 con respaldo y verificar claves referenciadas.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.unit_warranties (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 manufacturer_name VARCHAR(150) NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 terms VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_unit_warranty_sale(unit_id,sale_id),
 KEY idx_unit_warranty_client(business_id,client_id,id),
 CONSTRAINT fk_unit_warranty_sold FOREIGN KEY(unit_id,sale_id)
 REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_unit_warranty_client FOREIGN KEY(client_id)
 REFERENCES maderas7_ttrazabilidad.habitual_clients(id),
 CONSTRAINT fk_unit_warranty_actor FOREIGN KEY(created_by)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.service_cases (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 warranty_id BIGINT UNSIGNED NULL,
 status ENUM('received','diagnosis','repair','ready','closed') NOT NULL DEFAULT 'received',
 custody ENUM('customer','service','manufacturer') NOT NULL,
 problem VARCHAR(1000) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 closed_at DATETIME NULL,
 UNIQUE KEY uq_service_request(business_id,request_id),
 KEY idx_service_unit(business_id,unit_id,status),
 KEY idx_service_client(business_id,client_id,id),
 CONSTRAINT fk_service_sold FOREIGN KEY(unit_id,sale_id)
 REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_service_client FOREIGN KEY(client_id)
 REFERENCES maderas7_ttrazabilidad.habitual_clients(id),
 CONSTRAINT fk_service_warranty FOREIGN KEY(warranty_id)
 REFERENCES maderas7_ttrazabilidad.unit_warranties(id),
 CONSTRAINT fk_service_creator FOREIGN KEY(created_by)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.service_case_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 from_status VARCHAR(24) NULL,
 to_status VARCHAR(24) NOT NULL,
 custody ENUM('customer','service','manufacturer') NOT NULL,
 note VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_service_event_request(business_id,request_id),
 KEY idx_service_event_case(business_id,case_id,id),
 CONSTRAINT fk_service_event_case FOREIGN KEY(case_id)
 REFERENCES maderas7_ttrazabilidad.service_cases(id),
 CONSTRAINT fk_service_event_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
