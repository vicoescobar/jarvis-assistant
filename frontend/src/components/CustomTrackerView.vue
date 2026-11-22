<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  hydrateCustomTemplate,
  sanitizeCustomCss
} from '@/utils/customViewRenderer'

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

const emit = defineEmits(['cycle-action', 'open-detail'])

const hostEl = ref(null)
let shadowRoot = null

const BASE_SHADOW_RESET = `
*, *::before, *::after {
  box-sizing: border-box;
}
:host {
  display: block;
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
  word-wrap: break-word;
  overflow-wrap: anywhere;
  font-family: inherit;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}
.jarvis-shadow-container {
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}
.jarvis-shadow-container * {
  min-width: 0;
  max-width: 100%;
}
button, [data-jarvis-action] {
  font-family: inherit;
  cursor: pointer;
}
`

function renderShadowContent() {
  if (!shadowRoot) return
  const customView = props.tracker?.custom_view
  if (!customView || !customView.html) {
    shadowRoot.innerHTML = ''
    return
  }

  const cleanCss = sanitizeCustomCss(customView.css || '')
  const hydratedHtml = hydrateCustomTemplate(
    customView.html,
    props.tracker,
    props.records
  )

  shadowRoot.innerHTML = `<style>${BASE_SHADOW_RESET}\n${cleanCss}</style><div class="jarvis-shadow-container">${hydratedHtml}</div>`
}

function handleShadowClick(event) {
  if (props.busy) return
  const target = event.target?.closest?.('[data-jarvis-action]')
  if (!target) return

  event.preventDefault()
  const action = target.getAttribute('data-jarvis-action')

  if (action === 'advance_cycle') {
    emit('cycle-action', 'advance')
  } else if (action === 'skip_cycle') {
    emit('cycle-action', 'skip')
  } else if (action === 'open_detail') {
    emit('open-detail', props.tracker.id)
  }
}

onMounted(() => {
  if (hostEl.value) {
    shadowRoot = hostEl.value.attachShadow({ mode: 'open' })
    shadowRoot.addEventListener('click', handleShadowClick)
    renderShadowContent()
  }
})

onBeforeUnmount(() => {
  if (shadowRoot) {
    shadowRoot.removeEventListener('click', handleShadowClick)
  }
})

watch(
  () => [
    props.tracker,
    props.tracker?.custom_view?.html,
    props.tracker?.custom_view?.css,
    props.tracker?.custom_view?.updated_at,
    props.tracker?.current_step,
    props.tracker?.record_count,
    props.records
  ],
  () => {
    renderShadowContent()
  },
  { deep: true }
)
</script>

<template>
  <div ref="hostEl" class="w-full"></div>
</template>
