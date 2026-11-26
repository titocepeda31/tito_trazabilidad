-- Tito Trazabilidad: puntos de venta comerciales, independientes del inventario.
-- Importar después de 005, antes del backend. Requiere las tablas heredadas de terminales.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.sales_points (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(30) NOT NULL,
 name VARCHAR(120) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sales_point_code(business_id,code),
 UNIQUE KEY uq_sales_point_tenant(business_id,id),
 KEY idx_sales_point_branch(business_id,branch_id),
 CONSTRAINT fk_sales_point_branch FOREIGN KEY(business_id,branch_id) REFERENCES maderas7_ttrazabilidad.branches(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.sales_point_terminals (
 terminal_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sales_point_id BIGINT UNSIGNED NOT NULL,
 KEY idx_sales_point_terminal(business_id,sales_point_id),
 CONSTRAINT fk_sales_point_terminal FOREIGN KEY(terminal_id) REFERENCES maderas7_ttrazabilidad.pos_terminals(id),
 CONSTRAINT fk_sales_point_mapping FOREIGN KEY(business_id,sales_point_id) REFERENCES maderas7_ttrazabilidad.sales_points(business_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.sale_point_snapshots (
 sale_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sales_point_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 terminal_id BIGINT UNSIGNED NOT NULL,
 point_code VARCHAR(30) NOT NULL,
 point_name VARCHAR(120) NOT NULL,
 branch_name VARCHAR(120) NOT NULL,
 dispatch_warehouse_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_sale_point_history(business_id,sales_point_id,sale_id),
 CONSTRAINT fk_sale_snapshot_point FOREIGN KEY(business_id,sales_point_id) REFERENCES maderas7_ttrazabilidad.sales_points(business_id,id),
 CONSTRAINT fk_sale_snapshot_branch FOREIGN KEY(business_id,branch_id) REFERENCES maderas7_ttrazabilidad.branches(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.sales_point_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 sales_point_id BIGINT UNSIGNED NULL,
 terminal_id BIGINT UNSIGNED NULL,
 details TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_sales_point_events(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
