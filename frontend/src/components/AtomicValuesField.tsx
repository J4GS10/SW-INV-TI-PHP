import { useState } from 'react';
import { Autocomplete, Chip, TextField } from '@mui/material';
import { mergeAtomicValues, splitAtomicValues } from '../utils/atomicValues';

interface Props {
  value: string[];
  onChange: (values: string[]) => void;
  /** Values already used elsewhere, offered so the same thing is not spelled two ways. */
  suggestions?: string[];
  disabled?: boolean;
  placeholder?: string;
  ariaLabel?: string;
}

/**
 * List of indivisible values: each one is a chip of its own. Typing or pasting
 * «SSD, NVMe, HDD» adds three values, never one with commas inside.
 */
export const AtomicValuesField = ({ value, onChange, suggestions = [], disabled, placeholder, ariaLabel }: Props) => {
  const [input, setInput] = useState('');
  const taken = new Set(value.map((v) => v.toLowerCase()));

  const commit = (text: string) => {
    if (splitAtomicValues(text).length > 0) onChange(mergeAtomicValues([...value, text]));
    setInput('');
  };

  return (
    <Autocomplete
      multiple freeSolo size="small" disabled={disabled} value={value} inputValue={input}
      options={suggestions.filter((s) => !taken.has(s.toLowerCase()))}
      onChange={(_event, next) => { onChange(mergeAtomicValues(next)); setInput(''); }}
      onInputChange={(_event, text, reason) => {
        if (reason !== 'input') return;
        // Everything before the last separator is complete; the last piece may still be being typed.
        const parts = splitAtomicValues(text);
        if (parts.length > 1) {
          onChange(mergeAtomicValues([...value, ...parts.slice(0, -1)]));
          setInput(parts[parts.length - 1]);
        } else {
          setInput(text);
        }
      }}
      onBlur={() => commit(input)}
      renderTags={(tags, getTagProps) => tags.map((tag, index) => (
        <Chip size="small" label={tag} {...getTagProps({ index })} key={tag} />
      ))}
      renderInput={(params) => (
        <TextField {...params} variant="standard" placeholder={value.length === 0 ? placeholder : undefined}
          inputProps={{ ...params.inputProps, 'aria-label': ariaLabel }} />
      )}
    />
  );
};
