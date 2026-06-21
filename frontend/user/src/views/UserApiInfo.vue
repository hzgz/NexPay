<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { CopyDocument, Key, RefreshRight, Tickets } from '@element-plus/icons-vue'
import {
  generateUserApiRsaKeyPair,
  getUserApiInfo,
  resetUserApiMd5Key,
  saveUserApiSignMode,
} from '../lib/api'

const info = ref<Record<string, any>>({})
const loading = ref(false)
const md5Loading = ref(false)
const rsaLoading = ref(false)
const signSaving = ref(false)
const signMode = ref('md5_rsa')
const rsaDialogVisible = ref(false)

const generatedPair = reactive({
  public_key: '',
  private_key: '',
})

const defaultInterfaceSections = [
  {
    title: 'V1',
    items: [
      { method: 'POST', path: '/mapi.php' },
      { method: 'POST', path: '/api.php' },
    ],
  },
  {
    title: 'V2',
    items: [
      { method: 'POST', path: '/api/pay/create' },
      { method: 'POST', path: '/api/pay/query' },
      { method: 'POST', path: '/api/pay/refund' },
      { method: 'POST', path: '/api/transfer/*' },
    ],
  },
]

const signModeMeta: Record<string, { label: string; description: string }> = {
  md5_rsa: {
    label: 'MD5 + RSA 签名',
    description: '兼容模式',
  },
  rsa_only: {
    label: '仅 RSA 签名',
    description: '安全模式',
  },
}

const signModeOptions = computed(() => {
  const options = Array.isArray(info.value.sign_mode_options) ? info.value.sign_mode_options : []
  if (options.length === 0) {
    return Object.entries(signModeMeta).map(([value, meta]) => ({
      value,
      label: meta.label,
      description: meta.description,
    }))
  }

  return options
    .map((option) => {
      const value = String(option?.value || '').trim()
      const meta = signModeMeta[value] || {
        label: String(option?.label || value),
        description: String(option?.description || ''),
      }

      return {
        value,
        label: meta.label,
        description: meta.description,
      }
    })
    .filter((option) => option.value !== '')
})

const interfaceSections = computed(() => {
  const sections = Array.isArray(info.value.interface_sections) ? info.value.interface_sections : []
  if (sections.length === 0) {
    return defaultInterfaceSections
  }

  return sections
    .map((section) => ({
      title: String(section?.title || '').trim(),
      items: Array.isArray(section?.items)
        ? section.items
            .map((item: any) => ({
              method: String(item?.method || 'POST').trim() || 'POST',
              path: String(item?.path || '').trim(),
            }))
            .filter((item: { path: string }) => item.path !== '')
        : [],
    }))
    .filter((section) => section.title !== '' && section.items.length > 0)
})

const merchantIdText = computed(() => String(info.value.merchant_id || info.value.user_id || ''))
const siteUrlText = computed(() => String(info.value.site_url || info.value.interface_url || info.value.base_url || ''))
const merchantPublicKeyRaw = computed(() => String(info.value.merchant_public_key || info.value.rsa_public_key || ''))
const merchantPrivateKeyRaw = computed(() => String(info.value.merchant_private_key || info.value.rsa_private_key || ''))
const platformPublicKeyRaw = computed(() => String(info.value.platform_public_key || ''))

const merchantPublicKeyText = computed(() => formatKeyBlock(merchantPublicKeyRaw.value))
const merchantPrivateKeyText = computed(() => formatKeyBlock(merchantPrivateKeyRaw.value, 128))
const platformPublicKeyText = computed(() => formatKeyBlock(platformPublicKeyRaw.value))
const generatedPublicKeyText = computed(() => formatKeyBlock(generatedPair.public_key))
const generatedPrivateKeyText = computed(() => formatKeyBlock(generatedPair.private_key, 128))

onMounted(load)

async function load() {
  loading.value = true
  const resp = await getUserApiInfo()
  if (resp.code === 0 && resp.data) {
    info.value = resp.data
    signMode.value = String(resp.data.sign_mode || 'md5_rsa')
  } else if (resp.message) {
    ElMessage.error(resp.message)
  }
  loading.value = false
}

async function copyValue(value: string, label: string) {
  const text = value.trim()
  if (!text) {
    ElMessage.error(`${label}为空，暂时无法复制`)
    return
  }

  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success(`${label}已复制`)
  } catch {
    ElMessage.error(`${label}复制失败`)
  }
}

