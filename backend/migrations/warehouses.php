<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada. Migración detenida.');
// Se ejecuta sin ventas simultáneas. DDL es no transaccional y requiere permisos TRIGGER.
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouses (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(30) NOT NULL,
 name VARCHAR(120) NOT NULL,
 is_sales_location TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_warehouses_code (business_id,code),
 CONSTRAINT fk_warehouses_business FOREIGN KEY(business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouse_stocks (
 business_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 qty DECIMAL(14,3) NOT NULL DEFAULT 0,
 PRIMARY KEY (warehouse_id,product_id),
 KEY idx_wh_stock_business (business_id,product_id),
 CONSTRAINT fk_wh_stock_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_wh_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
 CONSTRAINT fk_wh_stock_product FOREIGN KEY (product_id) REFERENCES products(id),
 CONSTRAINT chk_wh_stock_nonnegative CHECK(qty>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouse_transfers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_warehouse_id BIGINT UNSIGNED NOT NULL,
 target_warehouse_id BIGINT UNSIGNED NOT NULL,
 note VARCHAR(250) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wh_transfer_request (business_id,request_id),
 KEY idx_wh_transfer_business (business_id,id),
 CONSTRAINT fk_wh_transfer_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_wh_transfer_user FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_wh_transfer_source FOREIGN KEY(source_warehouse_id) REFERENCES warehouses(id),
 CONSTRAINT fk_wh_transfer_target FOREIGN KEY(target_warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouse_transfer_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 transfer_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(14,3) NOT NULL,
 source_before DECIMAL(14,3) NOT NULL,
 source_after DECIMAL(14,3) NOT NULL,
 target_before DECIMAL(14,3) NOT NULL,
 target_after DECIMAL(14,3) NOT NULL,
 KEY idx_wh_transfer_items (transfer_id),
 CONSTRAINT fk_wh_transfer_items_transfer FOREIGN KEY(transfer_id) REFERENCES warehouse_transfers(id),
 CONSTRAINT fk_wh_transfer_items_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT chk_wh_transfer_item_qty CHECK(quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec("INSERT IGNORE INTO warehouses(business_id,code,name,is_sales_location) SELECT id,'local','Local de venta',1 FROM businesses");
$pdo->exec("INSERT IGNORE INTO warehouses(business_id,code,name,is_sales_location) SELECT id,'bodega','Bodega de respaldo',0 FROM businesses");
// Si los productos aún no tenían ubicaciones, asignamos conservadoramente su
// stock existente al local; el dueño redistribuye según conteo físico.
$pdo->exec("INSERT IGNORE INTO warehouse_stocks(business_id,warehouse_id,product_id,qty) SELECT p.business_id,w.id,p.id,CASE WHEN w.code='local' THEN p.stock_qty ELSE 0 END FROM products p JOIN warehouses w ON w.business_id=p.business_id");
// Detecta incoherencias, no intenta corregirlas sobrescribiendo existencias.
$st=$pdo->query("SELECT p.id,p.name,p.stock_qty,COALESCE(SUM(ws.qty),0) AS total_locations,COUNT(ws.warehouse_id) AS stock_rows FROM products p LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.business_id=p.business_id GROUP BY p.id,p.name,p.stock_qty HAVING ABS(p.stock_qty-total_locations)>0.001 OR stock_rows<>2 LIMIT 3");
$bad=$st->fetchAll();
if($bad)throw new RuntimeException('Stock total y ubicaciones no coinciden. No se habilitaron disparadores: '.json_encode($bad));
function titoHasTrigger(PDO $pdo,string $name): bool{
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
    $st->execute([$name]);return (int)$st->fetchColumn()>0;
}
if(!titoHasTrigger($pdo,'tpos_products_warehouse_insert')){
    $pdo->exec(<<<'SQL'
CREATE TRIGGER tpos_products_warehouse_insert AFTER INSERT ON products FOR EACH ROW
BEGIN
  INSERT INTO warehouse_stocks(business_id,warehouse_id,product_id,qty)
    SELECT NEW.business_id,w.id,NEW.id,CASE WHEN w.code='local' THEN NEW.stock_qty ELSE 0 END
    FROM warehouses w WHERE w.business_id=NEW.business_id;
END
SQL);
}
if(!titoHasTrigger($pdo,'tpos_products_warehouse_update')){
    $pdo->exec(<<<'SQL'
CREATE TRIGGER tpos_products_warehouse_update AFTER UPDATE ON products FOR EACH ROW
BEGIN
  IF NEW.stock_qty<>OLD.stock_qty THEN
    UPDATE warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id AND w.business_id=NEW.business_id
      SET ws.qty=ws.qty+(NEW.stock_qty-OLD.stock_qty)
      WHERE ws.business_id=NEW.business_id AND ws.product_id=NEW.id AND w.code='local';
    IF ROW_COUNT()<>1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Stock local no disponible o inconsistente';
    END IF;
  END IF;
END
SQL);
}
foreach(['tpos_products_warehouse_insert','tpos_products_warehouse_update'] as $t){
    if(!titoHasTrigger($pdo,$t))throw new RuntimeException('No se creó el disparador '.$t);
}
$st=$pdo->query("SELECT COUNT(*) FROM warehouses WHERE code IN ('local','bodega')");
echo "OK: bodegas y saldos verificados. Ubicaciones: ".$st->fetchColumn()."\n";
echo "IMPORTANTE: stock inicial asignado al local. Trasladar a bodega según conteo real.\n";
