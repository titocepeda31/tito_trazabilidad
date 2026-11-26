import React, {useEffect,useMemo,useState} from 'react';
import {newId,pesos,qtyFormat} from './api.js';

const storageKey='tito_pending_receipt';
const localDate=()=>new Date().toLocaleDateString('sv-SE',{timeZone:'America/Santiago'});

export default function ReceiptsPanel({products,request,onChange,notify}){
  const [supplier,setSupplier]=useState('');
  const [docType,setDocType]=useState('sin_documento');
  const [docNumber,setDocNumber]=useState('');
  const [date,setDate]=useState(localDate);
  const [search,setSearch]=useState('');
  const [showNew,setShowNew]=useState(false);
  const [lines,setLines]=useState([]);
  const [receipts,setReceipts]=useState([]);
  const [selected,setSelected]=useState(null);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(false);
  const [pending,setPending]=useState(()=>{
    try{return JSON.parse(sessionStorage.getItem(storageKey)||'null');}catch{return null;}
  });
  const locked=busy||Boolean(pending);

  const results=useMemo(()=>products.filter(p=>[p.name,p.sku,p.barcode].some(v=>String(v??'').toLowerCase().includes(search.toLowerCase()))).filter(p=>!lines.some(l=>!l.new_product&&l.product_id===Number(p.id))).slice(0,8),[products,search,lines]);
  const total=lines.reduce((s,l)=>s+Math.round(Number(l.quantity)*Number(l.unit_cost_clp)),0);

  async function load(){
    setLoading(true);
    try {const data=await request('/receipts');setReceipts(data.receipts||[]);}
    catch(error){notify(error.message,'error');}
    finally{setLoading(false);}
  }
  useEffect(()=>{load();},[]);

  function add(p){
    if(locked)return;
    if(lines.length>=80){notify('Máximo 80 productos por recepción.','error');return;}
    setLines(a=>[...a,{line_key:'existing-'+p.id,product_id:Number(p.id),product:p,quantity:'1',unit_cost_clp:''}]);
    setSearch('');
  }
  function addNew(e){
    e.preventDefault();
    if(locked)return;
    if(lines.length>=80){notify('Máximo 80 productos por recepción.','error');return;}
    const f=new FormData(e.currentTarget);
    const name=String(f.get('name')||'').trim();
    const sku=String(f.get('sku')||'').trim()||null;
    const barcode=String(f.get('barcode')||'').trim()||null;
    const price=Number(f.get('price_clp'));
    const unit=String(f.get('sale_unit')||'unidad');
    if(!name||name.length>120||!Number.isInteger(price)||price<0||price>1000000000){notify('Revisa el nombre y precio del producto.','error');return;}
    if(products.some(p=>p.name.trim().toLocaleLowerCase('es-CL')===name.toLocaleLowerCase('es-CL'))||
       lines.some(l=>l.new_product?.name.toLocaleLowerCase('es-CL')===name.toLocaleLowerCase('es-CL'))){
      notify('Ya existe un producto con ese nombre. Búscalo y selecciónalo para evitar duplicados.','error');return;
    }
    if((sku||barcode)&&([...products,...lines.filter(l=>l.new_product).map(l=>l.new_product)].some(p=>
       (sku&&p.sku===sku)||(barcode&&p.barcode===barcode)))){
      notify('Ya existe un producto con el mismo SKU o código de barras.','error');return;
    }
    setLines(a=>[...a,{line_key:'new-'+newId(),new_product:{name,sku,barcode,price_clp:price,sale_unit:unit},quantity:'1',unit_cost_clp:''}]);
    setShowNew(false);setSearch('');
    notify('Producto nuevo agregado a esta recepción. Se creará al confirmar el ingreso.','info');
  }
  function change(id,key,value){setLines(a=>a.map(l=>l.line_key===id?{...l,[key]:value}:l));}
  function remove(id){setLines(a=>a.filter(l=>l.line_key!==id));}

  async function send(body){
    setBusy(true);
    try{
      const result=await request('/receipts',{method:'POST',body});
      sessionStorage.removeItem(storageKey);setPending(null);
      notify(result.already_recorded?'La recepción ya estaba registrada; no se duplicó el stock.':'Recepción #'+result.receipt_id+' registrada. Stock actualizado.'+(result.new_products_created?' '+result.new_products_created+' producto(s) nuevo(s) creados.':''),'success');
      setLines([]);setSupplier('');setDocNumber('');setDocType('sin_documento');setSearch('');
      await Promise.all([load(),onChange()]);
    }catch(error){
      notify('No se pudo confirmar el resultado: '+error.message+'. Reintenta exactamente el mismo envío o verifica el historial antes de descartarlo.','error');
    }finally{setBusy(false);}
  }
  function submit(e){
    e.preventDefault();
    if(locked)return;
    if(!lines.length){notify('Agrega al menos un producto.','error');return;}
    const s=supplier.trim();
    if(!s){notify('Ingresa un proveedor.','error');return;}
    const doc=docNumber.trim();
    if(docType==='sin_documento'&&doc){notify('Selecciona un tipo de documento.','error');return;}
    if(docType!=='sin_documento'&&!doc){notify('Indica el número de documento.','error');return;}
    const items=[];
    for(const line of lines){
      const qty=Number(line.quantity),cost=Number(line.unit_cost_clp);
      const unit=line.new_product?.sale_unit||line.product.sale_unit;
      const fractional=unit!=='unidad';
      if(!Number.isFinite(qty)||qty<=0||qty>1000000000||(!fractional&&!Number.isInteger(qty))||Math.abs(qty*1000-Math.round(qty*1000))>0.00001){
        notify('Revisa la cantidad de '+(line.new_product?.name||line.product.name)+'.','error');return;
      }
      if(line.unit_cost_clp===''||!Number.isInteger(cost)||cost<0||cost>1000000000){
        notify('Revisa el costo unitario de '+(line.new_product?.name||line.product.name)+'.','error');return;
      }
      items.push({
        ...(line.new_product?{new_product:line.new_product}:{product_id:line.product_id}),
        quantity:qty,unit_cost_clp:cost
      });
    }
    const body={request_id:newId(),supplier_name:s,document_type:docType,document_number:doc||null,received_at:date,items};
    sessionStorage.setItem(storageKey,JSON.stringify(body));
    setPending(body);
    send(body);
  }

  async function view(id){
    setBusy(true);
    try{const data=await request('/receipts/'+id);setSelected(data);}
    catch(error){notify(error.message,'error');}
    finally{setBusy(false);}
  }
  function discard(){
    if(!window.confirm('Solo descarta el intento después de comprobar que no se registró la recepción. ¿Descartar y habilitar un nuevo registro?'))return;
    sessionStorage.removeItem(storageKey);setPending(null);
  }

  return <div className="receipts-page">
    <div className="section-intro"><div><p className="eyebrow">INVENTARIO</p><h2>Ingreso de mercadería</h2><p className="muted">Recibe productos, registra costos y suma cantidades al stock existente.</p></div></div>
    <section className="panel pad receipt-form">
      <div className="panel-heading"><div><h3>Nueva recepción</h3><p>El precio de venta del catálogo no cambiará.</p></div></div>
      {pending&&<div className="receipt-alert"><strong>Recepción pendiente de confirmación</strong><p>No crees otra recepción hasta verificar el resultado en el historial. Puedes reintentar con el mismo identificador; el servidor evita duplicar el stock.</p><div className="receipt-buttons"><button className="primary" disabled={busy} type="button" onClick={()=>send(pending)}>{busy?'Verificando…':'Reintentar mismo registro'}</button><button className="ghost" disabled={busy} onClick={discard}>Descartar tras verificar</button></div></div>}
      <form className="formstack" onSubmit={submit}>
        <div className="receipt-fields">
          <label>Proveedor<input required value={supplier} maxLength={150} disabled={locked} onChange={e=>setSupplier(e.target.value)} placeholder="Ej. Distribuidora Central"/></label>
          <label>Fecha de recepción<input type="date" required value={date} disabled={locked} onChange={e=>setDate(e.target.value)}/></label>
          <label>Documento<select value={docType} disabled={locked} onChange={e=>{setDocType(e.target.value);setDocNumber('');}}><option value="sin_documento">Sin documento</option><option value="factura">Factura</option><option value="guia">Guía de despacho</option><option value="boleta">Boleta</option></select></label>
          {docType!=='sin_documento'&&<label>Número de documento<input required maxLength={80} value={docNumber} disabled={locked} onChange={e=>setDocNumber(e.target.value)} placeholder="Ej. 15234"/></label>}
        </div>
        <div className="receipt-product-picker">
          <div className="receipt-picker-heading"><label>Buscar producto<input type="search" value={search} disabled={locked} onChange={e=>setSearch(e.target.value)} placeholder="Nombre, SKU o código de barras…"/></label><button type="button" className="ghost receipt-new-button" disabled={locked} onClick={()=>setShowNew(true)}>+ Producto nuevo</button></div>
          {search.trim()&&<div className="receipt-search-results">{results.map(p=><button type="button" key={p.id} className="receipt-search-item" disabled={locked} onClick={()=>add(p)}><strong>{p.name}</strong><span>{p.sku||p.barcode||'Sin código'} · {qtyFormat(p.stock_qty)} {p.sale_unit} en stock</span><b>+ Agregar</b></button>)}{results.length===0&&<p className="muted">No hay productos disponibles con ese criterio.</p>}</div>}
          <p className="helper">Si el producto ya existe, selecciónalo. Si no está registrado, pulsa «+ Producto nuevo» para incorporarlo a esta misma recepción.</p>
        </div>
        {lines.length===0?<div className="receipt-empty">Aún no hay productos agregados.</div>:<div className="receipt-items">
          {lines.map(l=>{
            const np=l.new_product;
            const product=np||l.product;
            const unit=product.sale_unit;
            return <div className="receipt-item" key={l.line_key}>
            <div className="receipt-item-title"><div><strong>{product.name}</strong>{np&&<span className="receipt-new-label">Nuevo</span>}<small>{product.sku||product.barcode||(np?'SKU interno automático':'Sin código')} · Stock actual {np?'0':qtyFormat(l.product.stock_qty)} {unit}</small>{np&&<small>Precio de venta: {pesos(product.price_clp)}</small>}</div><button className="ghost mini" type="button" disabled={locked} onClick={()=>remove(l.line_key)}>Quitar</button></div>
            <div className="receipt-item-values">
              <label>Cantidad ({unit})<input type="number" required min={unit==='unidad'?'1':'0.001'} step={unit==='unidad'?'1':'0.001'} max="1000000000" value={l.quantity} disabled={locked} onChange={e=>change(l.line_key,'quantity',e.target.value)}/></label>
              <label>Costo unitario (CLP)<input type="number" required min="0" step="1" max="1000000000" placeholder="0" value={l.unit_cost_clp} disabled={locked} onChange={e=>change(l.line_key,'unit_cost_clp',e.target.value)}/></label>
              <div className="receipt-line-total"><small>Subtotal</small><strong>{pesos(Number(l.quantity)*Number(l.unit_cost_clp))}</strong></div>
            </div>
          </div>;
          })}
        </div>}
        <div className="receipt-total"><span>Total costo de recepción</span><strong>{pesos(total)}</strong></div>
        <p className="helper">Los costos son datos internos. Esta pantalla no emite ni registra documentos tributarios ante el SII.</p>
        <button type="submit" className="primary wide" disabled={locked||!lines.length}>{busy?'Registrando…':`Confirmar recepción de ${lines.length} producto(s)`}</button>
      </form>
    </section>
    <section className="panel"><div className="panel-heading pad"><div><h3>Últimas recepciones</h3><p>Historial de ingresos realizados.</p></div><button className="ghost" onClick={load} disabled={loading}>↻ Actualizar</button></div>
      <div className="table-wrap"><table><thead><tr><th>#</th><th>Fecha recepción</th><th>Proveedor</th><th>Documento</th><th>Productos</th><th>Costo total</th><th></th></tr></thead><tbody>{receipts.map(r=><tr key={r.id}><td><strong>#{r.id}</strong></td><td>{r.received_at}</td><td>{r.supplier_name}</td><td>{r.document_type==='sin_documento'?'Sin documento':r.document_type+' '+(r.document_number||'')}</td><td>{r.item_count}</td><td><strong>{pesos(r.total_cost_clp)}</strong></td><td><button type="button" className="ghost mini" disabled={busy} onClick={()=>view(r.id)}>Ver</button></td></tr>)}</tbody></table>{!loading&&!receipts.length&&<p className="muted receipt-no-data">No hay recepciones registradas.</p>}</div>
    </section>
    {showNew&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setShowNew(false);}}><div className="modal" role="dialog" aria-modal="true" aria-label="Agregar producto nuevo"><div className="modal-head"><h3>Nuevo producto para esta recepción</h3><button type="button" className="ghost close" onClick={()=>setShowNew(false)} aria-label="Cerrar">×</button></div>
      <form className="formstack" onSubmit={addNew}>
        <label>Nombre del producto<input name="name" required maxLength="120" defaultValue={search} placeholder="Ej. Tornillo 2 pulgadas"/></label>
        <div className="field-row"><label>SKU (opcional)<input name="sku" maxLength="50" placeholder="Código interno"/></label><label>Código de barras (opcional)<input name="barcode" maxLength="50" placeholder="Código del fabricante"/></label></div>
        <div className="field-row"><label>Precio de venta (CLP)<input type="number" name="price_clp" required min="0" max="1000000000" step="1" placeholder="2500"/></label><label>Unidad<select name="sale_unit" defaultValue="unidad"><option value="unidad">Unidad</option><option value="kg">Kg</option><option value="litro">Litro</option></select></label></div>
        <p className="helper">El producto se crea cuando confirmes la recepción. Su cantidad y costo se ingresan en el listado junto con los demás productos. Si no tiene código, se genera un SKU interno.</p>
        <button type="submit" className="primary wide" disabled={locked}>Agregar a la recepción</button>
      </form>
    </div></div>}
    {selected&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setSelected(null);}}><div className="modal" role="dialog" aria-modal="true" aria-label="Detalle de recepción"><div className="modal-head"><h3>Recepción #{selected.receipt.id}</h3><button type="button" className="ghost close" onClick={()=>setSelected(null)} aria-label="Cerrar">×</button></div>
      <div className="receipt-detail-meta"><p><strong>Proveedor:</strong> {selected.receipt.supplier_name}</p><p><strong>Fecha:</strong> {selected.receipt.received_at}</p><p><strong>Documento:</strong> {selected.receipt.document_type==='sin_documento'?'Sin documento':selected.receipt.document_type+' '+selected.receipt.document_number}</p><p><strong>Registró:</strong> {selected.receipt.entered_by_email}</p></div>
      <div className="table-wrap"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Costo</th><th>Stock antes</th><th>Stock después</th></tr></thead><tbody>{selected.items.map(i=><tr key={i.id}><td><strong>{i.product_name_snapshot}</strong><small className="sub">{i.sku||''}</small></td><td>{qtyFormat(i.quantity)}</td><td>{pesos(i.unit_cost_clp)}</td><td>{qtyFormat(i.stock_before)}</td><td>{qtyFormat(i.stock_after)}</td></tr>)}</tbody></table></div>
      <div className="receipt-total"><span>Total registrado</span><strong>{pesos(selected.receipt.total_cost_clp)}</strong></div>
    </div></div>}
  </div>;
}
