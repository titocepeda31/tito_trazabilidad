export const APPROVAL_CHANNEL='tito-pos-approval-navigation-v1';

export function approvalIdFromUrl(href,expectedOrigin){
  try{
    const url=new URL(href,expectedOrigin);
    if(url.origin!==expectedOrigin)return null;
    const id=url.searchParams.get('approval_request');
    return id&&/^[1-9][0-9]*$/.test(id)?id:null;
  }catch{return null;}
}
export function approvalIdFromPushClick(event,expectedOrigin){
  const href=event?.result?.url??event?.notification?.launchURL??null;
  return typeof href==='string'?approvalIdFromUrl(href,expectedOrigin):null;
}
export function validApprovalBridgeMessage(msg){
  if(!msg||typeof msg!=='object')return false;
  if(!['open','handled'].includes(msg.type))return false;
  if(typeof msg.nonce!=='string'||!/^[a-f0-9-]{36}$/i.test(msg.nonce))return false;
  return typeof msg.id==='string'&&/^[1-9][0-9]*$/.test(msg.id);
}
