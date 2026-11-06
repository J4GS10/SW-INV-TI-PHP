const INTERNAL_ID = /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i;
/** Types whose name says nothing beyond the event already shown when their reference is internal. */
const OMITTED_WITHOUT_REFERENCE = new Set(['MOVIMIENTO']);
const SEPARATOR = ' — ';

export interface KardexReasonInput {
  motivo: string | null | undefined;
  documentType: string | null | undefined;
  documentRef: string | null | undefined;
}

/** The server writes «Ingreso ING-00001» as the reason of a receipt whose document already says so. */
const repeatsReceipt = (motivo: string, type: string | null | undefined, ref: string | null | undefined): boolean => {
  if (type !== 'INGRESO' || !ref) return false;
  const code = /^Ingreso\s+(\S+)$/i.exec(motivo.trim())?.[1];
  return !!code && ref.split(' · ')[0] === code;
};

/**
 * Reason and document of a kardex event for reading: without internal identifiers or repetitions.
 * The ledger itself is immutable, so this only affects how it is displayed.
 */
export const kardexReasonText = ({ motivo, documentType, documentRef }: KardexReasonInput, typeLabel: (type: string) => string): string => {
  const ref = documentRef?.trim() || null;
  const internal = !!ref && INTERNAL_ID.test(ref);
  const omitted = internal && !!documentType && OMITTED_WITHOUT_REFERENCE.has(documentType);
  const document = omitted ? '' : [documentType ? typeLabel(documentType) : '', internal ? '' : ref].filter(Boolean).join(' ');
  const reason = motivo && !repeatsReceipt(motivo, documentType, ref) ? motivo : '';
  return [reason, document].filter(Boolean).join(SEPARATOR);
};
