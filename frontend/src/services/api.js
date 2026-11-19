import { reactive } from 'vue'

const TOKEN_STORAGE_KEY = 'jarvis_sanctum_token'

export const API_BASE_URL = (
  import.meta.env.VITE_API_BASE_URL || 'https://jarvis-api.victor-giroud.fr'
).replace(/\/+$/, '')

export const telemetry = reactive({
  lastLatencyMs: null,
  avgLatencyMs: null,
  lastEndpoint: null,
  lastStatus: 'idle',
  samples: []
})

let unauthorizedHandler = null

export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

export function getStoredToken() {
  return localStorage.getItem(TOKEN_STORAGE_KEY) || ''
}

export function setStoredToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }
}

function recordLatency(endpoint, durationMs, ok) {
  const rounded = Math.round(durationMs)
  telemetry.lastLatencyMs = rounded
  telemetry.lastEndpoint = endpoint
  telemetry.lastStatus = ok ? 'ok' : 'error'
  telemetry.samples.push(rounded)
  if (telemetry.samples.length > 15) {
    telemetry.samples.shift()
  }
  const sum = telemetry.samples.reduce((acc, v) => acc + v, 0)
  telemetry.avgLatencyMs = Math.round(sum / telemetry.samples.length)
}

export async function apiRequest(path, options = {}) {
  const token = getStoredToken()
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers || {})
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const start = performance.now()
  let response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      headers
    })
  } catch (networkError) {
    recordLatency(path, performance.now() - start, false)
    throw new Error('Connexion au serveur Jarvis impossible.')
  }

  recordLatency(path, performance.now() - start, response.ok)

  if (response.status === 401) {
    setStoredToken('')
    if (typeof unauthorizedHandler === 'function') {
      unauthorizedHandler()
    }
  }

  const text = await response.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = { raw: text }
  }

  if (!response.ok) {
    const message =
      (data && (data.message || data.error)) ||
      `Erreur HTTP ${response.status}`
    throw new Error(message)
  }

  return data
}

export const jarvisApi = {
  health() {
    return apiRequest('/api/health', { method: 'GET' })
  },
  login(email, password) {
    return apiRequest('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    })
  },
  logout() {
    return apiRequest('/api/auth/logout', { method: 'POST' })
  },
  me() {
    return apiRequest('/api/auth/me', { method: 'GET' })
  },
  getTrackers() {
    return apiRequest('/api/trackers', { method: 'GET' })
  },
  getTracker(trackerId, includeArchive = false) {
    const query = includeArchive ? '?include_archive=true' : ''
    return apiRequest(`/api/trackers/${encodeURIComponent(trackerId)}${query}`, {
      method: 'GET'
    })
  },
  createRecord(trackerId, payload) {
    return apiRequest(`/api/trackers/${encodeURIComponent(trackerId)}/records`, {
      method: 'POST',
      body: JSON.stringify(payload)
    })
  },
  updateRecord(trackerId, recordId, payload) {
    return apiRequest(
      `/api/trackers/${encodeURIComponent(trackerId)}/records/${encodeURIComponent(recordId)}`,
      {
        method: 'PATCH',
        body: JSON.stringify(payload)
      }
    )
  },
  deleteRecord(trackerId, recordId) {
    return apiRequest(
      `/api/trackers/${encodeURIComponent(trackerId)}/records/${encodeURIComponent(recordId)}`,
      {
        method: 'DELETE'
      }
    )
  },
  mutateCycle(trackerId, action, targetIndex = null) {
    const body = { action }
    if (targetIndex !== null && targetIndex !== undefined) {
      body.target_index = Number(targetIndex)
    }
    return apiRequest(`/api/trackers/${encodeURIComponent(trackerId)}/cycle`, {
      method: 'POST',
      body: JSON.stringify(body)
    })
  },
  getMemory() {
    return apiRequest('/api/memory', { method: 'GET' })
  },
  createMemoryRule(payload) {
    return apiRequest('/api/memory/rules', {
      method: 'POST',
      body: JSON.stringify(payload)
    })
  },
  deleteMemoryRule(ruleId) {
    return apiRequest(`/api/memory/rules/${encodeURIComponent(ruleId)}`, {
      method: 'DELETE'
    })
  },
  getChatHistory() {
    return apiRequest('/api/jarvis/chat/history', { method: 'GET' })
  },
  sendChatMessage(text) {
    return apiRequest('/api/jarvis/chat', {
      method: 'POST',
      body: JSON.stringify({ text })
    })
  },
  sendCommand(command, notifyDiscord = false) {
    return apiRequest('/api/jarvis/command', {
      method: 'POST',
      body: JSON.stringify({ command, notify_discord: notifyDiscord })
    })
  },
  triggerBriefing() {
    return apiRequest('/api/jarvis/briefing/trigger', {
      method: 'POST'
    })
  },
  createLiveSession() {
    return apiRequest('/api/jarvis/live/session', {
      method: 'POST'
    })
  },
  executeLiveTool(toolName, args = {}) {
    return apiRequest('/api/jarvis/live/tool', {
      method: 'POST',
      body: JSON.stringify({
        tool_name: toolName,
        arguments: args
      })
    })
  }
}
