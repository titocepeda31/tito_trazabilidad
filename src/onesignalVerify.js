// onesignal-verify.js — onesignal:managed v1
// Solo desarrollo. Se llama desde src/main.jsx tras importar el SDK.
export function installOneSignalDevVerification(){
  if(!import.meta.env.DEV||typeof window==='undefined')return;
  if(!window.OneSignalDeferred)return;
  let logged=false;
  window.OneSignalDeferred.push(async os=>{
    function report(){
      const id=os.User?.PushSubscription?.id;
      if(!id||id.startsWith('local-')||logged)return;
      logged=true;
      console.info('[Tito POS OneSignal dev] Suscripción creada:',id);
    }
    os.User.PushSubscription.addEventListener('change',report);
    report();
    if(typeof Notification!=='undefined'&&Notification.permission==='default'){
      console.info('[Tito POS OneSignal dev] Acepta el permiso si el navegador permite probar esta app/origen.');
      setTimeout(()=>os.Slidedown.promptPush({force:true}).catch(()=>{}),2000);
    }
  });
}
