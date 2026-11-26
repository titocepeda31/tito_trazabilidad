<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base de datos inesperada. No se instalaron devoluciones.');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS customer_returns (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 return_kind ENUM('commercial','warranty') NOT NULL,
 item_condition ENUM('resellable','defective') NOT NULL,
 resolution ENUM('refund','exchange','repair') NOT NULL,
 reason VARCHAR(250) NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL DEFAULT 0,
 refund_status ENUM('pending','confirmed','not_applicable') NOT NULL DEFAULT 'pending',
 refund_method ENUM('efectivo','transferencia','tarjeta','otro') NULL,
 refund_reference VARCHAR(120) NULL,
 refunded_by BIGINT UNSIGNED NULL,
 refunded_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_customer_return_request (business_id,request_id),
 KEY idx_customer_returns_business (business_id,id),
 KEY idx_customer_returns_sale (sale_id,id),
 CONSTRAINT fk_customer_returns_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_customer_returns_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
 CONSTRAINT fk_customer_returns_author FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT fk_customer_returns_refunder FOREIGN KEY (refunded_by) REFERENCES users(id),
 CONSTRAINT chk_customer_return_condition CHECK (
   (return_kind='commercial' AND item_condition='resellable') OR return_kind='warranty'
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS customer_return_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 return_id BIGINT UNSIGNED NOT NULL,
 sale_item_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(14,3) NOT NULL,
 unit_price_clp BIGINT UNSIGNED NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL,
 restocked TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_customer_return_line (return_id,sale_item_id),
 KEY idx_customer_returns_item (sale_item_id),
 CONSTRAINT fk_customer_return_items_return FOREIGN KEY (return_id) REFERENCES customer_returns(id),
 CONSTRAINT fk_customer_return_items_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
 CONSTRAINT fk_customer_return_items_product FOREIGN KEY (product_id) REFERENCES products(id),
 CONSTRAINT chk_customer_return_qty CHECK(quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach(['customer_returns'=>['id','business_id','sale_id','request_id','payload_hash','return_kind','item_condition','resolution','amount_clp','refund_status'],'customer_return_items'=>['return_id','sale_item_id','product_id','quantity','amount_clp','restocked']] as $table=>$required){
 $actual=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
 foreach($required as $column)if(!in_array($column,$actual,true))throw new RuntimeException('Falta '.$table.'.'.$column);
}
// Impide anular una venta si ya tuvo devoluciones: la anulación repone todo el stock.
$st=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='tito_prevent_void_after_return'");
$st->execute();
if((int)$st->fetchColumn()===0){
 $db->exec(<<<'SQL'
CREATE TRIGGER tito_prevent_void_after_return
 BEFORE UPDATE ON sales FOR EACH ROW
BEGIN
 IF NEW.status='voided' AND OLD.status<>'voided'
   AND EXISTS(SELECT 1 FROM customer_returns WHERE sale_id=OLD.id LIMIT 1)
 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='No se puede anular una venta que ya tiene devoluciones registradas';
 END IF;
END
SQL);
}
echo "OK: devoluciones de clientes y protección contra anulación doble verificadas.\n";
