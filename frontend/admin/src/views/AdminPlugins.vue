<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { RefreshRight, Search } from '@element-plus/icons-vue'
import { useRoute } from 'vue-router'
import {
  deleteAdminPaymentMethod,
  deleteAdminPlugin,
  getAdminPlugins,
  saveAdminPaymentMethod,
  scanAdminPlugins,
  toggleAdminPaymentMethod,
  toggleAdminPlugin,
} from '../lib/api'

type PaymentMethod = {
  code: string
  name: string
  category?: string
  settlement?: string
  status?: string
  status_code?: number
}

type PaymentPlugin = {
  code: string
  name: string
  version?: string
  status?: string
  status_code?: number
  description?: string
  kind?: string
  payment_methods?: string[]
  developer?: string
}

const route = useRoute()
const loading = ref(false)
const methodDialog = ref(false)
const pluginKeyword = ref('')
const pluginSearch = ref('')

const data = ref<{
  methods: PaymentMethod[]
  items: PaymentPlugin[]
}>({
  methods: [],
  items: [],
})

const methodForm = reactive({
  code: '',
  name: '',
  category: '',
  settlement: '',
})

const activeSection = computed<'methods' | 'plugins'>(() => (
  route.meta.section === 'plugins' ? 'plugins' : 'methods'
))

const methodMap = computed(() => {
  const map: Record<string, PaymentMethod> = {}
  for (const item of data.value.methods || []) {
    if (item.code) map[item.code] = item
  }
  return map
})

