import { ref, watch, type Ref } from 'vue'

export type MenuChild = {
  label: string
  path: string
}

export type MenuItem = {
  key: string
  label: string
  icon: any
  path?: string
  children?: MenuChild[]
}

export function useSidebarAccordion(
  menus: MenuItem[],
  routePath: Ref<string>,
  sidebarCollapsed: Ref<boolean>,
  isMobile: Ref<boolean>,
  menuOpen: Ref<boolean>,
) {
  const expandedKey = ref('')

  function collapseAllMenus() {
    expandedKey.value = ''
  }

  function syncExpandedByRoute() {
    if (sidebarCollapsed.value) return
    if (routePath.value === '/dashboard') {
      collapseAllMenus()
      return
    }

    const matchedKey = menus
      .filter((item) => item.children?.some((child) => routePath.value === child.path))
      .map((item) => item.key)[0]

    expandedKey.value = matchedKey || ''
  }

  function handleMenuClick(item: MenuItem, onNavigate?: (path: string) => void, onExpandOpen?: () => void) {
    if (!item.children?.length) {
      collapseAllMenus()
      if (item.path && onNavigate) onNavigate(item.path)
      return
    }

    if (sidebarCollapsed.value && !isMobile.value && onExpandOpen) {
      onExpandOpen()
    }

    expandedKey.value = expandedKey.value === item.key ? '' : item.key
  }

  function handleChildClick(parentKey: string, path: string, onNavigate?: (path: string) => void) {
    expandedKey.value = parentKey
    if (onNavigate) onNavigate(path)
  }

  function isExpanded(key: string) {
    return expandedKey.value === key
  }

  function isChildActive(path: string) {
    return routePath.value === path
  }

  function isGroupActive(item: MenuItem) {
    if (item.path) return routePath.value === item.path
    return item.children?.some((child) => routePath.value === child.path) || false
  }

  watch(routePath, () => {
    if (isMobile.value) menuOpen.value = false
    syncExpandedByRoute()
  })

  return {
    expandedKey,
    collapseAllMenus,
    syncExpandedByRoute,
    handleMenuClick,
    handleChildClick,
    isExpanded,
    isChildActive,
    isGroupActive,
  }
}
