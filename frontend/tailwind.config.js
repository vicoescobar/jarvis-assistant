/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}'
  ],
  theme: {
    extend: {
      colors: {
        graphite: {
          deep: 'var(--bg-deep)',
          bg: 'var(--bg-canvas)',
          card: 'var(--bg-card)',
          raised: 'var(--bg-raised)',
          hover: 'var(--bg-hover)'
        },
        ink: {
          primary: 'var(--text-primary)',
          secondary: 'var(--text-secondary)',
          muted: 'var(--text-muted)'
        },
        accent: {
          DEFAULT: 'var(--accent-primary)',
          hover: 'var(--accent-primary-hover)',
          tint: 'var(--accent-primary-tint)'
        },
        pastel: {
          sage: 'var(--accent-sage)',
          'sage-tint': 'var(--accent-sage-tint)',
          lavender: 'var(--accent-lavender)',
          'lavender-tint': 'var(--accent-lavender-tint)',
          butter: 'var(--accent-butter)',
          'butter-tint': 'var(--accent-butter-tint)',
          rose: 'var(--accent-rose)',
          'rose-tint': 'var(--accent-rose-tint)'
        },
        semantic: {
          positive: 'var(--accent-sage)',
          negative: 'var(--accent-rose)'
        }
      },
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Geist', 'Inter', 'system-ui', 'sans-serif'],
        mono: ['Geist Mono', 'JetBrains Mono', 'SFMono-Regular', 'ui-monospace', 'monospace']
      },
      transitionTimingFunction: {
        fluid: 'cubic-bezier(0.22, 1, 0.36, 1)'
      }
    }
  },
  plugins: []
}
