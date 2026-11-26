import {useEffect,useRef,useState} from 'react';
import {api} from './api.js';
import {APPROVAL_CHANNEL,approvalIdFromPushClick,approvalIdFromUrl,validApprovalBridgeMessage} from './approvalDeepLink.js';

// No se transfieren tokens, contraseñas ni datos de clientes entre pestañas.
// La pestaña que YA tiene sesión confirma contra la API que la solicitud
// pertenece a su negocio antes de abrir la bandeja.
export default function useApprovalPushNavigation({token,user,onOpen}){
  const openRef=useRef(onOpen);
  openRef.current=onOpen;
  const [handledElsewhere,setHandledElsewhere]=useState(false);
  const [loginHere,setLoginHere]=useState(false);

  useEffect(()=>{
    const idFromLink=approvalIdFromUrl(window.location.href,window.location.origin);
    const channel='BroadcastChannel' in window?new BroadcastChannel(APPROVAL_CHANNEL):null;
    const nonce=crypto.randomUUID();
    let active=true;
    const timers=[];

    async function openInAdminTab(id,ackNonce=null){
      if(!token||user?.role!=='admin')return;
      try{
        const detail=await fetchAdminRequest(id);
        if(!active||!detail?.request)return;
        openRef.current(id);
        try{window.focus();}catch{/* Algunos móviles bloquean cambiar de ventana. */}
        if(ackNonce)channel?.postMessage({type:'handled',id,nonce:ackNonce});
      }catch{/* Otro negocio, sesión vencida o error: nunca confirmar sin autorización. */}
    }
    async function fetchAdminRequest(id){
      return api('/sales/discount-requests/'+id,{token});
    }
    const messageHandler=event=>{
      const msg=event.data;
      if(!validApprovalBridgeMessage(msg))return;
      if(msg.type==='open'){
        if(token&&user?.role==='admin')void openInAdminTab(msg.id,msg.nonce);
      }else if(msg.type==='handled'&&!token&&!loginHere&&idFromLink===msg.id&&nonce===msg.nonce){
        setHandledElsewhere(true);
      }
    };
    channel?.addEventListener('message',messageHandler);

    if(channel&&!token&&idFromLink&&!loginHere){
      const announce=()=>{if(active)channel.postMessage({type:'open',id:idFromLink,nonce});};
      announce();
      timers.push(setTimeout(announce,650),setTimeout(announce,1800),setTimeout(announce,4000));
    }
    // El SDK puede elegir abrir/focalizar la pestaña existente según Android/PWA.
    // Si la focaliza, el evento click abre Autorizaciones sin recargar ni perder sesión.
    let removeSdkListener=null;
    if(token&&user?.role==='admin'&&window.titoOneSignalReady){
      Promise.resolve(window.titoOneSignalReady).then(os=>{
        if(!active)return;
        const onClick=event=>{
          const id=approvalIdFromPushClick(event,window.location.origin);
          if(id)void openInAdminTab(id);
        };
        os.Notifications.addEventListener('click',onClick);
        removeSdkListener=()=>os.Notifications.removeEventListener('click',onClick);
      }).catch(()=>{});
    }
    return ()=>{
      active=false;
      for(const timer of timers)clearTimeout(timer);
      removeSdkListener?.();
      channel?.removeEventListener('message',messageHandler);
      channel?.close();
    };
  },[token,user?.role,loginHere]);

  function loginInNewTab(){
    setLoginHere(true);
    setHandledElsewhere(false);
  }
  return {handledElsewhere,loginInNewTab};
}
