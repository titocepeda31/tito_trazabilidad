<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base de datos inesperada');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS warehouse_adjustments(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 warehouse_code VARCHAR(30) NOT NULL,
 delta_qty DECIMAL(14,3) NOT NULL,
 qty_before DECIMAL(14,3) NOT NULL,
 qty_after DECIMAL(14,3) NOT NULL,
 total_before DECIMAL(14,3) NOT NULL,
 total_after DECIMAL(14,3) NOT NULL,
 reason VARCHAR(250) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_adjustment_request(business_id,request_id),
 KEY idx_adjustment_business_date(business_id,id),
 CONSTRAINT fk_wa_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_wa_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_wa_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT chk_wa_location CHECK(warehouse_code IN ('local','bodega'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach(['id','business_id','request_id','product_id','warehouse_code','delta_qty','qty_before','qty_after','total_before','total_after','reason'] as $name){
 $cols=array_column($db->query('SHOW COLUMNS FROM warehouse_adjustments')->fetchAll(PDO::FETCH_ASSOC),'Field');
 if(!in_array($name,$cols,true))throw new RuntimeException('Falta columna '.$name);
}
echo "OK: tabla de ajustes por ubicación verificada.\n";
