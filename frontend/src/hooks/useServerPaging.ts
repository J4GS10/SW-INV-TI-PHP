import { useMemo, useState } from 'react';
import type { SortState } from '../components/ui/tableSort';
import type { PedidoPagina } from '../types/paging';

/**
 * Página, tamaño y orden de un listado paginado en el servidor. `filtros` identifica los filtros vigentes:
 * si cambian, se vuelve a la primera página (la que se veía puede ya no existir).
 * `tabla` va tal cual a `DataTableShell`, junto con `total`.
 */
export const useServerPaging = (filtros: string, filasIniciales = 25) => {
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(filasIniciales);
  const [sort, setSort] = useState<SortState | null>(null);
  const [filtrosVistos, setFiltrosVistos] = useState(filtros);
  if (filtros !== filtrosVistos) {
    setFiltrosVistos(filtros);
    setPage(0);
  }
  const pedido = useMemo<PedidoPagina>(() => ({
    skip: page * rowsPerPage, limit: rowsPerPage, sort: sort?.key, sort_dir: sort?.direction,
  }), [page, rowsPerPage, sort]);

  return {
    pedido,
    tabla: {
      page,
      rowsPerPage,
      sort,
      onPageChange: setPage,
      onRowsPerPageChange: (filas: number) => { setRowsPerPage(filas); setPage(0); },
      onSortChange: (orden: SortState | null) => { setSort(orden); setPage(0); },
    },
  };
};
