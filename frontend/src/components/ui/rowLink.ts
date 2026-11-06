import type { KeyboardEvent } from 'react';

/** A table row that opens something must also open it with the keyboard. */
export const rowLink = (open: () => void) => ({
  tabIndex: 0,
  onClick: open,
  onKeyDown: (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      open();
    }
  },
});
