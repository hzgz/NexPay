import { computed, reactive, watch } from 'vue'

export const PAGE_SIZE_OPTIONS = [10, 20, 30, 50, 100] as const

export type PaginationState = {
  page: number
  pageSize: number
}

export function createPaginationState(initialPageSize = 20) {
  const normalized = PAGE_SIZE_OPTIONS.includes(initialPageSize as (typeof PAGE_SIZE_OPTIONS)[number])
    ? initialPageSize
    : PAGE_SIZE_OPTIONS[1]

  return reactive<PaginationState>({
    page: 1,
    pageSize: normalized,
  })
}

export function usePagination<T = Record<string, any>>(
  source: () => T[] | readonly T[] | null | undefined,
  initialPageSize = 20,
) {
  const pagination = createPaginationState(initialPageSize)
  const normalizedRows = computed<T[]>(() => {
    const rows = source()
    return Array.isArray(rows) ? [...rows] as T[] : []
  })

  const total = computed(() => normalizedRows.value.length)
  const pageCount = computed(() => Math.max(1, Math.ceil(total.value / Math.max(1, pagination.pageSize))))
  const pagedRows = computed<T[]>(() => {
    const start = (pagination.page - 1) * pagination.pageSize
    return normalizedRows.value.slice(start, start + pagination.pageSize)
  })

  watch(total, () => {
    if (pagination.page > pageCount.value) {
      pagination.page = pageCount.value
    }

    if (pagination.page < 1) {
      pagination.page = 1
    }
  }, { immediate: true })

  watch(() => pagination.pageSize, () => {
    pagination.page = 1
  })

  return {
    pagination,
    total,
    pageCount,
    pagedRows,
  }
}

export function resetPagination(state: PaginationState) {
  state.page = 1
}
