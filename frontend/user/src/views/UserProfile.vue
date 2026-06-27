<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useRoute } from 'vue-router'
import AppPagination from '../components/AppPagination.vue'
import {
  getUserProfile,
  saveUserPassword,
  saveUserProfile,
  setUserSessionUser,
  startUserOAuth,
  uploadUserAvatar,
} from '../lib/api'
import { resetPagination, usePagination } from '../lib/pagination'

const route = useRoute()

const profile = reactive<Record<string, any>>({
  avatar: '',
  nickname: '',
  merchant_name: '',
  merchant_id: '',
  username: '',
  email: '',
  phone: '',
  notify_url: '',
  return_url: '',
  white_ip: [],
  realname: {
    status: '',
    real_name: '',
    id_card: '',
    submitted_at: '',
    result: '',
    last_error: '',
  },
  notifications: {},
  bindings: {},
  login_logs: [],
})

const password = reactive({
  old_password: '',
  new_password: '',
})

const bindingLoading = ref('')
const avatarUploading = ref(false)
const avatarInputRef = ref<HTMLInputElement | null>(null)

const oauthChannels = [
  { key: 'qq', label: 'QQ' },
  { key: 'wechat', label: '微信' },
  { key: 'alipay', label: '支付宝' },
  { key: 'google', label: 'Google' },
  { key: 'telegram', label: 'Telegram' },
]

const activeSection = computed<'profile' | 'realname' | 'security' | 'notifications' | 'bindings' | 'logins'>(() => {
  const section = route.meta.section
  if (
    section === 'realname' ||
    section === 'security' ||
    section === 'notifications' ||
    section === 'bindings' ||
    section === 'logins'
  ) {
    return section
  }
  return 'profile'
})

const realnameStatusLabelMap: Record<string, string> = {
  approved: '已认证',
  success: '已认证',
  pending: '待审核',
  reviewing: '待审核',
  failed: '未通过',
  rejected: '未通过',
  denied: '未通过',
  unsubmitted: '未提交',
}

const realnameResultLabelMap: Record<string, string> = {
  manual_approved: '人工审核通过',
  provider_approved: '接口校验通过',
  manual_rejected: '人工审核驳回',
  provider_rejected: '接口校验驳回',
  pending_manual_review: '待人工审核',
  provider_disabled: '待人工审核',
  provider_pending: '接口处理中',
  provider_request_failed: '接口请求失败',
  provider_config_missing: '接口配置缺失',
  approved: '已通过',
  failed: '未通过',
  pending: '待处理',
}

function normalizeLookupKey(value: unknown): string {
  return String(value ?? '').trim().toLowerCase()
}

function looksBrokenText(value: unknown): boolean {
  const text = String(value ?? '').trim()
  if (text === '') {
    return false
  }

  return /^\?{2,}[0-9a-zA-Z_-]*$/u.test(text) || text.includes('\uFFFD')
}

function cleanDisplayText(value: unknown): string {
  const text = String(value ?? '').trim()
  return looksBrokenText(text) ? '' : text
}

function sanitizeRealnameRecord(value: unknown): Record<string, any> {
  const next = {
    status: '',
    real_name: '',
    id_card: '',
    submitted_at: '',
    result: '',
    last_error: '',
    ...(value && typeof value === 'object' ? (value as Record<string, any>) : {}),
  }

  next.real_name = cleanDisplayText(next.real_name)
  next.last_error = cleanDisplayText(next.last_error)
  return next
}

function formatRealnameStatus(value: unknown): string {
  const text = cleanDisplayText(value)
  const key = normalizeLookupKey(text)
  return realnameStatusLabelMap[key] || text || '未提交'
}

function formatRealnameResult(value: unknown): string {
  const text = cleanDisplayText(value)
  const key = normalizeLookupKey(text)
  return realnameResultLabelMap[key] || text
}

const realnameStatusText = computed(() => formatRealnameStatus(profile.realname?.status))
const realnameResultText = computed(() => formatRealnameResult(profile.realname?.result))
const loginLogRows = computed<Record<string, any>[]>(() => (
  Array.isArray(profile.login_logs) ? profile.login_logs : []
))
const { pagination, total, pagedRows } = usePagination(() => loginLogRows.value, 20)

