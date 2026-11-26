// onesignal:managed v1
// Único acceso al OneSignal Web SDK. No se usa OneSignal.login: las
// suscripciones se vinculan al administrador exclusivamente en la API PHP.
export const ONESIGNAL_APP_ID='00000000-0000-0000-0000-000000000000';

export async function getOneSignal(){
  if(typeof window==='undefined'||!window.titoOneSignalReady)throw new Error('OneSignal no está disponible en esta página.');
  let timer;
  try{
    return await Promise.race([
      window.titoOneSignalReady,
      new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('OneSignal no respondió. Revisa la conexión y el dominio configurado.')),14000);})
    ]);
  }finally{clearTimeout(timer);}
}

export function currentPushState(os){
  const sub=os.User.PushSubscription;
  return {
    id:sub.id||null,
    enabled:Boolean(sub.id&&sub.optedIn&&os.Notifications.permission),
    permission:typeof Notification==='undefined'?'unsupported':Notification.permission
  };
}

export async function observeOneSignalPush(onChange){
  const os=await getOneSignal();
  const listener=()=>onChange(currentPushState(os));
  os.User.PushSubscription.addEventListener('change',listener);
  listener();
  return ()=>os.User.PushSubscription.removeEventListener('change',listener);
}

export async function requestOneSignalPush(){
  const os=await getOneSignal();
  if(typeof Notification==='undefined')throw new Error('Este navegador no permite notificaciones web.');
  if(Notification.permission==='denied')throw new Error('Permiso bloqueado. Debes permitir notificaciones desde los ajustes del teléfono.');
  // Si antes se desactivó, Reactiva la suscripción sin repetir el permiso.
  if(Notification.permission==='granted'){
    await os.User.PushSubscription.optIn();
  }else{
    // La ventana de OneSignal ofrece un botón antes del permiso nativo.
    await os.Slidedown.promptPush();
  }
  return currentPushState(os);
}

export async function stopOneSignalPush(){
  const os=await getOneSignal();
  const state=currentPushState(os);
  await os.User.PushSubscription.optOut();
  return state.id;
}
