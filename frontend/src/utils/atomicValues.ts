/** A comma between digits is a decimal («2,5»); any other comma, «;», «|» or a line break separates values. */
const SEPARATOR = /[;|\r\n]+|(?<!\d),|,(?!\d)/;

/** «SSD, NVMe; HDD» → three values. Each one is stored and shown on its own. */
export const splitAtomicValues = (text: string): string[] =>
  text.split(SEPARATOR).map((part) => part.trim()).filter((part) => part !== '');

/** Splits every entry and drops repeats, ignoring case; the first spelling wins. */
export const mergeAtomicValues = (entries: string[]): string[] => {
  const seen = new Set<string>();
  return entries.flatMap(splitAtomicValues).filter((value) => {
    const key = value.toLowerCase();
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
};