async function load() {
  const resp = await getUserProfile()
  if (resp.code === 0 && resp.data) {
    Object.assign(profile, resp.data)
    profile.white_ip = Array.isArray(resp.data.white_ip) ? resp.data.white_ip : []
    profile.realname = sanitizeRealnameRecord(resp.data.realname)
    profile.notifications = resp.data.notifications && typeof resp.data.notifications === 'object' ? resp.data.notifications : {}
    profile.bindings = resp.data.bindings && typeof resp.data.bindings === 'object' ? resp.data.bindings : {}
    profile.login_logs = Array.isArray(resp.data.login_logs) ? resp.data.login_logs : []
    resetPagination(pagination)
  }
}

async function submitProfile() {
  const resp = await saveUserProfile({
    avatar: profile.avatar,
    nickname: profile.nickname,
    merchant_name: profile.merchant_name,
    email: profile.email,
    phone: profile.phone,
  })
  if (resp.code === 0) {
    setUserSessionUser(resp.data || {}, { bumpAvatarVersion: true })
    ElMessage.success(resp.message || '资料已保存')
    await load()
  }
}

async function submitRealname() {
  const resp = await saveUserProfile({ realname: profile.realname })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '实名认证资料已保存')
    await load()
  }
}

async function submitSecurity() {
  const resp = await saveUserProfile({
    email: profile.email,
    notify_url: profile.notify_url,
    return_url: profile.return_url,
    white_ip: profile.white_ip,
  })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '安全设置已保存')
    await load()
  }
}

async function submitNotifications() {
  const resp = await saveUserProfile({ notifications: profile.notifications })
  if (resp.code === 0) {
    ElMessage.success(resp.message || '通知设置已保存')
    await load()
  }
}

async function submitPassword() {
  const resp = await saveUserPassword(password)
  if (resp.code === 0) {
    password.old_password = ''
    password.new_password = ''
    ElMessage.success(resp.message || '密码修改成功')
    await load()
  }
}

function saveCurrentSection() {
  if (activeSection.value === 'realname') return submitRealname()
  if (activeSection.value === 'security') return submitSecurity()
  if (activeSection.value === 'notifications') return submitNotifications()
  return submitProfile()
}

function bindingText(channel: string): string {
  const value = profile.bindings?.[channel]
  if (value && typeof value === 'object') {
    const nickname = cleanDisplayText(value.nickname)
    const openid = cleanDisplayText(value.openid)
    const status = cleanDisplayText(value.status) || '已绑定'
    return nickname || openid || status
  }

  return cleanDisplayText(value) || '未绑定'
}

async function bindOAuth(channel: string) {
  bindingLoading.value = channel
  try {
    const resp = await startUserOAuth(channel, 'bind')
    if (resp.code === 0 && resp.data?.auth_url) {
      window.location.href = String(resp.data.auth_url)
      return
    }
    ElMessage.error(resp.message || '发起第三方绑定失败')
  } catch {
    ElMessage.error('发起第三方绑定失败')
  } finally {
    bindingLoading.value = ''
  }
}

function openAvatarPicker() {
  if (avatarUploading.value) {
    return
  }

  avatarInputRef.value?.click()
}

