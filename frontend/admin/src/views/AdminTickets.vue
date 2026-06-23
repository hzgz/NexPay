<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute } from 'vue-router'
import {
  ADMIN_SESSION_UPDATED_EVENT,
  createAdminTicket,
  deleteAdminTicketCategory,
  getAdminSessionUser,
  getAdminTicketDetail,
  getAdminTickets,
  replyAdminTicket,
  resolveAdminAvatarUrl,
  saveAdminTicketCategory,
  updateAdminTicket,
} from '../lib/api'

const route = useRoute()
const data = ref<Record<string, any>>({
  items: [],
  categories: [],
})

const ticketDialog = ref(false)
const ticketLoading = ref(false)
const ticketReplyLoading = ref(false)
const createTicketDialog = ref(false)
const categoryDialog = ref(false)
const currentTicket = ref<Record<string, any> | null>(null)

const ticketForm = reactive({
  id: 0,
  status: '待处理',
  priority: '普通',
  content: '',
})

const createTicketForm = reactive({
  merchant_id: 0,
  merchant_name: '',
  category_id: 0,
  title: '',
  content: '',
  priority: '普通',
})

const categoryForm = reactive({
  id: 0,
  name: '',
  status: '启用',
  description: '',
})

const activeSection = computed<'tickets' | 'categories'>(() => (route.meta.section === 'categories' ? 'categories' : 'tickets'))
const currentMessages = computed(() => {
  const messages = currentTicket.value?.messages
  return Array.isArray(messages) ? messages : []
})

function createFallbackAvatar(letter: string) {
  const safeLetter = letter.trim().slice(0, 1).toUpperCase() || 'A'
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="#0d66ff"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="42" font-weight="700">${safeLetter}</text></svg>`
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
}

const sessionUser = ref<Record<string, any>>(getAdminSessionUser())
const adminDefaultAvatar = createFallbackAvatar('A')
const merchantDefaultAvatar = createFallbackAvatar('M')

function loadSessionUser() {
  sessionUser.value = getAdminSessionUser()
}

async function load() {
  const resp = await getAdminTickets()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
}

async function openTicket(item: Record<string, any>) {
  ticketDialog.value = true
  ticketLoading.value = true
  ticketForm.content = ''

  try {
    const resp = await getAdminTicketDetail(Number(item.id || 0))
    if (resp.code === 0 && resp.data?.ticket) {
      currentTicket.value = resp.data.ticket
      ticketForm.id = Number(resp.data.ticket.id || 0)
      ticketForm.status = String(resp.data.ticket.status || '待处理')
      ticketForm.priority = String(resp.data.ticket.priority || '普通')
    } else {
      currentTicket.value = item
      ticketForm.id = Number(item.id || 0)
      ticketForm.status = String(item.status || '待处理')
      ticketForm.priority = String(item.priority || '普通')
    }
  } finally {
    ticketLoading.value = false
  }
}

async function refreshCurrentTicket() {
  const id = Number(currentTicket.value?.id || 0)
  if (!id) return

  const resp = await getAdminTicketDetail(id)
  if (resp.code === 0 && resp.data?.ticket) {
    currentTicket.value = resp.data.ticket
    ticketForm.status = String(resp.data.ticket.status || ticketForm.status)
    ticketForm.priority = String(resp.data.ticket.priority || ticketForm.priority)
  }
}

async function submitTicketStatus() {
  const resp = await updateAdminTicket({
    id: ticketForm.id,
    status: ticketForm.status,
    priority: ticketForm.priority,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '工单已更新')
    await refreshCurrentTicket()
    await load()
  }
}

async function submitTicketReply() {
  const content = ticketForm.content.trim()
  if (!ticketForm.id || content === '') return

  ticketReplyLoading.value = true
  try {
    const resp = await replyAdminTicket({
      id: ticketForm.id,
      status: ticketForm.status,
      priority: ticketForm.priority,
      content,
    })
    if (resp.code === 0) {
      ElMessage.success(resp.message || '回复成功')
      ticketForm.content = ''
      if (resp.data?.ticket) {
        currentTicket.value = resp.data.ticket
      } else {
        await refreshCurrentTicket()
      }
      await load()
    }
  } finally {
    ticketReplyLoading.value = false
  }
}

function openCreateTicket() {
  Object.assign(createTicketForm, {
    merchant_id: 0,
    merchant_name: '',
    category_id: 0,
    title: '',
    content: '',
    priority: '普通',
  })
  createTicketDialog.value = true
}

function openCategory(item?: Record<string, any>) {
  Object.assign(categoryForm, {
    id: item?.id || 0,
    name: item?.name || '',
    status: item?.status || '启用',
    description: item?.description || '',
  })
  categoryDialog.value = true
}

async function submitCreateTicket() {
  const resp = await createAdminTicket(createTicketForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '工单创建成功')
    createTicketDialog.value = false
    await load()
  }
}

async function submitCategory() {
  const resp = await saveAdminTicketCategory(categoryForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '分类已保存')
    categoryDialog.value = false
    await load()
  }
}

async function removeCategory(item: Record<string, any>) {
  await ElMessageBox.confirm(`确认删除工单分类 ${item.name} 吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  const resp = await deleteAdminTicketCategory(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '分类已删除')
    await load()
  }
}

