<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { jarvisApi } from '@/services/api'
import { themeStore } from '@/stores/theme'

const props = defineProps({
  tracker: {
    type: Object,
    required: true
  },
  records: {
    type: Array,
    default: null
  },
  busy: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'cycle-action',
  'open-detail',
  'refresh-requested',
  'toast',
  'fallback-standard'
])

const iframeRef = ref(null)
const iframeHeight = ref(140)
const runtimeError = ref('')
const copiedError = ref(false)
const viewId = `jcv_${Math.random().toString(36).slice(2, 10)}_${Date.now()}`

const LIGHT_VARS = {
  '--bg-canvas': '#FAF6F0',
  '--bg-surface': '#FFFDF9',
  '--bg-subtle': '#F3ECE1',
  '--border-subtle': '#E8DFD1',
  '--text-primary': '#2B2623',
  '--text-secondary': '#544C45',
  '--text-muted': '#6E655D',
  '--accent-primary': '#DF8A6A',
  '--accent-soft': '#F8E4DA',
  '--pastel-sage': '#5E8C73',
  '--pastel-sage-soft': '#E3EFE8',
  '--pastel-lavender': '#7A68A6',
  '--pastel-lavender-soft': '#EEEAF7',
  '--pastel-butter': '#B58E2E',
  '--pastel-butter-soft': '#F9F0D6',
  '--pastel-rose': '#C96D6D',
  '--pastel-rose-soft': '#F8E3E3'
}

const DARK_VARS = {
  '--bg-canvas': '#141415',
  '--bg-surface': '#19191b',
  '--bg-subtle': '#0f0f10',
  '--border-subtle': 'rgba(237, 237, 237, 0.08)',
  '--text-primary': '#ededed',
  '--text-secondary': '#a1a1a6',
  '--text-muted': '#85858b',
  '--accent-primary': '#e56b38',
  '--accent-soft': 'rgba(229, 107, 56, 0.16)',
  '--pastel-sage': '#4cc38a',
  '--pastel-sage-soft': 'rgba(76, 195, 138, 0.15)',
  '--pastel-lavender': '#b3a4d8',
  '--pastel-lavender-soft': 'rgba(157, 142, 194, 0.16)',
  '--pastel-butter': '#e2c068',
  '--pastel-butter-soft': 'rgba(217, 180, 90, 0.16)',
  '--pastel-rose': '#e5484d',
  '--pastel-rose-soft': 'rgba(229, 72, 77, 0.15)'
}

function getThemeVars(themeName) {
  return themeName === 'dark' ? DARK_VARS : LIGHT_VARS
}

function formatCssVarsBlock(varsObj) {
  return Object.entries(varsObj)
    .map(([k, v]) => `${k}: ${v};`)
    .join(' ')
}

function filterActiveRecords(rawList) {
  if (!Array.isArray(rawList)) return []
  return rawList.filter((rec) => {
    if (!rec || typeof rec !== 'object') return false
    if (rec.deleted === true) return false
    return !Object.values(rec).some(
      (v) =>
        typeof v === 'string' &&
        ['SUPPRIMÉ', '[SUPPRIMÉ]', 'SUPPRIME', '[SUPPRIME]'].includes(
          v.trim().toUpperCase()
        )
    )
  })
}

const activeRecords = computed(() => {
  const source = Array.isArray(props.records)
    ? props.records
    : Array.isArray(props.tracker?.recent_records)
      ? props.tracker.recent_records
      : Array.isArray(props.tracker?.records)
        ? [...props.tracker.records].reverse()
        : []
  return filterActiveRecords(source)
})

const trackerMeta = computed(() => {
  const t = props.tracker || {}
  return {
    id: t.id || '',
    display_name: t.display_name || t.id || '',
    type: t.type || '',
    include_in_morning_briefing: Boolean(t.include_in_morning_briefing),
    cycle_config: t.cycle_config || null,
    current_step: t.current_step || null,
    fields_schema: Array.isArray(t.fields_schema) ? t.fields_schema : [],
    record_count: Number(t.record_count ?? activeRecords.value.length ?? 0)
  }
})

function sanitizeHtml(raw) {
  return String(raw || '')
    .slice(0, 24576)
    .replace(
      /<(script|iframe|object|embed|link|meta|base|applet)\b[^>]*>[\s\S]*?<\/\1>/gi,
      ''
    )
    .replace(
      /<\/?(script|iframe|object|embed|link|meta|base|applet)\b[^>]*\/?>/gi,
      ''
    )
    .replace(/(javascript|vbscript|data\s*:\s*text\/html)\s*:/gi, 'blocked:')
    .trim()
}

