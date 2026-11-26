import {useEffect,useRef,useState} from 'react';
import {approvedRemoteSalePayload} from './remoteAutoSale.js';
import {REMOTE_QUEUE_STORAGE_KEY,canQueueRemoteSale,loadRemoteQueue,normalizeRemoteQueue,receiptFromRemoteSale,remoteActive} from './remoteSaleQueue.js';

// Cada solicitud conserva su propio UUID, resumen, estado y recibo.
// El carrito del cliente actual no depende de ninguna solicitud en esta cola.
export default function useCashierApprovalQueue({request,refresh,notify}){
  const [queue,setQueue]=useState(()=>loadRemoteQueue(sessionStorage));
  const queueRef=useRef(queue);
  const checks=useRef(new Set());
  const registering=useRef(new Set());
  const ownedToken=useRef(sessionStorage.getItem('tito_token'));
  const validSession=()=>!!ownedToken.current&&sessionStorage.getItem('tito_token')===ownedToken.current;

  function updateQueue(updater){
    if(!validSession())return; // Una respuesta tardía no debe revivir ventas al cerrar/cambiar sesión.
    const next=normalizeRemoteQueue(typeof updater==='function'?updater(queueRef.current):updater);
    queueRef.current=next;
    sessionStorage.setItem(REMOTE_QUEUE_STORAGE_KEY,JSON.stringify(next));
    setQueue(next);
  }

  useEffect(()=>{
    // Las claves antiguas dejan de controlar el carrito; la cola ya las migró.
    sessionStorage.setItem(REMOTE_QUEUE_STORAGE_KEY,JSON.stringify(queueRef.current));
    sessionStorage.removeItem('tito_pending_remote_sale');
    sessionStorage.removeItem('tito_remote_auto_attempted');
    const oldAttempt=(()=>{try{return JSON.parse(sessionStorage.getItem('tito_pending_sale')||'null');}catch{return null;}})();
    if(oldAttempt?.discount_request_id&&queueRef.current.some(x=>x.id===Number(oldAttempt.discount_request_id))){
      // El UUID persiste en la cola y su marca evita un POST nuevo tras recargar.
      updateQueue(items=>items.map(x=>x.id===Number(oldAttempt.discount_request_id)?{...x,attempted:true}:x));
      sessionStorage.removeItem('tito_pending_sale');
    }
  },[]);

  async function addRequest(sale,summary){
    if(!canQueueRemoteSale(queueRef.current))throw new Error('Ya hay 10 autorizaciones activas. Revisa o cancela alguna antes de solicitar otra.');
    const result=await request('/sales/discount-requests',{method:'POST',body:{sale}});
    if(!validSession())throw new Error('La sesión cambió antes de confirmar la solicitud.');
    if(!result.ok||!result.request_id)throw new Error('La API no confirmó la solicitud.');
    const id=Number(result.request_id);
    const current=queueRef.current.find(x=>x.id===id);
    if(!current){
      updateQueue(items=>[...items,{
        id,sale,summary,
        status:result.status||'pending',attempted:false,
        processing:false,error:'',reason:'',receipt:null,createdAt:Date.now()
      }]);
    }
    return id;
  }

  async function registerApproved(id,{manual=false}={}){
    const entry=queueRef.current.find(x=>x.id===id);
    if(!entry||entry.status!=='approved'||registering.current.has(id)||(entry.attempted&&!manual))return;
    const sale=approvedRemoteSalePayload(entry,'approved');
    if(!sale)return;
    registering.current.add(id);
    updateQueue(items=>items.map(x=>x.id===id?{...x,attempted:true,processing:true,error:''}:x));
    try{
      const data=await request('/sales',{method:'POST',body:sale});
      if(!validSession())return;
      if(!Number.isSafeInteger(Number(data.sale_id))||Number(data.sale_id)<=0)throw new Error('La API no confirmó el número de venta.');
      const receipt=receiptFromRemoteSale(data,entry);
      updateQueue(items=>items.map(x=>x.id===id?{...x,status:'completed',receipt,processing:false,error:''}:x));
      notify('Venta #'+receipt.id+' de la solicitud #'+id+' registrada correctamente.','success');
      void refresh(false);
    }catch(error){
      if(!validSession())return;
      const uncertain=!(error.status>=400&&error.status<500&&error.status!==408&&error.status!==429);
      const explanation=uncertain
        ?'El resultado de la venta es incierto. Verifica su estado antes de reintentar la misma solicitud.'
        :'No se pudo registrar la venta autorizada: '+error.message;
      updateQueue(items=>items.map(x=>x.id===id?{...x,processing:false,error:explanation}:x));
      notify('Solicitud #'+id+': '+explanation,'error');
    }finally{
      registering.current.delete(id);
    }
  }

  async function pollRequest(entry){
    const id=entry.id;
    if(checks.current.has(id))return;
    checks.current.add(id);
    try{
      const result=await request('/sales/discount-requests/'+id);
      if(!validSession())return;
      const current=queueRef.current.find(x=>x.id===id);
      if(!current)return; // Pudo descartarse mientras se consultaba.
      const latest=result.request;
      if(latest.status==='completed'&&latest.used_sale_id){
        const receipt=current.receipt||{
          id:Number(latest.used_sale_id),total:Number(latest.summary?.total_clp||0),
          payment:current.sale.payment_method,items:current.sale.items.length,
          when:new Date().toISOString()
        };
        updateQueue(items=>items.map(x=>x.id===id?{...x,status:'completed',receipt,processing:false,error:''}:x));
        if(current.status!=='completed'){
          notify('Venta #'+receipt.id+' confirmada para solicitud #'+id+'.','success');
          void refresh(false);
        }
        return;
      }
      updateQueue(items=>items.map(x=>x.id===id?{
        ...x,status:latest.status,reason:latest.decision_note||x.reason,summary:latest.summary||x.summary
      }:x));
      if(latest.status==='approved'&&!current.attempted&&!current.processing){
        notify('Solicitud #'+id+' aprobada. Registrando esa venta sin interrumpir el carrito actual.','info');
        void registerApproved(id);
      }
    }catch(error){
      if(error.status===403||error.status===404){
        updateQueue(items=>items.map(x=>x.id===id?{...x,error:'No se pudo consultar la solicitud: '+error.message}:x));
      }
      // Los cortes de conexión no descartan autorizaciones pendientes.
    }finally{
      checks.current.delete(id);
    }
  }

  useEffect(()=>{
    let active=true;
    const poll=()=>{
      if(!active)return;
      for(const entry of queueRef.current){
        if(remoteActive(entry)||entry.processing)void pollRequest(entry);
      }
    };
    poll();
    const timer=setInterval(poll,2500);
    return ()=>{active=false;clearInterval(timer);};
  },[]);

  async function cancelRequest(id){
    const entry=queueRef.current.find(x=>x.id===id);
    if(!entry||!remoteActive(entry)||entry.processing||registering.current.has(id))return;
    if(!window.confirm('¿Cancelar la solicitud #'+id+'? Esa venta no se registrará.'))return;
    updateQueue(items=>items.map(x=>x.id===id?{...x,processing:true}:x));
    try{
      await request('/sales/discount-requests/'+id+'/cancel',{method:'POST',body:{}});
      if(!validSession())return;
      updateQueue(items=>items.map(x=>x.id===id?{...x,status:'cancelled',processing:false,error:''}:x));
      notify('Solicitud #'+id+' cancelada; no se registró esa venta.','info');
    }catch(error){
      if(!validSession())return;
      updateQueue(items=>items.map(x=>x.id===id?{...x,processing:false,error:'Comprueba el estado antes de continuar: '+error.message}:x));
      notify('Solicitud #'+id+': '+error.message,'error');
    }
  }
  function dismissRequest(id){
    const entry=queueRef.current.find(x=>x.id===id);
    if(!entry||remoteActive(entry)||entry.processing)return;
    updateQueue(items=>items.filter(x=>x.id!==id));
  }

  return {queue,addRequest,registerApproved,cancelRequest,dismissRequest};
}
