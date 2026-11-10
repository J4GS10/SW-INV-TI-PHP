import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Paper,
  Skeleton,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  useTheme,
} from '@mui/material';
import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from './EmptyState';
import type { SvgIconComponent } from '@mui/icons-material';
import InboxIcon from '@mui/icons-material/Inbox';
import { nextSort, sortRows, type SortState, type SortValue } from './tableSort';

interface Column<T> {
  key: string;
  /** Texto o nodo (p. ej. un Checkbox de "seleccionar todo"). */
  label: ReactNode;
  width?: number | string;
  align?: 'left' | 'center' | 'right';
  padding?: 'normal' | 'checkbox' | 'none';
  /** Value to sort by on the client; the list must be fully loaded. */
  sortValue?: (row: T) => SortValue;
  /** The server sorts this column (see `sort` and `onSortChange`). */
  serverSortable?: boolean;
}

interface DataTableShellProps<T> {
  columns: Column<T>[];
  rows: T[];
  loading?: boolean;
  total?: number;
  page?: number;
  rowsPerPage?: number;
  onPageChange?: (page: number) => void;
  onRowsPerPageChange?: (rows: number) => void;
  renderRow: (row: T, index: number) => ReactNode;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyIcon?: SvgIconComponent;
  emptyAction?: { label: string; onClick: () => void };
  toolbar?: ReactNode;
  stickyHeader?: boolean;
  rowsPerPageOptions?: number[];
  /** Columnas ocultas por el usuario (claves); las celdas se ocultan por posición. */
  hiddenColumns?: ReadonlySet<string>;
  /** Densidad de las filas (preferencia del usuario). */
  density?: 'compact' | 'comfortable';
  /** Order set by the server, for paginated lists. */
  sort?: SortState | null;
  onSortChange?: (sort: SortState | null) => void;
  /** The list could not be loaded: shows the error and a way to retry instead of an empty list. */
  error?: boolean;
  onRetry?: () => void;
}

const SKELETON_ROWS = 6;
/** Columnas de botones: en el teléfono van al final de la tarjeta, sin nombre. */
const ACTION_KEYS = new Set(['actions', 'acciones', 'acc']);
/** Rows per page for lists paged in the browser: short by default, remembered per viewer. */
const PAGE_SIZE_KEY = 'inv_filas_por_pagina';
const DEFAULT_PAGE_SIZE = 10;
const readPageSize = (): number => {
  try {
    const saved = Number(window.localStorage.getItem(PAGE_SIZE_KEY));
    return [10, 25, 50, 100].includes(saved) ? saved : DEFAULT_PAGE_SIZE;
  } catch {
    return DEFAULT_PAGE_SIZE;
  }
};

/**
 * Shell de tabla de datos estándar.
 * Integra: loading skeleton, estado vacío, paginación server-side, orden por columna, error con
 * reintento y toolbar colapsable. En un teléfono cada fila se muestra como una tarjeta, con el nombre
 * de la columna junto a cada dato y sin desplazamiento lateral.
 * Los datos y renderizado de filas son responsabilidad del componente padre.
 */
