import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { TextField } from '@mui/material';
import { Dialog } from './Dialog';

const setup = () => {
  const onClose = vi.fn();
  render(<Dialog open onClose={onClose}><TextField label="name" /></Dialog>);
  return onClose;
};
const pressEsc = () => fireEvent.keyDown(screen.getAllByRole('dialog')[0], { key: 'Escape' });

test('Esc closes an untouched form at once', () => {
  const onClose = setup();
  pressEsc();
  expect(onClose).toHaveBeenCalledOnce();
});

test('Esc asks before discarding typed text, and can keep editing', () => {
  const onClose = setup();
  fireEvent.input(screen.getByLabelText('name'), { target: { value: 'x' } });
  pressEsc();
  expect(onClose).not.toHaveBeenCalled();
  expect(screen.getByText('common.unsaved_title')).toBeInTheDocument();
  fireEvent.click(screen.getByText('common.unsaved_keep'));
  expect(onClose).not.toHaveBeenCalled();
});

test('confirming discards', () => {
  const onClose = setup();
  fireEvent.input(screen.getByLabelText('name'), { target: { value: 'x' } });
  pressEsc();
  fireEvent.click(screen.getByText('common.unsaved_discard'));
  expect(onClose).toHaveBeenCalledOnce();
});
