import React,{useId,useState} from 'react';

/** Botón de ojo reutilizable, sin alterar el valor ni el envío de la contraseña. */
export default function PasswordField({label,name,value,onChange,disabled=false,required=false,autoComplete='off',minLength,maxLength,placeholder,error}){
  const id=useId();
  const [visible,setVisible]=useState(false);
  return <div className="password-field">
    <label htmlFor={id}>{label}</label>
    <div className="password-input-wrap">
      <input id={id} type={visible?'text':'password'} name={name} value={value}
        onChange={onChange} disabled={disabled} required={required} minLength={minLength}
        maxLength={maxLength} autoComplete={autoComplete} placeholder={placeholder} aria-invalid={error?true:undefined} aria-describedby={error?id+'-error':undefined}/>
      <button type="button" className="password-input-toggle" disabled={disabled}
        onClick={()=>setVisible(old=>!old)} aria-pressed={visible}
        aria-label={visible?'Ocultar '+label.toLowerCase():'Mostrar '+label.toLowerCase()}
        title={visible?'Ocultar contraseña':'Mostrar contraseña'}>
        {visible?
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" width="21" height="21">
            <path d="M3 3l18 18M10.6 10.7a2 2 0 002.7 2.7"/><path d="M9.8 5.4A11 11 0 0112 5c5 0 9 5 10 7a14 14 0 01-3.3 4.1M6.4 6.4C4.1 8 2.5 10.3 2 12c1 2 5 7 10 7 1.5 0 3-.4 4.3-1.1"/>
          </svg>:
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" width="21" height="21">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>
          </svg>}
      </button>
    </div>
    {error&&<span className="platform-field-error" id={id+'-error'}>{error}</span>}
  </div>;
}
