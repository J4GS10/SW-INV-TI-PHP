import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { PendingUnitsPage } from './PendingUnitsPage';
import { goodsReceiptService, type PendingUnit } from './goodsReceiptService';

vi.mock('notistack', () => ({ useSnackbar: () => ({ enqueueSnackbar: vi.fn() }) }));
vi.mock('../../context/auth-context', () => ({ useAuth: () => ({ user: { role: 'TECNICO', sub: 'tecnico' } }) }));
vi.mock('../assets/assetService', () => ({ assetService: { downloadLabels: vi.fn() } }));
vi.mock('../../api/productividadService', () => ({ guardarArchivo: vi.fn() }));
vi.mock('./goodsReceiptService', () => ({
  goodsReceiptService: {
    pendingUnits: vi.fn(),
    completeUnit: vi.fn(),
    serialExists: vi.fn().mockResolvedValue(false),
    hostnameExists: vi.fn().mockResolvedValue(false),
  },
}));

const service = vi.mocked(goodsReceiptService);
const unit = (id: string): PendingUnit => ({
  asset_id: id, code: id.toUpperCase(), item_text: 'Laptop', receipt_id: 1, receipt_code: 'ING-1', document_number: 'F1', site: 'Norte', type: 'Laptop', product: 'HP 450',
  specifications: [
    { type_id: 1, name: 'RAM', unit: 'GB', data_type: 'NUMBER', required: true, min: 1, max: 64, options: [], value: null },
    { type_id: 2, name: 'MAC', unit: null, data_type: 'TEXT', required: false, min: null, max: null, options: [], value: null },
  ],
} as unknown as PendingUnit);

const show = async (units: PendingUnit[]) => {
  service.pendingUnits.mockResolvedValue(units);
  render(<MemoryRouter><PendingUnitsPage /></MemoryRouter>);
  await screen.findByText('goods_receipt.common_data');
};
const serials = () => screen.getAllByLabelText(/goods_receipt\.serial/);
const type = (el: HTMLElement, value: string) => fireEvent.change(el, { target: { value } });

beforeEach(() => {
  vi.clearAllMocks();
  service.completeUnit.mockResolvedValue({ code: 'SAVED', pending: false, missing_specifications: [], receipt_status: 'COMPLETED' } as never);
});

test('Enter without the required data moves on and saves nothing', async () => {
  await show([unit('a'), unit('b')]);
  type(serials()[0], 'SN1');
  fireEvent.keyDown(serials()[0], { key: 'Enter' });
  expect(service.completeUnit).not.toHaveBeenCalled();
  expect(document.activeElement).toBe(serials()[1]);
  expect(screen.getAllByText('goods_receipt.missing').length).toBeGreaterThan(0);
});

test('shared data is typed once and each unit is saved with its own serial', async () => {
  await show([unit('a'), unit('b')]);
  type(screen.getByLabelText(/RAM \(GB\)/), '16');
  type(serials()[0], 'SN1');
  fireEvent.keyDown(serials()[0], { key: 'Enter' });
  await waitFor(() => expect(service.completeUnit).toHaveBeenCalledOnce());
  expect(service.completeUnit).toHaveBeenCalledWith('a', { serial: 'SN1', hostname: undefined, specifications: [{ type_id: 1, value: '16' }] });
});

test('saving the ready units skips the ones without a serial', async () => {
  await show([unit('a'), unit('b'), unit('c')]);
  type(screen.getByLabelText(/RAM \(GB\)/), '8');
  type(serials()[0], 'SN1');
  type(serials()[2], 'SN3');
  fireEvent.click(screen.getByRole('button', { name: 'goods_receipt.save_ready' }));
  await waitFor(() => expect(service.completeUnit).toHaveBeenCalledTimes(2));
  expect(service.completeUnit.mock.calls.map(([id]) => id)).toEqual(['a', 'c']);
});

test('data of one unit does not reach the others, and later shared changes do not overwrite it', async () => {
  await show([unit('a'), unit('b')]);
  fireEvent.click(screen.getAllByRole('button', { name: 'goods_receipt.unit_details' })[0]);
  const own = await screen.findAllByLabelText(/RAM \(GB\)/);
  type(own[own.length - 1], '32');
  type(screen.getAllByLabelText(/RAM \(GB\)/)[0], '16');
  type(serials()[0], 'SN1');
  type(serials()[1], 'SN2');
  fireEvent.click(screen.getByRole('button', { name: 'goods_receipt.save_ready' }));
  await waitFor(() => expect(service.completeUnit).toHaveBeenCalledTimes(2));
  const sent = Object.fromEntries(service.completeUnit.mock.calls.map(([id, data]) => [id, data.specifications]));
  expect(sent.a).toEqual([{ type_id: 1, value: '32' }]);
  expect(sent.b).toEqual([{ type_id: 1, value: '16' }]);
});

test('a serial already in use is shown on that unit', async () => {
  service.completeUnit.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { detail: 'SERIAL_NUMBER_ALREADY_EXISTS' } } });
  await show([unit('a'), unit('b')]);
  type(screen.getByLabelText(/RAM \(GB\)/), '8');
  type(serials()[0], 'SN1');
  fireEvent.keyDown(serials()[0], { key: 'Enter' });
  expect(await screen.findByText('goods_receipt.errors.serial_taken')).toBeInTheDocument();
});
