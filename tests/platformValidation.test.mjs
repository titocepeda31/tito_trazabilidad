import test from 'node:test';
import assert from 'node:assert/strict';
import {validRut,validateCompany,companyErrorField} from '../src/platformValidation.js';
const valid={name:'Comercial prueba',legal_name:'Comercial prueba SpA',tax_id:'12.345.678-5',admin_email:'prueba@example.test',admin_password:'Prueba123456!',confirm:'Prueba123456!'};
test('RUT con dígito verificador',()=>{assert.equal(validRut('12.345.678-5'),true);assert.equal(validRut('12.345.678-9'),false);assert.equal(validRut(''),false);assert.equal(validRut('6.088.182-K'),true);});
test('validación de empresa',()=>{assert.deepEqual(validateCompany(valid),{});const e=validateCompany({...valid,tax_id:'12345678-9',admin_email:'correo',confirm:'otra'});assert.ok(e.tax_id);assert.ok(e.admin_email);assert.ok(e.confirm);});
test('errores API y compatibilidad',()=>{assert.equal(companyErrorField({field:'admin_email',message:'conflicto'}),'admin_email');assert.equal(companyErrorField({message:'El correo ya está registrado en el sistema.'}),'admin_email');assert.equal(companyErrorField({message:'Ya existe una empresa con ese RUT.'}),'tax_id');assert.equal(companyErrorField({message:'No se pudo conectar con la API.'}),null);});