function openDoc(url: string) {
  window.open(url || '/doc', '_blank', 'noopener')
}

async function resetMd5Key() {
  md5Loading.value = true
  const resp = await resetUserApiMd5Key()
  md5Loading.value = false
  if (resp.code === 0 && resp.data) {
    info.value = resp.data
    signMode.value = String(resp.data.sign_mode || signMode.value)
    ElMessage.success(resp.message || 'MD5 密钥已重置')
    return
  }

  ElMessage.error(resp.message || 'MD5 密钥重置失败')
}

async function generateRsaKeyPair() {
  rsaLoading.value = true
  const resp = await generateUserApiRsaKeyPair()
  rsaLoading.value = false
  if (resp.code === 0 && resp.data) {
    info.value = resp.data.info || info.value
    signMode.value = String((resp.data.info || {}).sign_mode || signMode.value)
    generatedPair.public_key = String(resp.data.generated_public_key || '')
    generatedPair.private_key = String(resp.data.generated_private_key || '')
    rsaDialogVisible.value = generatedPair.public_key !== '' || generatedPair.private_key !== ''
    ElMessage.success(resp.message || 'RSA 密钥对已生成')
    return
  }

  ElMessage.error(resp.message || 'RSA 密钥对生成失败')
}

async function saveSignModeSetting() {
  signSaving.value = true
  const resp = await saveUserApiSignMode(signMode.value)
  signSaving.value = false
  if (resp.code === 0 && resp.data) {
    info.value = resp.data
    signMode.value = String(resp.data.sign_mode || signMode.value)
    ElMessage.success(resp.message || '签名方式已保存')
    return
  }

  ElMessage.error(resp.message || '签名方式保存失败')
}

