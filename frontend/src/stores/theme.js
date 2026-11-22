import { reactive } from 'vue'

const THEME_STORAGE_KEY = 'jarvis_theme'
const LIGHT_THEME_COLOR = '#FAF6F0'
const DARK_THEME_COLOR = '#141415'

function getInitialTheme() {
  if (typeof window === 'undefined') return 'light'
  const saved = localStorage.getItem(THEME_STORAGE_KEY)
  if (saved === 'light' || saved === 'dark') {
    return saved
  }
  return 'light'
}

export const themeStore = reactive({
  current: getInitialTheme(),

  get isDark() {
    return this.current === 'dark'
  },

  apply(themeName) {
    this.current = themeName === 'dark' ? 'dark' : 'light'
    if (typeof document === 'undefined') return

    const root = document.documentElement
    root.setAttribute('data-theme', this.current)
    if (this.current === 'dark') {
      root.classList.add('dark')
    } else {
      root.classList.remove('dark')
    }

    const metaTheme = document.querySelector('meta[name="theme-color"]')
    if (metaTheme) {
      metaTheme.setAttribute(
        'content',
        this.current === 'dark' ? DARK_THEME_COLOR : LIGHT_THEME_COLOR
      )
    }

    try {
      localStorage.setItem(THEME_STORAGE_KEY, this.current)
    } catch {
      // Ignore storage quota issues
    }
  },

  toggle() {
    const next = this.current === 'dark' ? 'light' : 'dark'
    if (
      typeof document !== 'undefined' &&
      typeof document.startViewTransition === 'function' &&
      !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
      document.startViewTransition(() => {
        this.apply(next)
      })
    } else {
      this.apply(next)
    }
  },

  init() {
    this.apply(this.current)
  }
})
