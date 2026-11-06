import { Card, CardContent, Chip, Stack, Typography } from '@mui/material';
import { PrototypeActionBar, type PrototypeAction } from './PrototypeActionBar';

export function PrototypeWorkflowCard({
  title,
  status,
  description,
  actions,
}: {
  title: string;
  status: string;
  description?: string;
  actions: PrototypeAction[];
}) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={1.5}>
          <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
            <Typography variant="h6">{title}</Typography>
            <Chip label={status} size="small" />
          </Stack>
          {description && <Typography color="text.secondary">{description}</Typography>}
          <PrototypeActionBar actions={actions} />
        </Stack>
      </CardContent>
    </Card>
  );
}
