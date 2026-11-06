import client from '../../api/axios';

export type GoodsReceiptStatus = 'REGISTERED' | 'PENDING_APPROVAL' | 'CONFIRMED' | 'COMPLETED' | 'CANCELLED';
export type LineType = 'ASSET' | 'CONSUMABLE' | 'LICENSE' | 'OTHER';

export interface GoodsReceiptLine {
  id: number;
  number: number;
  item_text: string;
  quantity: number;
  received_quantity: number | null;
  unit_cost?: string;
  warranty_months: number | null;
  line_type: LineType | null;
  asset_type_id: number | null;
  asset_type: string | null;
  model_id: number | null;
  product: string | null;
  consumable_id: number | null;
  consumable_name: string | null;
  software_id: number | null;
  license_id: number | null;
  keys_completed: boolean;
  units_total: number;
  units_done: number;
}

export interface GoodsReceiptEvent {
  event: string;
  at: string;
  user: string | null;
  detail: Record<string, unknown> | null;
}

export interface GoodsReceipt {
  id: number;
  code: string;
  status: GoodsReceiptStatus;
  supplier_id: number;
  supplier: string;
  document_number: string;
  received_date: string;
  site_id: number;
  site: string;
  currency: string;
  notes: string | null;
  difference_reason: string | null;
  approval_id: number | null;
  confirmed_at: string | null;
  created_by: string | null;
  /** Lo registró quien consulta: esa persona no puede confirmarlo. */
  mine: boolean;
  lines?: GoodsReceiptLine[];
  events?: GoodsReceiptEvent[];
}

export interface NewLine {
  item_text: string;
  quantity: number;
  unit_cost: string;
  warranty_months?: number | null;
}

export interface NewGoodsReceipt {
  supplier_id: number;
  document_number: string;
  received_date: string;
  site_id: number | null;
  currency: string;
  notes?: string;
  lines: NewLine[];
}

export interface ClassifiedLine {
  id: number;
  received_quantity: number;
  line_type: LineType;
  asset_type_id?: number;
  model_id?: number;
  consumable_id?: number;
  consumable_name?: string;
  unit?: string;
  software_id?: number;
  license_type_id?: number;
}

export interface UnitSpecification {
  type_id: number;
  name: string;
  unit: string | null;
  data_type: 'TEXT' | 'NUMBER' | 'LIST' | 'BOOLEAN';
  min: number | null;
  max: number | null;
  required: boolean;
  options: string[] | null;
  value: string | null;
}

export interface PendingUnit {
  asset_id: string;
  code: string;
  item_text: string;
  receipt_id: number;
  receipt_code: string;
  document_number: string;
  site: string;
  type: string | null;
  product: string;
  specifications: UnitSpecification[];
}

export interface ClassificationSuggestion {
  line_type: LineType;
  asset_type_id: number | null;
  model_id: number | null;
  consumable_id: number | null;
  consumable_name: string | null;
  unit: string | null;
  software_id: number | null;
  license_type_id: number | null;
}

export const statusColor = (status: GoodsReceiptStatus) => {
  switch (status) {
    case 'REGISTERED': return 'info' as const;
    case 'PENDING_APPROVAL': return 'warning' as const;
    case 'CONFIRMED': return 'primary' as const;
    case 'COMPLETED': return 'success' as const;
    default: return 'default' as const;
  }
};

const BASE = '/goods-receipts';

export const goodsReceiptService = {
  list: async (status?: GoodsReceiptStatus) => (await client.get<GoodsReceipt[]>(BASE, { params: { status } })).data,
  get: async (id: number) => (await client.get<GoodsReceipt>(`${BASE}/${id}`)).data,
  formContext: async () => (await client.get<{ site_id: number | null; received_date: string; supplier_id: number | null; currency: string }>(`${BASE}/form-context`)).data,
  // La llave evita un ingreso duplicado por doble clic o reintento de red.
  register: async (data: NewGoodsReceipt, idempotencyKey: string) =>
    (await client.post<GoodsReceipt>(BASE, data, { headers: { 'Idempotency-Key': idempotencyKey } })).data,
  cancel: async (id: number, reason: string) => (await client.post<GoodsReceipt>(`${BASE}/${id}/cancel`, { reason })).data,
  confirm: async (id: number, lines: ClassifiedLine[], differenceReason?: string) =>
    (await client.post<GoodsReceipt>(`${BASE}/${id}/confirm`, { lines, difference_reason: differenceReason })).data,
  documentExists: async (supplierId: number, documentNumber: string) =>
    (await client.get<{ exists: boolean }>(`${BASE}/checks/document`, { params: { supplier_id: supplierId, document_number: documentNumber } })).data.exists,
  serialExists: async (value: string, except?: string) => (await client.get<{ exists: boolean }>(`${BASE}/checks/serial`, { params: { value, except } })).data.exists,
  hostnameExists: async (value: string, except?: string) => (await client.get<{ exists: boolean }>(`${BASE}/checks/hostname`, { params: { value, except } })).data.exists,
  suggestion: async (text: string) => (await client.get<ClassificationSuggestion | null>(`${BASE}/classification-suggestion`, { params: { text } })).data,
  pendingUnits: async () => (await client.get<PendingUnit[]>(`${BASE}/pending-units`)).data,
  completeUnit: async (assetId: string, data: { serial: string; hostname?: string; specifications: { type_id: number; value: string }[] }) =>
    (await client.patch<{ pending: boolean; missing_specifications: string[]; receipt_status: string; code: string }>(`${BASE}/units/${assetId}`, data)).data,
  completeKeys: async (lineId: number, keys: string[]) => (await client.post<GoodsReceipt>(`${BASE}/lines/${lineId}/license-keys`, { keys })).data,
  createModel: async (assetTypeId: number, brand: string, model: string) =>
    (await client.post<{ model_id: number; brand_id: number; created: boolean }>(`${BASE}/catalog/models`, { asset_type_id: assetTypeId, brand, model })).data,
  act: async (id: number, lang: string) => (await client.get<Blob>(`${BASE}/${id}/act.pdf`, { responseType: 'blob', params: { lang } })).data,
  attach: async (id: number, file: File) => {
    const form = new FormData();
    form.append('file', file);
    await client.post(`/adjuntos/ingresos/${id}`, form);
  },
};

/** Las mismas reglas de la API, para avisar mientras se escribe. */
export const receiptRules = {
  today: () => new Date().toLocaleDateString('en-CA'),
  dateError: (value: string): string | null => {
    if (!value) return 'date_required';
    return value > receiptRules.today() ? 'date_future' : null;
  },
  quantityError: (value: string): string | null => (/^[1-9]\d*$/.test(value) ? null : 'quantity_invalid'),
  costError: (value: string): string | null => (/^\d{1,12}(\.\d{1,2})?$/.test(value) ? null : 'cost_invalid'),
  warrantyError: (value: string): string | null => (value === '' || (/^\d+$/.test(value) && Number(value) <= 600) ? null : 'warranty_invalid'),
};
