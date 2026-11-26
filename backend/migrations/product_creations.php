<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('No coincide la base Tito POS; no se realizaron cambios.');
foreach(['products','users','businesses'] as $table){
  $st=$db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $st->execute([$table]);
  if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla requerida: '.$table);
}
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS product_creation_audit(
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  origin ENUM('manual','excel','mercaderia','otro') NOT NULL DEFAULT 'otro',
  name_snapshot VARCHAR(120) NOT NULL,
  sku_snapshot VARCHAR(50) NULL,
  barcode_snapshot VARCHAR(50) NULL,
  price_clp BIGINT UNSIGNED NOT NULL,
  sale_unit ENUM('unidad','kg','litro') NOT NULL,
  stock_initial_qty DECIMAL(14,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_creation_once(product_id),
  KEY idx_creation_business_recent (business_id,id),
  KEY idx_creation_actor (created_by),
  CONSTRAINT fk_creation_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_creation_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_creation_author FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$columns=array_column($db->query('SHOW COLUMNS FROM product_creation_audit')->fetchAll(PDO::FETCH_ASSOC),'Field');
foreach(['id','business_id','product_id','created_by','origin','name_snapshot','sku_snapshot','barcode_snapshot','price_clp','sale_unit','stock_initial_qty','created_at'] as $required){
  if(!in_array($required,$columns,true))throw new RuntimeException('Columna de auditoría ausente: '.$required);
}
$st=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='tpos_product_creation_audit'");
$st->execute();$exists=(int)$st->fetchColumn();
if($exists===0){
  $db->exec(<<<'SQL'
CREATE TRIGGER tpos_product_creation_audit AFTER INSERT ON products FOR EACH ROW
BEGIN
  DECLARE audit_actor BIGINT UNSIGNED DEFAULT NULL;
  DECLARE audit_origin VARCHAR(20) DEFAULT 'otro';
  IF @tito_product_creator_id IS NOT NULL
     AND @tito_product_creator_business = NEW.business_id
  THEN
    SET audit_actor=(SELECT u.id FROM users u
      WHERE u.id=@tito_product_creator_id AND u.business_id=NEW.business_id
      LIMIT 1);
    IF audit_actor IS NOT NULL
       AND @tito_product_creator_origin IN ('manual','excel','mercaderia')
    THEN
      SET audit_origin=@tito_product_creator_origin;
    END IF;
  END IF;
  INSERT INTO product_creation_audit(
    business_id,product_id,created_by,origin,name_snapshot,sku_snapshot,
    barcode_snapshot,price_clp,sale_unit,stock_initial_qty
  ) VALUES (
    NEW.business_id,NEW.id,audit_actor,audit_origin,NEW.name,NEW.sku,
    NEW.barcode,NEW.price_clp,NEW.sale_unit,NEW.stock_qty
  );
END
SQL);
}
$st->execute();
if((int)$st->fetchColumn()!==1)throw new RuntimeException('Trigger de auditoría no encontrado.');
echo "OK: registro de creación de productos y responsable habilitado (sin modificar productos históricos).\n";
