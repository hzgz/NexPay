<script setup lang="ts">
import { Bell, Close } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getUserDashboard } from '../lib/api'
import {
  isAnnouncementRead,
  markAnnouncementRead,
  markAnnouncementsRead,
  normalizeMerchantAnnouncement,
  readAnnouncementFingerprints,
  type MerchantAnnouncement,
} from '../lib/merchant-announcements'

const props = defineProps<{
  merchantId?: string | number | null
  mobile?: boolean
}>()

const drawerOpen = ref(false)
const loading = ref(false)
const announcements = ref<MerchantAnnouncement[]>([])
const activeAnnouncementId = ref<number | null>(null)
const readFingerprints = ref<Set<string>>(new Set())
let previousBodyOverflow = ''

const hasAnnouncements = computed(() => announcements.value.length > 0)
const unreadCount = computed(() =>
  announcements.value.reduce((total, item) => total + (isRead(item) ? 0 : 1), 0),
)
const unreadBadge = computed(() => `+${Math.min(unreadCount.value, 99)}`)
const unreadCopy = computed(() => (unreadCount.value ? `未读 ${unreadCount.value} 条` : '全部已读'))

function syncReadState() {
  readFingerprints.value = new Set(readAnnouncementFingerprints(props.merchantId))
}

function isRead(item: MerchantAnnouncement) {
  return isAnnouncementRead(item, readFingerprints.value)
}

function toggleDrawer() {
  drawerOpen.value = !drawerOpen.value
}

function closeDrawer() {
  drawerOpen.value = false
}

function selectDefaultAnnouncement() {
  const firstUnread = announcements.value.find((item) => !isRead(item))
  activeAnnouncementId.value = firstUnread?.id ?? announcements.value[0]?.id ?? null
}

function openAnnouncement(item: MerchantAnnouncement) {
  activeAnnouncementId.value = item.id
  if (!isRead(item)) {
    readFingerprints.value = markAnnouncementRead(props.merchantId, item)
  }
}

function markAllAsRead() {
  if (!announcements.value.length || unreadCount.value === 0) {
    return
  }

  readFingerprints.value = markAnnouncementsRead(props.merchantId, announcements.value)
  ElMessage.success('公告已全部设为已读')
}

async function loadAnnouncements() {
  if (loading.value) {
    return
  }

  loading.value = true

  try {
    const resp = await getUserDashboard()
    if (resp.code !== 0) {
      throw new Error(resp.message || '公告加载失败')
    }

    const items = Array.isArray(resp.data?.announcements)
      ? resp.data.announcements
          .map((item: Record<string, any>) => normalizeMerchantAnnouncement(item))
          .filter((item: MerchantAnnouncement | null): item is MerchantAnnouncement => Boolean(item))
      : []

    announcements.value = items
    syncReadState()

    if (!items.some((item) => item.id === activeAnnouncementId.value)) {
      selectDefaultAnnouncement()
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : '公告加载失败'
    if (drawerOpen.value) {
      ElMessage.error(message)
    }
  } finally {
    loading.value = false
  }
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && drawerOpen.value) {
    closeDrawer()
  }
}

watch(
  () => props.merchantId,
  () => {
    syncReadState()
    void loadAnnouncements()
  },
  { immediate: true },
)

watch(drawerOpen, (open) => {
  if (open) {
    previousBodyOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    if (!announcements.value.length) {
      void loadAnnouncements()
    }

    if (!activeAnnouncementId.value) {
      selectDefaultAnnouncement()
    }
    return
  }

  document.body.style.overflow = previousBodyOverflow
})

watch(
  () => props.mobile,
  () => {
    if (drawerOpen.value && !announcements.value.length) {
      void loadAnnouncements()
    }
  },
)

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.body.style.overflow = previousBodyOverflow
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <button class="announce-trigger" :class="{ 'has-unread': unreadCount > 0 }" type="button" aria-label="公告中心" @click="toggleDrawer">
    <span class="announce-trigger__main">
      <span class="announce-trigger__icon-wrap">
        <Bell class="announce-trigger__icon" />
      </span>
      <span v-if="!mobile" class="announce-trigger__label">公告</span>
    </span>
    <span v-if="unreadCount" class="announce-trigger__badge">{{ unreadBadge }}</span>
  </button>

  <teleport to="body">
    <div v-if="drawerOpen" class="announce-layer" @click.self="closeDrawer">
      <aside class="announce-drawer" :class="{ 'announce-drawer--mobile': mobile }">
        <section class="announce-center">
          <header class="announce-center__head">
            <div class="announce-center__intro">
              <h3>公告中心</h3>
              <p>{{ unreadCopy }}</p>
            </div>

            <div class="announce-center__actions">
              <button
                class="soft-btn announce-center__mark-all"
                type="button"
                :disabled="!unreadCount"
                @click="markAllAsRead"
              >
                全部已读
              </button>

              <button class="announce-center__close" type="button" aria-label="关闭公告中心" @click="closeDrawer">
                <Close class="announce-center__close-icon" />
              </button>
            </div>
          </header>

          <div v-if="loading && !hasAnnouncements" class="announce-empty">公告加载中...</div>
          <div v-else-if="!hasAnnouncements" class="announce-empty">暂无公告</div>
          <div v-else class="announce-list">
            <article
              v-for="item in announcements"
              :key="item.id"
              class="announce-item"
              :class="{ active: activeAnnouncementId === item.id, unread: !isRead(item) }"
            >
              <button class="announce-item__head" type="button" @click="openAnnouncement(item)">
                <div class="announce-item__title">
                  <span v-if="!isRead(item)" class="announce-item__dot" />
                  <strong>{{ item.title }}</strong>
                </div>
                <span class="announce-item__state" :class="{ unread: !isRead(item) }">
                  {{ isRead(item) ? '已读' : '未读' }}
                </span>
              </button>

              <p v-if="item.summary" class="announce-item__summary">{{ item.summary }}</p>

              <div class="announce-item__meta">
                <span>{{ item.created_at || '系统公告' }}</span>
              </div>

              <div v-if="activeAnnouncementId === item.id" class="announce-item__detail">
                <p>{{ item.content || item.summary }}</p>
              </div>
            </article>
          </div>
        </section>
      </aside>
    </div>
  </teleport>
