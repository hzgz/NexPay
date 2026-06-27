<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute } from 'vue-router'
import AppPagination from '../components/AppPagination.vue'
import {
  createAdminMerchant,
  deleteAdminMerchantGroup,
  getAdminMerchants,
  reviewAdminMerchant,
  reviewAdminMerchantRealname,
  saveAdminMerchantGroup,
} from '../lib/api'
import { resetPagination, usePagination } from '../lib/pagination'

type MerchantItem = {
  id: number
  name: string
  merchant_no?: string
  appid?: string
  group_name?: string
  contact_name?: string
  email?: string
  balance?: string
  rate?: string
  status?: string
  status_code?: number
  audit_status?: string
  register_fee_status?: string
  register_fee_amount?: string
  register_fee_trade_no?: string
  register_fee_paid_at?: string
  audit_reason?: string
  audited_at?: string
  audited_by?: string
  realname_status?: string
  realname_status_label?: string
  realname_real_name?: string
  realname_id_card?: string
  realname_provider?: string
  realname_result?: string
  realname_last_error?: string
  realname_submitted_at?: string
  realname_reviewed_at?: string
  realname_reviewed_by?: string
  realname_review_reason?: string
  registered_at?: string
}

type MerchantGroup = {
  id: number
  name: string
  code: string
  rate_discount?: string
  daily_limit?: string
  status?: string
}

const route = useRoute()
const keyword = ref('')
const merchantData = ref<Record<string, any>>({
  summary: {},
  items: [],
  groups: [],
})

const merchantDialogVisible = ref(false)
const selectedMerchant = ref<MerchantItem | null>(null)
const createMerchantDialogVisible = ref(false)
const createMerchantForm = reactive({
  merchant_name: '',
  contact_name: '',
  username: '',
  email: '',
  phone: '',
  password: '',
  group_name: '',
  rate: '0.80',
  status_code: 1,
})

const groupDialogVisible = ref(false)
const groupForm = reactive({
  id: 0,
  name: '',
  code: '',
  rate_discount: '',
  daily_limit: '',
  status: '启用',
})

const activeSection = computed<'merchants' | 'groups'>(() =>
  route.meta.section === 'groups' ? 'groups' : 'merchants',
)
const gatewayBaseUrl = computed(() => window.location.origin)
const merchantGroups = computed<MerchantGroup[]>(() =>
  Array.isArray(merchantData.value.groups) ? (merchantData.value.groups as MerchantGroup[]) : [],
)

const summaryCards = computed(() => {
  return [
    { label: '商户总数', value: merchantData.value.summary?.total ?? 0 },
    { label: '正常商户', value: merchantData.value.summary?.active ?? 0 },
    { label: '待审核', value: merchantData.value.summary?.pending ?? 0 },
    { label: '实名待审', value: ((merchantData.value.items || []) as MerchantItem[]).filter((item) => realnamePending(item)).length },
  ]
})

const overviewRows = computed(() => {
  const cards = summaryCards.value
  return [
    cards.map((item) => ({
      ...item,
      copy:
        item.label === '商户总数'
          ? '平台已创建的全部商户账号总量。'
          : item.label === '正常商户'
            ? '已通过审核并可正常运行的商户。'
            : item.label === '待审核'
              ? '等待后台审核或处理注册费的商户。'
              : '等待实名认证审核的商户数量。',
    })),
  ]
})

const filteredMerchants = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  const items = (merchantData.value.items || []) as MerchantItem[]

  if (!query) return items

  return items.filter((item) =>
    [
      item.name,
      merchantNo(item),
      item.group_name,
      item.contact_name,
      item.email,
      item.status,
      item.audit_status,
      item.register_fee_status,
      item.realname_status_label,
      item.realname_real_name,
    ]
      .some((value) => String(value || '').toLowerCase().includes(query)),
  )
})

const { pagination: merchantPagination, total: merchantTotal, pagedRows: pagedMerchants } = usePagination(() => filteredMerchants.value, 20)
const { pagination: groupPagination, total: groupTotal, pagedRows: pagedGroups } = usePagination(() => merchantGroups.value, 20)

function merchantNo(item: MerchantItem | null | undefined) {
  return String(item?.merchant_no || item?.id || item?.appid || '')
}

function merchantDisplayName(item: MerchantItem | null | undefined) {
  return item?.name || merchantNo(item) || '-'
}

