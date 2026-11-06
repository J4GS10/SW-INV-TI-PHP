import { createFilterOptions } from '@mui/material/Autocomplete';
import type { FilterOptionsState } from '@mui/material/useAutocomplete';

export const AUTOCOMPLETE_MIN_CHARS = 2;
export const AUTOCOMPLETE_RESULT_LIMIT = 12;

export const hasAutocompleteQuery = (value: string, minChars = AUTOCOMPLETE_MIN_CHARS) =>
  value.trim().length >= minChars;

export const createSearchFilter = <T,>(
  stringify: (option: T) => string,
  limit = AUTOCOMPLETE_RESULT_LIMIT,
  minChars = AUTOCOMPLETE_MIN_CHARS,
) => {
  const filter = createFilterOptions<T>({
    ignoreAccents: true,
    ignoreCase: true,
    limit,
    stringify,
    trim: true,
  });

  return (options: T[], state: FilterOptionsState<T>) => (
    hasAutocompleteQuery(state.inputValue, minChars) ? filter(options, state) : []
  );
};
