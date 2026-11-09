/** Rules for the two ways of setting the logo: an uploaded image or a link. */
export type LogoSource = 'upload' | 'link';

export const LOGO_MAX_BYTES = 2 * 1024 * 1024;
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

const UPLOADED_LOGO_PATH = '/api/v1/gov/config/logo';
const DRIVE_FILE = /^https:\/\/drive\.google\.com\/(?:file\/d\/|open\?id=|uc\?(?:[^#]*&)?id=)([\w-]{10,})/i;

export const LOGO_SIZE_MIN = 50;
export const LOGO_SIZE_MAX = 250;
/** Logo height at 100 % in the side menu and on the sign-in page. */
export const LOGO_BASE_HEIGHT = { menu: 48, login: 80 } as const;

/** Saved size as a factor (1 = 100 %); the API may return it as text. */
export const logoScale = (size?: number | string | null): number => {
    const percent = Number(size ?? 100);
    return (Number.isFinite(percent) ? Math.min(LOGO_SIZE_MAX, Math.max(LOGO_SIZE_MIN, percent)) : 100) / 100;
};

export const isUploadedLogo =(url?: string | null): boolean => (url ?? '').startsWith(UPLOADED_LOGO_PATH);

export const logoSourceOf = (url?: string | null): LogoSource => (url && !isUploadedLogo(url) ? 'link' : 'upload');

/** A Drive sharing link opens a web page, not an image: this turns it into the file's image address. */
export const directLogoUrl = (url: string): string => {
    const clean = url.trim();
    const drive = DRIVE_FILE.exec(clean);
    return drive ? `https://drive.google.com/thumbnail?id=${drive[1]}&sz=w1000` : clean;
};
