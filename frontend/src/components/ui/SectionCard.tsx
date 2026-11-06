import { Box, Card, CardContent, Divider, Stack, Typography } from '@mui/material';
import type { ReactNode } from 'react';

interface SectionCardProps {
  title: string;
  subtitle?: string;
  icon?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  noPadding?: boolean;
}

/**
 * Tarjeta de sección para formularios y bloques de detalle.
 * Agrupa campos relacionados bajo un encabezado claro con divider.
 */
export const SectionCard = ({
  title,
  subtitle,
  icon,
  actions,
  children,
  noPadding = false,
}: SectionCardProps) => (
  <Card>
    <CardContent sx={{ p: 0 }}>
      <Box
        sx={{
          px: 2.5,
          py: 1.75,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 1,
        }}
      >
        <Stack direction="row" alignItems="center" spacing={1}>
          {icon && (
            <Box sx={{ color: 'text.secondary', display: 'flex' }}>{icon}</Box>
          )}
          <Box>
            <Typography variant="subtitle2" fontWeight={700} lineHeight={1.3}>
              {title}
            </Typography>
            {subtitle && (
              <Typography variant="caption" color="text.secondary">
                {subtitle}
              </Typography>
            )}
          </Box>
        </Stack>
        {actions && <Box>{actions}</Box>}
      </Box>

      <Divider />

      <Box sx={noPadding ? {} : { p: 2.5 }}>{children}</Box>
    </CardContent>
  </Card>
);
