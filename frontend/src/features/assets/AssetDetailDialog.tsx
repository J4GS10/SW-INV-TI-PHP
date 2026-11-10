import type { ElementType, ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Paper, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, MenuItem, Stack, Tab, Tabs, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import type { ChipProps } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import CloseIcon from '@mui/icons-material/Close';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import HistoryIcon from '@mui/icons-material/History';
import AppsIcon from '@mui/icons-material/Apps';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import MemoryIcon from '@mui/icons-material/Memory';
import ComputerIcon from '@mui/icons-material/Computer';
import StorageIcon from '@mui/icons-material/Storage';
import ShieldIcon from '@mui/icons-material/ShieldOutlined';
import LanIcon from '@mui/icons-material/LanOutlined';
import SourceIcon from '@mui/icons-material/SourceOutlined';
import DnsIcon from '@mui/icons-material/DnsOutlined';
import ExtensionIcon from '@mui/icons-material/Extension';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import UndoIcon from '@mui/icons-material/Undo';
import BuildIcon from '@mui/icons-material/Build';
import EditIcon from '@mui/icons-material/Edit';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import DeleteForeverIcon from '@mui/icons-material/DeleteForever';
import { useNavigate } from 'react-router-dom';
import { PageHeader, KardexTimeline } from '../../components/ui';
import OpacityIcon from '@mui/icons-material/Opacity';
import PlaceIcon from '@mui/icons-material/Place';
import { ConsumiblesEquipo } from './ConsumiblesEquipo';
import { InstalacionAreaDialog } from '../operations/InstalacionAreaDialog';
import type { EstadoInstalacion } from '../operations/movementService';
import { reglasDe } from '../../utils/clasesActivo';
import { usePermissions } from '../../hooks/usePermissions';
import { inventarioService } from '../../api/inventarioService';
import { aprobacionService } from '../../api/aprobacionService';
import type { KardexEvento } from '../../types/kardex';
import { normalizeLanguage } from '../../types/language';
import { formatDate } from '../../utils/dates';
import { typeLabel } from '../../utils/catalogLabels';
import { BajaDialog } from './BajaDialog';
import { ComponentesSection } from './ComponentesSection';
import { DevolucionDialog } from '../operations/DevolucionDialog';
import { AssignmentDialog } from '../operations/AssignmentDialog';
import { useTranslation } from 'react-i18next';
import { useCatalogLabel, useCatalogo } from '../../hooks/useCatalogo';
import { stateLabel } from '../../utils/catalogLabels';
import { EmptyState } from '../../components/ui';
import { useSnackbar } from 'notistack';
import { AttachmentsSection } from '../attachments/AttachmentsSection';
import { SectionCard } from '../../components/ui';
import { assetSubtitle, serialLabel } from '../../utils/assetDisplay';
import { Activo, EstadoOperativo, Marca, Modelo, TipoActivo } from '../../types/inventory';
import { assetService, InstalacionDetalle, Especificacion, TipoEspecificacion } from './assetService';
import { catalogService } from '../catalogs/catalogService';
import client from '../../api/axios';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import type { Movimiento } from '../../types/traceability';
import { formatMoney } from '../../utils/currency';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
const SIN_DATOS: never[] = [];
interface Props {
    open: boolean;
    asset: Activo | null;
    marcas?: Marca[];
    modelos?: Modelo[];
    tipos?: TipoActivo[];
    estados?: EstadoOperativo[];
    onClose: () => void;
    onChanged?: () => void;
    /** Ficha como página (/activos/:codigo) en vez de diálogo (vista rápida). */
    asPage?: boolean;
}
interface SoftwareItem {
    SOF_Software: number;
    SOF_Nombre: string;
    SOF_Version: string | null;
    SOF_Fabricante: string;
}
interface LicenciaItem {
    LIC_Licencia: number;
    FCO_Forma_Conteo?: number | null;
    LIC_Cantidad_Total: number;
    LIC_Cantidad_Usada: number;
    LIC_Fecha_Vencimiento: string | null;
}
type SpecGroupKey = 'hardware' | 'system' | 'security' | 'network' | 'source' | 'other';
interface SpecSection {
    key: SpecGroupKey;
    title: string;
    subtitle: string;
    icon: ElementType;
}
interface SummaryItem {
    label: string;
    value: ReactNode;
    monospace?: boolean;
}
const emptyGroupedSpecs: Record<SpecGroupKey, Especificacion[]> = {
    hardware: [],
    system: [],
    security: [],
    network: [],
    source: [],
    other: [],
};
const normalizeSpecName = (value: string) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
const getSpecGroup = (name: string): SpecGroupKey => {
    const normalized = normalizeSpecName(name);
    if ([
        'ram', 'almacenamiento', 'procesador', 'tarjeta grafica', 'memoria detalle',
        'discos', 'volumenes', 'generacion cpu', 'nucleos hilos',
    ].includes(normalized))
        return 'hardware';
    if ([
        'sistema operativo', 'version so', 'build so', 'arquitectura',
    ].includes(normalized))
        return 'system';
    if ([
        'tpm', 'aptitud windows 11', 'detalle windows 11', 'agentes seguridad', 'detalle agentes',
    ].includes(normalized))
        return 'security';
    if ([
        'mac principal', 'red', 'teamviewer id',
    ].includes(normalized))
        return 'network';
    if ([
        'fuente inventario', 'fecha inventario', 'usuario detectado', 'perfiles usuario',
    ].includes(normalized))
        return 'source';
    return 'other';
};
const formatSpecValue = (spec: Especificacion) => (`${spec.ESP_Valor}${spec.TES_Unidad_Medida ? ` ${spec.TES_Unidad_Medida}` : ''}`);
const statusChipColor = (name?: string): ChipProps['color'] => {
    const normalized = normalizeSpecName(name ?? '');
    if (normalized === 'asignado')
        return 'info';
    if (normalized === 'en bodega')
        return 'success';
    if (normalized === 'en reparacion')
        return 'secondary';
    if (normalized === 'baja')
        return 'error';
    return 'default';
};
export const AssetDetailDialog = ({ open, asset, marcas: marcasProp = SIN_DATOS, modelos: modelosProp = SIN_DATOS, tipos: tiposProp = SIN_DATOS, estados: estadosProp = SIN_DATOS, onClose, onChanged, asPage, }: Props) => {
    const { t, i18n } = useTranslation();
    const motivoBaja = useCatalogLabel('motivos-baja');
    // Al equipo solo se asignan licencias por equipo, concurrentes o por sitio.
    const { items: formasConteo } = useCatalogo('formas-conteo');
    const codigoForma = (id?: number | null) => formasConteo.find((f) => f.id === id)?.codigo;
    const destinoBaja = useCatalogLabel('destinos-baja');
    const { enqueueSnackbar } = useSnackbar();
    const theme = useTheme();
    const navigate = useNavigate();
    // Contabilidad ve el activo y su valor, sin custodios ni historial; Consulta,
    // el inventario sin costos ni software. Lo que el rol no ve no se pide.
    const { canOperate, canAdminInventory, canSeeCosts, canSeeInventory, canSeePeople, canReadControl } = usePermissions();
    const [kardex, setKardex] = useState<KardexEvento[]>([]);
    // Baja solicitada y pendiente de aprobación: el equipo queda congelado.
    const [bajaPendiente, setBajaPendiente] = useState(false);
    const [dialogo, setDialogo] = useState<'asignar' | 'devolver' | 'baja' | 'instalar' | 'retirar' | null>(null);
    // Equipo compartido: área donde está instalado y su responsable.
    const [instalacion, setInstalacion] = useState<EstadoInstalacion | null>(null);
    // Desinstalar una licencia pide el motivo (queda en el historial de la licencia).
    const [retiroLicencia, setRetiroLicencia] = useState<number | null>(null);
    const [motivoRetiro, setMotivoRetiro] = useState('');
    const [tab, setTab] = useState(0);
    // Abierta desde el escáner o el tablero no recibe catálogos: los carga ella misma.
    const [catalogos, setCatalogos] = useState<{ marcas: Marca[]; modelos: Modelo[]; tipos: TipoActivo[]; estados: EstadoOperativo[] } | null>(null);
    const faltanCatalogos = estadosProp.length === 0;
    useEffect(() => {
        if (!open || !faltanCatalogos || catalogos)
            return;
        let vivo = true;
        Promise.all([assetService.getMarcas(), catalogService.getModelosFlat(), assetService.getTipos(), assetService.getEstados()])
            .then(([ma, mo, ti, es]) => { if (vivo) setCatalogos({ marcas: ma, modelos: mo as Modelo[], tipos: ti, estados: es }); })
            .catch(() => undefined);
        return () => { vivo = false; };
    }, [open, faltanCatalogos, catalogos]);
    const marcas = (marcasProp.length ? marcasProp : catalogos?.marcas ?? []) as Marca[];
    const modelos = (modelosProp.length ? modelosProp : catalogos?.modelos ?? []) as Modelo[];
    const tipos = (tiposProp.length ? tiposProp : catalogos?.tipos ?? []) as TipoActivo[];
    const estados = (estadosProp.length ? estadosProp : catalogos?.estados ?? []) as EstadoOperativo[];
    const [instalaciones, setInstalaciones] = useState<InstalacionDetalle[]>([]);
    const [historial, setHistorial] = useState<Movimiento[]>([]);
    const [loading, setLoading] = useState(false);
    const [adding, setAdding] = useState(false);
    const [softwareList, setSoftwareList] = useState<SoftwareItem[]>([]);
    const [licenciasList, setLicenciasList] = useState<LicenciaItem[]>([]);
    const [softwareSel, setSoftwareSel] = useState<number | ''>('');
    const [licenciaSel, setLicenciaSel] = useState<number | ''>('');
    const [submitting, setSubmitting] = useState(false);
    const [specs, setSpecs] = useState<Especificacion[]>([]);
    const [specTypes, setSpecTypes] = useState<TipoEspecificacion[]>([]);
    const [specTypeSel, setSpecTypeSel] = useState<number | ''>('');
    const [specValor, setSpecValor] = useState('');
    const [savingSpec, setSavingSpec] = useState(false);
    const sectionSurface = alpha(theme.palette.text.primary, theme.palette.mode === 'light' ? 0.035 : 0.06);
    const subtleSurface = alpha(theme.palette.text.primary, theme.palette.mode === 'light' ? 0.025 : 0.045);
    const groupedSpecs = useMemo(() => specs.reduce<Record<SpecGroupKey, Especificacion[]>>((acc, spec) => {
        acc[getSpecGroup(spec.TES_Nombre)].push(spec);
        return acc;
    }, {
        hardware: [],
        system: [],
        security: [],
        network: [],
        source: [],
        other: [],
    }), [specs]);
    const loadSpecs = useCallback(async () => {
        if (!asset)
            return;
        try {
            setSpecs(await assetService.getEspecificaciones(asset.ACT_Activo));
        }
        catch {
            setSpecs([]);
        }
    }, [asset]);
    const handleAddSpec = async () => {
        if (!asset || !specTypeSel || !specValor.trim())
            return;
        setSavingSpec(true);
        try {
            await assetService.addEspecificacion(asset.ACT_Activo, Number(specTypeSel), specValor.trim());
            setSpecTypeSel('');
            setSpecValor('');
            await loadSpecs();
        }
        catch (err: unknown) {
            const d = getApiErrorDetail(err);
            enqueueSnackbar(d === 'SPECIFICATION_ALREADY_EXISTS_FOR_ASSET'
                ? t('assets.specs.dup')
                : getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setSavingSpec(false);
        }
    };
    const handleDeleteSpec = async (espId: number) => {
        try {
            await assetService.deleteEspecificacion(espId);
            await loadSpecs();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    useEffect(() => {
        setInstalacion(null);
        if (open && asset?.ACT_Instalado) {
            client.get<EstadoInstalacion>(`/trazabilidad/instalacion/${asset.ACT_Activo}`).then((r) => setInstalacion(r.data)).catch(() => setInstalacion(null));
        }
    }, [open, asset?.ACT_Activo, asset?.ACT_Instalado]);
    const loadAll = useCallback(async () => {
        if (!asset)
            return;
        setLoading(true);
        try {
            const [ins, hist, kdx, pend] = await Promise.all([
                canSeePeople ? assetService.getInstalaciones(asset.ACT_Activo, true) : Promise.resolve([]),
                canSeeInventory ? assetService.getHistorial(asset.ACT_Activo) : Promise.resolve([]),
                canSeeInventory ? inventarioService.kardex({ activo_id: asset.ACT_Activo }, 300) : Promise.resolve({ items: [] }),
                // Solo los roles que el backend deja leer aprobaciones (TECNICO recibiría 403).
                canReadControl
                    ? aprobacionService.listar({ activo_id: asset.ACT_Activo, estado: 'PENDIENTE' }).catch(() => [])
                    : Promise.resolve([]),
            ]);
            setBajaPendiente(pend.some((a) => a.APR_Tipo === 'BAJA'));
            setInstalaciones(ins);
            setHistorial(hist);
            setKardex(kdx.items);
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [asset, enqueueSnackbar, t, canSeePeople, canSeeInventory, canReadControl]);
    useLiveRefresh(loadAll);
    useEffect(() => {
        if (open && asset) {
            loadAll();
            loadSpecs();
            if (canOperate)
                client.get<SoftwareItem[]>('/soft/software').then((r) => setSoftwareList(r.data)).catch(() => setSoftwareList([]));
            assetService.getTiposEspecificacion().then(setSpecTypes).catch(() => setSpecTypes([]));
            setAdding(false);
            setSoftwareSel('');
            setLicenciaSel('');
            setSpecTypeSel('');
            setSpecValor('');
            setTab(0);
        }
    }, [open, asset, loadAll, loadSpecs, canOperate]);
    useEffect(() => {
        if (!softwareSel) {
            setLicenciasList([]);
            setLicenciaSel('');
            return;
        }
        client.get<LicenciaItem[]>(`/soft/licencias?software_id=${softwareSel}`).then((r) => {
            const usadasIds = new Set(instalaciones.map((i) => i.LIC_Licencia));
            setLicenciasList(r.data.filter((l) => !usadasIds.has(l.LIC_Licencia)
                && codigoForma(l.FCO_Forma_Conteo) !== 'PERSONA'));
        });
        setLicenciaSel('');
        // eslint-disable-next-line react-hooks/exhaustive-deps -- formas del catálogo: no reinician la selección
    }, [softwareSel, instalaciones]);
    const handleInstalar = async () => {
        if (!asset || !licenciaSel)
            return;
        setSubmitting(true);
        try {
            const today = new Date().toISOString().slice(0, 10);
            await assetService.instalarLicencia(asset.ACT_Activo, Number(licenciaSel), today);
            enqueueSnackbar(t('software.installed_ok'), { variant: 'success' });
            setAdding(false);
            setSoftwareSel('');
            setLicenciaSel('');
            await loadAll();
            onChanged?.();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setSubmitting(false);
        }
    };
    const handleDesinstalar = async (licenciaId: number) => {
        if (!asset)
            return;
        setRetiroLicencia(null);
        try {
            await assetService.desinstalarLicencia(asset.ACT_Activo, licenciaId, motivoRetiro ? t(`software.uninstall_reasons.${motivoRetiro}`) : undefined);
            enqueueSnackbar(t('software.uninstalled_ok'), { variant: 'success' });
            await loadAll();
            onChanged?.();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    if (!asset)
        return null;
    // Custodia vigente (movimiento abierto) para el resumen y las acciones.
    const abierto = historial.find((m) => !m.MOV_Fecha_Devolucion);
    const custodia = abierto ? {
        persona: abierto.persona ? `${abierto.persona.PER_Primer_Nombre} ${abierto.persona.PER_Primer_Apellido}` : '—',
        area: abierto.area?.ARE_Nombre ?? '—',
    } : null;
    const modelo = modelos.find((item) => item.MOD_Modelo === asset.MOD_Modelo);
    const marca = modelo ? marcas.find((item) => item.MAR_Marca === modelo.MAR_Marca) : undefined;
    const tipo = tipos.find((item) => item.TAC_Tipo_Activo === asset.TAC_Tipo_Activo);
    // Lo que admite el equipo según su clase (una impresora no lleva software ni se asigna a una persona).
    const reglas = reglasDe(tipo);
    const compartido = reglas.uso === 'COMPARTIDO';
    const instalado = !!asset.ACT_Instalado;
    const estado = estados.find((item) => item.EOP_Estado_Operativo === asset.EOP_Estado_Operativo);
    const visibleGroups = groupedSpecs ?? emptyGroupedSpecs;
    const allSpecSections: SpecSection[] = [
        {
            key: 'hardware',
            title: t('assets.specs.sections.hardware'),
            subtitle: t('assets.specs.sections.hardware_hint'),
            icon: StorageIcon,
        },
        {
            key: 'system',
            title: t('assets.specs.sections.system'),
            subtitle: t('assets.specs.sections.system_hint'),
            icon: ComputerIcon,
        },
        {
            key: 'security',
            title: t('assets.specs.sections.security'),
            subtitle: t('assets.specs.sections.security_hint'),
            icon: ShieldIcon,
        },
        {
            key: 'network',
            title: t('assets.specs.sections.network'),
            subtitle: t('assets.specs.sections.network_hint'),
            icon: LanIcon,
        },
        {
            key: 'source',
            title: t('assets.specs.sections.source'),
            subtitle: t('assets.specs.sections.source_hint'),
            icon: SourceIcon,
        },
        {
            key: 'other',
            title: t('assets.specs.sections.other'),
            subtitle: t('assets.specs.sections.other_hint'),
            icon: DnsIcon,
        },
    ];
    const specSections = allSpecSections.filter((section) => visibleGroups[section.key].length > 0);
    // Ficha como centro de acciones: la acción principal depende del estado.
    const estadoSlug = normalizeSpecName(estado?.EOP_Nombre ?? '');
    const deBaja = estadoSlug === 'baja' || bajaPendiente;
    const esComponente = !!asset.ACT_Activo_Padre;
    const summaryItems: SummaryItem[] = [
        { label: t('assets.dialog.type'), value: tipo ? typeLabel(t, tipo.TAC_Nombre) : asset.TAC_Tipo_Activo },
        { label: t('assets.dialog.brand'), value: marca?.MAR_Nombre ?? '-' },
        { label: t('assets.dialog.model'), value: modelo?.MOD_Nombre ?? asset.MOD_Modelo },
        { label: t('assets.table.status'), value: estado ? stateLabel(t, estado.EOP_Nombre) : asset.EOP_Estado_Operativo },
        { label: t('assets.dialog.purchase_date'), value: formatDate(asset.ACT_Fecha_Compra, i18n.language) },
        { label: t('assets.detail.warranty'), value: asset.ACT_Fin_Garantia ? formatDate(asset.ACT_Fin_Garantia, i18n.language) : '-' },
        ...(canSeeInventory && !compartido ? [{ label: t('assets.detail.custodian'), value: custodia ? `${custodia.persona} · ${custodia.area}` : t('assets.detail.no_custodian') }] : []),
        ...(compartido ? [{ label: t('shared.installed_in'), value: instalado && instalacion
            ? `${instalacion.area ?? '—'}${instalacion.responsable ? ` · ${t('shared.responsible')}: ${instalacion.responsable}` : ''}`
            : t('shared.not_installed') }] : []),
        { label: t('asset_class.field'), value: t(`asset_class.names.${reglas.clase}`) },
        ...(canSeeCosts ? [{ label: t('assets.dialog.cost'), value: formatMoney(asset.ACT_Costo, asset.ACT_Moneda, i18n.language) }] : []),
        { label: t('assets.table.serial'), value: serialLabel(asset.ACT_Serie_Fabricante, t('assets.table.no_serial')), monospace: true },
    ];
    const cerrarDialogo = (changed: boolean) => {
        setDialogo(null);
        if (changed) {
            loadAll();
            onChanged?.();
        }
    };
    const renderSpecSection = (section: SpecSection) => {
        const Icon = section.icon;
        return (<Box key={section.key} sx={{
                border: 1,
                borderColor: 'divider',
                borderRadius: 2,
                bgcolor: sectionSurface,
                overflow: 'hidden',
            }}>
        <Stack direction="row" spacing={1.5} alignItems="flex-start" sx={{ px: 2, py: 1.5, borderBottom: 1, borderColor: 'divider' }}>
          <Box sx={{
                width: 34,
                height: 34,
                borderRadius: 1.5,
                display: 'grid',
                placeItems: 'center',
                bgcolor: alpha(theme.palette.primary.main, 0.12),
                color: 'primary.main',
                flex: '0 0 auto',
            }}>
            <Icon fontSize="small"/>
          </Box>
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
              {section.title}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {section.subtitle}
            </Typography>
          </Box>
          <Chip size="small" variant="outlined" label={t('assets.specs.count', { count: visibleGroups[section.key].length })} sx={{ ml: 'auto' }}/>
        </Stack>
        <Box sx={{
                display: 'grid',
                gridTemplateColumns: { xs: '1fr', md: 'repeat(2, minmax(0, 1fr))' },
                gap: 1,
                p: 1.5,
            }}>
          {visibleGroups[section.key].map((spec) => (<Box key={spec.ESP_Especificacion} sx={{
                    minWidth: 0,
                    p: 1.25,
                    border: 1,
                    borderColor: 'divider',
                    borderRadius: 1.5,
                    bgcolor: subtleSurface,
                    display: 'grid',
                    gridTemplateColumns: 'minmax(0, 1fr) auto',
                    gap: 1,
                    alignItems: 'start',
                }}>
              <Box sx={{ minWidth: 0 }}>
                <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                  {spec.TES_Nombre}
                </Typography>
                <Typography variant="body2" sx={{
                    mt: 0.25,
                    overflowWrap: 'anywhere',
                    wordBreak: 'break-word',
                    lineHeight: 1.45,
                }}>
                  {formatSpecValue(spec)}
                </Typography>
              </Box>
              <Tooltip title={t('common.delete') as string}>
                <IconButton size="small" color="error" onClick={() => handleDeleteSpec(spec.ESP_Especificacion)} aria-label={t('assets.specs.delete_item', { name: spec.TES_Nombre })}>
                  <DeleteIcon fontSize="small"/>
                </IconButton>
              </Tooltip>
            </Box>))}
        </Box>
      </Box>);
    };
    const pestanas = (<>
        {tab === 0 && (<Stack spacing={2}>
            <SectionCard title={t('assets.detail.summary_title')} subtitle={t('assets.detail.summary_hint')} icon={<InfoOutlinedIcon fontSize="small"/>} actions={<Chip size="small" color={statusChipColor(estado?.EOP_Nombre)} variant="outlined" label={estado ? stateLabel(t, estado.EOP_Nombre) : t('common.unknown')}/>}>
              <Box sx={{
                display: 'grid',
                gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', md: 'repeat(4, minmax(0, 1fr))' },
                gap: 1,
            }}>
                {summaryItems.map((item) => (<Box key={item.label} sx={{ minWidth: 0, py: 0.5 }}>
                    <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                      {item.label}
                    </Typography>
                    <Typography variant="body2" sx={{
                    mt: 0.25,
                    fontFamily: item.monospace ? 'monospace' : undefined,
                    overflowWrap: 'anywhere',
                    wordBreak: 'break-word',
                }}>
                      {item.value}
                    </Typography>
                  </Box>))}
              </Box>
            </SectionCard>

            <SectionCard title={t('assets.specs.title')} icon={<MemoryIcon fontSize="small"/>} actions={specs.length > 0 ? (<Chip size="small" variant="outlined" label={t('assets.specs.total_count', { count: specs.length })}/>) : undefined}>

            {specs.length === 0 ? (<EmptyState compact title={t('assets.specs.empty')}/>) : (<Stack spacing={1.5} sx={{ mb: 2 }}>
                {specSections.map(renderSpecSection)}
              </Stack>)}

            <Stack direction="row" spacing={1.5} alignItems="center" sx={{ flexWrap: 'wrap' }}>
              <TextField select size="small" label={t('assets.specs.type')} value={specTypeSel} onChange={(e) => {
                const value = e.target.value;
                setSpecTypeSel(value === '' ? '' : Number(value));
            }} sx={{ minWidth: 220 }}>
                {specTypes.map((tp) => (<MenuItem key={tp.TES_Tipo_Especificacion} value={tp.TES_Tipo_Especificacion}>
                    {tp.TES_Nombre}{tp.TES_Unidad_Medida ? ` (${tp.TES_Unidad_Medida})` : ''}
                  </MenuItem>))}
              </TextField>
              <TextField size="small" label={t('assets.specs.value')} value={specValor} onChange={(e) => setSpecValor(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter')
            handleAddSpec(); }} placeholder={t('assets.specs.value_placeholder')} sx={{ minWidth: 180 }}/>
              <Button variant="contained" startIcon={<AddIcon />} onClick={handleAddSpec} disabled={!specTypeSel || !specValor.trim() || savingSpec}>
                {savingSpec ? <CircularProgress size={18}/> : t('common.add')}
              </Button>
            </Stack>
            </SectionCard>
          </Stack>)}

        {tab === 1 && (<Stack spacing={2}>
            <SectionCard noPadding title={t('software.installed_on_asset')} icon={<AppsIcon fontSize="small"/>} actions={<Button size="small" startIcon={<AddIcon />} variant="outlined" onClick={() => setAdding(true)} disabled={adding}>
                {t('software.install_license')}
              </Button>}>
            {loading ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>) : instalaciones.length === 0 ? (<Box sx={{ p: 2.5 }}><EmptyState compact title={t('software.no_installs')}/></Box>) : (<TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>{t('software.col_software')}</TableCell>
                      <TableCell>{t('software.col_version')}</TableCell>
                      <TableCell>{t('software.col_type')}</TableCell>
                      <TableCell>{t('software.col_install_date')}</TableCell>
                      <TableCell align="right">{t('common.actions')}</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {instalaciones.map((i) => (<TableRow key={i.INS_Instalacion} hover>
                        <TableCell>
                          <Typography fontWeight={500}>{i.licencia?.software?.SOF_Nombre ?? '-'}</Typography>
                          <Typography variant="caption" color="text.secondary">{i.licencia?.software?.SOF_Fabricante}</Typography>
                        </TableCell>
                        <TableCell>{i.licencia?.software?.SOF_Version ?? '-'}</TableCell>
                        <TableCell>
                          {i.licencia?.tipo_licencia?.TLI_Nombre && (<Chip size="small" label={i.licencia.tipo_licencia.TLI_Nombre}/>)}
                        </TableCell>
                        <TableCell>{formatDate(i.INS_Fecha_Instalacion, i18n.language)}</TableCell>
                        <TableCell align="right">
                          <Tooltip title={t('software.uninstall') as string}>
                            <IconButton size="small" color="error" onClick={() => { setMotivoRetiro(''); setRetiroLicencia(i.LIC_Licencia); }}>
                              <DeleteIcon fontSize="small"/>
                            </IconButton>
                          </Tooltip>
                        </TableCell>
                      </TableRow>))}
                  </TableBody>
                </Table>
              </TableContainer>)}
            </SectionCard>
            {adding && (<SectionCard title={t('software.install_license_title')}>
                <Stack direction="row" spacing={2} alignItems="center" useFlexGap flexWrap="wrap">
                  <TextField select size="small" label={t('software.col_software')} value={softwareSel} onChange={(e) => {
                    const value = e.target.value;
                    setSoftwareSel(value === '' ? '' : Number(value));
                }} sx={{ minWidth: 260, flex: '1 1 260px' }}>
                    {softwareList.map((s) => (<MenuItem key={s.SOF_Software} value={s.SOF_Software}>
                        {s.SOF_Nombre} {s.SOF_Version ? `/ v${s.SOF_Version}` : ''}
                      </MenuItem>))}
                  </TextField>
                  <TextField select size="small" label={t('software.col_license')} value={licenciaSel} onChange={(e) => {
                    const value = e.target.value;
                    setLicenciaSel(value === '' ? '' : Number(value));
                }} disabled={!softwareSel || licenciasList.length === 0} helperText={softwareSel && licenciasList.length === 0 ? t('software.no_available_licenses') : ''} sx={{ minWidth: 260, flex: '1 1 260px' }}>
                    {licenciasList.map((l) => (<MenuItem key={l.LIC_Licencia} value={l.LIC_Licencia} disabled={codigoForma(l.FCO_Forma_Conteo) !== 'SITIO' && l.LIC_Cantidad_Usada >= l.LIC_Cantidad_Total}>
                        #{l.LIC_Licencia} / {l.LIC_Cantidad_Usada}/{l.LIC_Cantidad_Total}
                        {l.LIC_Fecha_Vencimiento ? ` / ${t('software.expires_on', { date: l.LIC_Fecha_Vencimiento })}` : ''}
                      </MenuItem>))}
                  </TextField>
                  <Button onClick={() => setAdding(false)} disabled={submitting}>{t('common.cancel')}</Button>
                  <Button variant="contained" onClick={handleInstalar} disabled={!licenciaSel || submitting} sx={{ minWidth: 130 }}>
                    {submitting ? <CircularProgress size={20}/> : t('common.save')}
                  </Button>
                </Stack>
              </SectionCard>)}
          </Stack>)}

        {tab === 2 && (<SectionCard title={t('components.title')} subtitle={t('components.subtitle')} icon={<ExtensionIcon fontSize="small"/>}>
            <ComponentesSection activoId={asset.ACT_Activo} tipoEquipo={asset.TAC_Tipo_Activo} tipos={tipos} canOperate={canOperate && !deBaja} onChanged={() => { loadAll(); onChanged?.(); }}/>
          </SectionCard>)}

        {tab === 3 && (<SectionCard title={t('assets.detail.history_title')} subtitle={t('assets.detail.history_hint')} icon={<HistoryIcon fontSize="small"/>}>
            {loading ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>) : <KardexTimeline eventos={kardex}/>}
          </SectionCard>)}

        {tab === 4 && (<SectionCard title={t('assets.detail.attachments_title')} icon={<AttachFileIcon fontSize="small"/>}>
            <AttachmentsSection activoId={asset.ACT_Activo}/>
          </SectionCard>)}

        {tab === 5 && (<SectionCard title={t('asset_supplies.title')} subtitle={t('asset_supplies.subtitle')} icon={<OpacityIcon fontSize="small"/>}>
            <ConsumiblesEquipo activoId={asset.ACT_Activo}/>
          </SectionCard>)}
    </>);
    const porCompletar = !!asset.pending_completion;
    const accionesYPestanas = (<>
      {(canOperate || deBaja) && (<Stack direction="row" spacing={1} useFlexGap flexWrap="wrap" sx={{ px: 3, pb: 1.5 }}>
          {bajaPendiente && (<Chip color="warning" label={t('assets.detail.decommission_pending')}/>)}
          {deBaja && !bajaPendiente && (<Chip color="error" label={t('assets.detail.decommissioned_info', {
                reason: motivoBaja(asset.ACT_Baja_Motivo),
                destination: destinoBaja(asset.ACT_Baja_Destino),
            })}/>)}
          {canOperate && !porCompletar && compartido && !deBaja && !esComponente && estadoSlug !== 'en reparacion' && (<Button size="small" variant="contained" startIcon={<PlaceIcon />} onClick={() => setDialogo('instalar')}>
              {t(instalado ? 'shared.move' : 'shared.install')}
            </Button>)}
          {canOperate && compartido && instalado && !deBaja && estadoSlug !== 'en reparacion' && (<Button size="small" startIcon={<UndoIcon />} onClick={() => setDialogo('retirar')}>
              {t('shared.remove')}
            </Button>)}
          {canOperate && porCompletar && (<Button size="small" variant="contained" startIcon={<EditIcon />} onClick={() => { onClose(); navigate('/ingresos/por-completar'); }}>
              {t('assets.actions.complete')}
            </Button>)}
          {canOperate && !porCompletar && !compartido && reglas.uso !== 'INSUMO' && !deBaja && !esComponente && !custodia && estadoSlug !== 'en reparacion' && (<Button size="small" variant="contained" startIcon={<AssignmentIndIcon />} onClick={() => setDialogo('asignar')}>
              {t('assets.actions.assign')}
            </Button>)}
          {canOperate && custodia && (<Button size="small" variant="contained" startIcon={<UndoIcon />} onClick={() => setDialogo('devolver')}>
              {t('assets.actions.return')}
            </Button>)}
          {canOperate && custodia && (<Button size="small" startIcon={<AssignmentIndIcon />} onClick={() => setDialogo('asignar')}>
              {t('assets.actions.transfer')}
            </Button>)}
          {canOperate && !porCompletar && !deBaja && estadoSlug !== 'en reparacion' && (<Button size="small" startIcon={<BuildIcon />} onClick={() => { onClose(); navigate(`/mantenimiento?nuevo=${asset.ACT_Activo}`); }}>
              {t('assets.actions.maintenance')}
            </Button>)}
          {canSeeInventory && <Button size="small" startIcon={<PictureAsPdfIcon />} onClick={() => inventarioService.hojaDeVida(asset.ACT_Activo, normalizeLanguage(i18n.language)).catch((err) => enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' }))}>
            {t('assets.actions.history_pdf')}
          </Button>}
          {canAdminInventory && !deBaja && !custodia && !instalado && (<Button size="small" color="error" startIcon={<DeleteForeverIcon />} onClick={() => setDialogo('baja')}>
              {t('assets.actions.decommission')}
            </Button>)}
          {canAdminInventory && !deBaja && !!custodia && !bajaPendiente && (<Button size="small" color="error" startIcon={<DeleteForeverIcon />} onClick={() => setDialogo('baja')}>
              {t('row_actions.report_lost')}
            </Button>)}
        </Stack>)}

      <Tabs value={tab} onChange={(_, v: number) => setTab(v)} variant="scrollable" allowScrollButtonsMobile sx={{ borderBottom: 1, borderColor: 'divider' }}>
        <Tab value={0} icon={<InfoOutlinedIcon />} iconPosition="start" label={t('assets.detail.tab_info')}/>
        {canSeePeople && reglas.software && <Tab value={1} icon={<AppsIcon />} iconPosition="start" label={t('software.installed_on_asset')}/>}
        {(reglas.componentes || reglas.uso === 'COMPONENTE') && <Tab value={2} icon={<ExtensionIcon />} iconPosition="start" label={t('components.tab')}/>}
        {canSeeInventory && <Tab value={3} icon={<HistoryIcon />} iconPosition="start" label={t('assets.detail.tab_history')}/>}
        {canSeeInventory && <Tab value={4} icon={<AttachFileIcon />} iconPosition="start" label={t('assets.detail.tab_attachments')}/>}
        {reglas.consumibles && canSeeInventory && <Tab value={5} icon={<OpacityIcon />} iconPosition="start" label={t('asset_supplies.tab')}/>}
      </Tabs>

    </>);
    const subdialogos = (<>
      <AssignmentDialog open={dialogo === 'asignar'} activoInicial={asset} onClose={(refresh) => cerrarDialogo(!!refresh)}/>
      <DevolucionDialog open={dialogo === 'devolver'} activo={asset} custodio={custodia?.persona} onClose={cerrarDialogo}/>
      <BajaDialog open={dialogo === 'baja'} activo={asset} lostOnly={!!custodia} onClose={cerrarDialogo}/>
      <InstalacionAreaDialog modo={dialogo === 'instalar' || dialogo === 'retirar' ? dialogo : null} activo={asset} onClose={cerrarDialogo}/>
      <Dialog open={retiroLicencia != null} onClose={() => setRetiroLicencia(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('software.uninstall')}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('software.confirm_uninstall')}</Typography>
          <TextField select fullWidth label={t('software.uninstall_reason')} value={motivoRetiro} onChange={(e) => setMotivoRetiro(e.target.value)}>
            {['liberar', 'vencida', 'no_requerida', 'reinstalacion', 'cambio_equipo'].map((m) => (<MenuItem key={m} value={m}>{t(`software.uninstall_reasons.${m}`)}</MenuItem>))}
          </TextField>
        </DialogContent>
        <DialogActions>
          <Button color="inherit" onClick={() => setRetiroLicencia(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" color="error" disabled={!motivoRetiro} onClick={() => retiroLicencia != null && handleDesinstalar(retiroLicencia)}>{t('software.uninstall')}</Button>
        </DialogActions>
      </Dialog>
    </>);
    if (asPage) {
        return (<Box>
      <PageHeader title={asset.ACT_Codigo_Interno} subtitle={assetSubtitle(asset.ACT_Hostname, asset.ACT_Serie_Fabricante, t('assets.table.no_serial'))}
        crumb={asset.ACT_Codigo_Interno}
        actions={<Button variant="outlined" onClick={onClose}>{t('asset_page.back_to_list')}</Button>}/>
      <Paper variant="outlined" sx={{ pt: 1.5 }}>
        {accionesYPestanas}
        <Box sx={{ p: { xs: 1.5, md: 2.5 } }}>{pestanas}</Box>
      </Paper>
      {subdialogos}
    </Box>);
    }
    return (<Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 2 }}>
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="h6" sx={{ overflowWrap: 'anywhere' }}>{asset.ACT_Codigo_Interno}</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
            {assetSubtitle(asset.ACT_Hostname, asset.ACT_Serie_Fabricante, t('assets.table.no_serial'))}
          </Typography>
        </Box>
        <IconButton onClick={onClose} aria-label={t('common.close')}>
          <CloseIcon />
        </IconButton>
      </DialogTitle>

      {accionesYPestanas}
      <DialogContent dividers>
        {pestanas}
      </DialogContent>
      <DialogActions>
        {canOperate && (<Button onClick={() => { onClose(); navigate(`/activos/${encodeURIComponent(asset.ACT_Codigo_Interno)}`); }}>
            {t('asset_page.open_as_page')}
          </Button>)}
        <Button onClick={onClose}>{t('common.close')}</Button>
      </DialogActions>
      {subdialogos}
    </Dialog>);
};
