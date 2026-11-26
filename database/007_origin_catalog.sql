-- Tito Trazabilidad: catálogos de origen; importar después de 006.
-- No modifica productos, stock, ventas ni recepciones existentes.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.manufacturers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 country VARCHAR(100) NOT NULL DEFAULT '',
 website VARCHAR(250) NOT NULL DEFAULT '',
 email VARCHAR(160) NOT NULL DEFAULT '',
 phone VARCHAR(40) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_manufacturer_name(business_id,name),
 UNIQUE KEY uq_manufacturer_tenant(business_id,id),
 CONSTRAINT fk_manufacturer_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.brands (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 manufacturer_id BIGINT UNSIGNED NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_brand_name(business_id,name),
 UNIQUE KEY uq_brand_tenant(business_id,id),
 KEY idx_brand_manufacturer(business_id,manufacturer_id),
 CONSTRAINT fk_brand_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_brand_manufacturer FOREIGN KEY(business_id,manufacturer_id) REFERENCES maderas7_ttrazabilidad.manufacturers(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.suppliers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 tax_id VARCHAR(50) NULL,
 tax_country VARCHAR(2) NOT NULL DEFAULT 'CL',
 contact_name VARCHAR(120) NOT NULL DEFAULT '',
 email VARCHAR(160) NOT NULL DEFAULT '',
 phone VARCHAR(40) NOT NULL DEFAULT '',
 address VARCHAR(200) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_supplier_name(business_id,name),
 UNIQUE KEY uq_supplier_tax(business_id,tax_country,tax_id),
 UNIQUE KEY uq_supplier_tenant(business_id,id),
 CONSTRAINT fk_supplier_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.product_origins (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 manufacturer_id BIGINT UNSIGNED NULL,
 brand_id BIGINT UNSIGNED NULL,
 model VARCHAR(120) NOT NULL DEFAULT '',
 KEY idx_origin_manufacturer(business_id,manufacturer_id),
 KEY idx_origin_brand(business_id,brand_id),
 CONSTRAINT fk_origin_product FOREIGN KEY(product_id) REFERENCES maderas7_ttrazabilidad.products(id),
 CONSTRAINT fk_origin_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_origin_manufacturer FOREIGN KEY(business_id,manufacturer_id) REFERENCES maderas7_ttrazabilidad.manufacturers(business_id,id),
 CONSTRAINT fk_origin_brand FOREIGN KEY(business_id,brand_id) REFERENCES maderas7_ttrazabilidad.brands(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.product_suppliers (
 product_id BIGINT UNSIGNED NOT NULL,
 business_id BIGINT UNSIGNED NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(product_id,supplier_id),
 KEY idx_product_supplier_tenant(business_id,supplier_id),
 CONSTRAINT fk_product_supplier_product FOREIGN KEY(product_id) REFERENCES maderas7_ttrazabilidad.products(id),
 CONSTRAINT fk_product_supplier_supplier FOREIGN KEY(business_id,supplier_id) REFERENCES maderas7_ttrazabilidad.suppliers(business_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.origin_catalog_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 entity_type VARCHAR(30) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 details TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_origin_events(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
