-- 040: catálogo de precios comerciales CLP/UF e historial.
-- Seleccionar maderas7_ttrazabilidad en phpMyAdmin antes de importar; aplicar después de 038/039 con respaldo.
-- Nombres explícitos de esquema para evitar crear triggers en information_schema.
-- Si falló una instalación anterior, comprobar SHOW TRIGGERS FROM maderas7_ttrazabilidad antes de repetir.
-- Esta etapa habilita el catálogo; ventas y cotizaciones UF siguen bloqueadas.
-- Si una sentencia falla, no volver a ejecutar todo: revisar tabla y triggers existentes.
-- El backend exige los tres triggers antes de habilitar el formulario comercial.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.product_commercial_prices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 price_unit ENUM('CLP','UF') NOT NULL,
 source_amount DECIMAL(15,4) NOT NULL,
 source_basis ENUM('net','gross') NOT NULL,
 tax_treatment ENUM('vat19','exempt','non_taxable') NOT NULL,
 final_amount DECIMAL(17,6) NOT NULL,
 reference_clp BIGINT UNSIGNED NOT NULL,
 uf_rate_id BIGINT UNSIGNED NULL,
 reference_date DATE NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_commercial_price (business_id,product_id,id),
 FOREIGN KEY (business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 FOREIGN KEY (product_id) REFERENCES maderas7_ttrazabilidad.products(id),
 FOREIGN KEY (uf_rate_id) REFERENCES maderas7_ttrazabilidad.business_uf_rates(id),
 FOREIGN KEY (actor_id) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bloqueo transitorio a nivel de BD para TODOS los caminos legacy, incluidos crédito y descuentos.
-- Quitar únicamente cuando todas las escrituras exijan snapshots comerciales de la operación.
DELIMITER //
CREATE TRIGGER maderas7_ttrazabilidad.tito_uf_catalog_block_sale_item BEFORE INSERT ON maderas7_ttrazabilidad.sale_items
FOR EACH ROW
BEGIN
 IF COALESCE((SELECT price_unit FROM maderas7_ttrazabilidad.product_commercial_prices WHERE product_id=NEW.product_id ORDER BY id DESC LIMIT 1),'CLP')='UF' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Producto UF: venta pendiente de integración comercial.';
 END IF;
END//
CREATE TRIGGER maderas7_ttrazabilidad.tito_uf_catalog_block_quote_item BEFORE INSERT ON maderas7_ttrazabilidad.quote_items
FOR EACH ROW
BEGIN
 IF COALESCE((SELECT price_unit FROM maderas7_ttrazabilidad.product_commercial_prices WHERE product_id=NEW.product_id ORDER BY id DESC LIMIT 1),'CLP')='UF' THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Producto UF: cotización pendiente de integración comercial.';
 END IF;
END//
CREATE TRIGGER maderas7_ttrazabilidad.tito_uf_catalog_block_legacy_update BEFORE UPDATE ON maderas7_ttrazabilidad.products
FOR EACH ROW
BEGIN
 IF NEW.price_clp<>OLD.price_clp AND COALESCE((SELECT price_unit FROM maderas7_ttrazabilidad.product_commercial_prices WHERE business_id=OLD.business_id AND product_id=OLD.id ORDER BY id DESC LIMIT 1),'CLP')='UF'
 AND NEW.price_clp<>COALESCE((SELECT reference_clp FROM maderas7_ttrazabilidad.product_commercial_prices WHERE business_id=OLD.business_id AND product_id=OLD.id ORDER BY id DESC LIMIT 1),OLD.price_clp) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Producto UF: cambia la unidad o el precio desde el catálogo comercial.';
 END IF;
END//
DELIMITER ;

-- Verificar: debe devolver 3.
SELECT COUNT(*) AS protecciones_instaladas FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA='maderas7_ttrazabilidad'
 AND TRIGGER_NAME IN ('tito_uf_catalog_block_sale_item','tito_uf_catalog_block_quote_item','tito_uf_catalog_block_legacy_update');
