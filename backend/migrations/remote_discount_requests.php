<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base de datos inesperada');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS remote_discount_requests(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 cashier_id BIGINT UNSIGNED NOT NULL,
 sale_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 price_snapshot_json TEXT NOT NULL,
 summary_json TEXT NOT NULL,
 status ENUM('pending','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'pending',
 decided_by BIGINT UNSIGNED NULL,
 decided_at DATETIME NULL,
 decision_note VARCHAR(250) NULL,
 used_sale_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY uq_remote_sale(business_id,sale_request_id),
 UNIQUE KEY uq_remote_used_sale(used_sale_id),
 KEY idx_remote_business_status(business_id,status,expires_at),
 CONSTRAINT fk_rd_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_rd_cashier FOREIGN KEY(cashier_id) REFERENCES users(id),
 CONSTRAINT fk_rd_supervisor FOREIGN KEY(decided_by) REFERENCES users(id),
 CONSTRAINT fk_rd_sale FOREIGN KEY(used_sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$cols=array_column($db->query('SHOW COLUMNS FROM remote_discount_requests')->fetchAll(PDO::FETCH_ASSOC),'Field');
foreach(['id','business_id','cashier_id','sale_request_id','payload_hash','status','decided_by','used_sale_id','expires_at'] as $name){
 if(!in_array($name,$cols,true))throw new RuntimeException('Falta columna '.$name);
}
echo "OK: solicitudes remotas y auditoría configuradas.\n";
