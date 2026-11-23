import { act, renderHook } from '@testing-library/react';
import { expect, test } from 'vitest';
import { useServerPaging } from './useServerPaging';

test('pide la página que se ve y vuelve a la primera si cambian los filtros, el tamaño o el orden', () => {
  const { result, rerender } = renderHook(({ filtros }) => useServerPaging(filtros), { initialProps: { filtros: 'a' } });
  expect(result.current.pedido).toEqual({ skip: 0, limit: 25, sort: undefined, sort_dir: undefined });

  act(() => result.current.tabla.onPageChange(2));
  expect(result.current.pedido.skip).toBe(50);

  rerender({ filtros: 'b' });
  expect(result.current.pedido.skip).toBe(0);

  act(() => result.current.tabla.onPageChange(3));
  act(() => result.current.tabla.onRowsPerPageChange(10));
  expect(result.current.pedido).toMatchObject({ skip: 0, limit: 10 });

  act(() => result.current.tabla.onPageChange(1));
  act(() => result.current.tabla.onSortChange({ key: 'date', direction: 'desc' }));
  expect(result.current.pedido).toEqual({ skip: 0, limit: 10, sort: 'date', sort_dir: 'desc' });
});
