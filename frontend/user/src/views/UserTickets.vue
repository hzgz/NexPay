<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { useRoute, useRouter } from 'vue-router'
import AppPagination from '../components/AppPagination.vue'
import {
  createUserTicket,
  getUserSessionUser,
  getUserTicketDetail,
  getUserTickets,
  replyUserTicket,
  resolveUserAvatarUrl,
  USER_SESSION_UPDATED_EVENT,
} from '../lib/api'
import { resetPagination, usePagination } from '../lib/pagination'

const route = useRoute()
const router = useRouter()

const ticketData = ref<Record<string, any>>({
  items: [],
  categories: [],
})

const ticketKeyword = ref('')
const detailDialog = ref(false)
const detailLoading = ref(false)
const replyLoading = ref(false)
const currentTicket = ref<Record<string, any> | null>(null)
const replyForm = reactive({
  content: '',
})

const form = reactive({
  category_id: 0,
  title: '',
  priority: '普通',
  content: '',
})

const activeSection = computed<'list' | 'create'>(() => (route.meta.section === 'create' ? 'create' : 'list'))

const filteredTickets = computed(() => {
  const keyword = ticketKeyword.value.trim().toLowerCase()
  const items = Array.isArray(ticketData.value.items) ? ticketData.value.items : []

  if (!keyword) return items

  return items.filter((item) => {
    const haystack = [
      item.ticket_no,
      item.category_name,
      item.title,
      item.priority,
      item.status,
      item.updated_at,
      item.last_message,
    ]
      .map((value) => String(value || '').toLowerCase())
      .join(' ')

    return haystack.includes(keyword)
  })
})

const { pagination, total, pagedRows } = usePagination(() => filteredTickets.value, 20)

const currentMessages = computed(() => {
  const messages = currentTicket.value?.messages
  return Array.isArray(messages) ? messages : []
})

