import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ReturnSoftwareBatchChoice, ReturnSoftwareChoice } from './ReturnSoftwareSection';
import type { SoftwareInstalado } from './useInstalledSoftware';

const instalada = (id: number, nombre: string, codigo = 'LAP00013'): SoftwareInstalado => ({
  INS_Instalacion: id, LIC_Licencia: id * 10, INS_Fecha_Instalacion: '2026-11-01', INS_Estado: true, ACT_Activo: 'a', codigo,
  licencia: { LIC_Licencia: id * 10, LIC_Fecha_Vencimiento: null, LIC_Cantidad_Total: 5, LIC_Cantidad_Usada: 1,
    software: { SOF_Software: id, SOF_Nombre: nombre, SOF_Version: null, SOF_Fabricante: 'X' } },
});
const base = { loading: false, error: false, onRetry: vi.fn() };

test('cada licencia del equipo se marca o se deja con el equipo', () => {
  const onChange = vi.fn();
  render(<ReturnSoftwareChoice {...base} items={[instalada(1, 'Office'), instalada(2, 'AutoCAD')]} selected={[1, 2]} onChange={onChange} />);
  expect(screen.getByText('returns.software.title')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: /AutoCAD/ }));
  expect(onChange).toHaveBeenLastCalledWith([1]);
  fireEvent.click(screen.getByRole('button', { name: 'returns.software.none' }));
  expect(onChange).toHaveBeenLastCalledWith([]);
});

test('sin software instalado no pregunta, pero dice que lo revisó', () => {
  render(<ReturnSoftwareChoice {...base} items={[]} selected={[]} onChange={vi.fn()} />);
  expect(screen.getByRole('status')).toHaveTextContent('returns.software.empty');
  expect(screen.queryByRole('checkbox')).toBeNull();
});

test('si no se pudo revisar el software, lo dice y deja reintentar', () => {
  const onRetry = vi.fn();
  render(<ReturnSoftwareChoice {...base} error onRetry={onRetry} items={[]} selected={[]} onChange={vi.fn()} />);
  fireEvent.click(screen.getByRole('button', { name: 'common.retry' }));
  expect(onRetry).toHaveBeenCalledOnce();
});

test('en lote muestra lo instalado por equipo y pide decidir', () => {
  const onChange = vi.fn();
  render(<ReturnSoftwareBatchChoice {...base} items={[instalada(1, 'Office'), instalada(2, 'Visio', 'LAP00014')]} value={null} onChange={onChange} />);
  expect(screen.getByText('LAP00014')).toBeInTheDocument();
  expect(screen.getAllByRole('radio').every((r) => !(r as HTMLInputElement).checked)).toBe(true);
  fireEvent.click(screen.getByRole('radio', { name: 'returns.software.batch_keep' }));
  expect(onChange).toHaveBeenLastCalledWith('CONSERVAR');
});