const merchantDetailRows = computed(() => {
  const item = selectedMerchant.value
  if (!item) return []

  return [
    { label: '商户名称', value: item.name || '-' },
    { label: '商户ID', value: merchantNo(item) || '-' },
    { label: '用户组', value: item.group_name || '-' },
    { label: '联系人', value: item.contact_name || '-' },
    { label: '邮箱', value: item.email || '-' },
    { label: '余额', value: item.balance || '0.00' },
    { label: '费率', value: item.rate || '-' },
    { label: '状态', value: item.status || '-' },
    { label: '审核状态', value: auditStatusText(item.audit_status, item.status_code) },
    { label: '注册费状态', value: registerFeeText(item) },
    { label: '审核人', value: item.audited_by || '-' },
    { label: '审核时间', value: item.audited_at || '-' },
    { label: '审核备注', value: item.audit_reason || '-' },
    { label: '实名状态', value: item.realname_status_label || realnameStatusText(item) },
    { label: '实名姓名', value: item.realname_real_name || '-' },
    { label: '实名证件', value: item.realname_id_card || '-' },
    { label: '实名服务商', value: item.realname_provider || '-' },
    { label: '实名结果', value: item.realname_result || '-' },
    { label: '实名失败原因', value: item.realname_last_error || item.realname_review_reason || '-' },
    { label: '实名提交时间', value: item.realname_submitted_at || '-' },
    { label: '实名审核人', value: item.realname_reviewed_by || '-' },
    { label: '实名审核时间', value: item.realname_reviewed_at || '-' },
    { label: '注册时间', value: item.registered_at || '-' },
  ]
})

const merchantApiRows = computed(() => {
  const item = selectedMerchant.value
  if (!item) return []

  return [
    { label: '接口地址', value: gatewayBaseUrl.value },
    { label: 'V1 接口', value: '/mapi.php' },
    { label: 'V2 接口', value: '/api/pay/create' },
    { label: '签名方式', value: 'MD5 + RSA' },
    { label: '商户ID', value: merchantNo(item) || '-' },
    { label: '密钥说明', value: '请在商户接口信息页面查看实际密钥' },
  ]
})

async function load() {
  const resp = await getAdminMerchants()
  if (resp.code === 0 && resp.data) {
    merchantData.value = resp.data
  }
}

function openMerchantDetail(item: MerchantItem) {
  selectedMerchant.value = item
  merchantDialogVisible.value = true
}

function openCreateMerchant() {
  Object.assign(createMerchantForm, {
    merchant_name: '',
    contact_name: '',
    username: '',
    email: '',
    phone: '',
    password: '',
    group_name: merchantGroups.value[0]?.name || '',
    rate: '0.80',
    status_code: 1,
  })
  createMerchantDialogVisible.value = true
}

async function submitMerchant() {
  const resp = await createAdminMerchant(createMerchantForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '商户新增成功')
    createMerchantDialogVisible.value = false
    await load()
  }
}

async function reviewMerchant(item: MerchantItem, action: 'approve' | 'reject') {
  let reason = ''
  if (action === 'reject') {
    const result = await ElMessageBox.prompt('请输入驳回原因', '商户审核', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      type: 'warning',
    })
    reason = String(result.value || '')
  } else {
    await ElMessageBox.confirm(`确认通过商户 ${merchantDisplayName(item)} 的审核吗？`, '商户审核', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
  }

  const resp = await reviewAdminMerchant({
    merchant_id: item.id,
    action,
    reason,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '商户审核已处理')
    await load()
  } else if (resp.message) {
    ElMessage.error(resp.message)
  }
}

async function reviewRealname(item: MerchantItem, action: 'approve' | 'reject') {
  let reason = ''
  if (action === 'reject') {
    const result = await ElMessageBox.prompt('请输入实名驳回原因', '实名认证审核', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      type: 'warning',
    })
    reason = String(result.value || '')
  } else {
    await ElMessageBox.confirm(`确认通过商户 ${merchantDisplayName(item)} 的实名认证吗？`, '实名认证审核', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
  }

  const resp = await reviewAdminMerchantRealname({
    merchant_id: item.id,
    action,
    reason,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '实名审核已处理')
    await load()
  } else if (resp.message) {
    ElMessage.error(resp.message)
  }
}

