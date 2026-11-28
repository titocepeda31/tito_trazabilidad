<?php
/** Importación auditable por lotes, con idempotencia, bloqueo de productos y aislamiento por negocio. */
declare(strict_types=1);

function importProducts(PDO $db,int $businessId,int $userId,array $input): array {
    $importId=$input['import_id']??null;
    $chunk=$input['chunk_index']??null;
    $mode=$input['stock_mode']??'preserve_existing';
    $autoSku=$input['generate_missing_sku']??false;if(!is_bool($autoSku))fail('Opción de SKU inválida.',422);
    // Clientes anteriores sin warehouse_id continúan usando el local original.
    // La interfaz multisucursal nueva siempre envía un ID explícito.
    $warehouseId=$input['warehouse_id']??null;
    if($warehouseId!==null&&(!is_int($warehouseId)||$warehouseId<1))fail('Ubicación de inventario inválida.',422);
    $taxMode=$input['price_import_version']??null;
    if($taxMode!==null&&$taxMode!==1)fail('Configuración de precios inválida.',422);
    $rows=$input['rows']??null;
    if(!is_string($importId)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$importId))fail('Identificador de importación inválido.',422);
    if(!is_int($chunk)||$chunk<0||$chunk>100)fail('Número de lote inválido.',422);
    if(!in_array($mode,['preserve_existing','set_to_excel','add_excel'],true))fail('Modo de stock inválido.',422);
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>30)fail('El lote debe contener entre 1 y 30 productos.',422);

    $validated=[];$seenSkus=[];$seenBarcodes=[];
    foreach($rows as $index=>$r){
        if(!is_array($r)||array_is_list($r))fail('Fila '.($index+1).' inválida.',422);
        $sku=$r['sku']??null;
        $barcode=$r['barcode']??null;
        foreach(['sku','barcode'] as $field){
            $code=$field==='sku'?$sku:$barcode;
            if($code!==null&&!is_string($code))fail('Código inválido en fila '.($index+1).'.',422);
            $code=$code===null?null:trim($code);
            if($code!==null&&strlen($code)>50)fail('Código demasiado largo en fila '.($index+1).'.',422);
            if($field==='sku')$sku=$code?:null;else $barcode=$code?:null;
        }
        if($sku===null&&$barcode===null&&!$autoSku)fail('Se necesita SKU o código de barras en fila '.($index+1).'.',422);
        if($sku!==null){
            $key=mb_strtoupper($sku,'UTF-8');
            if(isset($seenSkus[$key]))fail('Hay SKU duplicados en el lote.',422);
            $seenSkus[$key]=true;
        }
        if($barcode!==null){
            $key=mb_strtoupper($barcode,'UTF-8');
            if(isset($seenBarcodes[$key]))fail('Hay códigos de barras duplicados en el lote.',422);
            $seenBarcodes[$key]=true;
        }
        $name=requireString($r,'name',120);
        $price=integerPrice($r['price_clp']??null);$source=$price;$basis=null;$treatment=null;
        if($taxMode===1){
            $basis=$r['source_price_basis']??null;$treatment=$r['tax_treatment']??null;
            if(!in_array($basis,['gross','net'],true)||!in_array($treatment,['vat19','exempt','non_taxable'],true))fail('Confirma si el precio incluye IVA y el tratamiento de cada producto.',422);
            if($basis==='net'&&$treatment==='vat19')$price=integerPrice($source+intdiv($source*19+50,100));
        }
        $unit=$r['sale_unit']??'unidad';
        if(!in_array($unit,['unidad','kg','litro'],true))fail('Unidad inválida en fila '.($index+1).'.',422);
        $stockRaw=$r['stock_qty']??null;
        if($mode!=='preserve_existing'&&$stockRaw===null)fail('Falta stock en fila '.($index+1).'.',422);
        $stock=$stockRaw===null?null:decimalQuantity($stockRaw,false,$unit!=='unidad');
        $entry=['sku'=>$sku,'barcode'=>$barcode,'name'=>$name,'price'=>$price,'unit'=>$unit,'stock'=>$stock];
        if($taxMode===1)$entry+=['source_price'=>$source,'source_basis'=>$basis,'tax_treatment'=>$treatment];
        $validated[]=$entry;
    }
    // Nuevos lotes ligan la sucursal a la huella. Sin warehouse_id conservamos
    // la huella histórica para permitir reintentos idempotentes del cliente anterior.
    $hashData=$warehouseId===null
        ?['mode'=>$mode,'rows'=>$validated]
        :['mode'=>$mode,'warehouse_id'=>$warehouseId,'rows'=>$validated];
    if($autoSku)$hashData['generate_missing_sku']=true;
    if($taxMode===1)$hashData['price_import_version']=1;
    $payloadHash=hash('sha256',json_encode($hashData,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $prior=$db->prepare('SELECT payload_hash,result_json FROM product_import_chunks WHERE business_id=? AND import_id=? AND chunk_index=?');
    $summary=['created'=>0,'updated'=>0,'unchanged'=>0,'stock_adjusted'=>0];
    $respondPrior=static function()use($prior,$businessId,$importId,$chunk,$payloadHash): array{
        $prior->execute([$businessId,$importId,$chunk]);$old=$prior->fetch();$prior->closeCursor();
        if(!$old)fail('Ya existe otro producto con un código duplicado. No se guardó el lote.',409);
        if(!hash_equals((string)$old['payload_hash'],$payloadHash))fail('Ya existe un lote con el mismo identificador y contenido diferente.',409);
        $data=json_decode((string)$old['result_json'],true,16,JSON_THROW_ON_ERROR);
        return ['ok'=>true,'already_recorded'=>true,'summary'=>$data];
    };
    try{
        $db->beginTransaction();originLockCompany($db,$businessId);
        // Replays must return the original result even if the current fiscal configuration has changed.
        $prior->execute([$businessId,$importId,$chunk]);$recorded=$prior->fetchColumn();$prior->closeCursor();
        if($recorded!==false){$result=$respondPrior();$db->commit();return $result;}
        $first=$db->prepare('SELECT result_json FROM product_import_chunks WHERE business_id=? AND import_id=? ORDER BY chunk_index LIMIT 1');$first->execute([$businessId,$importId]);$started=$first->fetchColumn();$first->closeCursor();
        if($started!==false){
            $previous=json_decode((string)$started,true,16,JSON_THROW_ON_ERROR);
            if(array_key_exists('price_audit',$previous)!==($taxMode===1))fail('No se puede cambiar el modo de precios de una importación iniciada. Reintenta la solicitud original.',409);
        }
        if($taxMode===null){
            if($started===false)fail('Actualiza el importador y confirma si los precios incluyen IVA antes de iniciar una carga nueva.',422);
        }else{
            $cfg=dteConfig($db,$businessId);if($cfg&&$cfg['price_basis']==='net')fail('La empresa está configurada con precios netos. Revisa la configuración tributaria y los precios existentes antes de cargar precios finales. No se modificó este lote.',409);
        }
        $taxDay=$taxMode===1?quoteToday():null;$priceAudit=[];
        $taxWrite=static function(int $pid,array $r)use($db,$businessId,$userId,$importId,$chunk,$taxDay,$payloadHash,$taxMode):void{
            if($taxMode!==1)return;
            $current=dteTaxPolicy($db,$businessId,$pid,$taxDay);
            if($current&&$current['treatment']!=='unknown'&&$current['treatment']!==$r['tax_treatment'])fail('El impuesto del producto '.$r['name'].' no coincide con su clasificación vigente. Revísalo en Documentos tributarios → Impuestos por producto. No se guardó el lote.',409);
            if($current&&$current['treatment']===$r['tax_treatment'])return;
            $s=$db->prepare('INSERT INTO product_tax_versions(business_id,product_id,treatment,effective_on,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?)');
            $s->execute([$businessId,$pid,$r['tax_treatment'],$taxDay,'Clasificación confirmada en importación '.$importId.' lote '.($chunk+1),$userId,$importId,$payloadHash]);
        };
        if($warehouseId===null){
            $st=$db->prepare("SELECT id,name FROM warehouses WHERE business_id=? AND code='local' AND is_active=1 FOR UPDATE");
            $st->execute([$businessId]);
        }else{
            $st=$db->prepare('SELECT id,name FROM warehouses WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            $st->execute([$businessId,$warehouseId]);
        }
        $location=$st->fetch();$st->closeCursor();
        if(!$location)fail('La sucursal o bodega de destino no existe o no está activa en esta empresa.',422);
        $destinationId=(int)$location['id'];
        $destinationName=(string)$location['name'];
        $ins=$db->prepare('INSERT INTO product_import_chunks (business_id,import_id,chunk_index,payload_hash,result_json,created_by) VALUES(?,?,?,?,?,?)');
        $ins->execute([$businessId,$importId,$chunk,$payloadHash,'{}',$userId]);
        $find=$db->prepare('SELECT id,name,sku,barcode,price_clp,sale_unit,stock_qty FROM products WHERE business_id=? AND (sku=? OR barcode=?) FOR UPDATE');
        $create=$db->prepare('INSERT INTO products(business_id,name,sku,barcode,price_clp,sale_unit,stock_qty) VALUES(?,?,?,?,?,?,0)');
        // No modificar products.stock_qty al actualizar datos de catálogo:
        // las existencias se cambian exclusivamente en la ubicación seleccionada.
        $update=$db->prepare('UPDATE products SET name=?,sku=?,barcode=?,price_clp=? WHERE id=? AND business_id=?');
        $locationBalance=$db->prepare('SELECT qty FROM warehouse_stocks WHERE business_id=? AND product_id=? AND warehouse_id=? FOR UPDATE');
        foreach($validated as $r){
            $find->execute([$businessId,$r['sku'],$r['barcode']]);$matches=$find->fetchAll();$find->closeCursor();
            if(count($matches)>1)fail('SKU y código de barras pertenecen a productos diferentes. Corrige el Excel.',409);
            $old=$matches[0]??null;
            if(!$old){
                if($r['sku']===null&&$r['barcode']===null){
                    $check=$db->prepare('SELECT id FROM products WHERE business_id=? AND name=? LIMIT 1 FOR UPDATE');$check->execute([$businessId,$r['name']]);$existing=$check->fetchColumn();$check->closeCursor();if($existing)fail('Producto sin código con nombre ya existente: '.$r['name'].'. Identifica su SKU antes de importar.',409);
                }
                if($autoSku&&$r['sku']===null)$r['sku']=productAutoSku($db,$businessId);
                // El trigger crea saldos en cero en todas las ubicaciones.
                // El stock del archivo entra SOLAMENTE en la sucursal elegida.
                $create->execute([$businessId,$r['name'],$r['sku'],$r['barcode'],$r['price'],$r['unit']]);
                $pid=(int)$db->lastInsertId();
                $taxWrite($pid,$r);
                if($taxMode===1)$priceAudit[]=['product_id'=>$pid,'source_price_clp'=>$r['source_price'],'source_basis'=>$r['source_basis'],'tax_treatment'=>$r['tax_treatment'],'stored_final_clp'=>$r['price']];
                $initial=$r['stock']??'0.000';
                if((float)$initial>0){
                    $after=titoApplyLocationStock($db,$businessId,$pid,$destinationId,(float)$initial);
                    move($db,$businessId,$pid,null,$userId,'initial',$initial,$after,'Importación Excel: '.$destinationName);
                }
                $summary['created']++;
                continue;
            }
            // Nunca convertir un producto a otra unidad ni sustituir identificadores distintos sin revisión humana.
            if($old['sale_unit']!==$r['unit'])fail('Unidad distinta para producto existente: '.$r['name'],409);
            if($r['sku']!==null&&$old['sku']!==null&&strcasecmp($old['sku'],$r['sku'])!==0)fail('Conflicto de SKU para '.$r['name'],409);
            if($r['barcode']!==null&&$old['barcode']!==null&&strcasecmp($old['barcode'],$r['barcode'])!==0)fail('Conflicto de código de barras para '.$r['name'],409);
            $sku=$old['sku']??$r['sku'];$barcode=$old['barcode']??$r['barcode'];
            if($autoSku&&$sku===null)$sku=productAutoSku($db,$businessId);
            $pid=(int)$old['id'];
            $taxWrite($pid,$r);
            if($taxMode===1)$priceAudit[]=['product_id'=>$pid,'source_price_clp'=>$r['source_price'],'source_basis'=>$r['source_basis'],'tax_treatment'=>$r['tax_treatment'],'stored_final_clp'=>$r['price'],'previous_price_clp'=>(int)$old['price_clp']];
            $delta=0.0;
            if($mode!=='preserve_existing'){
                // El producto se encuentra bloqueado con FOR UPDATE, por lo que
                // otros movimientos legítimos esperan antes de cambiar saldos.
                $locationBalance->execute([$businessId,$pid,$destinationId]);
                $current=$locationBalance->fetchColumn();$locationBalance->closeCursor();
                if($current===false)fail('Falta saldo del producto en la ubicación seleccionada.',409);
                $targetQty=$mode==='add_excel'?(float)$current+(float)$r['stock']:(float)$r['stock'];
                if($targetQty>1000000000)fail('Stock de sucursal fuera de rango.',422);
                $delta=$targetQty-(float)$current;
            }
            $catalogChanged=$old['name']!==$r['name']||(int)$old['price_clp']!==$r['price']
                ||($old['sku']??null)!==$sku||($old['barcode']??null)!==$barcode;
            if(!$catalogChanged&&abs($delta)<0.0005){$summary['unchanged']++;continue;}
            if($catalogChanged)$update->execute([$r['name'],$sku,$barcode,$r['price'],$pid,$businessId]);
            if(abs($delta)>=0.0005){
                $after=titoApplyLocationStock($db,$businessId,$pid,$destinationId,$delta);
                $movement=number_format($delta,3,'.','');
                move($db,$businessId,$pid,null,$userId,'import',$movement,$after,'Importación Excel en '.$destinationName);
                $summary['stock_adjusted']++;
            }
            $summary['updated']++;
        }
        $finish=$db->prepare('UPDATE product_import_chunks SET result_json=? WHERE business_id=? AND import_id=? AND chunk_index=?');
        if($taxMode===1)$summary['price_audit']=$priceAudit;
        $finish->execute([json_encode($summary,JSON_THROW_ON_ERROR),$businessId,$importId,$chunk]);
        $db->commit();
        return ['ok'=>true,'summary'=>$summary];
    }catch(PDOException $e){
        if($db->inTransaction())$db->rollBack();
        if((int)($e->errorInfo[1]??0)===1062){return $respondPrior();}
        throw $e;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
