-- Ejecutar en maderas7_ttrazabilidad después de 004, antes del backend.
-- Ausencia de alcance = acceso general de empresa según permisos del rol.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_user_branch_scopes (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_access_scope_tenant(user_id,business_id),
 CONSTRAINT fk_access_scope_assignment FOREIGN KEY(user_id) REFERENCES maderas7_ttrazabilidad.access_user_roles(user_id) ON DELETE CASCADE,
 CONSTRAINT fk_access_scope_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.access_user_branches (
 user_id BIGINT UNSIGNED NOT NULL,
 business_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,branch_id),
 KEY idx_access_branch_tenant(business_id,branch_id),
 KEY idx_access_branch_scope(user_id,business_id),
 CONSTRAINT fk_access_branch_scope FOREIGN KEY(user_id,business_id) REFERENCES maderas7_ttrazabilidad.access_user_branch_scopes(user_id,business_id) ON DELETE CASCADE,
 CONSTRAINT fk_access_branch_org FOREIGN KEY(business_id,branch_id) REFERENCES maderas7_ttrazabilidad.branches(business_id,id)
) ENGINE=InnoDB;
