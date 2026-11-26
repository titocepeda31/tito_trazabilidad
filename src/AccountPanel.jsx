import React,{useState} from 'react';
import PasswordField from './PasswordField.jsx';

export default function AccountPanel({user,request,notify,onPasswordChanged}){
  const [current,setCurrent]=useState('');
  const [password,setPassword]=useState('');
  const [confirm,setConfirm]=useState('');
  const [busy,setBusy]=useState(false);
  async function change(e){
    e.preventDefault();
    if(password.length<12||password.length>72){notify('La nueva contraseña debe tener entre 12 y 72 caracteres.','error');return;}
    if(password!==confirm){notify('La confirmación no coincide.','error');return;}
    if(password===current){notify('La nueva contraseña debe ser diferente de la actual.','error');return;}
    setBusy(true);
    try{
      const result=await request('/account/password',{method:'POST',body:{current_password:current,new_password:password}});
      if(!result.ok)throw new Error('La API no confirmó el cambio.');
      setCurrent('');setPassword('');setConfirm('');
      onPasswordChanged();
    }catch(e){
      if(e.status===404)notify('El cambio de contraseña aún no está instalado en la API de cPanel.','error');
      else if(e.status===0)notify('Resultado de conexión incierto. Comprueba si puedes entrar con tu contraseña actual antes de volver a intentar.','error');
      else notify(e.message,'error');
    }finally{setBusy(false);}
  }
  return <div className="account-page">
    <div className="section-intro"><div><p className="eyebrow">SEGURIDAD</p><h2>Mi cuenta</h2><p className="muted">Cambia tu contraseña sin pedir ayuda al administrador.</p></div></div>
    <section className="panel pad account-card">
      <h3>Datos de acceso</h3>
      <p><strong>Correo:</strong> {user?.email}</p>
      <p><strong>Rol:</strong> {user?.role==='admin'?'Administrador':'Cajero'}</p>
      <hr/>
      <h3>Actualizar mi contraseña</h3>
      <p className="muted">Se requiere la contraseña actual. Después del cambio, se cerrarán todas las sesiones de esta cuenta, incluida la del teléfono.</p>
      <form onSubmit={change} className="formstack">
        <PasswordField label="Contraseña actual" autoComplete="current-password" required value={current} onChange={e=>setCurrent(e.target.value)}/>
        <PasswordField label="Nueva contraseña (12 a 72 caracteres)" autoComplete="new-password" minLength={12} maxLength={72} required value={password} onChange={e=>setPassword(e.target.value)}/>
        <PasswordField label="Confirmar contraseña nueva" autoComplete="new-password" minLength={12} maxLength={72} required value={confirm} onChange={e=>setConfirm(e.target.value)}/>
        <button type="submit" className="primary" disabled={busy}>{busy?'Actualizando…':'Cambiar contraseña y cerrar sesiones'}</button>
      </form>
    </section>
  </div>;
}
