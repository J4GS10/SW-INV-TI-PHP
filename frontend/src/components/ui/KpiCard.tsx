import { Box, Card, CardContent, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import type { IconComponent } from '../../types/ui';

interface KpiCardProps {
  title: string;
  value: string | number;
  icon: IconComponent;
  tone?: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
  subtitle?: string;
}

/**
 * Tarjeta KPI compacta. Los tonos salen de la paleta del tema (no colores
 * fijos), así se adaptan a cualquier fondo configurado, claro u oscuro.
 */
export const KpiCard = ({ title, value, icon: Icon, tone = 'primary', subtitle }: KpiCardProps) => {
  const theme = useTheme();
  const toneColor = theme.palette[tone].main;
  return (
    <Card sx={{ height: '100%' }}>
      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Box sx={{
            width: 40, height: 40, flexShrink: 0, borderRadius: 1.5,
            // En teléfonos el icono se oculta: el título completo cabe en la tarjeta.
            display: { xs: 'none', sm: 'grid' }, placeItems: 'center',
            bgcolor: alpha(toneColor, 0.12), color: toneColor,
          }}>
            <Icon fontSize="small" />
          </Box>
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="body2" color="text.secondary" sx={{ lineHeight: 1.3 }}>{title}</Typography>
            <Typography variant="h5" component="p" sx={{ lineHeight: 1.25 }}>{value}</Typography>
          </Box>
        </Box>
        {subtitle && (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            {subtitle}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
};
