export type DialogCloseReason = 'backdropClick' | 'escapeKeyDown' | undefined;

/** Accidental ways of closing (Esc, clicking outside) must not discard what the user typed. */
export const shouldConfirmClose = (reason: DialogCloseReason, dirty: boolean): boolean =>
  dirty && (reason === 'backdropClick' || reason === 'escapeKeyDown');

/** A MUI Select or Autocomplete choice happens in a popup and fires no DOM change event. */
export const isChoiceTarget = (target: EventTarget | null): boolean =>
  target instanceof Element && !!target.closest('[role="option"]');
