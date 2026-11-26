-- Ejecutar solo en maderas7_ttrazabilidad; requiere 001_organization.sql.
-- No crea superadministradores ni altera usuarios existentes.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.platform_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 key_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 KEY idx_platform_expiry (expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.platform_business_creations (
 business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 admin_user_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_platform_created_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_platform_created_user FOREIGN KEY(admin_user_id) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB;
