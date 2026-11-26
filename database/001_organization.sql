-- Tito Trazabilidad: estructura organizacional, fase 1.
-- Ejecutar en phpMyAdmin después de respaldar. No altera stock, ventas ni usuarios.
-- Las tablas y referencias están calificadas para no tocar Tito POS.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.business_profiles (
 business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 legal_name VARCHAR(160) NOT NULL DEFAULT '',
 tax_id VARCHAR(16) NOT NULL DEFAULT '',
 address VARCHAR(200) NOT NULL DEFAULT '',
 city VARCHAR(100) NOT NULL DEFAULT '',
 email VARCHAR(160) NOT NULL DEFAULT '',
 phone VARCHAR(40) NOT NULL DEFAULT '',
 CONSTRAINT fk_org_profile_business FOREIGN KEY (business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.branches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(30) NOT NULL,
 name VARCHAR(120) NOT NULL,
 address VARCHAR(200) NOT NULL DEFAULT '',
 city VARCHAR(100) NOT NULL DEFAULT '',
 phone VARCHAR(40) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_org_branch_code (business_id, code),
 UNIQUE KEY uq_org_branch_tenant (business_id, id),
 CONSTRAINT fk_org_branch_business FOREIGN KEY (business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.branch_warehouses (
 warehouse_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 KEY idx_org_branch_warehouse (business_id, branch_id),
 CONSTRAINT fk_org_bw_warehouse FOREIGN KEY (warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id),
 CONSTRAINT fk_org_bw_branch FOREIGN KEY (business_id, branch_id) REFERENCES maderas7_ttrazabilidad.branches(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
