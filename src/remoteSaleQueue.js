export const REMOTE_QUEUE_STORAGE_KEY='tito_remote_sale_queue_v2';
export const MAX_REMOTE_ACTIVE=10;
const statuses=new Set(['pending','approved','completed','rejected','expired','cancelled']);
export const remoteActive=entry=>['pending','approved'].includes(entry.status);

export function normalizeRemoteQueue(value,{resetProcessing=false}={}){
  if(!Array.isArray(value))return [];
  const seen=new Set(),rows=[];
  for(const item of value){
    const id=Number(item?.id);
    if(!Number.isSafeInteger(id)||id<=0||seen.has(id)||!item.sale||
       typeof item.sale.request_id!=='string'||!Array.isArray(item.sale.items))continue;
    seen.add(id);
    rows.push({
      id,sale:item.sale,
      status:statuses.has(item.status)?item.status:'pending',
      attempted:!!item.attempted,
      processing:resetProcessing?false:!!item.processing, // Tras recargar se vuelve a consultar la API.
      error:typeof item.error==='string'?item.error:'',
      reason:typeof item.reason==='string'?item.reason:'',
      receipt:item.receipt&&Number.isSafeInteger(Number(item.receipt.id))?item.receipt:null,
      summary:item.summary&&typeof item.summary==='object'?item.summary:null,
      createdAt:Number(item.createdAt)||Date.now()
    });
  }
  // Las solicitudes activas nunca se descartan por límites de historial.
  const active=rows.filter(remoteActive);
  const recent=rows.filter(row=>!remoteActive(row)).slice(-20);
  return [...active,...recent].sort((a,b)=>a.createdAt-b.createdAt);
}

export function loadRemoteQueue(storage){
  let saved;
  try{saved=JSON.parse(storage.getItem(REMOTE_QUEUE_STORAGE_KEY)||'null');}catch{}
  if(Array.isArray(saved))return normalizeRemoteQueue(saved,{resetProcessing:true});
  // Migrar una sola solicitud existente de la versión anterior sin perder su UUID.
  let old,attempt;
  try{old=JSON.parse(storage.getItem('tito_pending_remote_sale')||'null');}catch{}
  attempt=storage.getItem('tito_remote_auto_attempted')===String(old?.id);
  return normalizeRemoteQueue(old?[{...old,status:'pending',attempted:attempt}]:[],{resetProcessing:true});
}

export function canQueueRemoteSale(entries){
  return entries.filter(remoteActive).length<MAX_REMOTE_ACTIVE;
}

export function receiptFromRemoteSale(response,entry){
  return {
    id:Number(response.sale_id),
    total:Number(response.total_clp),
    payment:entry.sale.payment_method,
    items:entry.sale.items.length,
    when:new Date().toISOString()
  };
}
