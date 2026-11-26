<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base MySQL inesperada.');
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quotes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 customer_name VARCHAR(150) NOT NULL,
 customer_contact VARCHAR(120) NULL,
 notes VARCHAR(500) NULL,
 valid_until DATE NOT NULL,
 status ENUM('draft','converted') NOT NULL DEFAULT 'draft',
 converted_sale_id BIGINT UNSIGNED NULL DEFAULT NULL,
 total_clp BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_quote_request (business_id,request_id),
 UNIQUE KEY uq_quote_sale (converted_sale_id),
 KEY idx_quote_business_date (business_id,id),
 CONSTRAINT fk_quotes_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_quotes_user FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT fk_quotes_sale FOREIGN KEY (converted_sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quote_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 quote_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 product_name_snapshot VARCHAR(120) NOT NULL,
 sale_unit ENUM('unidad','kg','litro') NOT NULL DEFAULT 'unidad',
 quantity DECIMAL(14,3) NOT NULL,
 list_price_clp BIGINT UNSIGNED NOT NULL,
 unit_price_clp BIGINT UNSIGNED NOT NULL,
 discount_reason VARCHAR(120) NULL,
 subtotal_clp BIGINT UNSIGNED NOT NULL,
 KEY idx_quote_items_quote (quote_id),
 CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes(id),
 CONSTRAINT fk_quote_items_product FOREIGN KEY (product_id) REFERENCES products(id),
 CONSTRAINT chk_quote_items_qty CHECK(quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach(['quotes'=>['id','business_id','created_by','request_id','payload_hash','customer_name','valid_until','status','converted_sale_id','total_clp'],'quote_items'=>['quote_id','product_id','quantity','list_price_clp','unit_price_clp','subtotal_clp']] as $table=>$cols){
  $actual=array_column($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
  foreach($cols as $col){if(!in_array($col,$actual,true))throw new RuntimeException('Falta '.$table.'.'.$col);}
}
echo "OK: tablas de cotizaciones verificadas.\n";
