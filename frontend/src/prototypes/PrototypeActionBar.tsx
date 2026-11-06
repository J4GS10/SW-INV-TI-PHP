import { Button, Stack } from '@mui/material';

export type PrototypeAction = {
  id: string;
  label: string;
  disabled?: boolean;
  onClick: () => void;
};

/** Barra reutilizable: las acciones llegan declaradas por estado/permisos. */
export function PrototypeActionBar({ actions }: { actions: PrototypeAction[] }) {
  return (
    <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
      {actions.map((action) => (
        <Button key={action.id} variant="outlined" disabled={action.disabled} onClick={action.onClick}>
          {action.label}
        </Button>
      ))}
    </Stack>
  );
}