async function handleAvatarChange(event: Event) {
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (!file) {
    return
  }

  avatarUploading.value = true
  try {
    const resp = await uploadUserAvatar(file)
    if (resp.code === 0 && resp.data?.avatar) {
      profile.avatar = String(resp.data.avatar)
      setUserSessionUser(resp.data.user || { avatar: profile.avatar }, { bumpAvatarVersion: true })
      ElMessage.success(resp.message || '头像上传成功')
      return
    }

    ElMessage.error(resp.message || '头像上传失败')
  } catch {
    ElMessage.error('头像上传失败')
  } finally {
    avatarUploading.value = false
    if (input) {
      input.value = ''
    }
  }
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div v-if="activeSection === 'profile'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">基础资料</h3>
            <p class="settings-block-copy">头像、名称和联系方式统一维护，保留现有资料保存逻辑。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" @click="saveCurrentSection">保存当前内容</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">头像地址</span>
            <div class="avatar-input-row">
              <input v-model="profile.avatar" class="avatar-input-row__main" type="text" />
              <button class="ghost-btn avatar-input-row__upload" :disabled="avatarUploading" type="button" @click="openAvatarPicker">
                {{ avatarUploading ? '上传中...' : '上传图片' }}
              </button>
              <input ref="avatarInputRef" class="avatar-input-row__native" type="file" accept="image/*" @change="handleAvatarChange" />
            </div>
          </label>
          <label class="field"><span class="field-label">显示名称</span><input v-model="profile.nickname" type="text" /></label>
          <label class="field"><span class="field-label">商户名称</span><input v-model="profile.merchant_name" type="text" /></label>
          <label class="field"><span class="field-label">商户 ID</span><input :value="profile.merchant_id || ''" type="text" readonly /></label>
          <label class="field"><span class="field-label">登录账号</span><input :value="profile.username || ''" type="text" readonly /></label>
          <label class="field"><span class="field-label">邮箱</span><input v-model="profile.email" type="email" /></label>
          <label class="field"><span class="field-label">手机号</span><input v-model="profile.phone" type="text" /></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'realname'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">实名认证</h3>
            <p class="settings-block-copy">实名状态和提交信息统一展示。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" @click="saveCurrentSection">保存当前内容</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field"><span class="field-label">认证状态</span><input :value="realnameStatusText" type="text" readonly /></label>
          <label class="field"><span class="field-label">真实姓名</span><input v-model="profile.realname.real_name" type="text" /></label>
          <label class="field"><span class="field-label">证件号</span><input v-model="profile.realname.id_card" type="text" /></label>
          <label class="field"><span class="field-label">提交时间</span><input :value="profile.realname.submitted_at || ''" type="text" readonly /></label>
          <label class="field"><span class="field-label">校验结果</span><input :value="realnameResultText" type="text" readonly /></label>
          <label class="field"><span class="field-label">失败原因</span><input :value="profile.realname.last_error || ''" type="text" readonly /></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'security'" class="settings-stack settings-workspace__body">
        <article class="settings-block">
          <div class="settings-block-head settings-block-head--split">
            <div>
              <h3 class="settings-block-title">安全设置</h3>
              <p class="settings-block-copy">绑定邮箱、回调地址和白名单统一维护。</p>
            </div>
            <div class="toolbar-actions">
              <button class="primary-btn" @click="saveCurrentSection">保存当前内容</button>
            </div>
          </div>
          <div class="field-grid compact">
            <label class="field"><span class="field-label">绑定邮箱</span><input v-model="profile.email" type="email" /></label>
            <label class="field"><span class="field-label">异步通知地址</span><input v-model="profile.notify_url" type="text" /></label>
            <label class="field"><span class="field-label">同步跳转地址</span><input v-model="profile.return_url" type="text" /></label>
            <label class="field field-span-2">
              <span class="field-label">IP 白名单（逗号分隔）</span>
              <input
                :value="(profile.white_ip || []).join(', ')"
                type="text"
                @input="profile.white_ip = String(($event.target as HTMLInputElement).value).split(',').map((item) => item.trim()).filter(Boolean)"
              />
            </label>
          </div>
        </article>

        <article class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">修改密码</h3>
            <p class="settings-block-copy">建议使用强密码并定期更新。</p>
          </div>
          <div class="field-grid compact">
            <label class="field"><span class="field-label">原密码</span><input v-model="password.old_password" type="password" /></label>
            <label class="field"><span class="field-label">新密码</span><input v-model="password.new_password" type="password" /></label>
          </div>
          <div class="toolbar-actions password-actions">
            <button class="primary-btn" @click="submitPassword">更新密码</button>
          </div>
        </article>
      </div>

      <div v-else-if="activeSection === 'notifications'" class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">通知设置</h3>
            <p class="settings-block-copy">邮件、短信和 TG 提醒可单独控制。</p>
          </div>
          <div class="toolbar-actions">
            <button class="primary-btn" @click="saveCurrentSection">保存当前内容</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field"><span class="field-label">邮件通知</span><select v-model="profile.notifications.email"><option :value="true">启用</option><option :value="false">停用</option></select></label>
          <label class="field"><span class="field-label">短信通知</span><select v-model="profile.notifications.sms"><option :value="true">启用</option><option :value="false">停用</option></select></label>
          <label class="field"><span class="field-label">TG 通知</span><select v-model="profile.notifications.telegram"><option :value="true">启用</option><option :value="false">停用</option></select></label>
          <label class="field"><span class="field-label">订单支付提醒</span><select v-model="profile.notifications.order_paid"><option :value="true">启用</option><option :value="false">停用</option></select></label>
          <label class="field"><span class="field-label">工单回复提醒</span><select v-model="profile.notifications.ticket_reply"><option :value="true">启用</option><option :value="false">停用</option></select></label>
        </div>
      </div>

      <div v-else-if="activeSection === 'bindings'" class="settings-block settings-workspace__body">
        <div class="settings-block-head">
          <h3 class="settings-block-title">第三方绑定</h3>
          <p class="settings-block-copy">已绑定信息和跳转动作统一收口到这一处，减少页面跳转负担。</p>
        </div>
        <div class="binding-list">
          <div v-for="item in oauthChannels" :key="item.key" class="binding-row">
            <span class="binding-name">{{ item.label }}</span>
            <div class="binding-meta">
              <strong>{{ bindingText(item.key) }}</strong>
              <span>
                {{
                  bindingText(item.key) === '未绑定'
                    ? '点击右侧按钮后会跳转到对应平台完成授权。'
                    : '当前账号已完成授权绑定，可直接用于聚合登录。'
                }}
              </span>
            </div>
            <button class="ghost-btn" :disabled="Boolean(bindingLoading)" type="button" @click="bindOAuth(item.key)">
              {{ bindingLoading === item.key ? '跳转中...' : '绑定' }}
            </button>
          </div>
        </div>
      </div>

      <div v-else class="table-wrap settings-workspace__body">
        <div class="table-head login-grid">
          <span>时间</span>
          <span>IP</span>
          <span>设备</span>
          <span>状态</span>
        </div>
        <div v-for="item in pagedRows" :key="item.time + item.ip" class="table-row login-grid">
          <strong>{{ item.time }}</strong>
          <span>{{ item.ip }}</span>
          <span>{{ item.device }}</span>
          <span>{{ item.status }}</span>
        </div>
        <p v-if="!loginLogRows.length" class="empty-note">暂无登录记录。</p>
        <AppPagination
          :total="total"
          :page="pagination.page"
          :page-size="pagination.pageSize"
          @update:page="pagination.page = $event"
          @update:page-size="pagination.pageSize = $event"
        />
      </div>
    </article>
  </section>
</template>

<style scoped>
.settings-block-head--split {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.password-actions {
  justify-content: flex-end;
  margin-top: 0;
}

.avatar-input-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 132px;
  gap: 12px;
  align-items: stretch;
}

.avatar-input-row__main {
  min-width: 0;
}

.avatar-input-row__upload {
  width: 132px;
  min-width: 132px;
  justify-content: center;
}

.avatar-input-row__native {
  display: none;
}

.login-grid {
  display: grid;
  grid-template-columns: 1fr 0.8fr 1fr 0.8fr;
  gap: 12px;
  align-items: center;
}

.binding-list {
  display: grid;
}

.binding-row {
  display: grid;
  grid-template-columns: 84px minmax(0, 1fr) auto;
  min-height: 72px;
  align-items: center;
  gap: 18px;
  padding: 0 20px;
  border-bottom: 1px solid var(--brand-border);
  background: #fff;
}

.binding-row:last-child {
  border-bottom: 0;
}

.binding-name {
  color: #3f516b;
  font-size: 12px;
  font-weight: 700;
}

.binding-meta {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.binding-meta strong {
  color: #24364d;
  font-size: 13px;
  font-weight: 700;
  word-break: break-all;
}

.binding-meta span {
  color: #7d8da4;
  font-size: 12px;
  line-height: 1.6;
}

@media (max-width: 1024px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }

  .avatar-input-row {
    grid-template-columns: 1fr;
  }

  .avatar-input-row__upload {
    width: 100%;
    min-width: 0;
  }

  .login-grid {
    grid-template-columns: 1fr;
  }

  .binding-row {
    grid-template-columns: 1fr;
    align-items: start;
    padding: 16px;
  }
}
</style>