function formatKeyBlock(value: string, chunkSize = 64) {
  const clean = String(value || '').replace(/\s+/g, '').trim()
  if (!clean) return '未生成'
  const chunkPattern = new RegExp(`(.{${chunkSize}})`, 'g')
  return clean.replace(chunkPattern, '$1\n')
}
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace api-info-surface" v-loading="loading">
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">接口资料</span>
          <h2 class="settings-workspace__title">API 对接信息</h2>
          <p class="settings-workspace__copy">
            左侧提供当前站点 URL、商户 ID、V1 MD5 密钥和 V2 RSA 信息；右侧列出兼容易支付 V1、V2 的接口清单。
          </p>
        </div>
      </div>

      <div class="api-layout settings-workspace__body">
        <div class="api-main">
          <section class="api-card">
            <header class="api-card__head">
              <h3>API 信息</h3>
              <p>当前站点与商户基础信息</p>
            </header>
            <div class="api-info-grid">
              <div class="api-info-row">
                <span class="api-info-label">网站地址</span>
                <code class="api-inline-code">{{ siteUrlText || '-' }}</code>
                <button class="copy-action" type="button" @click="copyValue(siteUrlText, '网站地址')">
                  <el-icon><CopyDocument /></el-icon>
                  复制
                </button>
              </div>
              <div class="api-info-row">
                <span class="api-info-label">商户 ID</span>
                <div class="api-info-meta">
                  <strong class="api-info-value">{{ merchantIdText || '-' }}</strong>
                </div>
                <button class="copy-action" type="button" @click="copyValue(merchantIdText, '商户 ID')">
                  <el-icon><CopyDocument /></el-icon>
                  复制
                </button>
              </div>
              <div class="api-info-row">
                <span class="api-info-label">兼容协议</span>
                <strong class="api-info-value">易支付 V1 / 易支付 V2</strong>
                <span class="api-row-placeholder" />
              </div>
            </div>
          </section>

          <section class="api-card">
            <header class="api-card__head">
              <div class="api-sign-title">
                <span class="version-chip version-chip--v1">V1</span>
                <div>
                  <h3>MD5 签名信息</h3>
                  <p>适用于兼容易支付 V1</p>
                </div>
              </div>
            </header>
            <div class="api-info-grid">
              <div class="api-info-row">
                <span class="api-info-label">MD5 密钥</span>
                <code class="api-inline-code api-inline-code--secret">{{ info.mch_key || '-' }}</code>
                <button class="copy-action" type="button" @click="copyValue(String(info.mch_key || ''), 'MD5 密钥')">
                  <el-icon><CopyDocument /></el-icon>
                  复制
                </button>
              </div>
            </div>
            <div class="api-card__actions">
              <button class="primary-btn" type="button" @click="openDoc(String(info.doc_v1_url || '/doc'))">
                <el-icon><Tickets /></el-icon>
                查看 V1 文档
              </button>
              <button class="danger-soft-btn" type="button" :disabled="md5Loading" @click="resetMd5Key">
                <el-icon><RefreshRight /></el-icon>
                {{ md5Loading ? '重置中...' : '重置 MD5 密钥' }}
              </button>
            </div>
          </section>

          <section class="api-card">
            <header class="api-card__head">
              <div class="api-sign-title">
                <span class="version-chip version-chip--v2">V2</span>
                <div>
                  <h3>RSA 签名信息</h3>
                  <p>适用于兼容易支付 V2</p>
                </div>
              </div>
            </header>

            <div class="api-key-grid">
              <div class="key-panel">
                <div class="key-panel__head">
                  <span>平台公钥</span>
                  <button class="copy-action copy-action--inline" type="button" @click="copyValue(platformPublicKeyRaw, '平台公钥')">
                    <el-icon><CopyDocument /></el-icon>
                    复制
                  </button>
                </div>
                <pre class="key-panel__body">{{ platformPublicKeyText }}</pre>
              </div>

              <div class="key-panel">
                <div class="key-panel__head">
                  <span>商户公钥</span>
                  <button class="copy-action copy-action--inline" type="button" @click="copyValue(merchantPublicKeyRaw, '商户公钥')">
                    <el-icon><CopyDocument /></el-icon>
                    复制
                  </button>
                </div>
                <pre class="key-panel__body">{{ merchantPublicKeyText }}</pre>
              </div>

              <div class="key-panel key-panel--wide">
                <div class="key-panel__head">
                  <span>商户私钥</span>
                  <button class="copy-action copy-action--inline" type="button" @click="copyValue(merchantPrivateKeyRaw, '商户私钥')">
                    <el-icon><CopyDocument /></el-icon>
                    复制
                  </button>
                </div>
                <pre class="key-panel__body">{{ merchantPrivateKeyText }}</pre>
              </div>
            </div>

            <div class="api-card__actions">
              <button class="primary-btn" type="button" @click="openDoc(String(info.doc_v2_url || '/doc'))">
                <el-icon><Tickets /></el-icon>
                查看 V2 文档
              </button>
              <button class="success-soft-btn" type="button" :disabled="rsaLoading" @click="generateRsaKeyPair">
                <el-icon><Key /></el-icon>
                {{ rsaLoading ? '生成中...' : '生成 RSA 密钥对' }}
              </button>
            </div>
          </section>

          <section class="api-card">
            <header class="api-card__head">
              <h3>签名方式设置</h3>
              <p>商户中心保存后立即生效</p>
            </header>
            <div class="sign-mode-group">
              <label v-for="option in signModeOptions" :key="option.value" class="sign-mode-option">
                <input v-model="signMode" type="radio" :value="option.value" />
                <span class="sign-mode-option__text">
                  <strong>{{ option.label }}</strong>
                  <small>{{ option.description }}</small>
                </span>
              </label>
            </div>
            <div class="api-card__actions">
              <button class="primary-btn" type="button" :disabled="signSaving || loading" @click="saveSignModeSetting">
                {{ signSaving ? '保存中...' : '保存设置' }}
              </button>
            </div>
          </section>
        </div>

        <aside class="api-side">
          <section class="api-side-card">
            <header class="api-side-card__head">
              <span class="settings-workspace__eyebrow">适配接口</span>
              <h3>对接程序 / 接口</h3>
              <p>右侧只放对接程序需要的协议入口，左侧保留可直接复制的 API 信息。</p>
            </header>

            <div v-for="section in interfaceSections" :key="section.title" class="protocol-card">
              <div class="protocol-card__head">
                <span class="version-chip" :class="section.title === 'V1' ? 'version-chip--v1' : 'version-chip--v2'">
                  {{ section.title }}
                </span>
                <strong>{{ section.title === 'V1' ? '易支付 V1 接口' : '易支付 V2 接口' }}</strong>
              </div>
              <ul class="endpoint-list">
                <li v-for="item in section.items" :key="`${section.title}-${item.method}-${item.path}`" class="endpoint-item">
                  <span class="endpoint-method">{{ item.method }}</span>
                  <code class="endpoint-path">{{ item.path }}</code>
                </li>
              </ul>
            </div>

            <div class="api-tip-list">
              <div class="api-tip">
                <strong>商户 ID</strong>
                <span>使用当前登录用户 ID 作为商户 ID。</span>
              </div>
              <div class="api-tip">
                <strong>V1 签名</strong>
                <span>使用左侧 MD5 密钥参与签名。</span>
              </div>
              <div class="api-tip">
                <strong>V2 签名</strong>
                <span>使用商户私钥签名，使用平台公钥验签。</span>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </article>

    <el-dialog v-model="rsaDialogVisible" title="新 RSA 密钥对" width="760px">
      <div class="dialog-form api-key-dialog">
        <div class="key-panel">
          <div class="key-panel__head">
            <span>商户公钥</span>
            <button class="copy-action copy-action--inline" type="button" @click="copyValue(generatedPair.public_key, '商户公钥')">
              <el-icon><CopyDocument /></el-icon>
              复制
            </button>
          </div>
          <pre class="key-panel__body">{{ generatedPublicKeyText }}</pre>
        </div>
        <div class="key-panel">
          <div class="key-panel__head">
            <span>商户私钥</span>
            <button class="copy-action copy-action--inline" type="button" @click="copyValue(generatedPair.private_key, '商户私钥')">
              <el-icon><CopyDocument /></el-icon>
              复制
            </button>
          </div>
          <pre class="key-panel__body key-panel__body--dialog">{{ generatedPrivateKeyText }}</pre>
        </div>
        <p class="field-note api-key-dialog__note">商户私钥请只保存在商户自己的安全环境中，不要泄露给第三方。</p>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="rsaDialogVisible = false">关闭</button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.api-info-surface {
  background:
    radial-gradient(circle at top right, rgba(22, 119, 255, 0.08), transparent 28%),
    var(--brand-panel);
}

