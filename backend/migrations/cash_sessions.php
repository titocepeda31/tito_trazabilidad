<?php
declare(strict_types=1);
/**
 * Tito POS - Caja MVP: SOLO DDL ADITIVO, no reescribe ventas ni saldos anteriores.
 * Ejecutar únicamente sobre copia ensayada y con respaldo SQL completo.
 */
$boot=getenv('TITO_BOOT');
if(!is_string($boot)||!is_file($boot))throw new RuntimeException('TITO_BOOT no configurado');
require $boot;
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada');
foreach(['businesses','users','warehouses','sales','credit_payments','credit_refunds','customer_returns'] as $table){
  $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $st->execute([$table]);
  if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla '.$table);
}
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cash_policy (
  business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  require_open_session TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cash_policy_business FOREIGN KEY(business_id) REFERENCES businesses(id),
  CONSTRAINT fk_cash_policy_user FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cash_drawers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  assigned_cashier_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cash_drawer_code(business_id,warehouse_id,code),
  UNIQUE KEY uq_cash_drawer_identity(business_id,id,warehouse_id),
  CONSTRAINT fk_cash_drawer_business FOREIGN KEY(business_id) REFERENCES businesses(id),
  CONSTRAINT fk_cash_drawer_warehouse FOREIGN KEY(warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_cash_drawer_user FOREIGN KEY(created_by) REFERENCES users(id),
  CONSTRAINT fk_cash_drawer_assigned FOREIGN KEY(assigned_cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cash_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  drawer_id BIGINT UNSIGNED NOT NULL,
  cashier_id BIGINT UNSIGNED NOT NULL,
  opened_by BIGINT UNSIGNED NOT NULL,
  open_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  opening_clp BIGINT NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closing_counted_clp BIGINT NULL,
  closing_expected_clp BIGINT NULL,
  closing_difference_clp BIGINT NULL,
  closing_reason VARCHAR(250) NULL,
  closed_by BIGINT UNSIGNED NULL,
  active_drawer_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='open' THEN drawer_id ELSE NULL END) STORED,
  active_cashier_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='open' THEN cashier_id ELSE NULL END) STORED,
  UNIQUE KEY uq_cash_open_request(business_id,open_request_id),
  UNIQUE KEY uq_cash_one_drawer(business_id,active_drawer_id),
  UNIQUE KEY uq_cash_one_cashier(business_id,active_cashier_id),
  KEY idx_cash_session_branch(business_id,warehouse_id,status),
  UNIQUE KEY uq_cash_session_business(business_id,id),
  CONSTRAINT fk_cash_session_business FOREIGN KEY(business_id) REFERENCES businesses(id),
  CONSTRAINT fk_cash_session_drawer FOREIGN KEY(business_id,drawer_id,warehouse_id) REFERENCES cash_drawers(business_id,id,warehouse_id),
  CONSTRAINT fk_cash_session_cashier FOREIGN KEY(cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cash_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  cash_session_id BIGINT UNSIGNED NOT NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  movement_type ENUM('cash_sale','credit_payment','customer_refund','credit_refund','withdrawal','addition') NOT NULL,
  amount_delta_clp BIGINT NOT NULL,
  source_sale_id BIGINT UNSIGNED NULL,
  source_credit_payment_id BIGINT UNSIGNED NULL,
  source_customer_return_id BIGINT UNSIGNED NULL,
  source_credit_refund_id BIGINT UNSIGNED NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  reference VARCHAR(120) NULL,
  reason VARCHAR(250) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cash_movement_request(business_id,request_id),
  UNIQUE KEY uq_cash_movement_source_sale(source_sale_id),
  UNIQUE KEY uq_cash_movement_source_payment(source_credit_payment_id),
  UNIQUE KEY uq_cash_movement_source_return(source_customer_return_id),
  UNIQUE KEY uq_cash_movement_source_credit_refund(source_credit_refund_id),
  KEY idx_cash_movement_session(cash_session_id,id),
  CONSTRAINT fk_cash_movement_session FOREIGN KEY(business_id,cash_session_id) REFERENCES cash_sessions(business_id,id),
  CONSTRAINT fk_cash_movement_actor FOREIGN KEY(actor_id) REFERENCES users(id),
  CONSTRAINT fk_cash_movement_sale FOREIGN KEY(source_sale_id) REFERENCES sales(id),
  CONSTRAINT fk_cash_movement_payment FOREIGN KEY(source_credit_payment_id) REFERENCES credit_payments(id),
  CONSTRAINT fk_cash_movement_return FOREIGN KEY(source_customer_return_id) REFERENCES customer_returns(id),
  CONSTRAINT fk_cash_movement_credit_refund FOREIGN KEY(source_credit_refund_id) REFERENCES credit_refunds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
// El origen del dinero puede asociarse al turno sin reescribir operaciones anteriores.
foreach([
 'credit_payments'=>['cash_session_id','fk_credit_payment_cash_session','idx_credit_payment_cash_session'],
 'credit_refunds'=>['cash_session_id','fk_credit_refund_cash_session','idx_credit_refund_cash_session'],
 'customer_returns'=>['refund_cash_session_id','fk_customer_return_cash_session','idx_customer_return_cash_session']
] as $table=>$spec){
  [$column,$fk,$idx]=$spec;
  $st=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
  $st->execute([$table,$column]);
  if((int)$st->fetchColumn()===0){
    $db->exec("ALTER TABLE $table ADD COLUMN $column BIGINT UNSIGNED NULL,
      ADD KEY $idx(business_id,$column),
      ADD CONSTRAINT $fk FOREIGN KEY(business_id,$column) REFERENCES cash_sessions(business_id,id)");
  }
}
// Una venta anterior no pertenece retroactivamente a un turno de dinero.
$st=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='cash_session_id'");
$st->execute();
if((int)$st->fetchColumn()===0){
  $db->exec('ALTER TABLE sales ADD COLUMN cash_session_id BIGINT UNSIGNED NULL,
      ADD KEY idx_sale_cash_session(business_id,cash_session_id),
      ADD CONSTRAINT fk_sales_cash_session FOREIGN KEY(business_id,cash_session_id) REFERENCES cash_sessions(business_id,id)');
}
foreach(['cash_drawers'=>['warehouse_id','code','is_active','assigned_cashier_id'],
         'cash_sessions'=>['drawer_id','cashier_id','status','active_drawer_id','active_cashier_id','opening_clp'],
         'cash_movements'=>['cash_session_id','amount_delta_clp','source_sale_id','source_credit_payment_id','source_customer_return_id','source_credit_refund_id'],
         'credit_payments'=>['cash_session_id'],
         'credit_refunds'=>['cash_session_id'],
         'customer_returns'=>['refund_cash_session_id'],
         'sales'=>['cash_session_id']] as $table=>$columns){
 foreach($columns as $col){
  $st=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
  $st->execute([$table,$col]);
  if((int)$st->fetchColumn()!==1)throw new RuntimeException("Esquema incompleto: $table.$col");
 }
}
echo "OK: estructura aditiva de caja instalada; sesiones y gavetas existentes preservadas. Control obligatorio DESACTIVADO por defecto.\n";
