<?php
declare(strict_types=1);
// Instalación aditiva: no modifica ventas, productos, stock ni cajas existentes.
// Requiere TITO_BOOT y copia SQL previa; ejecutar fuera del horario de ventas.
$boot=getenv('TITO_BOOT');
if(!$boot||!is_file($boot))throw new RuntimeException('Falta TITO_BOOT');
require $boot;
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada');
foreach(['businesses','users','warehouses','sales'] as $name){
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$name]);
    if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla '.$name);
}
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_terminal_policy (
 business_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 enforce_registered_terminal TINYINT(1) NOT NULL DEFAULT 0,
 changed_by BIGINT UNSIGNED NOT NULL,
 changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_terminal_policy_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_terminal_policy_actor FOREIGN KEY(changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_terminal_pairings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 kind ENUM('fixed','shift') NOT NULL,
 label VARCHAR(80) NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 redeemed_at DATETIME NULL,
 redeemed_by BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_terminal_code_hash (code_hash),
 KEY idx_terminal_pairing_business (business_id,created_at),
 CONSTRAINT fk_terminal_pairing_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_terminal_pairing_warehouse FOREIGN KEY(warehouse_id) REFERENCES warehouses(id),
 CONSTRAINT fk_terminal_pairing_actor FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_terminals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 activated_by BIGINT UNSIGNED NOT NULL,
 kind ENUM('fixed','shift') NOT NULL,
 label VARCHAR(80) NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 revoked_by BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_pos_terminal_hash (token_hash),
 KEY idx_pos_terminal_business (business_id,warehouse_id,revoked_at),
 CONSTRAINT fk_pos_terminal_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_pos_terminal_warehouse FOREIGN KEY(warehouse_id) REFERENCES warehouses(id),
 CONSTRAINT fk_pos_terminal_user FOREIGN KEY(activated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$missing=[];
foreach([
 'pos_terminal_policy'=>['business_id','enforce_registered_terminal'],
 'pos_terminal_pairings'=>['business_id','warehouse_id','code_hash','redeemed_at','kind','expires_at'],
 'pos_terminals'=>['business_id','warehouse_id','token_hash','revoked_at','expires_at','kind']
] as $table=>$cols){
 foreach($cols as $col){
  $q=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
  $q->execute([$table,$col]);if((int)$q->fetchColumn()!==1)$missing[]="$table.$col";
 }
}
if($missing)throw new RuntimeException('Esquema de cajas incompleto: '.implode(',',$missing));
echo "OK: tablas de autorización de cajas creadas o existentes. Modo estricto DESACTIVADO por defecto. Ningún stock fue modificado.\n";
