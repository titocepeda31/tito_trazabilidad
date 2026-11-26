<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada; no se instalaron créditos.');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS habitual_clients (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 phone VARCHAR(80) NULL,
 notes VARCHAR(300) NULL,
 credit_enabled TINYINT(1) NOT NULL DEFAULT 0,
 credit_limit_clp BIGINT UNSIGNED NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_habitual_clients_business (business_id,is_active,name),
 CONSTRAINT fk_habitual_clients_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_total_clp BIGINT UNSIGNED NOT NULL,
 due_date DATE NULL,
 notes VARCHAR(250) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_credit_account_sale (sale_id),
 UNIQUE KEY uq_credit_account_request (business_id,request_id),
 KEY idx_credit_account_client (business_id,client_id,id),
 CONSTRAINT fk_credit_account_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_credit_account_client FOREIGN KEY (client_id) REFERENCES habitual_clients(id),
 CONSTRAINT fk_credit_account_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
 CONSTRAINT fk_credit_account_author FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL,
 method ENUM('efectivo','transferencia','tarjeta','otro') NOT NULL,
 reference VARCHAR(120) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_credit_payment_request (business_id,request_id),
 KEY idx_credit_payments_account (account_id,id),
 CONSTRAINT fk_credit_payments_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_credit_payments_account FOREIGN KEY (account_id) REFERENCES credit_accounts(id),
 CONSTRAINT fk_credit_payments_author FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT chk_credit_payment_positive CHECK (amount_clp>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach([
'habitual_clients'=>['id','business_id','name','credit_enabled','credit_limit_clp','is_active'],
'credit_accounts'=>['id','business_id','client_id','sale_id','request_id','payload_hash','original_total_clp'],
'credit_payments'=>['id','business_id','account_id','request_id','payload_hash','amount_clp','method']
] as $table=>$columns){
    $actual=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
    foreach($columns as $column)if(!in_array($column,$actual,true))throw new RuntimeException('Falta columna '.$table.'.'.$column);
}
// La anulación automática repone todo el stock sin ajustar el libro de créditos.
// Bloquearla hasta implementar reversa integral del crédito y sus abonos.
$exists=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?");
$exists->execute(['tito_prevent_void_credit_sale']);
if((int)$exists->fetchColumn()===0){
    $db->exec(<<<'SQL'
CREATE TRIGGER tito_prevent_void_credit_sale
 BEFORE UPDATE ON sales FOR EACH ROW
BEGIN
 IF NEW.status='voided' AND OLD.status<>'voided'
   AND EXISTS(SELECT 1 FROM credit_accounts WHERE sale_id=OLD.id LIMIT 1)
 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La venta a credito no puede anularse sin regularizar la cuenta';
 END IF;
END
SQL);
}
$exists->execute(['tito_prevent_return_credit_sale']);
if((int)$exists->fetchColumn()===0){
    $db->exec(<<<'SQL'
CREATE TRIGGER tito_prevent_return_credit_sale
 BEFORE INSERT ON customer_returns FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM credit_accounts WHERE sale_id=NEW.sale_id LIMIT 1)
 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La devolucion a credito requiere ajuste de cuenta por cobrar';
 END IF;
END
SQL);
}
echo "OK: tablas de clientes, créditos y abonos verificadas; prohibida doble reversa.\n";