export const DataTableShell = <T,>({
  columns,
  rows,
  loading = false,
  total,
  page = 0,
  rowsPerPage = 25,
  onPageChange,
  onRowsPerPageChange,
  renderRow,
  emptyTitle,
  emptyDescription,
  emptyIcon = InboxIcon,
  emptyAction,
  toolbar,
  stickyHeader = false,
  rowsPerPageOptions = [10, 25, 50, 100],
  hiddenColumns,
  density = 'compact',
  sort,
  onSortChange,
  error = false,
  onRetry,
}: DataTableShellProps<T>) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const serverPaged = total !== undefined && !!onPageChange;
  const isEmpty = !loading && !error && rows.length === 0;
  const [clientSort, setClientSort] = useState<SortState | null>(null);
  const activeSort = onSortChange ? sort ?? null : clientSort;
  // Lists loaded in full are paged here, so a long list never pushes the rest of the page down.
  const [clientPage, setClientPage] = useState(0);
  const [clientPageSize, setClientPageSize] = useState(readPageSize);
  // A filter or search that changes how many rows there are starts again at the first page.
  const [seenCount, setSeenCount] = useState(rows.length);
  if (rows.length !== seenCount) {
    setSeenCount(rows.length);
    setClientPage(0);
  }
  const clientPaged = !serverPaged && rows.length > Math.min(...rowsPerPageOptions);
  const lastPage = Math.max(0, Math.ceil(rows.length / clientPageSize) - 1);
  const currentClientPage = Math.min(clientPage, lastPage);

  const sortedRows = useMemo(() => {
    const value = clientSort && columns.find((col) => col.key === clientSort.key)?.sortValue;
    return value && clientSort ? sortRows(rows, value, clientSort.direction) : rows;
    // `columns` is rebuilt on every render of the parent: the key identifies the column.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows, clientSort]);
  const pageStart = clientPaged ? currentClientPage * clientPageSize : 0;
  const visibleRows = clientPaged ? sortedRows.slice(pageStart, pageStart + clientPageSize) : sortedRows;
  const changeClientPageSize = (size: number) => {
    setClientPageSize(size);
    setClientPage(0);
    try { window.localStorage.setItem(PAGE_SIZE_KEY, String(size)); } catch { /* per-viewer convenience only */ }
  };
  const showPagination = serverPaged || clientPaged;

  const toggleSort = (key: string) => {
    if (onSortChange) onSortChange(nextSort(activeSort, key));
    else setClientSort((current) => nextSort(current, key));
  };

  const phoneCards: Record<string, object> = {};
  columns.forEach((col, i) => {
    const selector = `& tbody td:nth-of-type(${i + 1})`;
    if (hiddenColumns?.has(col.key)) phoneCards[selector] = { display: 'none' };
    // Acciones: botones al final de la tarjeta, sin etiqueta. Un dato alineado a la derecha sí lleva su nombre.
    else if (col.align === 'right' && ACTION_KEYS.has(col.key)) phoneCards[selector] = { justifyContent: 'flex-end' };
    else if (typeof col.label === 'string' && col.label) {
      phoneCards[`${selector}::before`] = {
        content: JSON.stringify(col.label),
        flexShrink: 0,
        fontWeight: 600,
        textAlign: 'left',
        color: theme.palette.text.secondary,
      };
    }
  });

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
      {toolbar && (
        <Box sx={{ p: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
          {toolbar}
        </Box>
      )}

      <TableContainer sx={{ border: 0, borderRadius: 0 }}>
        <Table
          size="small"
          stickyHeader={stickyHeader}
          sx={{
            '& .MuiTableCell-root': { whiteSpace: { sm: 'nowrap', lg: 'normal' } },
            ...(density === 'comfortable' ? { '& .MuiTableCell-root': { py: 1.5, whiteSpace: { sm: 'nowrap', lg: 'normal' } } } : {}),
            ...Object.fromEntries(columns.flatMap((col, i) => (hiddenColumns?.has(col.key)
              ? [[`& .MuiTableRow-root > .MuiTableCell-root:nth-of-type(${i + 1})`, { display: 'none' }]] : []))),
            // Acciones (columna alineada a la derecha): iconos siempre en una fila.
            '& .MuiTableCell-root.MuiTableCell-alignRight': { whiteSpace: 'nowrap', width: '1%' },
            [theme.breakpoints.down('sm')]: {
              display: 'block',
              '& thead': { display: 'none' },
              '& tbody': { display: 'block' },
              '& tbody tr': { display: 'block', py: 1, px: 0.5, borderBottom: `1px solid ${theme.palette.divider}` },
              '& tbody td.MuiTableCell-root': {
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                gap: 2,
                width: 'auto !important',
                maxWidth: 'none',
                border: 0,
                py: 0.5,
                textAlign: 'right',
                whiteSpace: 'normal',
                overflowWrap: 'anywhere',
              },
              ...phoneCards,
              // Celda sin contenido para este rol (acciones que no tiene): sin etiqueta suelta.
              '& tbody td:empty': { display: 'none !important' },
            },
          }}
        >
          <TableHead>
            <TableRow>
              {columns.map((col) => {
                const sortable = onSortChange ? col.serverSortable : !!col.sortValue;
                const direction = activeSort?.key === col.key ? activeSort.direction : undefined;
                return (
                  <TableCell
                    key={col.key}
                    align={col.align ?? 'left'}
                    padding={col.padding}
                    sortDirection={direction ?? false}
                    sx={{ width: col.width, whiteSpace: 'nowrap' }}
                  >
                    {sortable ? (
                      <TableSortLabel active={!!direction} direction={direction ?? 'asc'} onClick={() => toggleSort(col.key)}>
                        {col.label}
                      </TableSortLabel>
                    ) : col.label}
                  </TableCell>
                );
              })}
            </TableRow>
          </TableHead>

          <TableBody>
            {loading ? (
              Array.from({ length: SKELETON_ROWS }).map((_, i) => (
                <TableRow key={i}>
                  {columns.map((col) => (
                    <TableCell key={col.key} padding={col.padding}>
                      <Skeleton variant="text" width="80%" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : isEmpty || error ? null : (
              visibleRows.map((row, index) => renderRow(row, pageStart + index))
            )}
          </TableBody>
        </Table>
      </TableContainer>

      {!loading && error && (
        <Alert
          severity="error"
          sx={{ m: 2 }}
          action={onRetry && <Button color="inherit" size="small" onClick={onRetry}>{t('common.retry')}</Button>}
        >
          {t('common.error_loading')}
        </Alert>
      )}

      {/* El estado vacío va fuera de la tabla: centrado en el ancho visible
          (dentro de un colSpan quedaba fuera de pantalla en móvil). */}
      {isEmpty && (
        <EmptyState
          title={emptyTitle ?? t('common.no_records')}
          description={emptyDescription}
          icon={emptyIcon}
          action={emptyAction}
          compact
        />
      )}

      {showPagination && (
        <Stack
          direction="row"
          alignItems="center"
          justifyContent="flex-end"
          sx={{ borderTop: '1px solid', borderColor: 'divider', px: 1 }}
        >
          {loading && (
            <Box sx={{ mr: 'auto', pl: 1 }}>
              <CircularProgress size={16} thickness={4} />
            </Box>
          )}
          <TablePagination
            component="div"
            count={serverPaged ? total ?? 0 : rows.length}
            page={serverPaged ? page : currentClientPage}
            rowsPerPage={serverPaged ? rowsPerPage : clientPageSize}
            onPageChange={(_, p) => (serverPaged ? onPageChange?.(p) : setClientPage(p))}
            onRowsPerPageChange={(e) => {
              const size = parseInt(e.target.value, 10);
              if (serverPaged) onRowsPerPageChange?.(size);
              else changeClientPageSize(size);
            }}
            rowsPerPageOptions={rowsPerPageOptions}
            sx={{ '& .MuiTablePagination-selectLabel, & .MuiTablePagination-input': { display: { xs: 'none', sm: 'flex' } } }}
            labelRowsPerPage={t('common.table.rows_per_page')}
            labelDisplayedRows={({ from, to, count }) =>
              count !== -1
                ? t('common.table.displayed_rows', { from, to, count })
                : t('common.table.displayed_rows_more', { from, to })
            }
          />
        </Stack>
      )}
    </Paper>
  );
};