</template>

<style scoped>
.announce-trigger {
  position: relative;
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  padding: 0 8px 0 0;
  border: 0;
  background: transparent;
  color: #24436c;
}

.announce-trigger:hover {
  color: var(--brand-primary);
}

.announce-trigger__main {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.announce-trigger__icon-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  flex: none;
}

.announce-trigger__icon {
  width: 17px;
  height: 17px;
}

.announce-trigger__label {
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}

.announce-trigger__badge {
  position: absolute;
  top: -8px;
  right: -12px;
  min-width: 24px;
  height: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 5px;
  border-radius: 999px;
  background: linear-gradient(135deg, #0d66ff, #0b55d7);
  box-shadow: 0 0 0 2px #fff;
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  line-height: 1;
}

.announce-trigger.has-unread {
  padding-right: 18px;
}

.announce-layer {
  position: fixed;
  inset: 0;
  display: flex;
  justify-content: flex-end;
  background: rgba(15, 23, 42, 0.22);
  z-index: 120;
}

.announce-drawer {
  width: min(420px, calc(100vw - 24px));
  height: 100vh;
  background: #fff;
  box-shadow: -24px 0 48px rgba(17, 35, 62, 0.12);
}

.announce-drawer--mobile {
  width: 100vw;
}

.announce-center {
  height: 100%;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
}

.announce-center__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 24px 22px 18px;
  border-bottom: 1px solid var(--brand-border);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(246, 250, 255, 0.96));
}

.announce-center__intro {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.announce-center__intro h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
}

.announce-center__intro p {
  margin: 0;
  color: var(--brand-subtle);
  font-size: 12px;
}

.announce-center__actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.announce-center__mark-all {
  min-height: 38px;
  padding-inline: 14px;
  white-space: nowrap;
}

.announce-center__close {
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--brand-border);
  background: #fff;
  color: var(--brand-text-soft);
}

.announce-center__close-icon {
  width: 15px;
  height: 15px;
}

.announce-empty {
  display: grid;
  place-items: center;
  padding: 40px 22px;
  color: var(--brand-subtle);
  font-size: 13px;
}

.announce-list {
  overflow-y: auto;
}

.announce-item {
  padding: 16px 22px 18px;
  border-bottom: 1px solid var(--brand-border);
  background: rgba(255, 255, 255, 0.9);
}

.announce-item.active {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 250, 255, 0.98));
}

.announce-item.unread {
  border-left: 3px solid rgba(13, 102, 255, 0.9);
  padding-left: 19px;
}

.announce-item__head {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
}

.announce-item__title {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.announce-item__title strong {
  font-size: 14px;
  font-weight: 700;
  text-align: left;
}

.announce-item__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--brand-primary);
  flex: none;
}

.announce-item__state {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 50px;
  min-height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  background: rgba(120, 135, 156, 0.12);
  color: var(--brand-subtle);
  font-size: 11px;
  font-weight: 700;
  flex: none;
}

.announce-item__state.unread {
  background: rgba(13, 102, 255, 0.1);
  color: var(--brand-primary);
}

.announce-item__summary,
.announce-item__detail p {
  margin: 10px 0 0;
  color: var(--brand-text-soft);
  line-height: 1.75;
}

.announce-item__summary {
  font-size: 12px;
}

.announce-item__meta {
  margin-top: 8px;
  color: var(--brand-subtle);
  font-size: 11px;
}

.announce-item__detail {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed rgba(13, 102, 255, 0.16);
}

@media (max-width: 900px) {
  .announce-trigger {
    min-width: 30px;
    min-height: 30px;
    justify-content: center;
    padding-right: 0;
  }

  .announce-trigger__main {
    gap: 0;
  }

  .announce-trigger__badge {
    right: -14px;
  }

  .announce-layer {
    justify-content: stretch;
  }

  .announce-center__head {
    padding: 20px 16px 16px;
  }

  .announce-center__actions {
    gap: 8px;
  }

  .announce-center__mark-all {
    min-height: 36px;
    padding-inline: 12px;
  }

  .announce-center__close {
    width: 36px;
    height: 36px;
  }

  .announce-item {
    padding: 14px 16px 16px;
  }

  .announce-item.unread {
    padding-left: 13px;
  }
}
</style>
