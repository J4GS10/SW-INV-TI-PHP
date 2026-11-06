import { useState } from 'react';
import type { ReactNode } from 'react';
import { Box, Button, Collapse, Paper, Stack, useMediaQuery, useTheme } from '@mui/material';
import FilterListIcon from '@mui/icons-material/FilterList';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { useTranslation } from 'react-i18next';

interface CollapsibleToolbarProps {
    title?: string;
    summary?: ReactNode;
    /** Búsqueda: siempre visible (también en móvil), a la izquierda de los filtros. */
    search?: ReactNode;
    children?: ReactNode;
    defaultOpen?: boolean;
    storageKey?: string;
}

/**
 * Barra de filtros estándar.
 * - Escritorio: filtros siempre visibles, en una sola franja (sin clic extra).
 * - Móvil/tablet: plegada tras un botón "Filtros" para no ocupar la pantalla.
 * `summary` (conteos) solo se muestra junto al botón en móvil; en escritorio
 * el conteo ya está en el encabezado y en la paginación.
 */
export const CollapsibleToolbar = ({ title, summary, search, children, defaultOpen = false, storageKey }: CollapsibleToolbarProps) => {
    const { t } = useTranslation();
    const theme = useTheme();
    const isDesktop = useMediaQuery(theme.breakpoints.up('md'));
    const [open, setOpen] = useState(defaultOpen);
    const contentId = `${storageKey ?? 'module'}-filters`;

    if (isDesktop) {
        return (<Paper variant="outlined" sx={{ mb: 2, px: 2, py: 1.5 }} aria-label={title ?? t('common.filters')} role="search">
        <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5 }}>
          {search && <Box sx={{ flex: '1 1 260px', maxWidth: 420 }}>{search}</Box>}
          {children && <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>{children}</Box>}
        </Box>
      </Paper>);
    }
    return (<Paper variant="outlined" sx={{ mb: 2 }}>
      {search && <Box sx={{ px: 1.5, pt: 1.5 }}>{search}</Box>}
      {children && (<Stack direction="row" alignItems="center" spacing={1} useFlexGap flexWrap="wrap" sx={{ px: 1.5, py: 1 }}>
        <Button size="small" startIcon={<FilterListIcon />} endIcon={open ? <ExpandLessIcon /> : <ExpandMoreIcon />} onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-controls={contentId}>
          {title ?? t('common.filters')}
        </Button>
        {summary}
      </Stack>)}
      {children && (<Collapse in={open} timeout="auto" unmountOnExit>
        <Box id={contentId} sx={{ px: 1.5, pb: 1.5 }}>
          {children}
        </Box>
      </Collapse>)}
      {!children && <Box sx={{ pb: 1.5 }}/>}
    </Paper>);
};
