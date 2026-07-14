export const normalizeSearchText = (value = '') => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, ' ')
  .trim();

export const tokenizeSearchText = (value = '') => [
  ...new Set(normalizeSearchText(value).split(/\s+/).filter((token) => token.length > 1)),
];
