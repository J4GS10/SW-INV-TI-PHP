import type { SortDirection } from '../components/ui/tableSort';

/** Una página de un listado paginado en el servidor y cuántas filas coinciden en total. */
export interface Pagina<T> {
  total: number;
  items: T[];
}

/** Lo que se pide a un listado paginado en el servidor: los mismos parámetros en todos (`skip`, `limit`, `sort`, `sort_dir`). */
export interface PedidoPagina {
  skip: number;
  limit: number;
  sort?: string;
  sort_dir?: SortDirection;
}
