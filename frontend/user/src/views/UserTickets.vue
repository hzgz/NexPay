<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { useRoute, useRouter } from 'vue-router'
import { createUserTicket, getUserTickets } from '../lib/api'

const route = useRoute()
const router = useRouter()

const ticketData = ref<Record<string, any>>({
  items: [],
  categories: [],
})

const ticketKeyword = ref('')

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
    ]
      .map((value) => String(value || '').toLowerCase())
      .join(' ')

    return haystack.includes(keyword)
  })
})

async function load() {
  const resp = await getUserTickets()
  if (resp.code === 0 && resp.data) {
    ticketData.value = resp.data
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

function openCreate() {
  router.push('/tickets/create')
}

function backToList() {
  router.push('/tickets/list')
}

onMounted(load)
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
          </div>
          <div v-for="item in filteredTickets" :key="item.ticket_no" class="table-row ticket-grid">
            <strong>{{ item.ticket_no }}</strong>
            <span>{{ item.category_name }}</span>
            <span>{{ item.title }}</span>
            <span>{{ item.priority }}</span>
            <span>{{ item.status }}</span>
            <span>{{ item.updated_at }}</span>
          </div>
          <p v-if="!filteredTickets.length" class="empty-note tickets-empty">
            {{ ticketKeyword.trim() ? '没有找到匹配的工单。' : '暂无工单记录。' }}
          </p>
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
  grid-template-columns: 1fr 0.8fr 1.4fr 0.7fr 0.8fr 1fr;
  gap: 12px;
  align-items: center;
}

.tickets-empty {
  padding: 18px 20px 20px;
}

@media (max-width: 1200px) {
  .ticket-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .tickets-toolbar {
    flex-direction: column;
    align-items: stretch;
    padding: 16px;
  }

  .ticket-search {
    width: 100%;
  }
}
</style>
