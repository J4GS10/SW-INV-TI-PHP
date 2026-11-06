/** Rules for the two ways of setting the logo: an uploaded image or a link. */
export type LogoSource = 'upload' | 'link';

export const LOGO_MAX_BYTES = 2 * 1024 * 1024;
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

const UPLOADED_LOGO_PATH = '/api/v1/gov/config/logo';
const DRIVE_FILE = /^https:\/\/drive\.google\.com\/(?:file\/d\/|open\?id=|uc\?(?:[^#]*&)?id=)([\w-]{10,})/i;

export const isUploadedLogo = (url?: string | null): boolean => (url ?? '').startsWith(UPLOADED_LOGO_PATH);

export const logoSourceOf = (url?: string | null): LogoSource => (url && !isUploadedLogo(url) ? 'link' : 'upload');

/** A Drive sharing link opens a web page, not an image: this turns it into the file's image address. */
export const directLogoUrl = (url: string): string => {
    const clean = url.trim();
    const drive = DRIVE_FILE.exec(clean);
    return drive ? `https://drive.google.com/thumbnail?id=${drive[1]}&sz=w1000` : clean;
};
