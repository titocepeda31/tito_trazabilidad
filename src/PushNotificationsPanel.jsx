// onesignal:managed v1
import React,{useEffect,useState} from 'react';
import {observeOneSignalPush,requestOneSignalPush,stopOneSignalPush} from './onesignalPush.js';

export default function PushNotificationsPanel({request,notify}){
  const [state,setState]=useState({id:null,enabled:false,permission:'unknown'});
  const [busy,setBusy]=useState(false);
  const [ready,setReady]=useState(false);
  const [error,setError]=useState('');
  useEffect(()=>{
    let active=true,cleanup=()=>{},inFlight=null;
    async function sync(s){
      if(!active)return;
      setState(s);setReady(true);
      if(!s.enabled||!s.id||inFlight===s.id)return;
      inFlight=s.id;
      try{await request('/account/push-subscriptions/register',{method:'POST',body:{subscription_id:s.id}});}
      catch(e){if(active)setError('El navegador permitió avisos, pero no se vinculó el dispositivo: '+e.message);}
      finally{inFlight=null;}
    }
    observeOneSignalPush(sync).then(fn=>{if(active)cleanup=fn;else fn();}).catch(e=>{if(active){setError(e.message);setReady(true);}});
    return ()=>{active=false;cleanup();};
  },[]);
  async function activate(){
    setBusy(true);setError('');
    try{
      const s=await requestOneSignalPush();
      setState(s);
      if(s.enabled&&s.id){
        await request('/account/push-subscriptions/register',{method:'POST',body:{subscription_id:s.id}});
        notify('Dispositivo vinculado. Puedes enviar una prueba.','success');
      }else notify('Acepta el permiso en el aviso del navegador. La activación puede tardar unos segundos.','info');
    }catch(e){setError(e.message);}
    finally{setBusy(false);}
  }
  async function deactivate(){
    setBusy(true);setError('');
    try{
      const id=state.id;
      if(id)await request('/account/push-subscriptions/unregister',{method:'POST',body:{subscription_id:id}});
      await stopOneSignalPush();
      setState(s=>({...s,enabled:false}));
      notify('Avisos desactivados en este dispositivo.','success');
    }catch(e){setError('No se pudo desactivar por completo: '+e.message);}
    finally{setBusy(false);}
  }
  async function test(){
    if(!state.id)return;
    setBusy(true);setError('');
    try{
      const data=await request('/account/push-subscriptions/test',{method:'POST',body:{subscription_id:state.id}});
      if(data.accepted)notify('OneSignal aceptó el envío de prueba. Comprueba la pantalla de tu teléfono.','success');
      else setError('OneSignal no confirmó el envío. Revisa la configuración del servidor.');
    }catch(e){setError('No fue posible enviar la prueba: '+e.message);}
    finally{setBusy(false);}
  }
  const iPhone=typeof navigator!=='undefined'&&/iPhone|iPad|iPod/.test(navigator.userAgent);
  const standalone=typeof window!=='undefined'&&(window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true);
  return <section className="panel pad tito-push-settings">
    <h3>Notificaciones al teléfono del administrador</h3>
    <p className="muted">Recibe solicitudes de descuentos con Tito POS cerrado. Debes permitir notificaciones en este dispositivo. El sonido depende de sus ajustes.</p>
    {iPhone&&!standalone&&<p className="discount-approval-notice"><strong>En iPhone:</strong> abre Tito POS en Safari, usa Compartir → Agregar a pantalla de inicio y después abre Tito POS desde ese ícono. El permiso push se activa desde la aplicación instalada.</p>}
    <p className="tito-push-status" aria-live="polite">{!ready?'Comprobando OneSignal…':state.enabled?'✓ Navegador suscrito. Comprueba la vinculación con una notificación de prueba.':'Avisos push desactivados en este dispositivo.'}</p>
    <div className="inline-actions">
      {!state.enabled&&<button type="button" className="primary" disabled={busy||!ready||(iPhone&&!standalone)} onClick={activate}>{busy?'Activando…':'Activar notificaciones push'}</button>}
      {state.enabled&&<><button type="button" className="primary" disabled={busy} onClick={test}>Enviar notificación de prueba</button><button type="button" className="ghost" disabled={busy} onClick={deactivate}>Desactivar en este dispositivo</button></>}
    </div>
    {error&&<p className="tito-push-error" role="alert">{error}</p>}
    <p className="helper">Solo los administradores pueden vincular dispositivos al negocio. La bandeja de autorizaciones continúa disponible si falla el envío push.</p>
  </section>;
}
