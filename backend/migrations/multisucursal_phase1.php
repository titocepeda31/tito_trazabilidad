<?php
declare(strict_types=1);
// Tito POS 2.0: estructura aditiva para varias ubicaciones y futura asignación de cajas.
// NO ejecutar en producción: requiere respaldo y validación de toda la operación multisucursal.
require getenv('TITO_BOOT');
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada. Migración detenida.');
function titoMultisucursalColumn(PDO $db,string $table,string $column): bool {
    $st=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table,$column]);return (int)$st->fetchColumn()>0;
}
foreach(['warehouses','warehouse_stocks','users','sales'] as $table) {
    $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla previa: '.$table);
}
if(!titoMultisucursalColumn($pdo,'warehouses','is_active')){
    $pdo->exec('ALTER TABLE warehouses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
}
if(!titoMultisucursalColumn($pdo,'users','sales_warehouse_id')){
    $pdo->exec('ALTER TABLE users ADD COLUMN sales_warehouse_id BIGINT UNSIGNED NULL, ADD KEY idx_users_sales_warehouse (sales_warehouse_id), ADD CONSTRAINT fk_users_sales_warehouse FOREIGN KEY (sales_warehouse_id) REFERENCES warehouses(id)');
}
if(!titoMultisucursalColumn($pdo,'sales','warehouse_id')){
    $pdo->exec('ALTER TABLE sales ADD COLUMN warehouse_id BIGINT UNSIGNED NULL, ADD KEY idx_sales_warehouse (business_id,warehouse_id,id), ADD CONSTRAINT fk_sales_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)');
}
// Identificar ventas históricas con el local original, sin crear movimientos ni alterar productos.
$pdo->exec("UPDATE sales s JOIN warehouses w ON w.business_id=s.business_id AND w.code='local' SET s.warehouse_id=w.id WHERE s.warehouse_id IS NULL");
// No asignar cajeros ni mover stock automáticamente: requiere decisión administrativa.
$bad=$pdo->query("SELECT p.id,p.business_id,p.stock_qty,COALESCE(SUM(ws.qty),0) AS location_total FROM products p LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.business_id=p.business_id GROUP BY p.id,p.business_id,p.stock_qty HAVING ABS(p.stock_qty-location_total)>0.0005 LIMIT 3")->fetchAll();
if($bad)throw new RuntimeException('Inventario inconsistente; detener sin corregir automáticamente: '.json_encode($bad));
echo "OK: estructura multisucursal aditiva preparada. Inventario conciliado; sin asignación de cajas ni traslados.\n";