.api-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
  gap: 18px;
  padding: 18px 18px 22px;
}

.api-main {
  display: grid;
  gap: 18px;
}

.api-side {
  min-width: 0;
}

.api-card,
.api-side-card {
  border: 1px solid var(--brand-border);
  border-radius: 18px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(250, 252, 255, 0.98));
  box-shadow: 0 10px 28px rgba(18, 36, 66, 0.035);
}

.api-card__head,
.api-side-card__head {
  padding: 22px 26px 14px;
}

.api-card__head h3,
.api-side-card__head h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
}

.api-card__head p,
.api-side-card__head p {
  margin: 8px 0 0;
  color: var(--brand-subtle);
  font-size: 13px;
  line-height: 1.6;
}

.api-info-grid {
  display: grid;
  gap: 0;
  padding: 0 26px 8px;
}

.api-info-row {
  display: grid;
  grid-template-columns: 112px minmax(0, 1fr) auto;
  align-items: center;
  gap: 18px;
  min-height: 58px;
}

.api-info-label {
  color: var(--brand-subtle);
  font-size: 13px;
}

.api-info-meta {
  display: grid;
  gap: 4px;
}

.api-info-meta small {
  color: var(--brand-subtle);
  font-size: 12px;
}

.api-info-value {
  font-size: 15px;
  font-weight: 700;
}

.api-inline-code {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  color: var(--brand-text);
  font-size: 14px;
  word-break: break-all;
}

.api-inline-code--secret {
  letter-spacing: 0.01em;
}

.api-row-placeholder {
  width: 1px;
  height: 1px;
}

.copy-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #6d86ab;
  font-size: 12px;
  font-weight: 700;
}

.copy-action--inline {
  padding-left: 10px;
}

.api-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  padding: 8px 26px 22px;
}

.danger-soft-btn,
.success-soft-btn {
  min-height: 42px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 0 16px;
  border: 1px solid transparent;
  font-weight: 700;
}

.danger-soft-btn {
  background: rgba(225, 87, 87, 0.1);
  color: var(--brand-danger);
}

.success-soft-btn {
  background: rgba(47, 158, 98, 0.12);
  color: var(--brand-success);
}

.primary-btn :deep(svg),
.danger-soft-btn :deep(svg),
.success-soft-btn :deep(svg),
.copy-action :deep(svg) {
  width: 14px;
  height: 14px;
}

.api-sign-title {
  display: inline-flex;
  align-items: center;
  gap: 12px;
}

