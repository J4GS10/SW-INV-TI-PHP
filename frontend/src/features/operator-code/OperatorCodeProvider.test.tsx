import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios';
import client from '../../api/axios';
import { AuthContext, type AuthContextType } from '../../context/auth-context';
import { getApiErrorCode } from '../../api/errors';
import { OperatorCodeProvider } from './OperatorCodeProvider';

const enviadas: InternalAxiosRequestConfig[] = [];
const adaptadorOriginal = client.defaults.adapter;

beforeEach(() => {
  enviadas.length = 0;
  client.defaults.adapter = (async (config: InternalAxiosRequestConfig) => {
    enviadas.push(config);
    return { data: {}, status: 200, statusText: 'OK', headers: {}, config };
  }) as AxiosAdapter;
});
afterEach(() => { client.defaults.adapter = adaptadorOriginal; });

const montar = (role: 'ESCANER' | 'TECNICO') => render(
  <AuthContext.Provider value={{ user: { sub: 'escaner_bodega', role, exp: 0 } } as unknown as AuthContextType}>
    <OperatorCodeProvider><span>pantalla</span></OperatorCodeProvider>
  </AuthContext.Provider>,
);

test('la cuenta de escáner pide el código antes de devolver y lo envía en las cabeceras', async () => {
  montar('ESCANER');
  let respuesta: Promise<unknown> = Promise.resolve();
  act(() => { respuesta = client.post('/trazabilidad/devolucion', { ACT_Activo: 'x' }); });
  expect(await screen.findByText('operator_code.actions.return')).toBeInTheDocument();
  expect(enviadas).toHaveLength(0);
  fireEvent.change(screen.getByLabelText('operator_code.user'), { target: { value: 'tecnico.ana' } });
  fireEvent.change(screen.getByLabelText('operator_code.code'), { target: { value: '48a29-16' } });
  fireEvent.click(screen.getByRole('button', { name: 'operator_code.authorize' }));
  await act(async () => { await respuesta; });
  expect(enviadas).toHaveLength(1);
  expect(enviadas[0].headers['X-Operator-User']).toBe('tecnico.ana');
  expect(enviadas[0].headers['X-Operator-Code']).toBe('482916');
});

test('cancelar no envía nada y dice que falta el código', async () => {
  montar('ESCANER');
  let error: unknown;
  act(() => { client.post('/trazabilidad/movimientos', {}).catch((e: unknown) => { error = e; }); });
  fireEvent.click(await screen.findByRole('button', { name: 'common.cancel' }));
  await waitFor(() => expect(getApiErrorCode(error)).toBe('OPERATOR_CODE_CANCELLED'));
  expect(enviadas).toHaveLength(0);
});

test('las lecturas y, fuera del escáner, las cuentas de TI no piden código', async () => {
  montar('TECNICO');
  await act(async () => {
    await client.get('/trazabilidad/movimientos');
    await client.post('/trazabilidad/devolucion', {});
  });
  expect(enviadas).toHaveLength(2);
  expect(screen.queryByText('operator_code.title')).toBeNull();
  expect(enviadas.every((c) => !c.headers['X-Operator-Code'])).toBe(true);
  vi.restoreAllMocks();
});