function openCreateGroup() {
  Object.assign(groupForm, {
    id: 0,
    name: '',
    code: '',
    rate_discount: '',
    daily_limit: '',
    status: '启用',
  })
  groupDialogVisible.value = true
}

function editGroup(item: MerchantGroup) {
  Object.assign(groupForm, {
    id: item.id,
    name: item.name,
    code: item.code,
    rate_discount: item.rate_discount || '',
    daily_limit: item.daily_limit || '',
    status: item.status || '启用',
  })
  groupDialogVisible.value = true
}

async function submitGroup() {
  const resp = await saveAdminMerchantGroup(groupForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '用户组已保存')
    groupDialogVisible.value = false
    await load()
  }
}

async function removeGroup(item: MerchantGroup) {
  await ElMessageBox.confirm(`确认删除用户组 ${item.name} 吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })

  const resp = await deleteAdminMerchantGroup(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '用户组已删除')
    await load()
  }
}

function statusClass(status: string | undefined, statusCode?: number) {
  if (statusCode === 1 || status === '启用' || status === '正常') return 'success'
  if (statusCode === 0 || status === '待审核') return 'warning'
  return 'muted'
}

function auditStatusClass(item: MerchantItem) {
  if (item.audit_status === 'approved' || item.status_code === 1) return 'success'
  if (item.audit_status === 'pending' || item.audit_status === 'pending_payment' || item.status_code === 0) return 'warning'
  return 'muted'
}

function auditStatusText(status?: string, statusCode?: number) {
  if (status === 'approved' || statusCode === 1) return '已通过'
  if (status === 'pending_payment') return '待缴注册费'
  if (status === 'pending' || statusCode === 0) return '待审核'
  if (status === 'rejected') return '已驳回'
  if (status === 'disabled' || statusCode === 2) return '已停用'
  return status || '-'
}

function realnamePending(item: MerchantItem) {
  return String(item.realname_status || '').toLowerCase() === 'pending'
}

function realnameStatusClass(item: MerchantItem) {
  const status = String(item.realname_status || '').toLowerCase()
  if (status === 'approved' || status === 'success' || status === '已认证') return 'success'
  if (status === 'pending') return 'warning'
  if (status === 'failed' || status === 'rejected') return 'danger'
  return 'muted'
}

function realnameStatusText(item: MerchantItem) {
  if (item.realname_status_label) return item.realname_status_label
  const status = String(item.realname_status || '').toLowerCase()
  if (status === 'approved' || status === 'success' || status === '已认证') return '已认证'
  if (status === 'pending') return '待审核'
  if (status === 'failed' || status === 'rejected') return '未通过'
  return '未提交'
}

function registerFeeText(item: MerchantItem) {
  if (item.register_fee_status === 'paid') return `已支付 ${item.register_fee_amount || '0.00'}`
  if (item.register_fee_status === 'pending') return `待支付 ${item.register_fee_amount || '0.00'}`
  return '未收费'
}

function registerFeeStatusClass(item: MerchantItem) {
  if (item.register_fee_status === 'paid') return 'success'
  if (item.register_fee_status === 'pending') return 'warning'
  return 'muted'
}

onMounted(load)

watch(keyword, () => {
  resetPagination(merchantPagination)
})

watch(activeSection, () => {
  if (activeSection.value === 'merchants') {
    resetPagination(merchantPagination)
    return
  }

  resetPagination(groupPagination)
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div v-if="activeSection === 'merchants'" class="workbench-shell merchant-overview">
        <div v-for="(row, rowIndex) in overviewRows" :key="rowIndex" class="workbench-row">
          <div v-for="item in row" :key="item.label" class="workbench-cell">
            <span class="workbench-label">{{ item.label }}</span>
            <strong class="workbench-value">{{ item.value }}</strong>
            <span class="workbench-copy">{{ item.copy }}</span>
          </div>
        </div>
      </div>

      <div v-if="activeSection === 'merchants'" class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">商户明细</h3>
              <p class="settings-block-copy">支持按名称、商户ID或联系人搜索，审核按钮保留原有业务流程。</p>
            </div>
            <div class="toolbar-actions merchant-toolbar">
              <input
                v-model="keyword"
                type="text"
                class="search-input"
                placeholder="搜索商户名称 / 商户ID / 联系人"
              />
              <button class="primary-btn" type="button" @click="openCreateMerchant">新增商户</button>
            </div>
          </div>
          <div class="table-wrap">
            <div class="table-head merchant-grid">
              <span>商户名称</span>
              <span>商户ID</span>
              <span>分组</span>
              <span>联系人</span>
              <span>余额</span>
              <span>费率</span>
              <span>状态</span>
              <span>实名</span>
              <span>操作</span>
            </div>
            <div v-for="item in pagedMerchants" :key="item.id" class="table-row merchant-grid">
              <div class="merchant-primary">
                <strong class="merchant-primary__name" :title="item.name || '-'">{{ item.name || '-' }}</strong>
                <div class="minor-copy merchant-primary__email" :title="item.email || '-'">{{ item.email || '-' }}</div>
              </div>
              <span>{{ merchantNo(item) || '-' }}</span>
              <span>{{ item.group_name || '-' }}</span>
              <span>{{ item.contact_name || '-' }}</span>
              <span>{{ item.balance || '0.00' }}</span>
              <span>{{ item.rate || '-' }}</span>
              <span class="status-column">
                <span class="status-chip" :class="statusClass(item.status, item.status_code)">
                  {{ item.status || '-' }}
                </span>
                <span class="status-chip audit-chip" :class="auditStatusClass(item)">
                  {{ auditStatusText(item.audit_status, item.status_code) }}
                </span>
                <span class="status-chip fee-chip" :class="registerFeeStatusClass(item)">
                  {{ registerFeeText(item) }}
                </span>
              </span>
              <span class="status-column">
                <span class="status-chip" :class="realnameStatusClass(item)">
                  {{ realnameStatusText(item) }}
                </span>
              </span>
              <div class="inline-actions">
                <button class="link-action" type="button" @click="openMerchantDetail(item)">查看</button>
                <button v-if="Number(item.status_code) === 0" class="link-action" type="button" @click="reviewMerchant(item, 'approve')">通过</button>
                <button v-if="Number(item.status_code) === 0" class="link-action danger-text" type="button" @click="reviewMerchant(item, 'reject')">驳回</button>
                <button v-if="realnamePending(item)" class="link-action" type="button" @click="reviewRealname(item, 'approve')">实名通过</button>
                <button v-if="realnamePending(item)" class="link-action danger-text" type="button" @click="reviewRealname(item, 'reject')">实名驳回</button>
              </div>
            </div>
          </div>
          <AppPagination
            :total="merchantTotal"
            :page="merchantPagination.page"
            :page-size="merchantPagination.pageSize"
            @update:page="merchantPagination.page = $event"
            @update:page-size="merchantPagination.pageSize = $event"
          />
        </div>
      </div>

      <div v-else class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">用户组列表</h3>
              <p class="settings-block-copy">分组仅做展示与配置收口，实际权限与费率规则保持原样。</p>
            </div>
            <div class="toolbar-actions">
              <button class="primary-btn" type="button" @click="openCreateGroup">新增用户组</button>
            </div>
          </div>
          <div class="table-wrap">
            <div class="table-head group-grid">
              <span>分组ID</span>
              <span>用户组名称</span>
              <span>费率策略</span>
              <span>状态</span>
              <span>操作</span>
            </div>
            <div v-for="item in pagedGroups" :key="item.id" class="table-row group-grid">
              <strong>{{ item.id }}</strong>
              <span>{{ item.name }}</span>
              <span>{{ item.rate_discount || '-' }}</span>
              <span>
                <span class="status-chip" :class="statusClass(item.status)">{{ item.status || '-' }}</span>
              </span>
              <div class="inline-actions">
                <button class="link-action" type="button" @click="editGroup(item)">编辑</button>
                <button class="link-action danger-text" type="button" @click="removeGroup(item)">删除</button>
              </div>
            </div>
          </div>
          <AppPagination
            :total="groupTotal"
            :page="groupPagination.page"
            :page-size="groupPagination.pageSize"
            @update:page="groupPagination.page = $event"
            @update:page-size="groupPagination.pageSize = $event"
          />
        </div>
      </div>
    </article>

    <el-dialog v-model="merchantDialogVisible" title="商户详情" width="760px">
      <div v-if="selectedMerchant" class="dialog-form detail-dialog">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">基础信息</h3>
            <p class="settings-block-copy">仅整理展示层级，不调整商户字段与状态逻辑。</p>
          </div>
          <div class="field-grid compact">
            <label v-for="row in merchantDetailRows" :key="row.label" class="field">
              <span class="field-label">{{ row.label }}</span>
              <input :value="row.value" type="text" readonly />
            </label>
          </div>
        </div>

        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">接口信息</h3>
            <p class="settings-block-copy">网关路径和签名说明保持现有接口约定。</p>
          </div>
          <div class="field-grid compact">
            <label v-for="row in merchantApiRows" :key="row.label" class="field">
              <span class="field-label">{{ row.label }}</span>
              <input :value="row.value" type="text" readonly />
            </label>
          </div>
        </div>
      </div>
    </el-dialog>

    <el-dialog v-model="createMerchantDialogVisible" title="新增商户" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">创建商户</h3>
            <p class="settings-block-copy">账号资料和基础费率统一填写，保存后按原流程创建。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">商户名称</span>
              <input v-model="createMerchantForm.merchant_name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">联系人</span>
              <input v-model="createMerchantForm.contact_name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">商户账号</span>
              <input v-model="createMerchantForm.username" type="text" />
            </label>
            <label class="field">
              <span class="field-label">登录密码</span>
              <input v-model="createMerchantForm.password" type="password" />
            </label>
            <label class="field">
              <span class="field-label">邮箱</span>
              <input v-model="createMerchantForm.email" type="text" />
            </label>
            <label class="field">
              <span class="field-label">手机号</span>
              <input v-model="createMerchantForm.phone" type="text" />
            </label>
            <label class="field">
              <span class="field-label">用户组</span>
              <select v-model="createMerchantForm.group_name">
                <option value="">请选择用户组</option>
                <option v-for="group in merchantGroups" :key="group.id" :value="group.name">{{ group.name }}</option>
              </select>
            </label>
            <label class="field">
              <span class="field-label">平台费率</span>
              <input v-model="createMerchantForm.rate" type="text" />
            </label>
            <label class="field">
              <span class="field-label">状态</span>
              <select v-model="createMerchantForm.status_code">
                <option :value="1">正常</option>
                <option :value="0">待审核</option>
                <option :value="2">停用</option>
              </select>
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="createMerchantDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitMerchant">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="groupDialogVisible" title="用户组信息" width="520px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">分组配置</h3>
            <p class="settings-block-copy">请按现有规则维护费率与限额字段。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">用户组名称</span>
              <input v-model="groupForm.name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">用户组标识</span>
              <input v-model="groupForm.code" type="text" />
            </label>
            <label class="field">
              <span class="field-label">费率策略</span>
              <input v-model="groupForm.rate_discount" type="text" />
            </label>
            <label class="field">
              <span class="field-label">单日限额</span>
              <input v-model="groupForm.daily_limit" type="text" />
            </label>
            <label class="field">
              <span class="field-label">状态</span>
              <select v-model="groupForm.status">
                <option value="启用">启用</option>
                <option value="停用">停用</option>
              </select>
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="groupDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitGroup">保存</button>
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

.merchant-overview {
  border-bottom: 1px solid var(--brand-border);
}

.search-input {
  width: 260px;
}

.merchant-toolbar {
  justify-content: flex-end;
}

.minor-copy {
  margin-top: 4px;
  color: #607089;
  font-size: 11px;
}

.merchant-primary {
  min-width: 0;
}

.merchant-primary__name,
.merchant-primary__email {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.merchant-primary__name {
  max-width: 100%;
}

.merchant-primary__email {
  max-width: 100%;
}

.audit-chip {
  display: inline-flex;
  margin-top: 4px;
}

.fee-chip {
  display: inline-flex;
}

.fee-chip.muted {
  background: #eef2f7;
  color: #5f6f82;
}

.status-column {
  display: grid;
  gap: 4px;
  align-content: start;
}

.merchant-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) 0.7fr 0.62fr 0.78fr 0.58fr 0.5fr 0.82fr 0.72fr 1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.group-grid {
  display: grid;
  grid-template-columns: 0.45fr 1fr 1fr 0.7fr 0.8fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.detail-dialog {
  display: grid;
  gap: 16px;
}

@media (max-width: 820px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }

  .search-input {
    width: 100%;
  }
}
</style>
