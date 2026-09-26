export function cleanLegacyText(value: string) {
  if (!value) return '';

  return value
    .replace(/\[embedyt\][\s\S]*?\[\/embedyt\]/gi, ' ')
    .replace(/\[caption[^\]]*\]/gi, ' ')
    .replace(/\[\/caption\]/gi, ' ')
    .replace(/\[(?:\/)?[a-z][^\]]*\]/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#0*39;|&apos;/gi, "'")
    .replace(/\s+/g, ' ')
    .trim();
}