const filteredPlugins = computed(() => {
  const keyword = pluginSearch.value.trim().toLowerCase()
  if (!keyword) return data.value.items || []

  return (data.value.items || []).filter((item) => {
    const text = [
      item.name,
      item.code,
      item.developer,
      ...(item.payment_methods || []),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return text.includes(keyword)
  })
})

async function load() {
  loading.value = true
  const resp = await getAdminPlugins()
  if (resp.code === 0 && resp.data) {
    data.value = {
      methods: resp.data.methods || [],
      items: resp.data.items || [],
    }
  }
  loading.value = false
}

function openMethod(item?: PaymentMethod) {
  Object.assign(methodForm, {
    code: item?.code || '',
    name: item?.name || '',
    category: item?.category || '',
    settlement: item?.settlement || '',
  })
  methodDialog.value = true
}

async function submitMethod() {
  const resp = await saveAdminPaymentMethod(methodForm)
  if (resp.code === 0) {
    ElMessage.success('已保存')
    methodDialog.value = false
    await load()
  }
}

async function toggleMethod(item: PaymentMethod) {
  const resp = await toggleAdminPaymentMethod(item.code, Number(item.status_code) === 1 ? 0 : 1)
  if (resp.code === 0) {
    ElMessage.success('已更新')
    await load()
  }
}

async function removeMethod(item: PaymentMethod) {
  try {
    await ElMessageBox.confirm(`确认删除支付方式 ${item.name} 吗？`, '删除确认', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }

  const resp = await deleteAdminPaymentMethod(item.code)
  if (resp.code === 0) {
    ElMessage.success('已删除')
    await load()
  }
}

async function togglePlugin(item: PaymentPlugin) {
  const resp = await toggleAdminPlugin(item.code, Number(item.status_code) === 1 ? 0 : 1)
  if (resp.code === 0) {
    ElMessage.success('已更新')
    await load()
  }
}

async function removePlugin(item: PaymentPlugin) {
  try {
    await ElMessageBox.confirm(`确认删除支付插件 ${item.name} 吗？`, '删除确认', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }

  const resp = await deleteAdminPlugin(item.code)
  if (resp.code === 0) {
    ElMessage.success('已删除')
    await load()
  }
}

async function refreshPlugins() {
  const resp = await scanAdminPlugins()
  if (resp.code === 0) {
    ElMessage.success('已刷新')
    await load()
  }
}

function runPluginSearch() {
  pluginSearch.value = pluginKeyword.value.trim()
}

function resetPluginSearch() {
  pluginKeyword.value = ''
  pluginSearch.value = ''
}

function statusClass(statusCode?: number) {
  return {
    'status-chip': true,
    success: Number(statusCode) === 1,
    warning: Number(statusCode) !== 1,
  }
}

function statusText(statusCode?: number) {
  return Number(statusCode) === 1 ? '启用' : '关闭'
}

function methodIcon(code: string) {
  const normalized = String(code || '').toLowerCase()
  const map: Record<string, string> = {
    alipay: '/admin/payment-icons/alipay.png',
    wxpay: '/admin/payment-icons/wechat.png',
    qqpay: '/admin/payment-icons/qqpay.png',
    bank: '/admin/payment-icons/unionpay.png',
    jdpay: '/admin/payment-icons/jdpay.png',
    paypal: '/admin/payment-icons/paypal.png',
    douyinpay: '/admin/payment-icons/douyin.png',
    usdttrc20: '/admin/payment-icons/usdt.png',
    usdtaptos: '/admin/payment-icons/usdt.png',
    usdtpolygon: '/admin/payment-icons/usdt.png',
    trx: '/admin/payment-icons/trx.png',
  }

  return map[normalized] || null
}

function methodName(code: string) {
  return methodMap.value[code]?.name || code
}

function methodCategoryLabel(category?: string) {
  const normalized = String(category || '').trim().toLowerCase()

  if (normalized === 'qr pay') return '码支付'
  if (normalized === 'aggregate pay') return '聚合支付'
  if (normalized === 'on-chain pay') return '链上支付'
  if (normalized === 'international pay') return '国际支付'

  return category || '-'
}

function displayMethodNames(plugin: PaymentPlugin) {
  return (plugin.payment_methods || []).map((code) => methodName(code))
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div v-if="activeSection === 'methods'" class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">支付方式列表</h3>
              <p class="settings-block-copy">系统共有 {{ data.methods.length }} 个支付方式，展示层更统一，启停逻辑保持不变。</p>
            </div>
            <div class="toolbar-actions">
              <button class="primary-btn" @click="openMethod()">新增</button>
            </div>
          </div>
          <div class="table-wrap admin-table">
            <div class="table-head payment-grid">
              <span>调用值</span>
              <span>名称</span>
              <span>分类</span>
              <span>状态</span>
              <span>操作</span>
            </div>
            <div v-for="item in data.methods || []" :key="item.code" class="table-row payment-grid">
              <strong class="code-text">{{ item.code }}</strong>
              <div class="method-name-cell">
                <div v-if="methodIcon(item.code)" class="method-icon-shell">
                  <img :src="methodIcon(item.code) || undefined" :alt="item.name" class="method-icon" />
                </div>
                <div v-else class="method-icon-fallback">
                  {{ item.name.slice(0, 1) }}
                </div>
                <span>{{ item.name }}</span>
              </div>
              <span>{{ methodCategoryLabel(item.category) }}</span>
              <span :class="statusClass(item.status_code)">{{ statusText(item.status_code) }}</span>
              <div class="action-row">
                <button class="table-btn" @click="openMethod(item)">编辑</button>
                <button
                  :class="Number(item.status_code) === 1 ? 'table-btn warn' : 'table-btn success'"
                  @click="toggleMethod(item)"
                >
                  {{ Number(item.status_code) === 1 ? '关闭' : '启用' }}
                </button>
                <button class="table-btn danger" @click="removeMethod(item)">删除</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head plugin-toolbar-head">
            <div>
              <h3 class="settings-block-title">插件目录</h3>
              <p class="settings-block-copy">支持检索插件、刷新扫描结果，并维护插件启用状态。</p>
            </div>
            <div class="plugin-toolbar">
              <div class="plugin-search">
                <el-icon class="search-icon"><Search /></el-icon>
                <input
                  v-model="pluginKeyword"
                  type="text"
                  placeholder="搜索插件名称 / 标识"
                  @keyup.enter="runPluginSearch"
                />
              </div>
              <div class="plugin-toolbar-actions">
                <button class="primary-btn" @click="runPluginSearch">搜索</button>
                <button class="ghost-btn" @click="resetPluginSearch">重置</button>
              </div>
              <button class="ghost-btn refresh-btn" :disabled="loading" @click="refreshPlugins">
                <el-icon><RefreshRight /></el-icon>
                刷新
              </button>
            </div>
          </div>
          <div class="table-wrap admin-table">
            <div class="table-head plugin-grid">
              <span>插件名称</span>
              <span>插件标识</span>
              <span>包含的支付方式</span>
              <span>开发者</span>
              <span>版本号</span>
              <span>状态</span>
              <span>操作</span>
            </div>
            <div v-for="item in filteredPlugins" :key="item.code" class="table-row plugin-grid">
              <div class="plugin-name-cell">
                <strong>{{ item.name }}</strong>
              </div>
              <span class="code-text code-muted">{{ item.code }}</span>
              <div class="tag-cell">
                <span v-for="name in displayMethodNames(item)" :key="`${item.code}-${name}`" class="tiny-chip">
                  {{ name }}
                </span>
                <span v-if="!displayMethodNames(item).length" class="muted-text">-</span>
              </div>
              <span>{{ item.developer || '官方' }}</span>
              <span>{{ item.version || '-' }}</span>
              <span :class="statusClass(item.status_code)">{{ statusText(item.status_code) }}</span>
              <div class="action-row">
                <button
                  :class="Number(item.status_code) === 1 ? 'table-btn warn' : 'table-btn success'"
                  @click="togglePlugin(item)"
                >
                  {{ Number(item.status_code) === 1 ? '关闭' : '启用' }}
                </button>
                <button class="table-btn danger" @click="removePlugin(item)">删除</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </article>

    <el-dialog v-model="methodDialog" title="支付方式信息" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">支付方式配置</h3>
            <p class="settings-block-copy">这里只调整录入布局，字段含义与调用值规则不变。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">调用值</span>
              <input v-model="methodForm.code" type="text" placeholder="alipay / wxpay / usdttrc20" />
            </label>
            <label class="field">
              <span class="field-label">名称</span>
              <input v-model="methodForm.name" type="text" placeholder="支付宝 / 微信支付 / USDT-TRC20" />
            </label>
            <label class="field">
              <span class="field-label">分类</span>
              <input v-model="methodForm.category" type="text" placeholder="聚合支付 / 链上支付 / 国际支付" />
            </label>
            <label class="field">
              <span class="field-label">结算方式</span>
              <input v-model="methodForm.settlement" type="text" placeholder="T+0 / 链上实时" />
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" @click="methodDialog = false">取消</button>
        <button class="primary-btn" @click="submitMethod">保存</button>
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

.admin-table {
  border-top: 1px solid var(--brand-border);
}

.payment-grid {
  display: grid;
  grid-template-columns: 0.8fr 1.8fr 0.9fr 0.7fr 1fr;
  gap: 18px;
  align-items: center;
}

.plugin-grid {
  display: grid;
  grid-template-columns: 1.25fr 0.85fr 1.4fr 0.72fr 0.48fr 0.48fr 0.9fr;
  gap: 18px;
  align-items: center;
  min-width: 0;
}

.code-text {
  color: #41536d;
  font-weight: 700;
}

.code-muted {
  font-weight: 600;
}

.method-name-cell,
.plugin-name-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.plugin-name-cell {
  align-items: flex-start;
}

.method-icon-shell,
.method-icon-fallback {
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 26px;
}

.method-icon {
  width: 24px;
  height: 24px;
  object-fit: contain;
}

.method-icon-fallback {
  border-radius: 8px;
  background: #edf4ff;
  color: #1677ff;
  font-size: 12px;
  font-weight: 700;
}

.plugin-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: nowrap;
  width: 100%;
}

.plugin-toolbar-head {
  align-items: flex-start;
  justify-content: space-between;
}

.plugin-search {
  position: relative;
  flex: 1 1 360px;
  min-width: 280px;
  max-width: 520px;
}

.plugin-search input {
  padding-left: 38px;
  background: #fff;
}

.search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #8ca1bd;
  font-size: 16px;
}

.plugin-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 0 0 auto;
}

.refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-left: 0;
  flex: 0 0 auto;
}

