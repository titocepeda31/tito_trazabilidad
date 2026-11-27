import React,{useEffect,useMemo,useState} from 'react';
import {newId,pesos,qtyFormat} from './api.js';
import {openQuoteEmail,shareQuotePdf} from './quoteShare.js';
import QuoteClientPicker from './QuoteClientPicker.jsx';

const defaultDate=()=>{const d=new Date();d.setDate(d.getDate()+7);return d.toLocaleDateString('sv-SE',{timeZone:'America/Santiago'});};
const storage='tito_pending_quote';
const statusNames={draft:'Borrador',sent:'Enviada',accepted:'Aceptada',rejected:'Rechazada',expired:'Vencida',converted:'Convertida'};
const statusOf=q=>q.effective_status||q.status;
const eventNames={created:'Creada',edited:'Borrador editado',sent:'Envío registrado',accepted:'Aceptación registrada',rejected:'Rechazo registrado',converted:'Convertida en venta'};
const paymentOptions=[['efectivo','Efectivo'],['transferencia','Transferencia'],['tarjeta','Tarjeta'],['otro','Otro']];
export default function QuotesPanel({products,request,notify,onSale,businessName}){
  const [quotes,setQuotes]=useState([]);
  const [customer,setCustomer]=useState(null);
  const [screen,setScreen]=useState(()=>{try{return JSON.parse(sessionStorage.getItem(storage)||'null')?'new':'list';}catch{return 'list';}});
  const [quoteQuery,setQuoteQuery]=useState('');
  const [statusFilter,setStatusFilter]=useState('all');
  const [editing,setEditing]=useState(null);
  const [statusNote,setStatusNote]=useState('');
  const [contact,setContact]=useState('');
  const [valid,setValid]=useState(defaultDate);
  const [notes,setNotes]=useState('');
  const [search,setSearch]=useState('');
  const [items,setItems]=useState([]);
  const [selected,setSelected]=useState(null);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(false);
  const [payment,setPayment]=useState('efectivo');
  const [saleLocationId,setSaleLocationId]=useState(null);
  const [saleLocations,setSaleLocations]=useState([]);
  const [recipientEmail,setRecipientEmail]=useState('');
  const [sharing,setSharing]=useState(false);
  const [pending,setPending]=useState(()=>{try{return JSON.parse(sessionStorage.getItem(storage)||'null');}catch{return null;}});
  const locked=busy||Boolean(pending);
  const found=useMemo(()=>products.filter(p=>[p.name,p.sku,p.barcode].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(search.toLocaleLowerCase('es-CL')))).filter(p=>!items.some(i=>i.product_id===Number(p.id))).slice(0,10),[products,search,items]);
  const total=items.reduce((n,i)=>n+Math.round(Number(i.quantity)*Number(i.unit_price_clp)),0);
  async function refresh(){setLoading(true);try{const d=await request('/quotes');setQuotes(d.quotes||[]);}catch(e){notify(e.message,'error');}finally{setLoading(false);}}
  useEffect(()=>{refresh();request('/account/sales-location').then(d=>{setSaleLocationId(Number(d.warehouse_id));setSaleLocations(d.locations||[]);}).catch(e=>notify('No fue posible cargar sucursales para cotizar: '+e.message,'error'));},[]);
  function add(p){if(locked)return;setItems(old=>[...old,{product_id:Number(p.id),name:p.name,unit:p.sale_unit,list_price_clp:Number(p.price_clp),quantity:'1',unit_price_clp:String(p.price_clp),discount_reason:''}]);setSearch('');}
  function edit(id,field,value){setItems(old=>old.map(i=>i.product_id===id?{...i,[field]:value}:i));}
  async function save(body){
    setBusy(true);
    try{
      const isEdit=Boolean(body.quote_id);
      const {quote_id,...payload}=body;
      const result=await request(isEdit?'/quotes/'+quote_id+'/edit':'/quotes',{method:'POST',body:payload});
      sessionStorage.removeItem(storage);setPending(null);
      notify(result.already_recorded?'Cambio ya registrado, no se duplicó.':isEdit?'Borrador actualizado.':'Cotización #'+result.quote_id+' registrada. No se descontó stock.','success');
      setEditing(null);setCustomer(null);setContact('');setNotes('');setValid(defaultDate());setItems([]);setSearch('');
      await refresh();await view(result.quote_id);
    }catch(e){
      if(e.status>=400&&e.status<500&&![408,429].includes(e.status)){sessionStorage.removeItem(storage);setPending(null);notify('Cotización no registrada: '+e.message,'error');}
      else notify('No se pudo confirmar: '+e.message+'. Reintenta el mismo registro para evitar duplicados.','error');
    }
    finally{setBusy(false);}
  }
  function submit(e){
    e.preventDefault();if(locked)return;
    if(!customer?.id){notify('Selecciona un cliente registrado.','error');return;}
    if(items.length===0){notify('Agrega productos.','error');return;}
    const lines=[];
    for(const i of items){
      const qty=Number(i.quantity),price=Number(i.unit_price_clp);
      if(!Number.isFinite(qty)||qty<=0||qty>1000000000||(i.unit==='unidad'&&!Number.isInteger(qty))||Math.abs(qty*1000-Math.round(qty*1000))>0.00001){notify('Revisa la cantidad de '+i.name,'error');return;}
      if(!Number.isInteger(price)||price<0||price>i.list_price_clp||(i.list_price_clp>0&&price===0)){notify('Precio no válido en '+i.name,'error');return;}
      if(price<i.list_price_clp&&!i.discount_reason.trim()){notify('Indica el motivo del descuento de '+i.name,'error');return;}
      lines.push({product_id:i.product_id,quantity:qty,unit_price_clp:price,discount_reason:price<i.list_price_clp?i.discount_reason.trim():null});
    }
    const body={request_id:newId(),...(editing?{quote_id:editing.id,revision:editing.revision}:{}),client_id:Number(customer.id),customer_contact:contact.trim()||null,valid_until:valid,notes:notes.trim()||null,items:lines};
    sessionStorage.setItem(storage,JSON.stringify(body));setPending(body);save(body);
  }
  async function view(id){
    setBusy(true);try{
      const data=await request('/quotes/'+id);
      setSelected(data);setStatusNote('');setScreen('detail');
      const contact=String(data.quote.customer_email||data.quote.customer_contact||'').trim();
      setRecipientEmail(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact)?contact:'');
    }catch(e){notify(e.message,'error');}finally{setBusy(false);}
  }
  async function convert(){
    if(!selected||statusOf(selected.quote)!=='accepted'||!saleLocationId||selected.items.some(i=>Number(i.serial_control)===1))return;
    if(!window.confirm('Se respetarán los precios pactados mientras la cotización esté vigente. Se verificará stock y disponibilidad. ¿Convertir cotización #'+selected.quote.id+' en venta con pago '+payment+'?'))return;
    setBusy(true);
    try{
      const r=await request('/quotes/'+selected.quote.id+'/convert',{method:'POST',body:{payment_method:payment,warehouse_id:saleLocationId}});
      notify('Venta #'+r.sale_id+' registrada desde cotización. Stock descontado.','success');
      await refresh();await view(selected.quote.id);await onSale();
    }catch(e){notify('No se pudo convertir: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function sharePdf(){
    if(!selected)return;
    setSharing(true);
    try{
      const result=await shareQuotePdf(selected,businessName);
      if(result==='downloaded')notify('PDF descargado. Adjunta el archivo al correo antes de enviarlo.','info');
      else if(result==='shared')notify('Confirma el destinatario y el envío en la aplicación de correo.','info');
    }catch(e){notify('No se pudo preparar el PDF: '+e.message,'error');}
    finally{setSharing(false);}
  }
  function prepareEmail(){
    if(!selected)return;
    if(recipientEmail.trim()&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipientEmail.trim())){notify('Corrige el correo del destinatario.','error');return;}
    openQuoteEmail(selected,businessName,recipientEmail);
  }
  function openNew(){setEditing(null);setSelected(null);setScreen('new');}
  function openEdit(){
    if(!selected||selected.quote.status!=='draft'||busy)return;
    const q=selected.quote;setEditing({id:Number(q.id),revision:Number(q.revision)});
    setCustomer(q.client_id?{id:Number(q.client_id),name:q.customer_name,rut:q.customer_rut}:null);
    setContact(q.customer_contact||'');setValid(q.valid_until);setNotes(q.notes||'');setSearch('');
    setItems(selected.items.map(i=>({product_id:Number(i.product_id),name:i.product_name_snapshot,unit:i.sale_unit,list_price_clp:Number(i.list_price_clp),quantity:String(i.quantity),unit_price_clp:String(i.unit_price_clp),discount_reason:i.discount_reason||''})));
    setScreen('new');
  }
  async function changeStatus(to){
    if(!selected||busy)return;
    if(to==='rejected'&&!statusNote.trim()){notify('Indica el motivo del rechazo.','error');return;}
    const descriptions={sent:'¿Confirmas que la cotización se envió efectivamente al cliente?',accepted:'¿Confirmas que el cliente aceptó estas condiciones?',rejected:'¿Confirmas el rechazo de esta cotización?'};
    if(!window.confirm(descriptions[to]))return;
    const id=selected.quote.id;setBusy(true);
    try{
      await request('/quotes/'+id+'/status',{method:'POST',body:{request_id:newId(),revision:Number(selected.quote.revision),status:to,note:statusNote.trim()||null}});
      notify('Estado actualizado.','success');await refresh();await view(id);
    }catch(e){notify('No se pudo confirmar el cambio: '+e.message+'. Se recargará el estado actual.','error');await refresh();await view(id);}
    finally{setBusy(false);}
  }
  function backFromNew(){
    if(locked)return;
    if((customer||items.length||contact||notes)&&!window.confirm('¿Salir sin guardar esta cotización?'))return;
    setEditing(null);setCustomer(null);setContact('');setNotes('');setItems([]);setSearch('');setValid(defaultDate());setScreen(selected?'detail':'list');
  }
  const shown=quotes.filter(q=>(statusFilter==='all'||statusOf(q)===statusFilter)&&[q.id,q.customer_name,q.customer_rut,statusNames[statusOf(q)]].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(quoteQuery.trim().toLocaleLowerCase('es-CL'))));
  const hasSerial=Boolean(selected?.items.some(i=>Number(i.serial_control)===1));
  return <div className="quotes-page">
    {screen==='list'&&<div className="section-intro"><div><p className="eyebrow">VENTAS</p><h2>Cotizaciones</h2><p className="muted">Presupuesta sin descontar ni reservar existencias.</p></div><button type="button" className="primary" disabled={busy} onClick={openNew}>+ Nueva cotización</button></div>}
    {screen==='new'&&<><div className="section-intro"><div><button type="button" className="text-button" disabled={locked} onClick={backFromNew}>← Volver a cotizaciones</button><h2>{editing?'Editar borrador #'+editing.id:'Nueva cotización'}</h2><p className="muted">Selecciona un cliente y los productos a cotizar.</p></div></div><section className="panel pad quote-form">
      <div className="panel-heading"><div><h3>{editing?'Modificar datos del borrador':'Datos de cotización'}</h3><p>Los presupuestos no son documentos tributarios del SII.</p></div></div>
      {pending&&<div className="receipt-alert"><strong>Envío pendiente de confirmar</strong><p>Verifica el historial o reintenta exactamente el mismo registro antes de crear otro.</p><div className="receipt-buttons"><button type="button" className="primary" disabled={busy} onClick={()=>save(pending)}>Reintentar el mismo registro</button><button type="button" className="ghost" disabled={busy} onClick={()=>{if(window.confirm('¿Comprobaste que no está registrada antes de descartar el envío?')){sessionStorage.removeItem(storage);setPending(null);}}}>Descartar tras verificar</button></div></div>}
      <form className="formstack" onSubmit={submit}>
        <div className="receipt-fields">
          <QuoteClientPicker request={request} value={customer} onChange={c=>{setCustomer(c);setContact('');}} disabled={locked}/><p className="helper">Si el cliente no existe, regístralo primero en Clientes.</p>
          <label>Contacto (opcional)<input maxLength="120" disabled={locked} value={contact} onChange={e=>setContact(e.target.value)} placeholder="Teléfono o correo"/></label>
          <label>Válida hasta<input required type="date" disabled={locked} value={valid} onChange={e=>setValid(e.target.value)}/></label>
          <label>Observaciones<input maxLength="500" disabled={locked} value={notes} onChange={e=>setNotes(e.target.value)} placeholder="Plazo, condiciones..."/></label>
        </div>
        <div className="receipt-product-picker">
          <label>Buscar producto<input disabled={locked} value={search} onChange={e=>setSearch(e.target.value)} type="search" placeholder="Nombre, SKU o código de barras"/></label>
          {search.trim()&&<div className="receipt-search-results">{found.map(p=><button type="button" className="receipt-search-item" key={p.id} disabled={locked} onClick={()=>add(p)}><strong>{p.name}</strong><span>Lista: {pesos(p.price_clp)} · Stock total {qtyFormat(p.stock_qty)}</span><b>+ Agregar</b></button>)}{!found.length&&<p className="muted">No hay productos con ese criterio.</p>}</div>}
        </div>
        {items.length===0?<div className="receipt-empty">Agrega al menos un producto.</div>:<div className="receipt-items">{items.map(i=><div key={i.product_id} className="receipt-item"><div className="receipt-item-title"><strong>{i.name}</strong><button type="button" className="ghost mini" disabled={locked} onClick={()=>setItems(a=>a.filter(x=>x.product_id!==i.product_id))}>Quitar</button></div><div className="receipt-item-values"><label>Cantidad ({i.unit})<input type="number" required min={i.unit==='unidad'?'1':'0.001'} step={i.unit==='unidad'?'1':'0.001'} disabled={locked} value={i.quantity} onChange={e=>edit(i.product_id,'quantity',e.target.value)}/></label><label>Precio unitario CLP<input type="number" required min="0" max={i.list_price_clp} step="1" disabled={locked} value={i.unit_price_clp} onChange={e=>edit(i.product_id,'unit_price_clp',e.target.value)}/></label><div className="receipt-line-total"><small>Subtotal</small><strong>{pesos(Number(i.quantity)*Number(i.unit_price_clp))}</strong></div></div>{Number(i.unit_price_clp)<i.list_price_clp&&<label>Motivo del precio especial<input required maxLength="120" disabled={locked} value={i.discount_reason} onChange={e=>edit(i.product_id,'discount_reason',e.target.value)}/></label>}</div>)}</div>}
        <div className="receipt-total"><span>Total presupuesto</span><strong>{pesos(total)}</strong></div>
        <button className="primary wide" disabled={locked||!customer||items.length===0} type="submit">{busy?'Guardando…':editing?'Guardar cambios':'Guardar borrador'}</button>
      </form>
    </section></>}
    {screen==='list'&&<section className="panel"><div className="panel-heading pad"><div><h3>Últimas cotizaciones</h3><p>Historial del negocio</p></div><button type="button" className="ghost" disabled={loading} onClick={refresh}>↻ Actualizar</button></div><div className="toolbar"><input type="search" aria-label="Buscar cotizaciones" placeholder="N.º, cliente o RUT" value={quoteQuery} onChange={e=>setQuoteQuery(e.target.value)}/><label>Estado<select value={statusFilter} onChange={e=>setStatusFilter(e.target.value)}><option value="all">Todos</option>{Object.entries(statusNames).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></label></div><div className="table-wrap"><table><thead><tr><th>N.º</th><th>Cliente</th><th>Creación</th><th>Validez</th><th>Estado</th><th>Total</th><th></th></tr></thead><tbody>{shown.map(q=><tr key={q.id}><td>#{q.id}</td><td>{q.customer_name}<small className="sub">{q.customer_rut||(!q.client_id?'Histórica · sin vínculo':'')}</small></td><td>{String(q.created_at).slice(0,16)}</td><td>{q.valid_until}</td><td>{statusNames[statusOf(q)]||q.status}</td><td>{pesos(q.total_clp)}</td><td><button type="button" className="ghost mini" disabled={busy} onClick={()=>view(q.id)}>Ver</button></td></tr>)}</tbody></table>{!shown.length&&!loading&&<p className="receipt-no-data">No se encontraron cotizaciones en las últimas 100.</p>}</div></section>}
    {screen==='detail'&&selected&&<section className="panel pad quote-detail"><div className="quote-detail-actions"><h3>Detalle de cotización #{selected.quote.id}</h3><button className="ghost" type="button" onClick={()=>{setSelected(null);setScreen('list');}}>← Volver a cotizaciones</button></div><div className="quote-print">
      <div className="quote-print-header"><h1>Cotización #{selected.quote.id}</h1><strong>Tito POS</strong></div>
      <p><strong>Cliente:</strong> {selected.quote.customer_name}{selected.quote.customer_rut?' · '+selected.quote.customer_rut:''}</p>{!selected.quote.client_id&&<p className="helper">Cotización histórica sin cliente vinculado. No se asigna automáticamente.</p>}<p><strong>Contacto:</strong> {selected.quote.customer_contact||'—'}</p><p><strong>Emisión:</strong> {String(selected.quote.created_at).slice(0,10)} · <strong>Vigencia:</strong> {selected.quote.valid_until}</p><p><strong>Estado:</strong> {selected.quote.status==='converted'?'Convertida en venta #'+selected.quote.converted_sale_id:statusNames[statusOf(selected.quote)]}</p>
      <table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio unit.</th><th>Subtotal</th></tr></thead><tbody>{selected.items.map(i=><tr key={i.id}><td>{i.product_name_snapshot}</td><td>{qtyFormat(i.quantity)} {i.sale_unit}</td><td>{pesos(i.unit_price_clp)}</td><td>{pesos(i.subtotal_clp)}</td></tr>)}</tbody></table>
      <div className="receipt-total"><span><strong>Total</strong></span><strong>{pesos(selected.quote.total_clp)}</strong></div>
      <p>{selected.quote.notes||''}</p><p className="helper">Los precios de esta cotización se respetan durante su vigencia, sujetos a stock disponible y a que los productos sigan activos y con la misma unidad de venta. No constituye factura, boleta ni documento tributario.</p>
    </div><div className="quote-actions">
      <section className="panel pad formstack">
        <h3>Seguimiento comercial</h3>
        <p className="helper">Creada por {selected.quote.created_by_email||'—'} · Revisión {selected.quote.revision}</p>
        {statusOf(selected.quote)==='draft'&&<button type="button" className="ghost" disabled={busy} onClick={openEdit}>Editar borrador</button>}
        {['draft','sent'].includes(statusOf(selected.quote))&&<><label>Observación / motivo<textarea rows={3} maxLength={500} disabled={busy} value={statusNote} onChange={e=>setStatusNote(e.target.value)}/></label>
        {statusOf(selected.quote)==='draft'?<button type="button" className="primary" disabled={busy||!selected.quote.client_id} onClick={()=>changeStatus('sent')}>Registrar envío realizado</button>:<div className="inline-actions"><button type="button" className="primary" disabled={busy} onClick={()=>changeStatus('accepted')}>Registrar aceptación</button><button type="button" className="ghost" disabled={busy||!statusNote.trim()} onClick={()=>changeStatus('rejected')}>Registrar rechazo</button></div>}
        <p className="helper">Registrar el envío no envía correo automáticamente. El rechazo requiere motivo.</p></>}
        {statusOf(selected.quote)==='expired'&&<p className="warning-box">Cotización vencida. No se puede convertir; crea una nueva.</p>}
        <h3>Historial de cambios</h3>
        {!(selected.events||[]).length&&<p className="helper">Sin eventos registrados desde la incorporación del historial.</p>}
        {(selected.events||[]).map(e=><details key={e.id}><summary><strong>{eventNames[e.event_type]||e.event_type}</strong> · {String(e.created_at).slice(0,16)}</summary><p>{e.actor_email} {e.note?'· '+e.note:''}</p>{e.event_type==='edited'&&e.details&&<><p>Total anterior: {pesos(e.details.before?.total_clp)} → {pesos(e.details.after?.total_clp)}</p><p>Productos antes: {(e.details.items_before||[]).map(i=>i.product_name_snapshot+' × '+qtyFormat(i.quantity)+' · '+pesos(i.unit_price_clp)).join('; ')}</p><p>Productos después: {(e.details.items_after||[]).map(i=>i.product_name_snapshot+' × '+qtyFormat(i.quantity)+' · '+pesos(i.unit_price_clp)).join('; ')}</p></>}</details>)}
      </section>
      <div className="quote-share-tools">
        <label>Correo del cliente (opcional)<input type="email" placeholder="cliente@empresa.cl" value={recipientEmail} onChange={e=>setRecipientEmail(e.target.value)}/></label>
        <button type="button" className="primary" disabled={sharing} onClick={sharePdf}>{sharing?'Preparando PDF…':'Compartir PDF por correo / WhatsApp'}</button>
        <button type="button" className="ghost" onClick={prepareEmail}>Preparar correo con detalle</button>
        <button type="button" className="ghost" onClick={()=>window.print()}>Imprimir / guardar PDF</button>
        <p className="helper">Compartir PDF abre el menú del teléfono: selecciona Gmail u otra app y confirma el envío. «Preparar correo» incluye el detalle como texto, sin adjuntar PDF automáticamente.</p>
      </div>
      {selected.quote.status!=='converted'&&hasSerial&&<p className="warning-box">Contiene productos serializados. La conversión con selección de series se implementará en la siguiente fase. No se descontará inventario por esta cotización.</p>}
      {statusOf(selected.quote)==='accepted'&&!hasSerial&&<><label>Sucursal que venderá<select aria-label="Sucursal que venderá" disabled={busy||!saleLocationId} value={saleLocationId||''} onChange={e=>setSaleLocationId(Number(e.target.value))}>{saleLocations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label><label>Medio de pago<select value={payment} disabled={busy} onChange={e=>setPayment(e.target.value)}>{paymentOptions.map(([v,l])=><option value={v} key={v}>{l}</option>)}</select></label><button type="button" className="primary" disabled={busy||!saleLocationId} onClick={convert}>Convertir en venta</button></>}</div></section>}
  </div>;
}
