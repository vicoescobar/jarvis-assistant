/**
 * Safe, zero-dependency Markdown renderer and TTS plain-text sanitizer.
 */

function escapeHtml(raw) {
  return String(raw || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function formatInline(escapedLine) {
  return escapedLine
    .replace(/`([^`]+)`/g, '<code class="md-inline-code">$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/__([^_]+)__/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
    .replace(/_([^_]+)_/g, '<em>$1</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="underline">$1</a>')
}

export function renderMarkdown(markdownText) {
  if (!markdownText) return ''
  const lines = String(markdownText).replace(/\r\n/g, '\n').split('\n')
  const html = []
  let inList = false
  let inCodeBlock = false
  let codeLines = []

  const closeList = () => {
    if (inList) {
      html.push('</ul>')
      inList = false
    }
  }

  for (const rawLine of lines) {
    if (rawLine.trim().startsWith('```')) {
      closeList()
      if (!inCodeBlock) {
        inCodeBlock = true
        codeLines = []
      } else {
        inCodeBlock = false
        html.push(
          `<pre class="md-code-block"><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`
        )
      }
      continue
    }

    if (inCodeBlock) {
      codeLines.push(rawLine)
      continue
    }

    const trimmed = rawLine.trim()
    if (!trimmed) {
      closeList()
      continue
    }

    const headingMatch = trimmed.match(/^(#{1,3})\s+(.+)$/)
    if (headingMatch) {
      closeList()
      const level = headingMatch[1].length
      const content = formatInline(escapeHtml(headingMatch[2]))
      html.push(`<h${level} class="md-heading-${level}">${content}</h${level}>`)
      continue
    }

    const listMatch = trimmed.match(/^[-*•]\s+(.+)$/)
    if (listMatch) {
      if (!inList) {
        html.push('<ul class="md-list">')
        inList = true
      }
      html.push(`<li>${formatInline(escapeHtml(listMatch[1]))}</li>`)
      continue
    }

    const numListMatch = trimmed.match(/^\d+\.\s+(.+)$/)
    if (numListMatch) {
      if (!inList) {
        html.push('<ul class="md-list">')
        inList = true
      }
      html.push(`<li>${formatInline(escapeHtml(numListMatch[1]))}</li>`)
      continue
    }

    closeList()
    html.push(`<p class="md-paragraph">${formatInline(escapeHtml(trimmed))}</p>`)
  }

  closeList()
  if (inCodeBlock && codeLines.length > 0) {
    html.push(
      `<pre class="md-code-block"><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`
    )
  }

  return html.join('')
}

/**
 * Strip all Markdown formatting symbols so speechSynthesis reads clean natural sentences.
 */
export function stripMarkdownForTts(markdownText) {
  if (!markdownText) return ''
  return String(markdownText)
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(\*|_)(.*?)\1/g, '$2')
    .replace(/^[\s>*•-]+/gm, '')
    .replace(/\n{2,}/g, '. ')
    .replace(/\n/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim()
}
