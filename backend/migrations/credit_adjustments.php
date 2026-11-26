<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada; no se aplicaron ajustes de crédito.');
foreach(['credit_accounts','credit_payments','customer_returns','customer_return_items','warehouse_stocks','sales','sale_items'] as $table){
    $st=$db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if((int)$st->fetchColumn()!==1)throw new RuntimeException('Tabla requerida ausente: '.$table);
}
$st=$db->query("SHOW COLUMNS FROM credit_accounts LIKE 'status'");
$col=$st->fetch(PDO::FETCH_ASSOC);
if(!$col){
    $db->exec("ALTER TABLE credit_accounts ADD COLUMN status ENUM('active','voided') NOT NULL DEFAULT 'active' AFTER original_total_clp");
}elseif(!str_contains(strtolower((string)$col['Type']),'voided')){
    throw new RuntimeException('Columna status de crédito incompatible.');
}
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_adjustments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind ENUM('return','void') NOT NULL,
 return_kind ENUM('commercial','warranty') NOT NULL,
 reason VARCHAR(250) NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_credit_adjustment_request (business_id,request_id),
 KEY idx_credit_adjustments_account (account_id,id),
 CONSTRAINT fk_credit_adj_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_credit_adj_account FOREIGN KEY(account_id) REFERENCES credit_accounts(id),
 CONSTRAINT fk_credit_adj_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_credit_adj_amount CHECK(amount_clp>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_adjustment_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 adjustment_id BIGINT UNSIGNED NOT NULL,
 sale_item_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(14,3) NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL,
 restocked TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_credit_adjustment_line (adjustment_id,sale_item_id),
 KEY idx_credit_adjustment_sale_item (sale_item_id),
 CONSTRAINT fk_credit_adj_items_adjustment FOREIGN KEY(adjustment_id) REFERENCES credit_adjustments(id),
 CONSTRAINT fk_credit_adj_items_saleitem FOREIGN KEY(sale_item_id) REFERENCES sale_items(id),
 CONSTRAINT fk_credit_adj_items_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT chk_credit_adj_items_qty CHECK(quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS credit_refunds (
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
 UNIQUE KEY uq_credit_refund_request (business_id,request_id),
 KEY idx_credit_refunds_account (account_id,id),
 CONSTRAINT fk_credit_refund_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_credit_refund_account FOREIGN KEY(account_id) REFERENCES credit_accounts(id),
 CONSTRAINT fk_credit_refund_author FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_credit_refund_amount CHECK(amount_clp>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach([
 'credit_accounts'=>['status'],
 'credit_adjustments'=>['id','business_id','account_id','kind','return_kind','amount_clp','request_id','payload_hash'],
 'credit_adjustment_items'=>['adjustment_id','sale_item_id','product_id','quantity','amount_clp','restocked'],
 'credit_refunds'=>['id','business_id','account_id','amount_clp','method','request_id','payload_hash']
] as $table=>$required){
    $cols=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
    foreach($required as $field)if(!in_array($field,$cols,true))throw new RuntimeException('Falta '.$table.'.'.$field);
}
// Cambiar el trigger SIN dejar un intervalo desprotegido.
// Primero instalamos el guard nuevo con otro nombre, mientras el anterior
// sigue activo. Luego retiramos el antiguo. Si un DDL falla, al menos
// uno de los dos guards continúa protegiendo las anulaciones.
$st=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='tito_prevent_void_credit_sale_v2'");
$st->execute();
if((int)$st->fetchColumn()===0){
    $db->exec(<<<'SQL'
CREATE TRIGGER tito_prevent_void_credit_sale_v2
 BEFORE UPDATE ON sales FOR EACH ROW
BEGIN
 IF NEW.status='voided' AND OLD.status<>'voided'
   AND EXISTS(SELECT 1 FROM credit_accounts WHERE sale_id=OLD.id AND status<>'voided' LIMIT 1)
 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La anulacion de credito requiere ajuste de cuenta por cobrar';
 END IF;
END
SQL);
}
$db->exec('DROP TRIGGER IF EXISTS tito_prevent_void_credit_sale');
// Sigue vigente tito_prevent_return_credit_sale: la ruta de devoluciones
// ordinaria NO puede crear customer_returns para una venta a crédito.
// El módulo nuevo registra los ajustes en su propio libro vinculado.
foreach(['tito_prevent_void_credit_sale_v2','tito_prevent_return_credit_sale'] as $trigger){
    $st=$db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
    $st->execute([$trigger]);
    if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta protección SQL: '.$trigger);
}
echo "OK: ajustes y reembolsos de crédito instalados; triggers de seguridad verificados.\n";
