import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { TableCell, TableRow } from '@mui/material';
import { DataTableShell } from './DataTableShell';

const rows = [{ code: 'B' }, { code: 'A' }];
const table = (extra = {}) => (
  <DataTableShell
    columns={[{ key: 'code', label: 'Code', sortValue: (r: { code: string }) => r.code }]}
    rows={rows}
    renderRow={(r) => <TableRow key={r.code}><TableCell>{r.code}</TableCell></TableRow>}
    {...extra}
  />
);
const cells = () => within(screen.getByRole('table')).getAllByRole('cell').map((c) => c.textContent);

test('keeps the list order until a header is used', () => {
  render(table());
  expect(cells()).toEqual(['B', 'A']);
  fireEvent.click(screen.getByRole('button', { name: 'Code' }));
  expect(cells()).toEqual(['A', 'B']);
  expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'ascending');
  fireEvent.click(screen.getByRole('button', { name: 'Code' }));
  expect(cells()).toEqual(['B', 'A']);
});

test('a server-sorted list asks for the order and does not reorder by itself', () => {
  const onSortChange = vi.fn();
  render(<DataTableShell
    columns={[{ key: 'code', label: 'Code', serverSortable: true }]}
    rows={rows}
    sort={null}
    onSortChange={onSortChange}
    renderRow={(r) => <TableRow key={r.code}><TableCell>{r.code}</TableCell></TableRow>}
  />);
  fireEvent.click(screen.getByRole('button', { name: 'Code' }));
  expect(onSortChange).toHaveBeenCalledWith({ key: 'code', direction: 'asc' });
  expect(cells()).toEqual(['B', 'A']);
});

test('a loading failure offers a retry instead of an empty list', () => {
  const onRetry = vi.fn();
  render(table({ error: true, onRetry }));
  expect(screen.queryByText('common.no_records')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'common.retry' }));
  expect(onRetry).toHaveBeenCalledOnce();
});
