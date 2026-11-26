export function validRut(raw){
  const rut=String(raw??'').trim().toUpperCase().replace(/[.\s-]/g,'');
  if(!/^[1-9]\d{0,7}[\dK]$/.test(rut))return false;
  const digits=rut.slice(0,-1);
  let sum=0,m=2;
  for(let i=digits.length-1;i>=0;i--){sum+=Number(digits[i])*m;m=m===7?2:m+1;}
  const d=11-sum%11;
  return rut.slice(-1)===(d===11?'0':d===10?'K':String(d));
}
export function validateCompany(values){
  const errors={};
  for(const [key,label,max] of [['name','nombre comercial',120],['legal_name','razón social',160],['tax_id','RUT',16],['admin_email','correo del administrador',190]]){
    const v=String(values[key]??'').trim();
    if(!v)errors[key]='Ingresa '+label+'.';
    else if(v.length>max)errors[key]='Máximo '+max+' caracteres.';
  }
  if(!errors.tax_id&&!validRut(values.tax_id))errors.tax_id='RUT incorrecto. Revisa el número y el dígito verificador.';
  if(!errors.admin_email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(values.admin_email).trim()))errors.admin_email='Ingresa un correo electrónico válido.';
  const length=new TextEncoder().encode(String(values.admin_password??'')).length;
  if(length<12||length>72)errors.admin_password='La contraseña debe tener entre 12 y 72 bytes.';
  if(!values.confirm||values.confirm!==values.admin_password)errors.confirm='Las contraseñas deben coincidir.';
  return errors;
}
export function companyErrorField(error){
  const fields=['name','legal_name','tax_id','admin_email','admin_password','confirm','address','city','email','phone','reason'];
  if(fields.includes(error?.field))return error.field;
  const msg=String(error?.message??'').toLowerCase();
  if(/rut inválido|ese rut|rut incorrecto/.test(msg))return 'tax_id';
  if(/correo.*registrado|correo.*inválido/.test(msg))return 'admin_email';
  if(/contraseña/.test(msg))return 'admin_password';
  const field=fields.find(f=>msg==='campo inválido: '+f);
  return field||null;
}

export function validateCompanyEdit(values){
  const errors={};
  for(const [key,label,max] of [['name','nombre comercial',120],['legal_name','razón social',160],['tax_id','RUT',16],['address','dirección',200],['city','ciudad',100],['email','correo de contacto',160],['phone','teléfono',40]]){
    const value=String(values[key]??'').trim();
    if(!value&&['name','legal_name','tax_id'].includes(key))errors[key]='Ingresa '+label+'.';
    else if(value.length>max)errors[key]='Máximo '+max+' caracteres.';
  }
  if(!errors.tax_id&&!validRut(values.tax_id))errors.tax_id='RUT incorrecto. Revisa el dígito verificador.';
  if(values.email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(values.email).trim()))errors.email='Correo de contacto inválido.';
  return errors;
}
