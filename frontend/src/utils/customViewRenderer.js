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

function isTruthyField(value) {
  if (value === null || value === undefined || value === false) return false
  if (typeof value === 'string' && value.trim() === '') return false
  if (typeof value === 'number' && (Number.isNaN(value) || value === 0)) return false
  if (Array.isArray(value)) return value.length > 0
  return Boolean(value)
}

function evaluateConditionals(blockHtml, contextObj = {}) {
  let out = blockHtml
  // Loop up to 4 passes to support nested conditional blocks
  for (let pass = 0; pass < 4; pass++) {
    const prev = out
    // {{#if field}}...{{/if}}
    out = out.replace(
      /\{\{#if\s+([a-zA-Z0-9_]+)\s*\}\}([\s\S]*?)\{\{\/if\}\}/g,
      (_, fieldKey, inner) => (isTruthyField(contextObj?.[fieldKey]) ? inner : '')
    )
    // {{#unless field}}...{{/unless}}
    out = out.replace(
      /\{\{#unless\s+([a-zA-Z0-9_]+)\s*\}\}([\s\S]*?)\{\{\/unless\}\}/g,
      (_, fieldKey, inner) => (!isTruthyField(contextObj?.[fieldKey]) ? inner : '')
    )
    // {{^field}}...{{/field}} (inverted Mustache section)
    out = out.replace(
      /\{\{\^\s*([a-zA-Z0-9_]+)\s*\}\}([\s\S]*?)\{\{\/\s*\1\s*\}\}/g,
      (_, fieldKey, inner) => (!isTruthyField(contextObj?.[fieldKey]) ? inner : '')
    )
    if (out === prev) break
  }
  return out
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

  const rawRecords = Array.isArray(records)
    ? records
    : Array.isArray(tracker.recent_records)
      ? tracker.recent_records
      : Array.isArray(tracker.records)
        ? [...tracker.records].reverse()
        : []

  const sourceRecords = rawRecords.filter((rec) => {
    if (!rec || typeof rec !== 'object') return false
    if (rec.deleted === true) return false
    return !Object.values(rec).some(
      (v) =>
        typeof v === 'string' &&
        ['SUPPRIMÉ', '[SUPPRIMÉ]', 'SUPPRIME', '[SUPPRIME]'].includes(v.trim().toUpperCase())
    )
  })

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
          const stepContext = {
            this: stepLabel,
            label: stepLabel,
            index: idx + 1,
            is_current: isCurrent ? 'is-current active' : ''
          }
          const withCond = evaluateConditionals(innerBlock, stepContext)
          return withCond
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
          const withCond = evaluateConditionals(innerBlock, rec)
          return withCond.replace(
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

  // 3. Top-level conditionals & scalar placeholders
  const scalarMap = {
    id: tracker.id || '',
    display_name: tracker.display_name || tracker.id || '',
    type: tracker.type || '',
    count: recordCount,
    record_count: recordCount,
    records: sourceRecords,
    steps,
    current_step: currentStep,
    next_step: nextStep,
    current_index: currentIndex + 1,
    total_steps: totalSteps,
    last_updated: lastUpdated
  }

  html = evaluateConditionals(html, scalarMap)

  html = html.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
    if (
      Object.prototype.hasOwnProperty.call(scalarMap, key) &&
      typeof scalarMap[key] !== 'object'
    ) {
      return escapeHtml(scalarMap[key])
    }
    return ''
  })

  // 4. Final safety sweep: strip any residual Mustache/Handlebars tags so raw {{...}} never leaks to screen
  html = html.replace(/\{\{[^}]+\}\}/g, '')

  return html
}
