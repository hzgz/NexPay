<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteAdminFile, getAdminFiles } from '../lib/api'

const keyword = ref('')
const files = ref<Array<Record<string, any>>>([])
const currentFile = ref<Record<string, any> | null>(null)
const fileDialogVisible = computed(() => !!currentFile.value)
const currentFileIsImage = computed(() => isImageFile(currentFile.value))
const currentFileUrl = computed(() => resolveFileUrl(currentFile.value))

const filteredFiles = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  if (!query) return files.value

  return files.value.filter((item) =>
    [item.file_name, item.merchant_name, item.category, item.remark, item.status].some((value) =>
      String(value ?? '').toLowerCase().includes(query),
    ),
  )
})

async function load() {
  const resp = await getAdminFiles()
  if (resp.code === 0 && resp.data) {
    files.value = resp.data.items || []
  }
}

async function removeFile(item: Record<string, any>) {
  await ElMessageBox.confirm(`确认删除文件 ${item.file_name} 吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })

  const resp = await deleteAdminFile(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '文件已删除')
    await load()
  }
}

function syncFileDialog(value: boolean) {
  if (!value) currentFile.value = null
}

function resolveFileUrl(item: Record<string, any> | null | undefined) {
  const raw = String(item?.file_url || '').trim()
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) return raw
  return raw.startsWith('/') ? raw : `/${raw}`
}

function isImageFile(item: Record<string, any> | null | undefined) {
  if (!item) return false

  const mimeType = String(item.mime_type || '').toLowerCase()
  if (mimeType.startsWith('image/')) return true

  const target = `${String(item.file_name || '')} ${String(item.file_url || '')}`.toLowerCase()
  return /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/.test(target)
}

function downloadFile(item: Record<string, any> | null | undefined) {
  const url = resolveFileUrl(item)
  if (!url) {
    ElMessage.warning('当前文件没有可下载地址')
    return
  }

  const link = document.createElement('a')
  link.href = url
  link.download = String(item?.file_name || 'file')
  link.target = '_blank'
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">文件列表</h3>
            <p class="settings-block-copy">集中查看平台文件，并支持预览、下载和删除。</p>
          </div>
          <div class="toolbar-actions">
            <input v-model="keyword" type="text" class="search-input" placeholder="搜索文件名 / 商户 / 分类" />
          </div>
        </div>
        <div class="table-wrap">
          <div class="table-head file-grid">
            <span>文件名称</span>
            <span>商户</span>
            <span>分类</span>
            <span>大小</span>
            <span>状态</span>
            <span>上传时间</span>
            <span>操作</span>
          </div>
          <div v-for="item in filteredFiles" :key="item.id" class="table-row file-grid">
            <strong>{{ item.file_name }}</strong>
            <span>{{ item.merchant_name }}</span>
            <span>{{ item.category }}</span>
            <span>{{ item.size }}</span>
            <span>{{ item.status }}</span>
            <span>{{ item.uploaded_at }}</span>
            <div class="inline-actions">
              <button class="link-action" @click="currentFile = item">查看</button>
              <button class="link-action" @click="downloadFile(item)">下载</button>
              <button class="link-action" @click="removeFile(item)">删除</button>
            </div>
          </div>
        </div>
      </div>
    </article>

    <el-dialog :model-value="fileDialogVisible" title="文件详情" width="560px" @close="currentFile = null" @update:model-value="syncFileDialog">
      <div v-if="currentFile" class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">{{ currentFile.file_name }}</h3>
            <p class="settings-block-copy">{{ currentFile.preview_text || '查看文件详情与预览内容。' }}</p>
          </div>
        </div>

        <div v-if="currentFileUrl" class="file-preview-block">
          <img v-if="currentFileIsImage" :src="currentFileUrl" :alt="currentFile.file_name" class="file-preview-image" />
          <div v-else class="file-preview-fallback">
            <span>当前文件不支持直接图片预览，请使用下载按钮查看。</span>
            <button class="primary-soft-btn" type="button" @click="downloadFile(currentFile)">下载文件</button>
          </div>
        </div>

        <div class="field-grid compact">
          <div class="field">
            <span class="field-label">商户</span>
            <input :value="currentFile.merchant_name" type="text" readonly />
          </div>
          <div class="field">
            <span class="field-label">分类</span>
            <input :value="currentFile.category" type="text" readonly />
          </div>
          <div class="field">
            <span class="field-label">文件地址</span>
            <input :value="currentFileUrl" type="text" readonly />
          </div>
          <div class="field">
            <span class="field-label">备注</span>
            <textarea :value="currentFile.remark" rows="3" readonly />
          </div>
        </div>

        <div class="dialog-actions">
          <button class="primary-soft-btn" type="button" @click="downloadFile(currentFile)">下载文件</button>
        </div>
      </div>
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

.search-input {
  width: 240px;
}

.dialog-form {
  gap: 0;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
}

.file-grid {
  display: grid;
  grid-template-columns: 1.2fr 0.9fr 0.8fr 0.6fr 0.7fr 1fr 1fr;
  gap: 12px;
  align-items: center;
}

.file-preview-block {
  margin: 0 0 18px;
  padding: 14px;
  border: 1px solid #d8e4f2;
  border-radius: 16px;
  background: #f7fbff;
}

.file-preview-image {
  display: block;
  width: 100%;
  max-height: 360px;
  object-fit: contain;
  border-radius: 12px;
  background: #fff;
}

.file-preview-fallback {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  color: #5f6f86;
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

@media (max-width: 1200px) {
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

  .file-grid {
    grid-template-columns: 1fr;
  }

  .file-preview-fallback {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
