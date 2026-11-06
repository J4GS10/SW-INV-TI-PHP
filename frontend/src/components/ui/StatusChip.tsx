import { Chip, Tooltip } from '@mui/material';
import type { ChipProps } from '@mui/material';

import { statusColor, type StatusVariant } from './statusColors';

export type { StatusVariant };

interface StatusChipProps {
  label: string;
  variant?: StatusVariant;
  tooltip?: string;
  size?: ChipProps['size'];
}

/**
 * Chip de estado semántico.
 * Usa `variant` para aplicar el color correcto sin depender de strings hardcodeados.
 * Si no se pasa variant, intenta resolver desde el label en minúsculas.
 */
export const StatusChip = ({
  label,
  variant,
  tooltip,
  size = 'small',
}: StatusChipProps) => {
  const resolvedVariant =
    variant ?? (label.toLowerCase().replace(/ /g, '_') as StatusVariant);
  const color = statusColor(resolvedVariant);

  const chip = (
    <Chip
      label={label}
      color={color}
      size={size}
      sx={{ fontWeight: 600, letterSpacing: 0 }}
    />
  );

  return tooltip ? (
    <Tooltip title={tooltip} arrow>
      <span>{chip}</span>
    </Tooltip>
  ) : (
    chip
  );
};
