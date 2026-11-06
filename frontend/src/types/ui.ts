import type { ChipProps, SvgIconProps } from '@mui/material';
import type { ComponentType, ReactNode } from 'react';

export type ChipColor = NonNullable<ChipProps['color']>;
export type IconComponent = ComponentType<SvgIconProps>;

export interface PanelProps {
  title: string;
  children: ReactNode;
}
