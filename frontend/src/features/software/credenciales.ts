import type { TFunction } from 'i18next';
import type { CatalogoItem } from '../../api/catalogoService';
import { CAMPOS_CREDENCIAL, type CampoCredencial } from '../../types/software';

const columna = (campo: CampoCredencial) => campo.charAt(0).toUpperCase() + campo.slice(1);

/** Campos que pide el método de activación (catálogo «metodos-activacion»). */
export const camposDelMetodo = (metodo: CatalogoItem | null | undefined): CampoCredencial[] =>
  metodo ? CAMPOS_CREDENCIAL.filter((c) => metodo.extras[`MAC_Pide_${columna(c)}`] === true) : [];

/**
 * Etiqueta de un campo: la que define el método (p. ej. «Correo»). Las de los
 * métodos de sistema se traducen; las de un método creado por el usuario se
 * muestran tal cual.
 */
export const etiquetaCampo = (t: TFunction, metodo: CatalogoItem | null | undefined, campo: CampoCredencial): string => {
  const propia = metodo?.extras[`MAC_Etiqueta_${columna(campo)}`] as string | null | undefined;
  if (propia && !metodo?.sistema) return propia;
  const generica = t(`software.cred_fields.${campo}`);
  return propia && metodo?.codigo
    ? t(`software.cred_labels.${metodo.codigo}.${campo}`, { defaultValue: generica })
    : generica;
};

/** Clave aleatoria de 16 caracteres (sin caracteres ambiguos) generada en el navegador. */
export const generarSecreto = (largo = 16): string => {
  const alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!#$%*+-?';
  const bytes = new Uint32Array(largo);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => alfabeto[b % alfabeto.length]).join('');
};
