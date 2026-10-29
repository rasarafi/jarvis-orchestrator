/**
 * Session transcripts leak raw output into `last_output_line` — fenced
 * ```json blocks, `{"summary": …}` wrappers, markdown emphasis. The board
 * shows one clean sentence; the full text stays in the Sessions view.
 */
export function cleanSessionSummary(raw: string | null | undefined): string | null {
  if (!raw) return null
  let s = raw.trim()

  s = s.replace(/^```[a-z]*\s*/i, '').replace(/```\s*$/, '').trim()

  // JSON payloads ({"summary": …}, {"headline": …}, …) possibly truncated
  // mid-string: surface the first string value.
  const jsonMatch = s.match(/^\{\s*"\w+"\s*:\s*"((?:[^"\\]|\\.)*)"?/)
  if (jsonMatch) {
    s = jsonMatch[1].replace(/\\"/g, '"').replace(/\\n/g, ' ')
  }

  s = s
    .replace(/^#{1,6}\s+/gm, '') // headings
    .replace(/\*\*([^*]+)\*\*/g, '$1') // bold
    .replace(/\*([^*]+)\*/g, '$1') // italics
    .replace(/`([^`]+)`/g, '$1') // inline code
    .replace(/\s+/g, ' ')
    .trim()

  return s.length > 0 ? s : null
}
