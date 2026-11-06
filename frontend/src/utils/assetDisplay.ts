/** Serial to display; an asset still to complete has none yet. */
export const serialLabel = (serial: string | null | undefined, noSerial: string): string => serial?.trim() || noSerial;

/** «Host / Serial», only with what exists. */
export const assetSubtitle = (hostname: string | null | undefined, serial: string | null | undefined, noSerial: string): string =>
  [hostname?.trim(), serialLabel(serial, noSerial)].filter(Boolean).join(' / ');
