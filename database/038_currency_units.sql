-- 038: Base comercial CLP/UF. Aplicar después de 037, con respaldo.
-- No modifica precios ni documentos existentes. Valores UF por empresa, fecha y versión.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.business_currency_settings (
 business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 default_unit ENUM('CLP','UF') NOT NULL DEFAULT 'CLP',
 allow_both TINYINT(1) NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 FOREIGN KEY (updated_by) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.business_uf_rates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 rate_on DATE NOT NULL,
 clp_per_uf DECIMAL(12,2) NOT NULL,
 source_reference VARCHAR(250) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_uf_rate (business_id,rate_on,id),
 FOREIGN KEY (business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 FOREIGN KEY (recorded_by) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
