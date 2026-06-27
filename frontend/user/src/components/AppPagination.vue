<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { PAGE_SIZE_OPTIONS } from '../lib/pagination'

const props = withDefaults(defineProps<{
  total: number
  page: number
  pageSize: number
  pageSizeOptions?: number[]
}>(), {
  pageSizeOptions: () => [...PAGE_SIZE_OPTIONS],
})

const emit = defineEmits<{
  (e: 'update:page', value: number): void
  (e: 'update:pageSize', value: number): void
}>()

const maxVisibleButtons = 6
const gotoInput = ref('1')

const totalPages = computed(() => Math.max(1, Math.ceil(Math.max(0, props.total) / Math.max(1, props.pageSize))))
const currentPage = computed(() => {
  if (props.page < 1) return 1
  if (props.page > totalPages.value) return totalPages.value
  return props.page
})

const pageButtons = computed(() => {
  const total = totalPages.value
  if (total <= maxVisibleButtons) {
    return Array.from({ length: total }, (_, index) => index + 1)
  }

  const half = Math.floor(maxVisibleButtons / 2)
  let start = Math.max(1, currentPage.value - half)
  let end = start + maxVisibleButtons - 1

  if (end > total) {
    end = total
    start = Math.max(1, end - maxVisibleButtons + 1)
  }

  return Array.from({ length: end - start + 1 }, (_, index) => start + index)
})

watch(currentPage, (value) => {
  gotoInput.value = String(value)
}, { immediate: true })

function updatePage(page: number) {
  const next = Math.min(Math.max(1, page), totalPages.value)
  if (next !== props.page) {
    emit('update:page', next)
  }
}

function updatePageSize(event: Event) {
  const target = event.target as HTMLSelectElement
  const next = Number(target.value || props.pageSize)
  if (Number.isFinite(next) && next > 0 && next !== props.pageSize) {
    emit('update:pageSize', next)
  }
}

function submitGoto() {
  const next = Number(gotoInput.value || currentPage.value)
  if (!Number.isFinite(next)) {
    gotoInput.value = String(currentPage.value)
    return
  }

  updatePage(next)
  gotoInput.value = String(Math.min(Math.max(1, Math.trunc(next)), totalPages.value))
}
</script>

<template>
  <div class="app-pagination" aria-label="分页导航">
    <span class="app-pagination__total">共{{ total }}条</span>

    <div class="app-pagination__pages">
      <button
        class="app-pagination__nav"
        type="button"
        :disabled="currentPage <= 1"
        aria-label="上一页"
        @click="updatePage(currentPage - 1)"
      >
        &lt;
      </button>

      <button
        v-for="pageNumber in pageButtons"
        :key="pageNumber"
        class="app-pagination__page"
        :class="{ 'is-active': currentPage === pageNumber }"
        type="button"
        :aria-current="currentPage === pageNumber ? 'page' : undefined"
        @click="updatePage(pageNumber)"
      >
        {{ pageNumber }}
      </button>

      <button
        class="app-pagination__nav"
        type="button"
        :disabled="currentPage >= totalPages"
        aria-label="下一页"
        @click="updatePage(currentPage + 1)"
      >
        &gt;
      </button>
    </div>

    <div class="app-pagination__tools">
      <label class="app-pagination__size">
        <select :value="pageSize" @change="updatePageSize">
          <option v-for="size in pageSizeOptions" :key="size" :value="size">{{ size }}条/页</option>
        </select>
      </label>

      <label class="app-pagination__goto">
        <span>前往</span>
        <input
          v-model="gotoInput"
          type="number"
          min="1"
          :max="totalPages"
          @blur="submitGoto"
          @keyup.enter="submitGoto"
        />
        <span>页</span>
      </label>
    </div>
  </div>
</template>

<style scoped>
.app-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  padding: 16px 18px;
  border-top: 1px solid var(--brand-border);
  background: #fff;
}

.app-pagination__total {
  color: #31445f;
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
}

.app-pagination__pages,
.app-pagination__tools,
.app-pagination__goto {
  display: flex;
  align-items: center;
}

.app-pagination__pages {
  gap: 8px;
}

.app-pagination__tools {
  gap: 18px;
  margin-left: auto;
}

.app-pagination__nav,
.app-pagination__page {
  min-width: 40px;
  height: 40px;
  padding: 0 12px;
  border: 1px solid #d7e2ef;
  border-radius: 8px;
  background: #fff;
  color: #355276;
  font-size: 15px;
  font-variant-numeric: tabular-nums;
  transition: border-color 0.18s ease, background-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}

.app-pagination__nav:hover:not(:disabled),
.app-pagination__page:hover:not(.is-active):not(:disabled) {
  border-color: #c4d3e5;
  background: #f8fbff;
}

.app-pagination__page.is-active {
  border-color: transparent;
  background: linear-gradient(135deg, #1677ff, #318bff);
  color: #fff;
  font-weight: 700;
  box-shadow: 0 8px 18px rgba(22, 119, 255, 0.2);
}

.app-pagination__nav:disabled,
.app-pagination__page:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.app-pagination__size select,
.app-pagination__goto input {
  min-height: 40px;
  border: 1px solid #d7e2ef;
  border-radius: 8px;
  background: #fff;
  color: #355276;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}

.app-pagination__size select {
  min-width: 128px;
  padding: 0 36px 0 14px;
}

.app-pagination__goto {
  gap: 10px;
  color: #526780;
  font-size: 14px;
  white-space: nowrap;
}

.app-pagination__goto input {
  width: 72px;
  padding: 0 12px;
  text-align: center;
}

@media (max-width: 820px) {
  .app-pagination {
    align-items: flex-start;
  }

  .app-pagination__tools {
    width: 100%;
    margin-left: 0;
    justify-content: space-between;
    flex-wrap: wrap;
  }

  .app-pagination__pages {
    width: 100%;
    flex-wrap: wrap;
  }
}
</style>
