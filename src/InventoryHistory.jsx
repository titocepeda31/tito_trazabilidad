import React,{useEffect,useMemo,useState} from 'react';
import {qtyFormat,pesos} from './api.js';
import {saveExcel} from './excelExport.js';

const creationOrigins={manual:'Alta manual',excel:'Importación Excel',mercaderia:'Ingreso de mercadería',otro:'Origen no identificado'};
const movementNames={initial:'Stock inicial',sale:'Venta',adjustment:'Ajuste manual',import:'Importación',reversal:'Anulación',receipt:'Ingreso mercadería'};
const movementLabel=r=>r.movement_type==='adjustment'&&String(r.reason||'').startsWith('Devolución cliente #')?'Devolución de cliente':movementNames[r.movement_type]||r.movement_type;
const dateTime=value=>{
  if(!value)return '—';
  const raw=String(value).replace(' ','T');
  const d=new Date(raw.endsWith('Z')?raw:raw+'Z');
  return Number.isNaN(d.valueOf())?String(value):d.toLocaleString('es-CL',{timeZone:'America/Santiago'});
};
const safeCell=value=>{
  const str=String(value??'');
  const guarded=/^[\s]*[=+\-@\t\r]/.test(str)?"'"+str:str;
  return '"'+guarded.replace(/"/g,'""')+'"';
};
function saveCsv(name,headers,rows){
  const contents='\uFEFFsep=;\r\n'+[headers.map(safeCell).join(';'),...rows.map(row=>row.map(safeCell).join(';'))].join('\r\n');
  const blob=new Blob([contents],{type:'text/csv;charset=utf-8;'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
}
const chFields={name:'Nombre',sku:'SKU',barcode:'Código de barras',price_clp:'Precio de venta'};
function describeChange(c){
  const old=c.before||{},next=c.after||{};
  return Object.keys(chFields).filter(k=>old[k]!==next[k]).map(k=>chFields[k]+': '+String(old[k]??'—')+' → '+String(next[k]??'—')).join(' · ');
}

export default function InventoryHistory({request,notify,onBack,products}){
  const [tab,setTab]=useState('movements');
  const [rows,setRows]=useState([]);
  const [cursor,setCursor]=useState(null);
  const [product,setProduct]=useState('');
  const [loading,setLoading]=useState(false);
  const [exporting,setExporting]=useState(false);
  const [requiresApi,setRequiresApi]=useState(false);

  async function load(more=false,kind=tab,pid=product){
    setLoading(true);
    try{
      const params=new URLSearchParams({limit:'100'});
      if(more&&cursor)params.set('before_id',String(cursor));
      if(pid&&kind==='movements')params.set('product_id',pid);
      const result=await request('/inventory/'+kind+'?'+params);
      setRequiresApi(false);
      setRows(prev=>more?[...prev,...(result[kind]||[])]:result[kind]||[]);
      setCursor(result.next_before_id??null);
    }catch(e){if(kind==='creations'&&e.status===404){setRequiresApi(true);setRows([]);setCursor(null);}else notify(e.message,'error');}
    finally{setLoading(false);}
  }
  useEffect(()=>{setRows([]);setCursor(null);load(false,tab,product);},[tab,product]);
  async function exportInventory(){
    setExporting(true);
    try{
      let list=[],after=0,steps=0;
      while(steps++<200){
        const data=await request('/inventory/export?after_id='+after);
        list.push(...data.products);
        if(!data.next_after_id)break;
        after=data.next_after_id;
      }
      if(steps>200)throw new Error('Exportación demasiado grande: utiliza una exportación por lotes.');
      await saveExcel('tito-pos-inventario-'+new Date().toLocaleDateString('sv-SE')+'.xlsx',[
        {key:'id',title:'ID',width:12,type:'text'},
        {key:'name',title:'Producto',width:37},
        {key:'sku',title:'SKU',width:20},
        {key:'barcode',title:'Código de barras',width:22},
        {key:'price_clp',title:'Precio CLP',width:17,type:'number'},
        {key:'sale_unit',title:'Unidad',width:15},
        {key:'stock_qty',title:'Stock total',width:15,type:'number',decimal:true},
        {key:'is_active',title:'Estado',width:13},
        {key:'created_at',title:'Creación UTC',width:23},
        {key:'updated_at',title:'Modificación UTC',width:23}
      ],list.map(p=>({...p,id:String(p.id),is_active:Number(p.is_active)===1?'Activo':'Inactivo'})));
      notify('Excel descargado: '+list.length+' productos.','success');
    }catch(e){notify('No se pudo exportar: '+e.message,'error');}
    finally{setExporting(false);}
  }
  const exportHistory=async()=>{
    try {
      const today=new Date().toLocaleDateString('sv-SE');
      if(tab==='movements')await saveExcel('tito-pos-movimientos-'+today+'.xlsx',[
        {key:'id',title:'ID',width:10},{key:'date',title:'Fecha UTC',width:22},{key:'name',title:'Producto',width:36},{key:'sku',title:'SKU',width:18},{key:'type',title:'Movimiento',width:18},{key:'delta',title:'Variación',width:14,type:'number',decimal:true},{key:'balance',title:'Stock final',width:14,type:'number',decimal:true},{key:'actor',title:'Responsable',width:28},{key:'reason',title:'Motivo',width:38},{key:'sale',title:'Venta',width:12}
      ],rows.map(r=>({id:String(r.id),date:r.created_at,name:r.product_name,sku:r.sku,type:movementLabel(r),delta:r.delta_qty,balance:r.balance_after,actor:r.actor_email,reason:r.reason,sale:r.sale_id})),'Movimientos');
      else if(tab==='creations')await saveExcel('tito-pos-altas-productos-'+today+'.xlsx',[
        {key:'id',title:'ID auditoría',width:16},{key:'product',title:'Producto al crear',width:38},{key:'sku',title:'SKU inicial',width:20},{key:'barcode',title:'Código de barras',width:22},{key:'price',title:'Precio inicial CLP',width:20,type:'number'},{key:'stock',title:'Stock al crear',width:19,type:'number',decimal:true},{key:'unit',title:'Unidad',width:15},{key:'origin',title:'Origen',width:22},{key:'actor',title:'Responsable',width:32},{key:'date',title:'Fecha UTC',width:24}
      ],rows.map(r=>({id:String(r.id),product:r.name_snapshot,sku:r.sku_snapshot,barcode:r.barcode_snapshot,price:r.price_clp,stock:r.stock_initial_qty,unit:r.sale_unit,origin:creationOrigins[r.origin]||r.origin,actor:r.actor_email||'No identificado',date:r.created_at})),'Altas');
      else await saveExcel('tito-pos-cambios-'+today+'.xlsx',[
        {key:'id',title:'ID',width:10},{key:'date',title:'Fecha UTC',width:22},{key:'name',title:'Producto',width:36},{key:'actor',title:'Responsable',width:28},{key:'changes',title:'Detalle de cambios',width:60}
      ],rows.map(r=>({id:String(r.id),date:r.created_at,name:r.product_name,actor:r.actor_email,changes:describeChange(r)})),'Cambios');
      notify('Excel generado con '+rows.length+' registros cargados.','success');
    }catch(e){notify('No se pudo exportar Excel: '+e.message,'error');}
  };
  const exportHistoryCsv=()=>{
    if(tab==='movements')saveCsv('tito-pos-movimientos.csv',['ID','Fecha UTC','Producto','SKU','Tipo','Variación','Stock resultante','Responsable','Motivo','Venta'],rows.map(r=>[r.id,r.created_at,r.product_name,r.sku,movementLabel(r),r.delta_qty,r.balance_after,r.actor_email,r.reason,r.sale_id]));
    else if(tab==='creations')saveCsv('tito-pos-altas-productos.csv',['ID','Fecha UTC','Producto al crear','SKU','Código de barras','Precio CLP','Stock al crear','Unidad','Origen','Responsable'],rows.map(r=>[r.id,r.created_at,r.name_snapshot,r.sku_snapshot,r.barcode_snapshot,r.price_clp,r.stock_initial_qty,r.sale_unit,creationOrigins[r.origin]||r.origin,r.actor_email||'No identificado']));
    else saveCsv('tito-pos-cambios-productos.csv',['ID','Fecha UTC','Producto','Responsable','Cambios'],rows.map(r=>[r.id,r.created_at,r.product_name,r.actor_email,describeChange(r)]));
    notify('Exportadas '+rows.length+' filas cargadas.','success');
  };
  return <div className="audit-page">
    <div className="section-intro"><div><p className="eyebrow">CONTROL DE INVENTARIO</p><h2>Historial y respaldo de datos</h2><p className="muted">Consulta quién realizó cambios y exporta el catálogo completo.</p></div><button className="ghost" onClick={onBack}>← Inventario</button></div>
    <section className="panel pad audit-export" aria-label="Exportar inventario">
      <div><h3>Descarga de inventario para Excel</h3><p className="muted">Archivo .xlsx con columnas, acentos y códigos conservados. Incluye activos e inactivos. No es un respaldo integral de MySQL.</p></div>
      <button className="primary" disabled={exporting} onClick={exportInventory}>{exporting?'Exportando…':'↓ Descargar Excel (.xlsx)'}</button>
    </section>
    <section className="panel pad audit-panel">
      <div className="audit-tabbar" role="tablist"><button type="button" className={tab==='movements'?'primary':'ghost'} onClick={()=>setTab('movements')}>Movimientos de stock</button><button type="button" className={tab==='changes'?'primary':'ghost'} onClick={()=>setTab('changes')}>Cambios de productos</button><button type="button" className={tab==='creations'?'primary':'ghost'} onClick={()=>setTab('creations')}>Creación de productos</button></div>
      <div className="audit-filters">
        {tab==='movements'&&<label>Filtrar por producto<select value={product} onChange={e=>setProduct(e.target.value)}><option value="">Todos los productos</option>{products.map(p=><option key={p.id} value={p.id}>{p.name} · {p.sku||'Sin SKU'}</option>)}</select></label>}
        <button type="button" className="ghost" disabled={loading||!rows.length} onClick={exportHistory}>↓ Excel (filas cargadas)</button>
        <button type="button" className="ghost" disabled={loading} onClick={()=>load(false)}>↻ Actualizar</button>
      </div>
      <div className="table-wrap"><table><thead><tr>{(tab==='movements'?['Fecha','Producto','Movimiento','Variación','Stock final','Responsable','Motivo']:tab==='creations'?['Fecha','Producto creado','Origen','Precio inicial','Stock al crear','Responsable']:['Fecha','Producto','Responsable','Detalle de modificación']).map(h=><th key={h}>{h}</th>)}</tr></thead><tbody>
        {tab==='movements'?rows.map(r=><tr key={r.id}><td>{dateTime(r.created_at)}</td><td><strong>{r.product_name}</strong><small className="sub">{r.sku||'Sin SKU'}</small></td><td>{movementLabel(r)}</td><td className={Number(r.delta_qty)<0?'audit-negative':'audit-positive'}>{Number(r.delta_qty)>0?'+':''}{qtyFormat(r.delta_qty)}</td><td>{qtyFormat(r.balance_after)} {r.sale_unit}</td><td>{r.actor_email}</td><td>{r.reason||'—'}</td></tr>)
        :tab==='creations'?rows.map(r=><tr key={r.id}>
          <td>{dateTime(r.created_at)}</td>
          <td><strong>{r.name_snapshot}</strong><small className="sub">{r.sku_snapshot||r.barcode_snapshot||'Sin código al crear'}</small></td>
          <td>{creationOrigins[r.origin]||'Origen no identificado'}</td>
          <td>{pesos(r.price_clp)}</td>
          <td>{qtyFormat(r.stock_initial_qty)} {r.sale_unit}</td>
          <td>{r.actor_email||'No identificado'}</td>
        </tr>)
        :rows.map(r=><tr key={r.id}><td>{dateTime(r.created_at)}</td><td><strong>{r.product_name}</strong></td><td>{r.actor_email}</td><td className="audit-change-detail">{describeChange(r)||'Sin diferencias relevantes'}</td></tr>)}
      </tbody></table>{!loading&&rows.length===0&&<div className="receipt-empty">{requiresApi&&tab==='creations'?'Para consultar las altas con responsable, primero instala la actualización de auditoría en cPanel.':'No hay registros para mostrar.'}</div>}</div>
      {cursor&&<button className="ghost wide" disabled={loading} onClick={()=>load(true)}>{loading?'Cargando…':'Cargar 100 anteriores'}</button>}
      <p className="helper">{tab==='creations'?'La auditoría de altas empieza al instalarse el registro en cPanel. No asigna responsables retroactivamente a productos antiguos; un origen externo puede aparecer como «No identificado». El stock mostrado es el del instante de creación, antes de una recepción posterior.':'Historial de stock: ventas, ajustes, recepciones y anulaciones.'} Una exportación de filas cargadas no equivale al historial completo.</p>
    </section>
  </div>;
}