function sanitizeCss(raw) {
  return String(raw || '')
    .slice(0, 12288)
    .replace(/@import\b[^;]*;?/gi, '')
    .replace(/expression\s*\([^)]*\)/gi, '')
    .replace(
      /url\s*\(\s*['"]?\s*(https?:|\/\/|javascript:|vbscript:|data\s*:\s*text\/html)[^)]*\)/gi,
      'url()'
    )
    .replace(/<\/?style\b[^>]*>/gi, '')
    .trim()
}

function sanitizeJs(raw) {
  return String(raw || '')
    .slice(0, 24576)
    .replace(/<\/?script\b[^>]*>/gi, '')
    .trim()
}

function safeJsonForInlineScript(value) {
  return JSON.stringify(value)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029')
}

function getLocalToday() {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

const srcdoc = ref('')

function buildSrcdoc() {
  runtimeError.value = ''
  const cv = props.tracker?.custom_view || {}
  const cleanHtml = sanitizeHtml(cv.html || '<div id="app"></div>')
  const cleanCss = sanitizeCss(cv.css || '')
  const cleanJs = sanitizeJs(cv.js || '')
  const currentTheme = themeStore.current === 'dark' ? 'dark' : 'light'
  const themeCssVars = formatCssVarsBlock(getThemeVars(currentTheme))

  const initialBootstrap = safeJsonForInlineScript({
    viewId,
    theme: currentTheme,
    today: getLocalToday(),
    tracker: trackerMeta.value,
    records: activeRecords.value
  })

  // Also include lightweight fallback hydration when a legacy Mustache view has no JS
  srcdoc.value = `<!DOCTYPE html>
<html lang="fr" data-theme="${currentTheme}">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:;">
<style id="jarvis-theme-vars">:root { ${themeCssVars} }</style>
<style id="jarvis-base-reset">
*, *::before, *::after { box-sizing: border-box; }
html, body {
  margin: 0;
  padding: 0;
  width: 100%;
  max-width: 100%;
  font-family: -apple-system, BlinkMacSystemFont, "Geist", "Inter", system-ui, sans-serif;
  color: var(--text-primary);
  background: transparent;
  overflow-x: hidden;
  word-wrap: break-word;
  font-variant-numeric: tabular-nums;
  -webkit-tap-highlight-color: transparent;
}
button, input, select, textarea {
  font-family: inherit;
  color: inherit;
}
button, [data-jarvis-action] {
  cursor: pointer;
}
</style>
<style id="jarvis-custom-css">${cleanCss}</style>
</head>
<body>
<div id="jarvis-root">${cleanHtml}</div>
<script>
(function() {
  const init = ${initialBootstrap};
  const viewId = init.viewId;
  const pendingRpc = new Map();
  const updateListeners = [];
  let rpcSeq = 0;

  function postToParent(payload) {
    window.parent.postMessage(Object.assign({ viewId: viewId }, payload), '*');
  }

  function callRpc(method, args) {
    return new Promise(function(resolve, reject) {
      const requestId = 'rpc_' + (++rpcSeq) + '_' + Date.now();
      pendingRpc.set(requestId, { resolve: resolve, reject: reject });
      postToParent({
        type: 'jarvis:rpc',
        requestId: requestId,
        method: method,
        args: args || {}
      });
    });
  }

  function escapeHtml(val) {
    if (val === null || val === undefined) return '';
    return String(val)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function hydrateLegacyMustacheIfNeeded(rawTemplate) {
    if (!rawTemplate || rawTemplate.indexOf('{{') === -1) return;
    const rootEl = document.getElementById('jarvis-root');
    if (!rootEl) return;
    const t = window.jarvis.tracker || {};
    const recs = Array.isArray(window.jarvis.records) ? window.jarvis.records : [];
    const steps = Array.isArray(t.cycle_config && t.cycle_config.steps) ? t.cycle_config.steps : [];
    const currentIndex = Number((t.cycle_config && t.cycle_config.current_index) || 0);
    let out = rawTemplate;

    out = out.replace(/\\{\\{#each\\s+steps\\}\\}([\\s\\S]*?)\\{\\{\\/each\\}\\}/g, function(_, block) {
      return steps.map(function(s, idx) {
        return block
          .replace(/\\{\\{\\s*this\\s*\\}\\}/g, escapeHtml(s))
          .replace(/\\{\\{\\s*label\\s*\\}\\}/g, escapeHtml(s))
          .replace(/\\{\\{\\s*index\\s*\\}\\}/g, String(idx + 1))
          .replace(/\\{\\{\\s*is_current\\s*\\}\\}/g, idx === currentIndex ? 'is-current active' : '');
      }).join('');
    });

    out = out.replace(/\\{\\{#each\\s+records\\}\\}([\\s\\S]*?)\\{\\{\\/each\\}\\}/g, function(_, block) {
      return recs.map(function(r) {
        let row = block;
        row = row.replace(/\\{\\{#if\\s+([a-zA-Z0-9_]+)\\s*\\}\\}([\\s\\S]*?)\\{\\{\\/if\\}\\}/g, function(__, k, inner) {
          return r && r[k] ? inner : '';
        });
        row = row.replace(/\\{\\{(?:#unless|\\^)\\s*([a-zA-Z0-9_]+)\\s*\\}\\}([\\s\\S]*?)\\{\\{\\/(?:unless|\\1)\\s*\\}\\}/g, function(__, k, inner) {
          return !r || !r[k] ? inner : '';
        });
        return row.replace(/\\{\\{\\s*([a-zA-Z0-9_]+)\\s*\\}\\}/g, function(__, k) {
          return escapeHtml(r && r[k] !== undefined ? r[k] : '');
        });
      }).join('');
    });

    const scalars = {
      id: t.id || '',
      display_name: t.display_name || '',
      type: t.type || '',
      count: recs.length,
      record_count: recs.length,
      current_step: t.current_step || (steps[currentIndex] || ''),
      next_step: steps.length ? steps[(currentIndex + 1) % steps.length] : '',
      current_index: currentIndex + 1,
      total_steps: steps.length,
      last_updated: (t.cycle_config && t.cycle_config.last_updated) || ''
    };
    out = out.replace(/\\{\\{\\s*([a-zA-Z0-9_]+)\\s*\\}\\}/g, function(_, k) {
      return Object.prototype.hasOwnProperty.call(scalars, k) ? escapeHtml(scalars[k]) : '';
    });
    out = out.replace(/\\{\\{[^}]+\\}\\}/g, '');
    rootEl.innerHTML = out;
  }

  const rawInitialTemplate = document.getElementById('jarvis-root')
    ? document.getElementById('jarvis-root').innerHTML
    : '';

  window.jarvis = {
    tracker: init.tracker,
    records: init.records,
    theme: init.theme,
    today: init.today,
    escapeHtml: escapeHtml,
    addRecord: function(data) {
      return callRpc('addRecord', { data: data || {} });
    },
    updateRecord: function(recordId, data) {
      return callRpc('updateRecord', { recordId: recordId, data: data || {} });
    },
    deleteRecord: function(recordId) {
      return callRpc('deleteRecord', { recordId: recordId });
    },
    mutateCycle: function(operation, payload) {
      return callRpc('mutateCycle', { operation: operation, payload: payload || {} });
    },
    openDetail: function(recordId) {
      return callRpc('openDetail', { recordId: recordId || null });
    },
    toast: function(message) {
      return callRpc('toast', { message: String(message || '') });
    },
    onUpdate: function(fn) {
      if (typeof fn === 'function') {
        updateListeners.push(fn);
      }
    }
  };

  window.addEventListener('message', function(event) {
    const msg = event.data;
    if (!msg || typeof msg !== 'object') return;

    if (msg.type === 'jarvis:rpc-result' && msg.requestId) {
      const entry = pendingRpc.get(msg.requestId);
      if (entry) {
        pendingRpc.delete(msg.requestId);
        if (msg.ok) {
          entry.resolve(msg.data);
        } else {
          entry.reject(new Error(msg.error || 'Action refusée'));
        }
      }
      return;
    }

    if (msg.type === 'jarvis:update') {
      if (msg.tracker) window.jarvis.tracker = msg.tracker;
      if (Array.isArray(msg.records)) window.jarvis.records = msg.records;
      if (msg.theme) {
        window.jarvis.theme = msg.theme;
        document.documentElement.setAttribute('data-theme', msg.theme);
      }
      if (msg.cssVars) {
        const styleEl = document.getElementById('jarvis-theme-vars');
        if (styleEl) styleEl.textContent = ':root { ' + msg.cssVars + ' }';
      }
      if (updateListeners.length === 0 && rawInitialTemplate.indexOf('{{') !== -1) {
        hydrateLegacyMustacheIfNeeded(rawInitialTemplate);
      }
      for (let i = 0; i < updateListeners.length; i++) {
        try {
          updateListeners[i](window.jarvis);
        } catch (err) {
          postToParent({
            type: 'jarvis:error',
            message: err && err.message ? err.message : String(err)
          });
        }
      }
      reportHeight();
    }
  });

  document.addEventListener('click', function(event) {
    const target = event.target && event.target.closest ? event.target.closest('[data-jarvis-action]') : null;
    if (!target) return;
    event.preventDefault();
    const act = target.getAttribute('data-jarvis-action');
    if (act === 'advance_cycle' || act === 'advance') {
      window.jarvis.mutateCycle('advance_cycle');
    } else if (act === 'skip_cycle' || act === 'skip_or_shift_cycle' || act === 'skip') {
      window.jarvis.mutateCycle('skip_or_shift_cycle');
    } else if (act === 'open_detail') {
      window.jarvis.openDetail();
    }
  });

  let lastReportedHeight = 0;
  function reportHeight() {
    const root = document.getElementById('jarvis-root') || document.body;
    const h = Math.ceil(Math.max(root.scrollHeight, root.offsetHeight, document.body.scrollHeight, 60));
    if (Math.abs(h - lastReportedHeight) > 1) {
      lastReportedHeight = h;
      postToParent({ type: 'jarvis:resize', height: h });
    }
  }

  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(function() {
      reportHeight();
    });
    ro.observe(document.body);
    const rootEl = document.getElementById('jarvis-root');
    if (rootEl) ro.observe(rootEl);
  }

  window.onerror = function(message, source, lineno) {
    postToParent({
      type: 'jarvis:error',
      message: String(message) + (lineno ? ' (ligne ' + lineno + ')' : '')
    });
  };

  window.onunhandledrejection = function(event) {
    const reason = event && event.reason;
    postToParent({
      type: 'jarvis:error',
      message: reason && reason.message ? reason.message : String(reason || 'Erreur Promise JS')
    });
  };

  if (!${Boolean(cleanJs)} && rawInitialTemplate.indexOf('{{') !== -1) {
    hydrateLegacyMustacheIfNeeded(rawInitialTemplate);
  }

  window.addEventListener('load', function() {
    reportHeight();
    setTimeout(reportHeight, 60);
  });
})();
<\/script>
<script>
try {
${cleanJs}
} catch (err) {
  window.parent.postMessage({
    viewId: '${viewId}',
    type: 'jarvis:error',
    message: err && err.message ? err.message : String(err)
  }, '*');
}
<\/script>
</body>
</html>`
}

function sendUpdateToIframe() {
  const win = iframeRef.value?.contentWindow
  if (!win) return
  const currentTheme = themeStore.current === 'dark' ? 'dark' : 'light'
  const payload = JSON.parse(
    JSON.stringify({
      type: 'jarvis:update',
      viewId,
      tracker: trackerMeta.value,
      records: activeRecords.value,
      theme: currentTheme,
      cssVars: formatCssVarsBlock(getThemeVars(currentTheme))
    })
  )
  win.postMessage(payload, '*')
}

function replyRpc(requestId, ok, data = null, error = null) {
  const win = iframeRef.value?.contentWindow
  if (!win || !requestId) return
  win.postMessage(
    JSON.parse(
      JSON.stringify({
        type: 'jarvis:rpc-result',
        viewId,
        requestId,
        ok,
        data,
        error
      })
    ),
    '*'
  )
}

async function handleWindowMessage(event) {
  if (!iframeRef.value || event.source !== iframeRef.value.contentWindow) {
    return
  }
  const msg = event.data
  if (!msg || typeof msg !== 'object' || msg.viewId !== viewId) {
    return
  }

  if (msg.type === 'jarvis:resize') {
    const nextHeight = Math.min(640, Math.max(80, Number(msg.height) || 140))
    iframeHeight.value = nextHeight
    return
  }

  if (msg.type === 'jarvis:error') {
    runtimeError.value = String(msg.message || 'Erreur d’exécution JS')
    return
  }

  if (msg.type === 'jarvis:rpc') {
    const { requestId, method, args = {} } = msg
    const trackerId = props.tracker?.id
    if (!trackerId) {
      replyRpc(requestId, false, null, 'Tracker ID manquant')
      return
    }

    try {
      if (method === 'addRecord') {
        const res = await jarvisApi.createRecord(trackerId, args.data || {})
        emit('refresh-requested')
        replyRpc(requestId, true, res?.record || res)
      } else if (method === 'updateRecord') {
        if (!args.recordId) {
          throw new Error('recordId requis pour updateRecord')
        }
        const res = await jarvisApi.updateRecord(
          trackerId,
          args.recordId,
          args.data || {}
        )
        emit('refresh-requested')
        replyRpc(requestId, true, res?.record || res)
      } else if (method === 'deleteRecord') {
        if (!args.recordId) {
          throw new Error('recordId requis pour deleteRecord')
        }
        await jarvisApi.deleteRecord(trackerId, args.recordId)
        emit('refresh-requested')
        replyRpc(requestId, true, { deleted: true, id: args.recordId })
      } else if (method === 'mutateCycle') {
        const rawOp = String(args.operation || 'advance')
        const normalizedAction =
          rawOp === 'advance_cycle' || rawOp === 'advance'
            ? 'advance'
            : rawOp === 'skip_or_shift_cycle' ||
                rawOp === 'skip_cycle' ||
                rawOp === 'skip'
              ? 'skip'
              : 'set'
        const targetIdx =
          args.payload?.target_index ?? args.payload?.targetIndex ?? null
        const res = await jarvisApi.mutateCycle(
          trackerId,
          normalizedAction,
          targetIdx
        )
        emit('refresh-requested')
        replyRpc(requestId, true, res)
      } else if (method === 'openDetail') {
        emit('open-detail', trackerId, args.recordId || null)
        replyRpc(requestId, true, { opened: true })
      } else if (method === 'toast') {
        if (args.message) {
          emit('toast', String(args.message))
        }
        replyRpc(requestId, true, { shown: true })
      } else {
        replyRpc(requestId, false, null, `Méthode SDK inconnue: ${method}`)
      }
    } catch (err) {
      replyRpc(
        requestId,
        false,
        null,
        err?.message || 'Erreur lors de l’exécution de l’action'
      )
    }
  }
}

async function copyErrorToClipboard() {
  if (!runtimeError.value) return
  try {
    await navigator.clipboard.writeText(
      `Erreur JS sur la vue personnalisée du tracker ${props.tracker?.id} : ${runtimeError.value}`
    )
    copiedError.value = true
    emit('toast', 'Erreur copiée dans le presse-papiers')
    setTimeout(() => {
      copiedError.value = false
    }, 2000)
  } catch {
    // Ignore clipboard permission errors
  }
}

onMounted(() => {
  window.addEventListener('message', handleWindowMessage)
  buildSrcdoc()
})

onBeforeUnmount(() => {
  window.removeEventListener('message', handleWindowMessage)
})

// Rebuild srcdoc only when custom_view code (html, css, js) changes
watch(
  () => [
    props.tracker?.id,
    props.tracker?.custom_view?.html,
    props.tracker?.custom_view?.css,
    props.tracker?.custom_view?.js
  ],
  () => {
    buildSrcdoc()
  }
)

// Push live data & theme updates via postMessage without reloading the iframe
watch(
  () => [
    themeStore.current,
    props.tracker?.current_step,
    props.tracker?.record_count,
    props.tracker?.cycle_config?.current_index,
    props.tracker?.cycle_config?.last_updated,
    props.records,
    props.tracker?.recent_records
  ],
  () => {
    sendUpdateToIframe()
  },
  { deep: true }
)
</script>

<template>
  <div class="w-full space-y-2.5">
    <!-- Non-blocking inline runtime error banner -->
    <div
      v-if="runtimeError"
      class="rounded-[10px] border border-pastel-rose/40 bg-pastel-rose-tint p-3 text-xs text-pastel-rose space-y-2"
    >
      <div class="font-mono meta-mono break-words">
        Erreur JS vue perso : {{ runtimeError }}
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="btn-secondary h-8 rounded-[6px] px-2.5 text-[11px] font-mono meta-mono"
          @click="copyErrorToClipboard"
        >
          {{ copiedError ? 'Copié ✓' : 'Copier l’erreur' }}
        </button>
        <button
          type="button"
          class="btn-secondary h-8 rounded-[6px] px-2.5 text-[11px] font-mono meta-mono"
          @click="emit('fallback-standard')"
        >
          Vue standard
        </button>
      </div>
    </div>

    <iframe
      ref="iframeRef"
      sandbox="allow-scripts"
      :srcdoc="srcdoc"
      :title="`Vue personnalisée ${tracker.display_name || tracker.id}`"
      class="w-full border-0 block bg-transparent transition-[height] duration-150 ease-out"
      :style="{
        height: `${iframeHeight}px`,
        minHeight: '80px',
        maxHeight: '640px'
      }"
    ></iframe>
  </div>
</template>
