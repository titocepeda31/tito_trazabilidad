<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base de datos inesperada');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sale_discount_approvals(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 cashier_id BIGINT UNSIGNED NOT NULL,
 supervisor_id BIGINT UNSIGNED NOT NULL,
 sale_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 price_snapshot_json TEXT NOT NULL,
 used_sale_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY uq_discount_token(token_hash),
 UNIQUE KEY uq_discount_sale(used_sale_id),
 KEY idx_discount_business(business_id,cashier_id,sale_request_id),
 CONSTRAINT fk_da_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_da_cashier FOREIGN KEY(cashier_id) REFERENCES users(id),
 CONSTRAINT fk_da_supervisor FOREIGN KEY(supervisor_id) REFERENCES users(id),
 CONSTRAINT fk_da_sale FOREIGN KEY(used_sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS discount_authorization_failures(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 cashier_id BIGINT UNSIGNED NOT NULL,
 attempted_at DATETIME NOT NULL,
 KEY idx_daf_recent(business_id,cashier_id,attempted_at),
 CONSTRAINT fk_daf_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_daf_cashier FOREIGN KEY(cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach(['sale_discount_approvals','discount_authorization_failures'] as $table){
  $st=$db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $st->execute([$table]);if((int)$st->fetchColumn()!==1)throw new RuntimeException('No se creó la tabla '.$table);
}
echo "OK: registros de autorización de descuentos verificados.\n";
