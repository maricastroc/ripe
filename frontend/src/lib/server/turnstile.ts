export function turnstileSiteKey(): string | null {
  const key = process.env.RIPE_TURNSTILE_SITE_KEY;
  return key ? key : null;
}
