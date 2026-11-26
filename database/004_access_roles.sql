-- Ejecutar después de 003, antes de desplegar el backend.
-- Sin modificaciones a los roles y usuarios existentes.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_roles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 description VARCHAR(250) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_access_role_name(business_id,name),
 UNIQUE KEY uq_access_role_tenant(business_id,id),
 CONSTRAINT fk_access_role_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_role_permissions (
 business_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 permission_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(role_id,permission_code),
 KEY idx_access_permissions_tenant(business_id,role_id),
 CONSTRAINT fk_access_permission_role FOREIGN KEY(business_id,role_id) REFERENCES maderas7_ttrazabilidad.access_roles(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_user_roles (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 KEY idx_access_assignment_role(business_id,role_id),
 CONSTRAINT fk_access_assignment_user FOREIGN KEY(user_id) REFERENCES maderas7_ttrazabilidad.users(id),
 CONSTRAINT fk_access_assignment_role FOREIGN KEY(business_id,role_id) REFERENCES maderas7_ttrazabilidad.access_roles(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_role_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 role_id BIGINT UNSIGNED NULL,
 target_user_id BIGINT UNSIGNED NULL,
 details TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_access_events(business_id,id),
 CONSTRAINT fk_access_event_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
