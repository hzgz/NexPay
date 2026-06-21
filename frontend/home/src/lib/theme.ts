export type ThemeMode = 'light'

export function resolveTheme(): ThemeMode {
  return 'light'
}

export function applyTheme(): ThemeMode {
  if (typeof document !== 'undefined') {
    document.documentElement.dataset.theme = 'light'
    document.documentElement.style.colorScheme = 'light'
  }

  return 'light'
}

export function initializeTheme(): ThemeMode {
  return applyTheme()
}

export function useThemeToggle() {
  return {
    theme: { value: 'light' as ThemeMode },
    isDark: { value: false },
    toggleTheme: () => 'light' as ThemeMode,
    setTheme: () => 'light' as ThemeMode,
  }
}
