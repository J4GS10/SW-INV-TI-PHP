import { Box, Typography, Button } from '@mui/material';
import type { SvgIconComponent } from '@mui/icons-material';
import InboxIcon from '@mui/icons-material/Inbox';
import { alpha, useTheme } from '@mui/material/styles';

interface EmptyStateProps {
  title: string;
  description?: string;
  icon?: SvgIconComponent;
  action?: {
    label: string;
    onClick: () => void;
  };
  compact?: boolean;
}

/**
 * Estado vacío estándar para tablas y secciones sin datos.
 * Muestra un ícono, mensaje principal, descripción opcional y acción CTA.
 */
export const EmptyState = ({
  title,
  description,
  icon: Icon = InboxIcon,
  action,
  compact = false,
}: EmptyStateProps) => {
  const theme = useTheme();
  const iconColor = alpha(theme.palette.primary.main, 0.35);

  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        textAlign: 'center',
        py: compact ? 4 : 8,
        px: 3,
        gap: 1.5,
      }}
    >
      <Box
        sx={{
          width: compact ? 48 : 64,
          height: compact ? 48 : 64,
          borderRadius: '50%',
          bgcolor: alpha(theme.palette.primary.main, 0.08),
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          mb: 0.5,
        }}
      >
        <Icon sx={{ fontSize: compact ? 28 : 36, color: iconColor }} />
      </Box>

      <Typography
        variant={compact ? 'body1' : 'h6'}
        fontWeight={600}
        color="text.primary"
      >
        {title}
      </Typography>

      {description && (
        <Typography
          variant="body2"
          color="text.secondary"
          maxWidth={360}
        >
          {description}
        </Typography>
      )}

      {action && (
        <Button
          variant="contained"
          size={compact ? 'small' : 'medium'}
          onClick={action.onClick}
          sx={{ mt: 1 }}
        >
          {action.label}
        </Button>
      )}
    </Box>
  );
};
