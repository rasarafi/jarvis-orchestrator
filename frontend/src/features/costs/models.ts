/**
 * Model mix, drawn the same way at every level of the page (window, project,
 * session) so one shape means one thing throughout.
 *
 * Colour is the ordinal PRICE tier, not an arbitrary per-model palette: the
 * question a model column answers is "how expensive was this work", and the
 * `--pri-*` ramp is the app's validated single-hue ordinal scale (see the
 * data-viz block in index.css). Anything unrecognised falls back to a neutral
 * rather than borrowing a tier it hasn't earned.
 */
const TIER: { match: RegExp; token: string }[] = [
  { match: /fable/, token: 'urgent' },
  { match: /opus/, token: 'high' },
  { match: /sonnet/, token: 'medium' },
  { match: /haiku/, token: 'low' },
]

export function modelColor(model: string): string {
  const tier = TIER.find((t) => t.match.test(model))
  return tier ? `var(--pri-${tier.token})` : 'var(--dim)'
}
