import client from '../../api/axios';

/** Same limit as the API (ProfilePhoto::MAX_UPLOAD_BYTES): checked here first, so nothing large travels. */
export const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
export const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';

// The address carries the photo version, so each one is downloaded once per tab.
const downloaded = new Map<string, Promise<string>>();

export const profilePhotoService = {
  async upload(file: File): Promise<string | null> {
    const form = new FormData();
    form.append('file', file);
    return (await client.put<{ foto_url: string | null }>('/me/foto', form)).data.foto_url;
  },

  async remove(): Promise<void> {
    await client.delete('/me/foto');
  },

  /** Local address of the image (the API asks for the session token, so <img> cannot fetch it directly). */
  objectUrl(url: string): Promise<string> {
    let image = downloaded.get(url);
    if (!image) {
      image = client.get<Blob>(url, { baseURL: '', responseType: 'blob' }).then((r) => URL.createObjectURL(r.data));
      image.catch(() => downloaded.delete(url));
      downloaded.set(url, image);
    }
    return image;
  },
};
