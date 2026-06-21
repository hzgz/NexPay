<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute } from 'vue-router'
import { createAdminTicket, deleteAdminTicketCategory, getAdminTickets, saveAdminTicketCategory, updateAdminTicket } from '../lib/api'

const route = useRoute()
const data = ref<Record<string, any>>({
  items: [],
  categories: [],
})

const ticketDialog = ref(false)
const createTicketDialog = ref(false)
const categoryDialog = ref(false)

const ticketForm = reactive({
  id: 0,
  status: '待处理',
  priority: '普通',
  reply: '',
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

async function load() {
  const resp = await getAdminTickets()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
}

function openTicket(item: Record<string, any>) {
  Object.assign(ticketForm, {
    id: item.id,
    status: item.status,
    priority: item.priority,
    reply: item.reply || '',
  })
  ticketDialog.value = true
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

async function submitTicket() {
  const resp = await updateAdminTicket(ticketForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '工单已更新')
    ticketDialog.value = false
    await load()
  }
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

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">{{ activeSection === 'tickets' ? '工单列表' : '工单分类' }}</h3>
              <p class="settings-block-copy">表格与弹窗展示收口到统一样式，工单处理和分类逻辑保持不动。</p>
            </div>
            <div class="toolbar-actions">
              <button
                v-if="activeSection === 'tickets'"
                class="primary-btn"
                type="button"
                @click="openCreateTicket()"
              >
                新增工单
              </button>
              <button
                v-else
                class="primary-btn"
                type="button"
                @click="openCategory()"
              >
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
          <span>{{ item.status }}</span>
          <span>{{ item.updated_at }}</span>
          <div class="inline-actions">
            <button class="link-action" type="button" @click="openTicket(item)">处理</button>
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

    <el-dialog v-model="ticketDialog" title="处理工单" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">处理内容</h3>
            <p class="settings-block-copy">状态、优先级和回复内容沿用原有工单处理规则。</p>
          </div>
          <div class="field-grid single">
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
            <label class="field">
              <span class="field-label">回复内容</span>
              <textarea v-model="ticketForm.reply" rows="5" />
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="ticketDialog = false">取消</button>
        <button class="primary-btn" type="button" @click="submitTicket">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="createTicketDialog" title="新增工单" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">工单信息</h3>
            <p class="settings-block-copy">录入商户、分类与内容信息后，仍按原有工单创建逻辑保存。</p>
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
  grid-template-columns: 1fr 0.8fr 0.8fr 1fr 0.7fr 0.7fr 1fr 0.5fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.category-grid {
  display: grid;
  grid-template-columns: 0.9fr 0.7fr 1.3fr 0.7fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

@media (max-width: 820px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }
}
</style>