function createFallbackAvatar(letter: string) {
  const safeLetter = letter.trim().slice(0, 1).toUpperCase() || 'M'
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="#0d66ff"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="42" font-weight="700">${safeLetter}</text></svg>`
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
}

const sessionUser = ref<Record<string, any>>(getUserSessionUser())
const merchantDefaultAvatar = createFallbackAvatar('M')
const adminDefaultAvatar = createFallbackAvatar('A')

function loadSessionUser() {
  sessionUser.value = getUserSessionUser()
}

async function load() {
  const resp = await getUserTickets()
  if (resp.code === 0 && resp.data) {
    ticketData.value = resp.data
    resetPagination(pagination)
    if (!form.category_id && resp.data.categories?.length) {
      form.category_id = resp.data.categories[0].id
    }
  }
}

async function submitTicket() {
  const resp = await createUserTicket(form)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '工单已提交')
    form.title = ''
    form.content = ''
    form.priority = '普通'
    await load()
    router.push('/tickets/list')
  }
}

async function openTicket(item: Record<string, any>) {
  detailLoading.value = true
  detailDialog.value = true
  replyForm.content = ''

  try {
    const resp = await getUserTicketDetail(Number(item.id || 0))
    if (resp.code === 0 && resp.data?.ticket) {
      currentTicket.value = resp.data.ticket
    } else {
      currentTicket.value = item
    }
  } finally {
    detailLoading.value = false
  }
}

async function refreshCurrentTicket() {
  const ticketId = Number(currentTicket.value?.id || 0)
  if (!ticketId) return

  const resp = await getUserTicketDetail(ticketId)
  if (resp.code === 0 && resp.data?.ticket) {
    currentTicket.value = resp.data.ticket
  }
}

async function submitReply() {
  const content = replyForm.content.trim()
  const ticketId = Number(currentTicket.value?.id || 0)
  if (!ticketId || content === '') return

  replyLoading.value = true
  try {
    const resp = await replyUserTicket({
      id: ticketId,
      content,
    })

    if (resp.code === 0) {
      ElMessage.success(resp.message || '回复成功')
      replyForm.content = ''
      if (resp.data?.ticket) {
        currentTicket.value = resp.data.ticket
      } else {
        await refreshCurrentTicket()
      }
      await load()
    }
  } finally {
    replyLoading.value = false
  }
}

function ticketStatusClass(status: string) {
  if (status === '已关闭') return 'danger'
  if (status === '已回复') return 'success'
  if (status === '处理中') return 'warning'
  return 'muted'
}

function openCreate() {
  router.push('/tickets/create')
}

function backToList() {
  router.push('/tickets/list')
}

function closeDetail() {
  detailDialog.value = false
  currentTicket.value = null
  replyForm.content = ''
}

function syncDetailDialog(value: boolean) {
  if (!value) {
    closeDetail()
  }
}

function messageAvatar(message: Record<string, any>) {
  if (message?.sender_type === 'merchant') {
    const sessionAvatar = resolveUserAvatarUrl(sessionUser.value.avatar, sessionUser.value.avatar_version)
    if (sessionAvatar) {
      return sessionAvatar
    }
  }

  return resolveUserAvatarUrl(message?.sender_avatar)
}

function messageInitial(message: Record<string, any>) {
  const name = String(message?.sender_name || '').trim()
  return name ? name.slice(0, 1).toUpperCase() : '?'
}

function messageAvatarSrc(message: Record<string, any>) {
  const avatar = messageAvatar(message)
  if (avatar) {
    return avatar
  }

  if (message?.sender_type === 'merchant') {
    return merchantDefaultAvatar
  }

  return adminDefaultAvatar
}

onMounted(() => {
  load()
  loadSessionUser()
  window.addEventListener(USER_SESSION_UPDATED_EVENT, loadSessionUser as EventListener)
})

onBeforeUnmount(() => {
  window.removeEventListener(USER_SESSION_UPDATED_EVENT, loadSessionUser as EventListener)
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <template v-if="activeSection === 'list'">
        <div class="tickets-toolbar">
          <label class="ticket-search">
            <el-icon class="ticket-search__icon"><Search /></el-icon>
            <input v-model="ticketKeyword" type="text" placeholder="搜索工单号 / 标题 / 分类 / 状态" />
          </label>
          <button class="primary-btn" type="button" @click="openCreate">提交工单</button>
        </div>

        <div class="table-wrap settings-workspace__body">
          <div class="table-head ticket-grid">
            <span>工单号</span>
            <span>分类</span>
            <span>标题</span>
            <span>优先级</span>
            <span>状态</span>
            <span>更新时间</span>
            <span>操作</span>
          </div>
          <div v-for="item in pagedRows" :key="item.ticket_no" class="table-row ticket-grid">
            <strong>{{ item.ticket_no }}</strong>
            <span>{{ item.category_name }}</span>
            <span>{{ item.title }}</span>
            <span>{{ item.priority }}</span>
            <span><span class="status-chip" :class="ticketStatusClass(String(item.status || ''))">{{ item.status }}</span></span>
            <span>{{ item.updated_at }}</span>
            <div class="inline-actions">
              <button class="link-action" type="button" @click="openTicket(item)">查看</button>
              <button class="link-action" type="button" @click="openTicket(item)">回复</button>
            </div>
          </div>
          <p v-if="!filteredTickets.length" class="empty-note tickets-empty">
            {{ ticketKeyword.trim() ? '没有找到匹配的工单。' : '暂无工单记录。' }}
          </p>
          <AppPagination
            :total="total"
            :page="pagination.page"
            :page-size="pagination.pageSize"
            @update:page="pagination.page = $event"
            @update:page-size="pagination.pageSize = $event"
          />
        </div>
      </template>

      <template v-else>
        <div class="tickets-toolbar tickets-toolbar--create">
          <button class="ghost-btn" type="button" @click="backToList">返回工单列表</button>
          <button class="primary-btn" type="button" @click="submitTicket">提交工单</button>
        </div>

        <div class="settings-block settings-workspace__body">
          <div class="settings-block-head">
            <h3 class="settings-block-title">提交工单</h3>
            <p class="settings-block-copy">请填写分类、优先级和问题描述后提交工单。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">工单分类</span>
              <select v-model="form.category_id">
                <option v-for="item in ticketData.categories || []" :key="item.id" :value="item.id">{{ item.name }}</option>
              </select>
            </label>
            <label class="field">
              <span class="field-label">优先级</span>
              <select v-model="form.priority">
                <option value="普通">普通</option>
                <option value="高">高</option>
                <option value="紧急">紧急</option>
              </select>
            </label>
            <label class="field field-span-2">
              <span class="field-label">工单标题</span>
              <input v-model="form.title" type="text" />
            </label>
            <label class="field field-span-2">
              <span class="field-label">问题描述</span>
              <textarea v-model="form.content" rows="6" />
            </label>
          </div>
        </div>
      </template>
    </article>

    <el-dialog :model-value="detailDialog" title="工单会话" width="760px" @close="closeDetail" @update:model-value="syncDetailDialog">
      <div v-loading="detailLoading" class="ticket-detail">
        <template v-if="currentTicket">
          <div class="ticket-detail__meta">
            <div>
              <h3>{{ currentTicket.title }}</h3>
              <p>{{ currentTicket.ticket_no }} · {{ currentTicket.category_name }} · {{ currentTicket.priority }}</p>
            </div>
            <span class="status-chip" :class="ticketStatusClass(String(currentTicket.status || ''))">{{ currentTicket.status }}</span>
          </div>

          <div class="ticket-thread">
            <article
              v-for="message in currentMessages"
              :key="`${message.id}-${message.created_at}`"
              class="ticket-message"
              :class="{ 'is-self': message.sender_type === 'merchant' }"
            >
              <div class="ticket-message__avatar">
                <img v-if="messageAvatarSrc(message)" :src="messageAvatarSrc(message)" :alt="message.sender_name" />
                <span v-else>{{ messageInitial(message) }}</span>
              </div>
              <div class="ticket-message__body">
                <div class="ticket-message__head">
                  <strong class="ticket-message__name">{{ message.sender_name }}</strong>
                  <span class="ticket-message__time">{{ message.created_at }}</span>
                </div>
                <div class="ticket-message__bubble">{{ message.content }}</div>
              </div>
            </article>
          </div>

          <div class="ticket-reply">
            <label class="field">
              <span class="field-label">回复内容</span>
              <textarea v-model="replyForm.content" rows="4" placeholder="继续补充问题、反馈结果或追问进展" />
            </label>
            <div class="ticket-reply__actions">
              <button class="ghost-btn" type="button" @click="closeDetail">关闭</button>
              <button class="primary-btn" :disabled="replyLoading || !replyForm.content.trim()" type="button" @click="submitReply">
                {{ replyLoading ? '发送中...' : '发送回复' }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </el-dialog>
  </section>
</template>

<style scoped>
.tickets-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.tickets-toolbar--create {
  justify-content: flex-end;
}

.ticket-search {
  position: relative;
  width: min(360px, 100%);
}

.ticket-search input {
  padding-left: 40px;
  background: #fff;
}

.ticket-search__icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #8ca1bd;
  font-size: 15px;
}

.ticket-grid {
  display: grid;
  grid-template-columns: 1fr 0.8fr 1.3fr 0.7fr 0.8fr 1fr 0.7fr;
  gap: 12px;
  align-items: center;
}

.tickets-empty {
  padding: 18px 20px 20px;
}

.ticket-detail {
  display: grid;
  gap: 16px;
}

.ticket-detail__meta {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid #e4edf8;
}

.ticket-detail__meta h3 {
  margin: 0;
  font-size: 16px;
}

.ticket-detail__meta p {
  margin: 8px 0 0;
  color: #72829b;
  font-size: 12px;
}

.ticket-thread {
  display: grid;
  gap: 14px;
  max-height: 420px;
  overflow-y: auto;
  padding-right: 4px;
}

.ticket-message {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.ticket-message.is-self {
  justify-content: flex-end;
}

.ticket-message__avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  overflow: hidden;
  background: #eaf3ff;
  color: #1668dc;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  flex: 0 0 38px;
}

.ticket-message__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.ticket-message__body {
  display: grid;
  gap: 6px;
  max-width: min(78%, 520px);
}

.ticket-message.is-self .ticket-message__body {
  align-items: flex-start;
}

.ticket-message__head {
  display: grid;
  gap: 2px;
  color: #72829b;
  font-size: 12px;
}

.ticket-message__name {
  color: #20344f;
  font-size: 13px;
  line-height: 1.3;
}

.ticket-message__time {
  line-height: 1.3;
}

.ticket-message__bubble {
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid #dce7f5;
  background: #f7fbff;
  color: #20344f;
  line-height: 1.7;
  white-space: pre-wrap;
  word-break: break-word;
}

.ticket-message.is-self .ticket-message__bubble {
  background: #1668dc;
  border-color: #1668dc;
  color: #fff;
}

.ticket-reply {
  display: grid;
  gap: 14px;
  padding-top: 4px;
}

.ticket-reply__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

@media (max-width: 1200px) {
  .ticket-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .tickets-toolbar,
  .ticket-detail__meta,
  .ticket-reply__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .tickets-toolbar {
    padding: 16px;
  }

  .ticket-search,
  .ticket-message__body {
    width: 100%;
    max-width: none;
  }
}
</style>
