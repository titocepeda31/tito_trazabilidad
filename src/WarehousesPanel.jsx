import React,{useEffect,useMemo,useState} from 'react';
import {newId,qtyFormat} from './api.js';
import {paginateRows} from './warehousePagination.js';

const pendingKey='tito_pending_transfer';

function WarehousePagination({scope,pagination,pageSize,onChangePage,onChangeSize}){
  return <div className="warehouse-pagination" role="group" aria-label={'Paginación de '+scope}>
    <span className="warehouse-pagination-total" aria-live="polite">Mostrando <strong>{pagination.first}–{pagination.last}</strong> de <strong>{pagination.total}</strong> registros cargados</span>
    <div className="warehouse-pagination-actions">
      <label className="warehouse-page-size">Filas por página
        <select aria-label={'Filas por página en '+scope} value={pageSize} onChange={e=>onChangeSize(Number(e.target.value))}>
          {[10,20,50].map(n=><option key={n} value={n}>{n}</option>)}
        </select>
      </label>
      <div className="warehouse-page-controls">
        <button type="button" className="ghost mini" disabled={pagination.page<=1} onClick={()=>onChangePage(pagination.page-1)}>← Anterior</button>
        <span aria-live="polite">Página <strong>{pagination.page}</strong> de {pagination.pages}</span>
        <button type="button" className="ghost mini" disabled={pagination.page>=pagination.pages} onClick={()=>onChangePage(pagination.page+1)}>Siguiente →</button>
      </div>
    </div>
  </div>;
}
export default function WarehousesPanel({request,notify,onChange}){
  const [warehouses,setWarehouses]=useState([]);
  const [stocks,setStocks]=useState([]);
  const [transfers,setTransfers]=useState([]);
  const [stockFilter,setStockFilter]=useState('');
  const [transferFilter,setTransferFilter]=useState('');
  const [stockPage,setStockPage]=useState(1);
  const [transferPage,setTransferPage]=useState(1);
  const [stockPageSize,setStockPageSize]=useState(10);
  const [transferPageSize,setTransferPageSize]=useState(10);
  const [selected,setSelected]=useState(null);
  const [source,setSource]=useState('');
  const [target,setTarget]=useState('');
  const [note,setNote]=useState('');
  const [search,setSearch]=useState('');
  const [items,setItems]=useState([]);
  const [busy,setBusy]=useState(false);
  const [creating,setCreating]=useState(false);
  const [locationName,setLocationName]=useState('');
  const [editing,setEditing]=useState(null);
  const [editName,setEditName]=useState('');
  const [renaming,setRenaming]=useState(false);
  const [pending,setPending]=useState(()=>{try{return JSON.parse(sessionStorage.getItem(pendingKey)||'null');}catch{return null;}});
  const locked=busy||renaming||Boolean(pending);
  const from=warehouses.find(w=>String(w.id)===source);
  const to=warehouses.find(w=>String(w.id)===target);
  const qtyAt=(p,w)=>Number(p.locations?.[String(w?.id)]??(w?.code==='local'?p.local_qty:w?.code==='bodega'?p.bodega_qty:0));
  const stockFor=p=>qtyAt(p,from);
  const found=useMemo(()=>stocks.filter(p=>String(p.name+' '+(p.sku||'')).toLocaleLowerCase('es-CL').includes(search.toLocaleLowerCase('es-CL'))).filter(p=>!items.some(i=>i.product_id===Number(p.product_id))).slice(0,10),[stocks,search,items]);
  const filteredStocks=useMemo(()=>{
    const query=stockFilter.trim().toLocaleLowerCase('es-CL');
    return query?stocks.filter(p=>[p.name,p.sku,p.product_id].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(query))):stocks;
  },[stocks,stockFilter]);
  const filteredTransfers=useMemo(()=>{
    const query=transferFilter.trim().toLocaleLowerCase('es-CL');
    return query?transfers.filter(t=>[t.id,t.note,t.source_name,t.target_name,t.actor_email,t.created_at].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(query))):transfers;
  },[transfers,transferFilter]);
  const visibleStocks=paginateRows(filteredStocks,stockPage,stockPageSize);
  const visibleTransfers=paginateRows(filteredTransfers,transferPage,transferPageSize);
  async function load(){
    setBusy(true);
    try{
      const [w,p,t]=await Promise.all([request('/warehouses'),request('/warehouses/stock'),request('/warehouse-transfers')]);
      setWarehouses(w.warehouses||[]);setStocks(p.stocks||[]);setTransfers(t.transfers||[]);
      setSource(old=>old||String(w.warehouses.find(x=>x.code==='bodega')?.id||''));
      setTarget(old=>old||String(w.warehouses.find(x=>x.code==='local')?.id||''));
    }catch(e){notify(e.message,'error');}
    finally{setBusy(false);}
  }
  useEffect(()=>{load();},[]);
  async function view(id){
    setBusy(true);
    try{setSelected(await request('/warehouse-transfers/'+id));}
    catch(e){notify(e.message,'error');}
    finally{setBusy(false);}
  }
  async function lookupSerials(pid,wid,q=''){
    setItems(a=>a.map(i=>i.product_id===pid?{...i,serialLoading:true,serialError:''}:i));
    try{const r=await request('/serials/available?product_id='+pid+'&warehouse_id='+encodeURIComponent(wid)+'&q='+encodeURIComponent(q));
      setItems(a=>a.map(i=>i.product_id===pid&&i.serialWarehouse===wid?{...i,available:r.units||[],serialMore:r.more,serialLoading:false}:i));
    }catch(e){setItems(a=>a.map(i=>i.product_id===pid&&i.serialWarehouse===wid?{...i,serialError:e.message,serialLoading:false}:i));}
  }
  function add(p){if(locked)return;const id=Number(p.product_id),controlled=Number(p.serial_control)===1;
    setItems(a=>[...a,{product_id:id,product:p,quantity:controlled?'0':'1',serials:[],available:[],serialWarehouse:source,serialSearch:'',serialLoading:controlled}]);setSearch('');if(controlled)void lookupSerials(id,source);}
  function toggleSerial(id,number,checked){setItems(a=>a.map(i=>{if(i.product_id!==id)return i;const serials=checked?[...new Set([...i.serials,number])]:i.serials.filter(s=>s!==number);return {...i,serials,quantity:String(serials.length)};}));}
  function searchSerial(id,value){setItems(a=>a.map(i=>i.product_id===id?{...i,serialSearch:value}:i));}
  function change(id,q){setItems(a=>a.map(i=>i.product_id===id?{...i,quantity:q}:i));}
  async function send(body){
    setBusy(true);
    try{
      const result=await request('/warehouse-transfers',{method:'POST',body});
      sessionStorage.removeItem(pendingKey);setPending(null);
      notify(result.already_recorded?'Traslado ya registrado, sin duplicar cantidades.':'Traslado #'+result.transfer_id+' realizado. El stock total no cambia.','success');
      setItems([]);setNote('');setSearch('');
      await load();await onChange();
    }catch(e){notify('Resultado pendiente: '+e.message+'. No crees otro traslado hasta comprobar el historial; puedes reintentar el mismo.','error');}
    finally{setBusy(false);}
  }
  async function createLocation(e){
    e.preventDefault();
    if(locked||creating)return;
    if(locationName.trim().length<2){notify('Indica el nombre de la ubicación de inventario.','error');return;}
    if(!window.confirm('Crear '+locationName.trim()+' con stock inicial en cero?'))return;
    setCreating(true);
    try{
      await request('/warehouses',{method:'POST',body:{name:locationName.trim(),type:'storage'}});
      setLocationName('');
      notify('Ubicación creada con stock en cero. Puedes trasladar mercadería entre ubicaciones.','success');
      await load();
    }catch(err){notify('No se pudo crear la ubicación: '+err.message,'error');}
    finally{setCreating(false);}
  }
  async function renameLocation(e){
    e.preventDefault();
    if(locked||creating||!editing)return;
    const name=editName.trim();
    if(name.length<2){notify('Indica un nombre de al menos 2 caracteres.','error');return;}
    if(name===editing.name){setEditing(null);return;}
    setRenaming(true);
    try{
      await request('/warehouses/'+editing.id+'/rename',{method:'POST',body:{name}});
      setEditing(null);
      notify('Nombre actualizado. Las existencias no cambiaron.','success');
      await load();
      await onChange?.();
    }catch(err){notify(err.message,'error');}
    finally{setRenaming(false);}
  }
  function submit(e){
    e.preventDefault();if(locked)return;
    if(!from||!to||source===target){notify('Selecciona ubicaciones diferentes.','error');return;}
    if(note.trim().length<3){notify('Escribe el motivo del traslado.','error');return;}
    if(!items.length){notify('Agrega al menos un producto.','error');return;}
    const lines=[];
    for(const item of items){
      const qty=Number(item.quantity);
      if(!Number.isFinite(qty)||qty<=0||qty>1000000000||(item.product.sale_unit==='unidad'&&!Number.isInteger(qty))||Math.abs(qty*1000-Math.round(qty*1000))>0.00001||qty>stockFor(item.product)+0.00001){
        notify('Cantidad mayor que las existencias en origen para '+item.product.name,'error');return;
      }
      const controlled=Number(item.product.serial_control)===1;
      if(controlled&&(item.serials.length!==qty||qty>500)){notify('Selecciona hasta 500 series por producto.','error');return;}
      lines.push({product_id:item.product_id,quantity:qty,...(controlled?{serial_numbers:item.serials}:{})});
    }
    const body={request_id:newId(),source_warehouse_id:Number(source),target_warehouse_id:Number(target),note:note.trim(),items:lines};
    if(!window.confirm('Trasladar '+lines.length+' producto(s) de '+from.name+' a '+to.name+' sin modificar el stock total?'))return;
    sessionStorage.setItem(pendingKey,JSON.stringify(body));setPending(body);send(body);
  }
  return <div className="warehouses-page">
    <div className="section-intro"><div><p className="eyebrow">INVENTARIO FÍSICO</p><h2>Bodegas y ubicaciones de inventario</h2><p className="muted">Controla existencias físicas y traslados de mercadería entre ubicaciones.</p></div></div>
    <section className="panel pad">
      <h3>Agregar ubicación de inventario</h3>
      <p className="helper">Registra bodegas, depósitos u otras ubicaciones físicas de mercadería. Las nuevas ubicaciones comienzan con stock cero. Las sucursales se administran en Empresas y sucursales.</p>
      <form className="formstack" onSubmit={createLocation}>
        <label>Nombre de la ubicación<input value={locationName} maxLength="120" required disabled={locked||creating} onChange={e=>setLocationName(e.target.value)} placeholder="Ej. Bodega central"/></label>
        <button className="primary" type="submit" disabled={locked||creating}>{creating?'Creando…':'+ Crear ubicación'}</button>
      </form>
    </section>
    <div className="warehouse-cards">
      {warehouses.map(w=><div className="panel pad warehouse-card" key={w.id}><div className="warehouse-icon">▤</div><div><strong>{w.name}</strong><p className="muted">Ubicación de inventario</p><button type="button" className="ghost mini" disabled={locked||creating} aria-label={'Editar nombre de '+w.name} onClick={()=>{setEditing(w);setEditName(w.name);}}>Editar nombre</button></div><div className="warehouse-sum">{stocks.filter(p=>qtyAt(p,w)>0).length}<small>productos con existencias</small></div></div>)}
    </div>
    <p className="helper warehouse-explainer">El resumen cuenta productos con existencias por ubicación. Cada ubicación nueva empieza en cero y recibe productos mediante traslados, sin modificar el stock total de la empresa.</p>
    <section className="panel pad warehouse-transfer">
      <div className="panel-heading"><div><h3>Nuevo traslado interno</h3><p>No es una compra y no cambia el total del negocio.</p></div></div>
      {pending&&<div className="receipt-alert"><strong>Envío con resultado pendiente</strong><p>Comprueba el historial antes de descartarlo. Reintenta el mismo identificador para evitar duplicaciones.</p><div className="receipt-buttons"><button type="button" className="primary" disabled={busy} onClick={()=>send(pending)}>Reintentar traslado</button><button type="button" className="ghost" disabled={busy} onClick={()=>{if(window.confirm('¿Verificaste que el traslado no está registrado?')){sessionStorage.removeItem(pendingKey);setPending(null);}}}>Descartar tras verificar</button></div></div>}
      <form className="formstack" onSubmit={submit}>
        <div className="receipt-fields">
          <label>Desde<select required value={source} disabled={locked} onChange={e=>{setSource(e.target.value);setItems([]);}}>{warehouses.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
          <label>Hacia<select required value={target} disabled={locked} onChange={e=>setTarget(e.target.value)}>{warehouses.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
        </div>
        <label>Motivo del traslado<input required maxLength="250" disabled={locked} value={note} onChange={e=>setNote(e.target.value)} placeholder="Ej. Reposición de estantería local"/></label>
        <div className="receipt-product-picker"><label>Buscar producto<input type="search" disabled={locked} value={search} onChange={e=>setSearch(e.target.value)} placeholder="Nombre o SKU"/></label>{search.trim()&&<div className="receipt-search-results">{found.map(p=><button type="button" className="receipt-search-item" key={p.product_id} disabled={locked||stockFor(p)<=0} onClick={()=>add(p)}><strong>{p.name}</strong><span>Origen: {qtyFormat(stockFor(p))} {p.sale_unit}; total: {qtyFormat(p.total_qty)}</span><b>+ Agregar</b></button>)}{found.length===0&&<p className="muted">No encontramos ese producto.</p>}</div>}</div>
        {items.length===0?<div className="receipt-empty">Busca productos para este traslado.</div>:<div className="receipt-items">{items.map(i=><div className="receipt-item" key={i.product_id}><div className="receipt-item-title"><strong>{i.product.name}</strong><button type="button" className="ghost mini" disabled={locked} onClick={()=>setItems(a=>a.filter(x=>x.product_id!==i.product_id))}>Quitar</button></div><p className="muted">Disponibles en {from?.name}: {qtyFormat(stockFor(i.product))} {i.product.sale_unit}</p>{Number(i.product.serial_control)===1?<div><strong>Series seleccionadas: {i.serials.length}</strong><p className="helper">Selecciona las unidades físicas disponibles en origen. La cantidad se calcula automáticamente.</p><div className="field-row"><input value={i.serialSearch} maxLength={120} disabled={locked||i.serialLoading} placeholder="Buscar número de serie" onChange={e=>searchSerial(i.product_id,e.target.value)}/><button type="button" className="ghost" disabled={locked||i.serialLoading} onClick={()=>lookupSerials(i.product_id,i.serialWarehouse,i.serialSearch)}>Buscar</button></div>{i.serialError&&<p role="alert">{i.serialError}</p>}{i.serialLoading?<p>Cargando series…</p>:<div style={{maxHeight:220,overflowY:'auto'}}>{i.available.map(s=><label className="role-check" key={s.id}><input type="checkbox" disabled={locked||(!i.serials.includes(s.serial_number)&&i.serials.length>=500)} checked={i.serials.includes(s.serial_number)} onChange={e=>toggleSerial(i.product_id,s.serial_number,e.target.checked)}/>{s.serial_number}</label>)}{!i.available.length&&<p className="muted">Sin series disponibles.</p>}</div>}{i.serialMore&&<p className="helper">Más de 500 resultados: filtra por serie.</p>}{i.serials.filter(n=>!i.available.some(s=>s.serial_number===n)).map(n=><button type="button" key={n} className="ghost mini" disabled={locked} onClick={()=>toggleSerial(i.product_id,n,false)}>{n} ×</button>)}</div>:<label>Cantidad a trasladar<input type="number" required min={i.product.sale_unit==='unidad'?'1':'0.001'} max={stockFor(i.product)} step={i.product.sale_unit==='unidad'?'1':'0.001'} disabled={locked} value={i.quantity} onChange={e=>change(i.product_id,e.target.value)}/></label>}</div>)}</div>}
        <button className="primary wide" type="submit" disabled={locked||!items.length||!warehouses.length}>Confirmar traslado</button>
      </form>
    </section>
    <section className="panel warehouse-section warehouse-stock-section">
      <div className="panel-heading warehouse-section-heading">
        <div><h3>Existencias por ubicación</h3><p>Consulta las existencias de cada ubicación y el stock total de la empresa.</p></div>
      </div>
      <div className="warehouse-table-toolbar">
        <label>Buscar producto
          <input type="search" placeholder="Nombre, SKU o número de producto…" value={stockFilter} onChange={e=>{setStockFilter(e.target.value);setStockPage(1);}}/>
        </label>
        <button type="button" className="ghost" disabled={busy} onClick={load}>↻ Actualizar existencias</button>
      </div>
      <div className="table-wrap warehouse-table-wrap">
        <table><thead><tr><th>Producto</th>{warehouses.map(w=><th key={w.id}>{w.name}</th>)}<th>Total empresa</th></tr></thead>
          <tbody>{visibleStocks.rows.map(p=><tr key={p.product_id}><td><strong>{p.name}</strong><small className="sub">{p.sku||'Sin SKU'}</small></td>{warehouses.map(w=><td key={w.id}>{qtyFormat(qtyAt(p,w))} {p.sale_unit}</td>)}<td><strong>{qtyFormat(p.total_qty)} {p.sale_unit}</strong></td></tr>)}</tbody>
        </table>
        {visibleStocks.total===0&&<div className="warehouse-empty">{stockFilter?'No hay productos que coincidan con esa búsqueda.':'Todavía no hay productos registrados.'}</div>}
      </div>
      <WarehousePagination scope="existencias por ubicación" pagination={visibleStocks} pageSize={stockPageSize} onChangePage={setStockPage} onChangeSize={n=>{setStockPageSize(n);setStockPage(1);}}/>
      <p className="warehouse-data-limit">Las existencias se paginan entre los productos cargados. La API puede devolver hasta 3.000 productos.</p>
    </section>
    <section className="panel warehouse-section warehouse-transfers-section">
      <div className="panel-heading warehouse-section-heading">
        <div><h3>Historial de traslados</h3><p>Revisa movimientos registrados entre ubicaciones de inventario.</p></div>
      </div>
      <div className="warehouse-table-toolbar">
        <label>Buscar traslado
          <input type="search" placeholder="N.º, origen, destino, motivo o responsable…" value={transferFilter} onChange={e=>{setTransferFilter(e.target.value);setTransferPage(1);}}/>
        </label>
        <button type="button" className="ghost" disabled={busy} onClick={load}>↻ Actualizar traslados</button>
      </div>
      <div className="table-wrap warehouse-table-wrap warehouse-transfer-table">
        <table><thead><tr><th>#</th><th>Fecha</th><th>Origen → Destino</th><th>Motivo</th><th>Responsable</th><th></th></tr></thead>
          <tbody>{visibleTransfers.rows.map(t=><tr key={t.id}><td><strong>#{t.id}</strong></td><td>{t.created_at}</td><td>{t.source_name} → {t.target_name}</td><td>{t.note}</td><td>{t.actor_email}</td><td><button type="button" className="ghost mini" disabled={busy} onClick={()=>view(t.id)}>Ver detalle</button></td></tr>)}</tbody>
        </table>
        {visibleTransfers.total===0&&<div className="warehouse-empty">{transferFilter?'No hay traslados que coincidan con esa búsqueda.':'Aún no hay traslados registrados.'}</div>}
      </div>
      <WarehousePagination scope="historial de traslados" pagination={visibleTransfers} pageSize={transferPageSize} onChangePage={setTransferPage} onChangeSize={n=>{setTransferPageSize(n);setTransferPage(1);}}/>
      <p className="warehouse-data-limit">La API devuelve los últimos 100 traslados. Estas páginas organizan los registros cargados; el historial anterior requiere paginación en el servidor.</p>
    </section>
    {editing&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget&&!renaming)setEditing(null);}}><div className="modal" role="dialog" aria-modal="true" aria-label="Editar nombre de ubicación"><div className="modal-head"><h3>Editar ubicación</h3><button type="button" className="ghost close" aria-label="Cerrar" disabled={renaming} onClick={()=>setEditing(null)}>×</button></div><p className="muted">Solo se cambia el nombre. Se conservan existencias, código, asignaciones y movimientos registrados.</p><form className="formstack" onSubmit={renameLocation}><label>Nombre de la ubicación<input autoFocus value={editName} maxLength={120} minLength={2} required disabled={renaming} onChange={e=>setEditName(e.target.value)}/></label><div className="receipt-buttons"><button className="primary" disabled={locked||creating}>{renaming?'Guardando…':'Guardar cambios'}</button><button type="button" className="ghost" disabled={renaming} onClick={()=>setEditing(null)}>Cancelar</button></div></form></div></div>}
    {selected&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setSelected(null);}}><div className="modal" role="dialog" aria-modal="true" aria-label="Detalle traslado"><div className="modal-head"><h3>Traslado #{selected.transfer.id}</h3><button className="ghost close" onClick={()=>setSelected(null)}>×</button></div><p><strong>Trayecto:</strong> {selected.transfer.source_name} → {selected.transfer.target_name}</p><p><strong>Motivo:</strong> {selected.transfer.note}</p><p><strong>Registró:</strong> {selected.transfer.actor_email}</p><div className="table-wrap"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Antes origen</th><th>Después origen</th><th>Antes destino</th><th>Después destino</th></tr></thead><tbody>{selected.items.map(i=><tr key={i.product_id}><td>{i.name}{i.serial_numbers?.length>0&&<small className="sub">Series: {i.serial_numbers.join(', ')}</small>}</td><td>{qtyFormat(i.quantity)}</td><td>{qtyFormat(i.source_before)}</td><td>{qtyFormat(i.source_after)}</td><td>{qtyFormat(i.target_before)}</td><td>{qtyFormat(i.target_after)}</td></tr>)}</tbody></table></div></div></div>}
  </div>;
}
