/**
 * Client-side defense-in-depth sanitization and mustache-style data hydration
 * for agent-generated Shadow DOM custom tracker views.
 */

function escapeHtml(value) {
  if (value === null || value === undefined) return ''
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

export function sanitizeCustomHtml(rawHtml) {
  return String(rawHtml || '')
    .slice(0, 16384)
    .replace(
      /<(script|iframe|object|embed|form|link|meta|base|applet)\b[^>]*>[\s\S]*?<\/\1>/gi,
      ''
    )
    .replace(
      /<\/?(script|iframe|object|embed|form|link|meta|base|applet)\b[^>]*\/?>/gi,
      ''
    )
    .replace(/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/(javascript|vbscript|data\s*:\s*text\/html)\s*:/gi, 'blocked:')
    .trim()
}

export function sanitizeCustomCss(rawCss) {
  return String(rawCss || '')
    .slice(0, 8192)
    .replace(/@import\b[^;]*;?/gi, '')
    .replace(/expression\s*\([^)]*\)/gi, '')
    .replace(
      /url\s*\(\s*['"]?\s*(javascript|vbscript|data\s*:\s*text\/html)[^)]*\)/gi,
      'url()'
    )
    .replace(/<\/?style\b[^>]*>/gi, '')
    .trim()
}

export function hydrateCustomTemplate(templateHtml, tracker = {}, records = null) {
  let html = sanitizeCustomHtml(templateHtml)
  if (!html) return ''

  const steps = Array.isArray(tracker.cycle_config?.steps)
    ? tracker.cycle_config.steps
    : []
  const currentIndex = Number(tracker.cycle_config?.current_index ?? 0)
  const totalSteps = steps.length
  const currentStep =
    tracker.current_step ||
    (totalSteps > 0 ? steps[currentIndex] || '' : '')
  const nextStep =
    totalSteps > 0 ? steps[(currentIndex + 1) % totalSteps] || '' : ''
  const lastUpdated = tracker.cycle_config?.last_updated || ''

  const sourceRecords = Array.isArray(records)
    ? records
    : Array.isArray(tracker.recent_records)
      ? tracker.recent_records
      : Array.isArray(tracker.records)
        ? [...tracker.records].reverse().slice(0, 8)
        : []

  const recordCount = Number(
    tracker.record_count ?? sourceRecords.length ?? 0
  )

  // 1. Evaluate {{#each steps}}...{{/each}}
  html = html.replace(
    /\{\{#each\s+steps\}\}([\s\S]*?)\{\{\/each\}\}/g,
    (_, innerBlock) => {
      if (steps.length === 0) return ''
      return steps
        .map((stepLabel, idx) => {
          const isCurrent = idx === currentIndex
          return innerBlock
            .replace(/\{\{\s*this\s*\}\}/g, escapeHtml(stepLabel))
            .replace(/\{\{\s*label\s*\}\}/g, escapeHtml(stepLabel))
            .replace(/\{\{\s*index\s*\}\}/g, String(idx + 1))
            .replace(
              /\{\{\s*is_current\s*\}\}/g,
              isCurrent ? 'is-current active' : ''
            )
        })
        .join('')
    }
  )

  // 2. Evaluate {{#each records}}...{{/each}}
  html = html.replace(
    /\{\{#each\s+records\}\}([\s\S]*?)\{\{\/each\}\}/g,
    (_, innerBlock) => {
      if (sourceRecords.length === 0) return ''
      return sourceRecords
        .slice(0, 8)
        .map((rec) => {
          return innerBlock.replace(
            /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g,
            (__, fieldKey) => {
              if (fieldKey === 'this') return escapeHtml(JSON.stringify(rec))
              return escapeHtml(rec?.[fieldKey] ?? '')
            }
          )
        })
        .join('')
    }
  )

  // 3. Replace scalar placeholders
  const scalarMap = {
    id: tracker.id || '',
    display_name: tracker.display_name || tracker.id || '',
    type: tracker.type || '',
    count: recordCount,
    record_count: recordCount,
    current_step: currentStep,
    next_step: nextStep,
    current_index: currentIndex + 1,
    total_steps: totalSteps,
    last_updated: lastUpdated
  }

  html = html.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
    if (Object.prototype.hasOwnProperty.call(scalarMap, key)) {
      return escapeHtml(scalarMap[key])
    }
    return ''
  })

  return html
}
