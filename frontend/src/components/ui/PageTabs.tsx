import { Box, Chip, Tab, Tabs } from '@mui/material';

export interface PageTab<V extends string> {
  value: V;
  label: string;
  /** Cuántos elementos hay en la pestaña (se muestra junto al nombre). */
  count?: number;
  /** Resalta el contador cuando la pestaña pide atención. */
  alert?: boolean;
}

/** Pestañas de una página: cada bloque largo en su propia vista, sin bajar por toda la página. */
export const PageTabs = <V extends string>({ tabs, value, onChange }: {
  tabs: PageTab<V>[];
  value: V;
  onChange: (view: V) => void;
}) => (
  <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
    <Tabs value={value} onChange={(_, v: V) => onChange(v)} variant="scrollable" allowScrollButtonsMobile>
      {tabs.map((tab) => (
        <Tab
          key={tab.value}
          value={tab.value}
          sx={{ minHeight: 48, textTransform: 'none', fontWeight: 600 }}
          label={(
            <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 1 }}>
              {tab.label}
              {tab.count !== undefined && (
                <Chip
                  size="small"
                  label={tab.count}
                  color={tab.alert ? 'warning' : 'default'}
                  sx={{ height: 20, minWidth: 28, fontWeight: 600, pointerEvents: 'none' }}
                />
              )}
            </Box>
          )}
        />
      ))}
    </Tabs>
  </Box>
);
