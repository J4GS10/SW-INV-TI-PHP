import client from '../../api/axios';

export interface AssetStockRow {
  model_id: number | null;
  type: string | null;
  brand: string | null;
  model: string | null;
  total: number;
  assigned: number;
  available: number;
  last_entry_at: string | null;
}

export interface ConsumableStockRow {
  id: number;
  name: string;
  stock: number;
  minimum: number;
  unit_cost: number | null;
  currency: string | null;
  last_entry_at: string | null;
}

export interface EntryRow {
  date: string | null;
  type: string;
  item: string | null;
  quantity: number | null;
  unit_cost: number | null;
  currency: string | null;
  document: string | null;
}

export interface AssignmentRow {
  assigned_at: string | null;
  returned_at: string | null;
  asset_code: string;
  product: string;
  person: string;
  area: string | null;
}

interface Periodo {
  desde?: string;
  hasta?: string;
}

export const reportsService = {
  stock: async () => (await client.get<{ assets: AssetStockRow[]; consumables: ConsumableStockRow[] }>('/reports/stock')).data,
  entries: async (params: Periodo) => (await client.get<EntryRow[]>('/reports/entries', { params })).data,
  assignments: async (params: Periodo) => (await client.get<AssignmentRow[]>('/reports/assignments', { params })).data,
};
