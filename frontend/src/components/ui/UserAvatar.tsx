import { useEffect, useState } from 'react';
import { Avatar } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';

import { profilePhotoService } from '../../features/profile/profilePhotoService';

interface UserAvatarProps {
  name: string;
  /** API address of the photo (`foto_url`); without one, the initial is shown. */
  photoUrl?: string | null;
  size?: number;
}

/** Profile photo, or the person's initial while it loads or when there is none. */
export const UserAvatar = ({ name, photoUrl, size = 32 }: UserAvatarProps) => {
  const theme = useTheme();
  const [loaded, setLoaded] = useState<{ url: string; src: string } | null>(null);

  useEffect(() => {
    if (!photoUrl) return;
    let alive = true;
    profilePhotoService.objectUrl(photoUrl)
      .then((src) => { if (alive) setLoaded({ url: photoUrl, src }); })
      .catch(() => undefined);
    return () => { alive = false; };
  }, [photoUrl]);

  const src = photoUrl && loaded?.url === photoUrl ? loaded.src : undefined;
  return (
    <Avatar
      src={src}
      alt={name}
      sx={{
        width: size, height: size, fontSize: size * 0.42, fontWeight: 700,
        bgcolor: alpha(theme.palette.primary.main, 0.15), color: 'primary.main',
      }}
    >
      {name.trim().charAt(0).toUpperCase() || '?'}
    </Avatar>
  );
};
