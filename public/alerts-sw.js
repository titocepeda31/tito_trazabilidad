/* Avisos del navegador mientras Tito POS está activo; no recibe push en segundo plano. */
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  event.waitUntil((async()=>{
    const windows=await clients.matchAll({type:'window',includeUncontrolled:true});
    const target=windows.find(w=>w.url.startsWith(self.location.origin));
    if(target){await target.focus();return;}
    await clients.openWindow('/');
  })());
});