.tag-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.tiny-chip {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  border: 1px solid rgba(22, 119, 255, 0.14);
  background: #f5f9ff;
  color: #2a4c7c;
  font-size: 11px;
}

.muted-text {
  color: #7d8da4;
}

.action-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.table-btn {
  min-width: 60px;
  min-height: 32px;
  padding: 0 12px;
  border-radius: 9px;
  border: 1px solid rgba(209, 219, 232, 0.95);
  background: #fff;
  color: #44556d;
}

.table-btn.success {
  border-color: rgba(103, 194, 58, 0.18);
  background: #67c23a;
  color: #fff;
}

.table-btn.warn {
  border-color: rgba(230, 162, 60, 0.18);
  background: #e6a23c;
  color: #fff;
}

.table-btn.danger {
  border-color: rgba(245, 108, 108, 0.18);
  background: rgba(245, 108, 108, 0.08);
  color: #f56c6c;
}

@media (max-width: 1320px) {
  .payment-grid,
  .plugin-grid {
    min-width: 0;
  }
}

@media (max-width: 820px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }

  .plugin-toolbar-head {
    display: grid;
    gap: 16px;
  }

  .plugin-toolbar {
    flex-wrap: wrap;
  }

  .plugin-search {
    min-width: 100%;
    max-width: none;
  }

}
</style>
