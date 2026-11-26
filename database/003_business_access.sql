-- Tito Trazabilidad: control de acceso comercial por empresa.
-- Respaldar e importar DESPUÉS de 001 y 002, ANTES del nuevo backend.
-- Las empresas sin registro en esta tabla permanecen activas.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.business_access_state (
 business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 reason VARCHAR(250) NOT NULL DEFAULT '',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_platform_access_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.business_access_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 is_active TINYINT(1) NOT NULL,
 reason VARCHAR(250) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_access_event_business(business_id,id),
 CONSTRAINT fk_platform_event_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
