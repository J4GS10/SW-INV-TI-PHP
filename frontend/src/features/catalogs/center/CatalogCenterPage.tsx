import { lazy, Suspense, useEffect, useMemo, useState, type ComponentType } from 'react';
import {
  Box, CircularProgress, List, ListItemButton, ListItemText, MenuItem, Paper, Tab, Tabs, TextField, useMediaQuery, useTheme,
} from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { catalogoService, type CatalogoDef, type CatalogoGrupo } from '../../../api/catalogoService';
import { PageHeader, SectionCard } from '../../../components/ui';
import { EmbeddedPageContext } from '../../../components/ui/embeddedPage';
import { LicenseTypesDialog } from '../../software/LicenseTypesDialog';
import { CatalogItemsPanel } from './CatalogItemsPanel';
import { SpecsByTypePanel } from './SpecsByTypePanel';
import { KitsPanel } from './KitsPanel';
import { useLiveTick } from '../../../hooks/useLiveRefresh';

const ModelsPage = lazy(() => import('../models/ModelsPage').then((m) => ({ default: m.ModelsPage })));
const AssetTypesPage = lazy(() => import('../types/AssetTypesPage').then((m) => ({ default: m.AssetTypesPage })));
const LocationsPage = lazy(() => import('../../locations/LocationsPage').then((m) => ({ default: m.LocationsPage })));

const GROUPS: CatalogoGrupo[] = ['ACTIVOS', 'MOVIMIENTOS', 'LICENCIAS', 'CONSUMIBLES', 'MANTENIMIENTO', 'UBICACIONES', 'DOCUMENTOS'];

/** Entries that are not standard catalogs: they keep their own screen, shown inside this one. */
const SPECIAL: Record<string, CatalogoGrupo> = {
  'tipos-activo': 'ACTIVOS', 'especificaciones-tipo': 'ACTIVOS', modelos: 'ACTIVOS', kits: 'MOVIMIENTOS', ubicaciones: 'UBICACIONES',
};
const EMBEDDED: Record<string, ComponentType> = { modelos: ModelsPage, 'tipos-activo': AssetTypesPage, ubicaciones: LocationsPage };

/** Opens as a dialog over the current list. */
const LICENSE_TYPES = 'tipos-licencia';

/** Assets read in the order they are set up: what it is, what it asks for, who makes it. */
const ASSETS_ORDER = ['tipos-activo', 'especificaciones-tipo', 'tipos-especificacion', 'unidades-medida', 'marcas', 'modelos', 'tipos-conexion'];
const position = (key: string) => {
  const index = ASSETS_ORDER.indexOf(key);
  return index === -1 ? ASSETS_ORDER.length : index;
};

/**
 * Centro de catálogos, por áreas: primero se elige el área (activos, licencias…)
 * y solo se ven sus listas, siempre dentro de esta pantalla. `?c=` abre una lista.
 */
export const CatalogCenterPage = () => {
  const { t } = useTranslation();
  const theme = useTheme();
  const compact = useMediaQuery(theme.breakpoints.down('md'));
  const [defs, setDefs] = useState<CatalogoDef[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [licenseTypes, setLicenseTypes] = useState(false);
  const [params, setParams] = useSearchParams();

  const liveTick = useLiveTick();
  useEffect(() => {
    catalogoService.definiciones().then(setDefs).catch(() => setDefs([])).finally(() => setLoaded(true));
  }, [liveTick]);

  const groups = useMemo(() => GROUPS.map((group) => ({
    group,
    keys: [
      ...defs.filter((d) => d.grupo === group).map((d) => d.clave as string),
      ...Object.entries(SPECIAL).filter(([, g]) => g === group).map(([k]) => k),
    ].sort((a, b) => position(a) - position(b)),
  })).filter((g) => g.keys.length > 0), [defs]);

  const requested = params.get('c');
  const current = groups.find((g) => g.group === params.get('g'))
    ?? groups.find((g) => requested !== null && g.keys.includes(requested))
    ?? groups[0];
  const selected = current && requested !== null && current.keys.includes(requested) ? requested : current?.keys[0];
  const def = defs.find((d) => d.clave === selected);
  const Embedded = selected ? EMBEDDED[selected] : undefined;

  // Each choice is a history entry, so the browser's back button returns to the previous list.
  const pickGroup = (group: CatalogoGrupo) => setParams({ g: group });
  const pickCatalog = (key: string) => {
    if (key === LICENSE_TYPES) setLicenseTypes(true);
    else setParams({ g: current?.group ?? '', c: key });
  };

  const entries = current ? [...current.keys, ...(current.group === 'LICENCIAS' ? [LICENSE_TYPES] : [])] : [];
  const label = (key: string) => t(`catalog_center.catalogs.${key}`);

  return (
    <Box>
      <PageHeader help="catalogs" title={t('catalog_center.title')} subtitle={t('catalog_center.subtitle')}
        crumb={current && selected ? `${t(`catalog_center.groups.${current.group}`)} · ${label(selected)}` : undefined} />
      {loaded && current && (
        <>
          <Tabs value={current.group} onChange={(_, group: CatalogoGrupo) => pickGroup(group)} variant="scrollable"
            allowScrollButtonsMobile aria-label={t('catalog_center.area')} sx={{ mb: 2, borderBottom: 1, borderColor: 'divider' }}>
            {groups.map((g) => <Tab key={g.group} value={g.group} label={t(`catalog_center.groups.${g.group}`)} />)}
          </Tabs>
          <Box sx={{ display: 'flex', gap: 2, flexDirection: compact ? 'column' : 'row', alignItems: 'flex-start' }}>
            {entries.length > 1 && compact && (
              <TextField select fullWidth label={t('catalog_center.catalog')} value={selected ?? ''} onChange={(e) => pickCatalog(e.target.value)}>
                {entries.map((key) => <MenuItem key={key} value={key}>{label(key)}</MenuItem>)}
              </TextField>
            )}
            {entries.length > 1 && !compact && (
              <Paper variant="outlined" sx={{ width: 260, flexShrink: 0, position: 'sticky', top: 16 }}>
                <List component="nav" dense disablePadding sx={{ py: 0.5 }} aria-label={t('catalog_center.catalog')}>
                  {entries.map((key) => (
                    <ListItemButton key={key} selected={key === selected} onClick={() => pickCatalog(key)}>
                      <ListItemText primary={label(key)} />
                    </ListItemButton>
                  ))}
                </List>
              </Paper>
            )}
            {selected && (
              <Box sx={{ flex: 1, minWidth: 0, width: '100%' }}>
                <SectionCard title={label(selected)} subtitle={t(`catalog_center.hints.${selected}`, { defaultValue: '' }) || undefined}>
                  {selected === 'especificaciones-tipo' && <SpecsByTypePanel />}
                  {selected === 'kits' && <KitsPanel />}
                  {def && <CatalogItemsPanel key={def.clave} def={def} />}
                  {Embedded && (
                    <EmbeddedPageContext.Provider value>
                      <Suspense fallback={<CircularProgress size={24} />}><Embedded /></Suspense>
                    </EmbeddedPageContext.Provider>
                  )}
                </SectionCard>
              </Box>
            )}
          </Box>
        </>
      )}
      <LicenseTypesDialog open={licenseTypes} onClose={() => setLicenseTypes(false)} />
    </Box>
  );
};