function closeTicketDialog() {
  ticketDialog.value = false
  currentTicket.value = null
  ticketForm.id = 0
  ticketForm.content = ''
}

function syncTicketDialog(value: boolean) {
  if (!value) {
    closeTicketDialog()
  }
}

function ticketStatusClass(status: string) {
  if (status === '已关闭') return 'danger'
  if (status === '已回复') return 'success'
  if (status === '处理中') return 'warning'
  return 'muted'
}

function messageAvatar(message: Record<string, any>) {
  if (message?.sender_type === 'admin') {
    const sessionAvatar = resolveAdminAvatarUrl(sessionUser.value.avatar, sessionUser.value.avatar_version)
    if (sessionAvatar) {
      return sessionAvatar
    }
  }

  return resolveAdminAvatarUrl(message?.sender_avatar)
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

  if (message?.sender_type === 'admin') {
    return adminDefaultAvatar
  }

  return merchantDefaultAvatar
}

onMounted(() => {
  load()
  loadSessionUser()
  window.addEventListener(ADMIN_SESSION_UPDATED_EVENT, loadSessionUser as EventListener)
})

onBeforeUnmount(() => {
  window.removeEventListener(ADMIN_SESSION_UPDATED_EVENT, loadSessionUser as EventListener)
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">{{ activeSection === 'tickets' ? '工单列表' : '工单分类' }}</h3>
              <p class="settings-block-copy">
                {{ activeSection === 'tickets' ? '工单支持查看、回复、状态更新与多轮会话记录。' : '名称、状态与说明集中维护，不影响分类绑定逻辑。' }}
              </p>
            </div>
            <div class="toolbar-actions">
              <button v-if="activeSection === 'tickets'" class="primary-btn" type="button" @click="openCreateTicket()">
                新增工单
              </button>
              <button v-else class="primary-btn" type="button" @click="openCategory()">
                新增分类
              </button>
            </div>
          </div>

          <div v-if="activeSection === 'tickets'" class="table-wrap">
            <div class="table-head ticket-grid">
              <span>工单号</span>
              <span>商户</span>
              <span>分类</span>
              <span>标题</span>
              <span>优先级</span>
              <span>状态</span>
              <span>更新时间</span>
              <span>操作</span>
            </div>
            <div v-for="item in data.items || []" :key="item.id" class="table-row ticket-grid">
              <strong>{{ item.ticket_no }}</strong>
              <span>{{ item.merchant_name }}</span>
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
          </div>

          <div v-else class="table-wrap">
            <div class="table-head category-grid">
              <span>分类名称</span>
              <span>状态</span>
              <span>说明</span>
              <span>操作</span>
            </div>
            <div v-for="item in data.categories || []" :key="item.id" class="table-row category-grid">
              <strong>{{ item.name }}</strong>
              <span>{{ item.status }}</span>
              <span>{{ item.description }}</span>
              <div class="inline-actions">
                <button class="link-action" type="button" @click="openCategory(item)">编辑</button>
                <button class="link-action" type="button" @click="removeCategory(item)">删除</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </article>

    <el-dialog :model-value="ticketDialog" title="工单会话" width="860px" @close="closeTicketDialog" @update:model-value="syncTicketDialog">
      <div v-loading="ticketLoading" class="ticket-detail">
        <template v-if="currentTicket">
          <div class="ticket-detail__meta">
            <div>
              <h3>{{ currentTicket.title }}</h3>
              <p>{{ currentTicket.ticket_no }} · {{ currentTicket.merchant_name }} · {{ currentTicket.category_name }}</p>
            </div>
            <span class="status-chip" :class="ticketStatusClass(String(ticketForm.status || currentTicket.status || ''))">
              {{ ticketForm.status || currentTicket.status }}
            </span>
          </div>

          <div class="ticket-detail__controls">
            <label class="field">
              <span class="field-label">状态</span>
              <select v-model="ticketForm.status">
                <option value="待处理">待处理</option>
                <option value="处理中">处理中</option>
                <option value="已回复">已回复</option>
                <option value="已关闭">已关闭</option>
              </select>
            </label>
            <label class="field">
              <span class="field-label">优先级</span>
              <select v-model="ticketForm.priority">
                <option value="普通">普通</option>
                <option value="高">高</option>
                <option value="紧急">紧急</option>
              </select>
            </label>
            <div class="ticket-detail__save">
              <button class="primary-soft-btn" type="button" @click="submitTicketStatus">保存状态</button>
            </div>
          </div>

          <div class="ticket-thread">
            <article
              v-for="message in currentMessages"
              :key="`${message.id}-${message.created_at}`"
              class="ticket-message"
              :class="{ 'is-self': message.sender_type === 'admin' }"
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
              <textarea v-model="ticketForm.content" rows="4" placeholder="回复商户进展、处理结果或继续追问" />
            </label>
            <div class="ticket-reply__actions">
              <button class="ghost-btn" type="button" @click="closeTicketDialog">关闭</button>
              <button class="primary-btn" :disabled="ticketReplyLoading || !ticketForm.content.trim()" type="button" @click="submitTicketReply">
                {{ ticketReplyLoading ? '发送中...' : '发送回复' }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </el-dialog>

    <el-dialog v-model="createTicketDialog" title="新增工单" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">工单信息</h3>
            <p class="settings-block-copy">录入商户、分类与内容信息后，直接创建新的会话工单。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">商户ID</span>
              <input v-model="createTicketForm.merchant_id" type="number" min="1" />
            </label>
            <label class="field">
              <span class="field-label">商户名称</span>
              <input v-model="createTicketForm.merchant_name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">工单分类</span>
              <select v-model="createTicketForm.category_id">
                <option :value="0">未分类</option>
                <option v-for="item in data.categories || []" :key="item.id" :value="item.id">{{ item.name }}</option>
              </select>
            </label>
            <label class="field">
              <span class="field-label">优先级</span>
              <select v-model="createTicketForm.priority">
                <option value="普通">普通</option>
                <option value="高">高</option>
                <option value="紧急">紧急</option>
              </select>
            </label>
            <label class="field field-span-2">
              <span class="field-label">工单标题</span>
              <input v-model="createTicketForm.title" type="text" />
            </label>
            <label class="field field-span-2">
              <span class="field-label">工单内容</span>
              <textarea v-model="createTicketForm.content" rows="5" />
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="createTicketDialog = false">取消</button>
        <button class="primary-btn" type="button" @click="submitCreateTicket">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="categoryDialog" title="工单分类" width="520px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">分类信息</h3>
            <p class="settings-block-copy">名称、状态与说明集中维护，不影响分类绑定逻辑。</p>
          </div>
          <div class="field-grid single">
            <label class="field">
              <span class="field-label">分类名称</span>
              <input v-model="categoryForm.name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">状态</span>
              <select v-model="categoryForm.status">
                <option value="启用">启用</option>
                <option value="停用">停用</option>
              </select>
            </label>
            <label class="field">
              <span class="field-label">说明</span>
              <textarea v-model="categoryForm.description" rows="4" />
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="categoryDialog = false">取消</button>
        <button class="primary-btn" type="button" @click="submitCategory">保存</button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.settings-block-head--split {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.ticket-grid {
  display: grid;
  grid-template-columns: 1fr 0.9fr 0.8fr 1.1fr 0.7fr 0.8fr 1fr 0.7fr;
  gap: 12px;
  align-items: center;
}

.category-grid {
  display: grid;
  grid-template-columns: 0.9fr 0.7fr 1.3fr 0.7fr;
  gap: 12px;
  align-items: center;
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

.ticket-detail__controls {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 180px)) auto;
  gap: 14px;
  align-items: end;
}

.ticket-detail__save {
  display: flex;
  justify-content: flex-end;
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
  max-width: min(78%, 560px);
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

.primary-soft-btn {
  border: 1px solid #c9dcff;
  background: #eaf3ff;
  color: #1668dc;
  border-radius: 10px;
  height: 36px;
  padding: 0 16px;
  cursor: pointer;
}

@media (max-width: 900px) {
  .settings-block-head--split,
  .ticket-detail__meta,
  .ticket-reply__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .ticket-detail__controls,
  .ticket-grid,
  .category-grid {
    grid-template-columns: 1fr;
  }

  .ticket-detail__save {
    justify-content: flex-start;
  }

  .ticket-message__body {
    width: 100%;
    max-width: none;
  }
}
</style>
