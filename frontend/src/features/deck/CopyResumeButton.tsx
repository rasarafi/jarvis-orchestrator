import { useState } from 'react'
import { Copy, Check } from 'lucide-react'
import { copyText } from '@/lib/clipboard'

/** One-gesture context switch: copies `claude --resume <id>` for pasting into a terminal. */
export function CopyResumeButton({ sessionId, className = '' }: { sessionId: string; className?: string }) {
  const [copied, setCopied] = useState(false)

  return (
    <button
      onClick={async (e) => {
        e.stopPropagation()
        if (await copyText(`claude --resume ${sessionId}`)) {
          setCopied(true)
          setTimeout(() => setCopied(false), 1500)
        }
      }}
      onKeyDown={(e) => e.stopPropagation()}
      aria-label="Copy claude --resume command"
      title={`Copy: claude --resume ${sessionId}`}
      className={`inline-flex items-center gap-1 rounded-md border border-line px-1.5 py-0.5 font-mono2 text-[10.5px] text-mut transition-colors hover:border-arc/40 hover:text-arc ${className}`}
    >
      {copied ? <Check className="h-3 w-3 text-arc" /> : <Copy className="h-3 w-3" />}
      {copied ? 'copied' : 'resume'}
    </button>
  )
}