.api-sign-title h3 {
  margin: 0;
}

.api-sign-title p {
  margin: 6px 0 0;
  color: var(--brand-subtle);
  font-size: 13px;
}

.version-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  min-height: 28px;
  padding: 0 10px;
  border-radius: 8px;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.version-chip--v1 {
  background: linear-gradient(135deg, #8492a6, #5f6d82);
}

.version-chip--v2 {
  background: linear-gradient(135deg, #75c048, #3da940);
}

.api-key-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  padding: 0 26px 10px;
}

.key-panel {
  border: 1px solid rgba(13, 102, 255, 0.08);
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(247, 250, 255, 0.72), rgba(255, 255, 255, 0.94));
}

.key-panel--wide {
  grid-column: 1 / -1;
}

.key-panel__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 16px 18px 10px;
  color: var(--brand-text);
  font-size: 14px;
  font-weight: 700;
}

.key-panel__body {
  margin: 0;
  min-height: 124px;
  padding: 0 18px 18px;
  color: #6f7e97;
  background: transparent;
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 11px;
  line-height: 1.72;
  white-space: pre-wrap;
  word-break: break-all;
}

.key-panel--wide .key-panel__body {
  min-height: 0;
  max-height: 224px;
  padding-bottom: 14px;
  font-size: 10.5px;
  line-height: 1.56;
  overflow: auto;
}

.key-panel__body--dialog {
  max-height: 240px;
  font-size: 10.5px;
  line-height: 1.58;
  overflow: auto;
}

.sign-mode-group {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
  padding: 0 26px 10px;
}

.sign-mode-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 14px 16px;
  border: 1px solid var(--brand-border);
  border-radius: 14px;
  background: rgba(248, 250, 254, 0.95);
  color: var(--brand-text);
}

.sign-mode-option input {
  width: 18px;
  min-height: 18px;
  margin: 1px 0 0;
}

.sign-mode-option__text {
  display: grid;
  gap: 4px;
}

.sign-mode-option__text strong {
  font-size: 14px;
}

.sign-mode-option__text small {
  color: var(--brand-subtle);
  font-size: 12px;
}

.api-side-card {
  position: sticky;
  top: 16px;
}

.protocol-card {
  margin: 0 26px 16px;
  padding: 18px;
  border: 1px solid var(--brand-border);
  border-radius: 16px;
  background: rgba(246, 249, 254, 0.96);
}

.protocol-card__head {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.endpoint-list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.endpoint-item {
  display: grid;
  grid-template-columns: 56px minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 12px;
  background: #fff;
}

.endpoint-method {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 28px;
  border-radius: 8px;
  background: rgba(22, 119, 255, 0.1);
  color: #1677ff;
  font-size: 12px;
  font-weight: 800;
}

.endpoint-path {
  color: var(--brand-text);
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
  word-break: break-all;
}

.api-tip-list {
  display: grid;
  gap: 12px;
  padding: 0 26px 26px;
}

.api-tip {
  display: grid;
  gap: 4px;
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(248, 250, 254, 0.96);
  border: 1px solid var(--brand-border);
}

.api-tip strong {
  font-size: 13px;
}

.api-tip span {
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.6;
}

.api-key-dialog {
  gap: 14px;
}

.api-key-dialog__note {
  padding: 0 4px;
}

@media (max-width: 1080px) {
  .api-layout {
    grid-template-columns: 1fr;
  }

  .api-side-card {
    position: static;
  }
}

@media (max-width: 900px) {
  .api-info-row {
    grid-template-columns: 1fr;
    align-items: start;
    gap: 8px;
    padding: 12px 0;
  }

  .api-key-grid {
    grid-template-columns: 1fr;
  }

  .key-panel--wide {
    grid-column: auto;
  }

  .copy-action {
    justify-content: flex-start;
  }

  .api-row-placeholder {
    display: none;
  }
}

@media (max-width: 720px) {
  .api-layout {
    padding: 12px;
  }

  .api-card__head,
  .api-side-card__head,
  .api-info-grid,
  .api-key-grid,
  .api-card__actions,
  .sign-mode-group {
    padding-left: 16px;
    padding-right: 16px;
  }

  .protocol-card,
  .api-tip-list {
    margin-left: 16px;
    margin-right: 16px;
    padding-left: 0;
    padding-right: 0;
  }

  .api-tip-list {
    padding-bottom: 16px;
  }
}
</style>
