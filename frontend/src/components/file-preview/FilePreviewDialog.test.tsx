import { beforeAll, expect, test, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { FilePreviewDialog } from './FilePreviewDialog';
import type { PreviewFile } from './filePreview';

beforeAll(() => {
  URL.createObjectURL = vi.fn(() => 'blob:prueba');
  URL.revokeObjectURL = vi.fn();
});

const archivo = (name: string, type: string): PreviewFile => ({ name, load: () => Promise.resolve(new Blob(['x'], { type })) });

test('un PDF se ve dentro de la página, sin descargarlo', async () => {
  render(<FilePreviewDialog files={[archivo('factura.pdf', 'application/pdf')]} index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />);
  await waitFor(() => expect(document.querySelector('iframe[title="factura.pdf"]')).toHaveAttribute('src', 'blob:prueba#view=FitH'));
});

test('un Word explica que no se puede mostrar y ofrece descargarlo', async () => {
  render(<FilePreviewDialog files={[archivo('acta.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]}
    index={0} onIndexChange={vi.fn()} onClose={vi.fn()} />);
  expect(await screen.findByText('file_preview.unavailable_title')).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: 'file_preview.download' }).length).toBeGreaterThan(0);
});

test('las flechas pasan al archivo siguiente y vuelven al primero', async () => {
  const onIndexChange = vi.fn();
  render(<FilePreviewDialog files={[archivo('a.png', 'image/png'), archivo('b.pdf', 'application/pdf')]} index={1}
    onIndexChange={onIndexChange} onClose={vi.fn()} />);
  await waitFor(() => expect(document.querySelector('iframe[title="b.pdf"]')).not.toBeNull());
  fireEvent.keyDown(window, { key: 'ArrowRight' });
  expect(onIndexChange).toHaveBeenLastCalledWith(0);
  fireEvent.click(screen.getByRole('button', { name: 'file_preview.previous' }));
  expect(onIndexChange).toHaveBeenLastCalledWith(0);
});
