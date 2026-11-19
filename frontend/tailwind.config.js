/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}'
  ],
  theme: {
    extend: {
      colors: {
        graphite: {
          deep: '#0f0f10',
          bg: '#141415',
          card: '#19191b',
          raised: '#232325',
          hover: '#2b2b2e'
        },
        ink: {
          primary: '#ededed',
          secondary: '#a1a1a6',
          muted: '#85858b'
        },
        accent: {
          DEFAULT: '#e56b38',
          hover: '#f07844'
        },
        semantic: {
          positive: '#4cc38a',
          negative: '#e5484d'
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
