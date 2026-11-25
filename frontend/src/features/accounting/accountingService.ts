import client from '../../api/axios';

/* Valor contable de los activos fijos (espejo de /api/v1/accounting). Los montos llegan como texto exacto. */

export type DepreciationMethod = 'STRAIGHT_LINE' | 'NONE';

export interface AccountingCategory {
  id: number;
  code: string;
  name: string;
  country: string;
  method: DepreciationMethod;
  annual_rate: string;
  residual_percent: string;
  starts_next_month: boolean;
  useful_life_months: number | null;
  account_code: string | null;
  legal_basis: string | null;
  active: boolean;
  asset_types: number;
  row_version: number;
}

export interface AccountingCategoryInput {
  code: string;
  name: string;
  country: string;
  method: DepreciationMethod;
  annual_rate: string;
  residual_percent: string;
  starts_next_month: boolean;
  account_code: string | null;
  legal_basis: string | null;
  active?: boolean;
}

export type ValuationStatus = 'NO_COST' | 'NO_CATEGORY' | 'NOT_DEPRECIABLE' | 'DEPRECIATING' | 'FULLY_DEPRECIATED' | 'DISPOSED';

/** Suma de una moneda: nunca se mezclan quetzales con dólares. */
export interface CurrencyTotal {
  currency: string;
  count: number;
  acquisition_cost: string;
  accumulated_depreciation: string;
  book_value: string;
}

export interface ValuedAsset {
  asset_id: string;
  code: string;
  serial: string | null;
  product: string | null;
  type_id: number;
  type: string;
  parent_id: string | null;
  category: { id: number; code: string; name: string; annual_rate: string | null; account_code: string | null } | null;
  currency: string;
  invoice: string | null;
  acquired_on: string;
  disposed_on: string | null;
  useful_life_months: number | null;
  acquisition_cost: string | null;
  monthly_depreciation: string | null;
  months_elapsed: number;
  accumulated_depreciation: string | null;
  book_value: string | null;
  residual_value: string | null;
  depreciation_starts_on: string | null;
  fully_depreciated_on: string | null;
  status: ValuationStatus;
  /** Lo instalado en el equipo (RAM, disco, monitor…), con su propio valor. */
  components: ValuedAsset[];
  /** El activo más todo lo instalado en él. */
  totals: CurrencyTotal[];
}

export interface Pending {
  without_cost: number;
  without_category: number;
}

export interface ScheduleRow {
  year: number;
  months: number;
  depreciation: string;
  accumulated: string;
  book_value: string;
}

export interface AssetValuationResult {
  as_of: string;
  asset: ValuedAsset;
  schedule: ScheduleRow[];
  totals: CurrencyTotal[];
  pending: Pending;
}

export interface WorkstationResult {
  as_of: string;
  person: { person_id: string; name: string; email: string; department: string | null; position: string | null };
  items: ValuedAsset[];
  totals: CurrencyTotal[];
  pending: Pending;
}

export interface SummaryResult {
  as_of: string;
  site_id: number | null;
  by_category: { category: ValuedAsset['category']; totals: CurrencyTotal[] }[];
  by_type: { type_id: number; type: string; category: string | null; totals: CurrencyTotal[] }[];
  by_site: { site_id: number | null; site: string | null; totals: CurrencyTotal[] }[];
  /** Solo para quien lee personas (TI y auditor). */
  by_person?: { person_id: string | null; person: string | null; department: string | null; totals: CurrencyTotal[] }[];
  by_department?: { department_id: number | null; department: string | null; totals: CurrencyTotal[] }[];
  totals: CurrencyTotal[];
  pending: Pending;
}

const query = (params: Record<string, string | number | null | undefined>) => {
  const q = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => [k, String(v)]));
  const s = q.toString();
  return s ? `?${s}` : '';
};

export const accountingService = {
  categories: async (): Promise<AccountingCategory[]> => (await client.get<AccountingCategory[]>('/accounting/categories')).data,

  createCategory: async (data: AccountingCategoryInput): Promise<AccountingCategory> =>
    (await client.post<AccountingCategory>('/accounting/categories', data)).data,

  /** `data` lleva la `row_version` con que se abrió el formulario (api/rowVersion.ts). */
  updateCategory: async (id: number, data: Partial<AccountingCategoryInput> & { row_version?: number }): Promise<AccountingCategory> =>
    (await client.patch<AccountingCategory>(`/accounting/categories/${id}`, data)).data,

  asset: async (assetId: string, asOf?: string): Promise<AssetValuationResult> =>
    (await client.get<AssetValuationResult>(`/accounting/assets/${assetId}/valuation${query({ as_of: asOf })}`)).data,

  workstation: async (personId: string, asOf?: string): Promise<WorkstationResult> =>
    (await client.get<WorkstationResult>(`/accounting/persons/${personId}/workstation${query({ as_of: asOf })}`)).data,

  summary: async (asOf?: string, siteId?: number | ''): Promise<SummaryResult> =>
    (await client.get<SummaryResult>(`/accounting/summary${query({ as_of: asOf, site_id: siteId })}`)).data,

  /** Ruta del registro de activos fijos en CSV, para api/downloadCsv. */
  registerPath: (asOf?: string, siteId?: number | ''): string => `/accounting/register.csv${query({ as_of: asOf, site_id: siteId })}`,
};
