export const GAP_PATTERN = /\[\[\s*a definir\s*:?\s*([^\]]*?)\s*\]\]/gi;

export function extractGaps(content: string): string[] {
  const found = new Set<string>();
  for (const match of content.matchAll(GAP_PATTERN)) {
    const text = match[1]?.trim();
    if (text) found.add(text);
  }
  return [...found];
}

export function countWords(content: string) {
  return content.trim().split(/\s+/).filter(Boolean).length;
}
