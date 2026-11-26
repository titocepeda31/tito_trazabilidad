<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function originReady(PDO $db): void {
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('manufacturers','brands','suppliers','product_origins','product_suppliers','origin_catalog_events')");
    if((int)$s->fetchColumn()!==6)fail('Falta instalar la migración 007 de catálogos de origen.',503);
}
function originFields(string $type): array {
    return match($type){
        'manufacturers'=>['name'=>150,'country'=>100,'website'=>250,'email'=>160,'phone'=>40],
        'brands'=>['name'=>150,'manufacturer_id'=>0],
        'suppliers'=>['name'=>150,'tax_country'=>2,'tax_id'=>50,'contact_name'=>120,'email'=>160,'phone'=>40,'address'=>200],
        default=>throw new HttpError('Catálogo no encontrado.',404)
    };
}
function originText(array $b,string $field,int $max,bool $required=false): string {
    $v=$b[$field]??'';
    if(!is_string($v)||mb_strlen(trim($v))>$max||($required&&trim($v)===''))throw new HttpError('Revisa el valor de este campo.',422,$field);
    return trim($v);
}
function originId(mixed $v,string $field): ?int {
    if($v===null)return null;
    if(!is_int($v)||$v<1)throw new HttpError('Selecciona un registro válido.',422,$field);
    return $v;
}
function originRow(PDO $db,string $table,int $biz,int $id,string $field,bool $active=true): array {
    originFields($table);
    $s=$db->prepare("SELECT * FROM $table WHERE business_id=? AND id=? FOR UPDATE");$s->execute([$biz,$id]);$r=$s->fetch();
    if(!$r||($active&&(int)$r['is_active']!==1))throw new HttpError('Registro inexistente o inactivo en tu empresa.',422,$field);
    return $r;
}
function originLockCompany(PDO $db,int $biz): void {
    $s=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');$s->execute([$biz]);if(!$s->fetchColumn())fail('Empresa no encontrada.',404);
}
function originEvent(PDO $db,array $u,string $type,int $id,array $data): void {
    $s=$db->prepare('INSERT INTO origin_catalog_events(business_id,actor_id,entity_type,entity_id,details) VALUES(?,?,?,?,?)');
    $s->execute([(int)$u['business_id'],(int)$u['id'],$type,$id,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function registerOriginCatalogRoutes(RouteCollectorProxy $group): void {
    $group->get('/origin-catalog',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$db=db();originReady($db);$biz=(int)$u['business_id'];$out=[];
        foreach(['manufacturers','brands','suppliers'] as $table){
            $s=$db->prepare("SELECT * FROM $table WHERE business_id=? ORDER BY is_active DESC,name,id");$s->execute([$biz]);$out[$table]=$s->fetchAll();
        }
        $s=$db->prepare('SELECT DISTINCT supplier_name FROM goods_receipts WHERE business_id=? AND supplier_name IS NOT NULL AND supplier_name<>? ORDER BY supplier_name LIMIT 300');
        $s->execute([$biz,'']);$out['legacy_supplier_names']=$s->fetchAll(PDO::FETCH_COLUMN);
        return respond($response,$out);
    });
    $save=static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$db=db();originReady($db);$biz=(int)$u['business_id'];$type=$args['type'];$fields=originFields($type);$b=requestBody($request);$id=isset($args['id'])?(int)$args['id']:null;
        if(array_diff(array_keys($b),array_merge(array_keys($fields),['is_active'])))fail('Campos no permitidos.',422);
        if(!is_bool($b['is_active']??null))fail('Estado inválido.',422);
        $v=[];
        foreach($fields as $field=>$max)$v[$field]=$max===0?originId($b[$field]??null,$field):originText($b,$field,$max,$field==='name');
        if(isset($v['email'])&&$v['email']!==''&&!filter_var($v['email'],FILTER_VALIDATE_EMAIL))throw new HttpError('Correo electrónico inválido.',422,'email');
        if($type==='manufacturers'&&$v['website']!==''&&(!filter_var($v['website'],FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($v['website'],PHP_URL_SCHEME)),['http','https'],true)))throw new HttpError('Ingresa una dirección web http o https válida.',422,'website');
        if($type==='suppliers'){
            $v['tax_country']=strtoupper($v['tax_country']?:'CL');
            if(!preg_match('/^[A-Z]{2}$/D',$v['tax_country']))throw new HttpError('Usa el código de país de dos letras (CL, US, etc.).',422,'tax_country');
            if($v['tax_id']==='')$v['tax_id']=null;
            elseif($v['tax_country']==='CL'){try{$v['tax_id']=orgRut($v['tax_id']);}catch(HttpError $e){throw new HttpError('RUT inválido.',422,'tax_id');}}
        }
        $v['is_active']=(int)$b['is_active'];
        $db->beginTransaction();
        try{
            originLockCompany($db,$biz);
            $old=$id===null?null:originRow($db,$type,$biz,$id,'name',false);
            if($type==='brands'&&$v['manufacturer_id']!==null){
                originRow($db,'manufacturers',$biz,$v['manufacturer_id'],'manufacturer_id',$v['is_active']===1);
            }
            if($type==='brands'&&$old&&$old['manufacturer_id']!=$v['manufacturer_id']){
                $s=$db->prepare('SELECT product_id FROM product_origins WHERE business_id=? AND brand_id=? LIMIT 1');$s->execute([$biz,$id]);if($s->fetchColumn())fail('La marca ya está asociada a productos. Revisa sus asociaciones antes de cambiar el fabricante.',409);
            }
            if($type==='manufacturers'&&$v['is_active']===0){
                $s=$db->prepare('SELECT id FROM brands WHERE business_id=? AND manufacturer_id=? AND is_active=1 LIMIT 1');$s->execute([$biz,$id]);if($s->fetchColumn())fail('Desactiva o desvincula las marcas activas antes de inactivar el fabricante.',409);
            }
            $s=$db->prepare("SELECT id FROM $type WHERE business_id=? AND name=? AND id<>?");$s->execute([$biz,$v['name'],$id??0]);if($s->fetchColumn())throw new HttpError('Ya existe un registro con ese nombre.',409,'name');
            if($type==='suppliers'&&$v['tax_id']!==null){
                $s=$db->prepare('SELECT id FROM suppliers WHERE business_id=? AND tax_country=? AND tax_id=? AND id<>?');$s->execute([$biz,$v['tax_country'],$v['tax_id'],$id??0]);if($s->fetchColumn())throw new HttpError('Ya existe un proveedor con esa identificación tributaria.',409,'tax_id');
            }
            $keys=array_keys($v);
            if($id===null){
                $s=$db->prepare("INSERT INTO $type (business_id,".implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys)+1,'?')).')');
                $s->execute(array_merge([$biz],array_values($v)));$id=(int)$db->lastInsertId();
            }else{
                $s=$db->prepare("UPDATE $type SET ".implode(',',array_map(static fn($k)=>$k.'=?',$keys)).' WHERE business_id=? AND id=?');
                $s->execute(array_merge(array_values($v),[$biz,$id]));
            }
            originEvent($db,$u,$type,$id,['before'=>$old,'after'=>$v]);$db->commit();return respond($response,['ok'=>true,'id'=>$id]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Ya existe un registro con esos datos.',409);throw $e;}
    };
    $group->post('/origin-catalog/{type:manufacturers|brands|suppliers}',$save);
    $group->post('/origin-catalog/{type:manufacturers|brands|suppliers}/{id:[0-9]+}/update',$save);
    $group->get('/origin-catalog/products',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$db=db();originReady($db);$biz=(int)$u['business_id'];$q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||mb_strlen($q)>120)fail('Búsqueda inválida.',422);
        $term='%'.trim($q).'%';
        $s=$db->prepare('SELECT p.id,p.name,p.sku,p.is_active,o.brand_id,o.manufacturer_id,o.model FROM products p LEFT JOIN product_origins o ON o.product_id=p.id AND o.business_id=p.business_id WHERE p.business_id=? AND (p.name LIKE ? OR p.sku LIKE ?) ORDER BY p.is_active DESC,p.name,p.id LIMIT 100');$s->execute([$biz,$term,$term]);$rows=$s->fetchAll();
        $map=[];foreach($rows as $i=>$r){$rows[$i]['supplier_ids']=[];$map[$r['id']]=$i;}
        if($map){
            $s=$db->prepare('SELECT product_id,supplier_id FROM product_suppliers WHERE business_id=? AND product_id IN ('.implode(',',array_fill(0,count($map),'?')).')');$s->execute(array_merge([$biz],array_keys($map)));foreach($s->fetchAll() as $r)$rows[$map[$r['product_id']]]['supplier_ids'][]=(int)$r['supplier_id'];
        }
        return respond($response,['products'=>$rows]);
    });
    $group->post('/origin-catalog/products/{id:[0-9]+}/update',static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$db=db();originReady($db);$biz=(int)$u['business_id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['manufacturer_id','brand_id','model','supplier_ids'])||!is_array($b['supplier_ids']??null)||!array_is_list($b['supplier_ids'])||count($b['supplier_ids'])>100)fail('Asociaciones inválidas.',422);
        $mid=originId($b['manufacturer_id']??null,'manufacturer_id');$bid=originId($b['brand_id']??null,'brand_id');$model=originText($b,'model',120);
        $sup=[];foreach($b['supplier_ids'] as $sid){$sid=originId($sid,'supplier_ids');if($sid===null)fail('Proveedor inválido.',422);$sup[]=$sid;}$sup=array_values(array_unique($sup));sort($sup);
        $db->beginTransaction();
        try{
            originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id FROM products WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);if(!$s->fetchColumn())fail('Producto no encontrado.',404);
            $s=$db->prepare('SELECT manufacturer_id,brand_id,model FROM product_origins WHERE product_id=? AND business_id=?');$s->execute([$id,$biz]);$old=$s->fetch()?:[];
            $s=$db->prepare('SELECT supplier_id FROM product_suppliers WHERE product_id=? AND business_id=?');$s->execute([$id,$biz]);$oldSup=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
            if($bid!==null){
                $brand=originRow($db,'brands',$biz,$bid,'brand_id',(int)($old['brand_id']??0)!==$bid);
                if($brand['manufacturer_id']!==null){
                    if($mid!==null&&$mid!==(int)$brand['manufacturer_id'])throw new HttpError('El fabricante no coincide con la marca.',422,'manufacturer_id');
                    $mid=(int)$brand['manufacturer_id'];
                }
            }
            if($mid!==null)originRow($db,'manufacturers',$biz,$mid,'manufacturer_id',(int)($old['manufacturer_id']??0)!==$mid);
            foreach($sup as $sid)originRow($db,'suppliers',$biz,$sid,'supplier_ids',!in_array($sid,$oldSup,true));
            $s=$db->prepare('SELECT business_id FROM product_origins WHERE product_id=?');$s->execute([$id]);$owner=$s->fetchColumn();if($owner!==false&&(int)$owner!==$biz)fail('Asociación inconsistente.',409);
            $s=$db->prepare('INSERT INTO product_origins(product_id,business_id,manufacturer_id,brand_id,model) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE manufacturer_id=VALUES(manufacturer_id),brand_id=VALUES(brand_id),model=VALUES(model)');$s->execute([$id,$biz,$mid,$bid,$model]);
            $s=$db->prepare('DELETE FROM product_suppliers WHERE product_id=? AND business_id=?');$s->execute([$id,$biz]);
            $s=$db->prepare('INSERT INTO product_suppliers(product_id,business_id,supplier_id) VALUES(?,?,?)');foreach($sup as $sid)$s->execute([$id,$biz,$sid]);
            originEvent($db,$u,'product',$id,['before'=>array_merge($old,['supplier_ids'=>$oldSup]),'after'=>['manufacturer_id'=>$mid,'brand_id'=>$bid,'model'=>$model,'supplier_ids'=>$sup]]);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
