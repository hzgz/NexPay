<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import AppPagination from '../components/AppPagination.vue'
import { deleteUserFile, getUserFiles } from '../lib/api'
import { resetPagination, usePagination } from '../lib/pagination'

const items = ref<Array<Record<string, any>>>([])
const currentFile = ref<Record<string, any> | null>(null)
const dialogVisible = computed(() => !!currentFile.value)
const currentFileIsImage = computed(() => isImageFile(currentFile.value))
const currentFileUrl = computed(() => resolveFileUrl(currentFile.value))
const { pagination, total, pagedRows } = usePagination(() => items.value, 20)

async function load() {
  const resp = await getUserFiles()
  if (resp.code === 0 && resp.data) {
    items.value = resp.data.items || []
    resetPagination(pagination)
  }
}

async function removeFile(item: Record<string, any>) {
  await ElMessageBox.confirm(`确认删除文件 ${item.file_name} 吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })

  const resp = await deleteUserFile(item.id)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '文件已删除')
    await load()
  }
}

function syncDialog(value: boolean) {
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
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">文件中心</span>
          <h2 class="settings-workspace__title">文件管理</h2>
          <p class="settings-workspace__copy">支持文件预览、下载和删除，图片文件会在弹窗中直接显示。</p>
        </div>
      </div>

      <div class="table-wrap settings-workspace__body">
        <div class="table-head file-grid">
          <span>文件名称</span>
          <span>分类</span>
          <span>大小</span>
          <span>状态</span>
          <span>上传时间</span>
          <span>操作</span>
        </div>
        <div v-for="item in pagedRows" :key="item.id" class="table-row file-grid">
          <strong class="file-cell file-cell--name" data-label="文件名称">{{ item.file_name }}</strong>
          <span class="file-cell file-cell--category" data-label="分类">{{ item.category }}</span>
          <span class="file-cell file-cell--size" data-label="大小">{{ item.size }}</span>
          <span class="file-cell file-cell--status" data-label="状态">{{ item.status }}</span>
          <span class="file-cell file-cell--uploaded" data-label="上传时间">{{ item.uploaded_at }}</span>
          <div class="inline-actions file-cell file-cell--actions" data-label="操作">
            <button class="link-action" @click="currentFile = item">查看</button>
            <button class="link-action" @click="downloadFile(item)">下载</button>
            <button class="link-action" @click="removeFile(item)">删除</button>
          </div>
        </div>
        <AppPagination
          :total="total"
          :page="pagination.page"
          :page-size="pagination.pageSize"
          @update:page="pagination.page = $event"
          @update:page-size="pagination.pageSize = $event"
        />
      </div>
    </article>

    <el-dialog :model-value="dialogVisible" title="文件详情" width="560px" @close="currentFile = null" @update:model-value="syncDialog">
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
            <span class="field-label">分类</span>
            <input :value="currentFile.category" type="text" readonly />
          </div>
          <div class="field">
            <span class="field-label">文件地址</span>
            <input :value="currentFileUrl" type="text" readonly />
          </div>
          <div class="field">
            <span class="field-label">备注</span>
            <textarea :value="currentFile.remark" rows="4" readonly />
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
  grid-template-columns: minmax(260px, 1.6fr) minmax(110px, 0.8fr) minmax(90px, 0.6fr) minmax(90px, 0.7fr) minmax(160px, 1fr) minmax(120px, auto);
  gap: 12px;
  align-items: center;
  min-width: 920px;
}

.file-cell {
  min-width: 0;
}

.file-cell--name {
  word-break: break-all;
}

.file-cell--actions {
  justify-self: end;
}

.table-row.file-grid {
  padding-top: 14px;
  padding-bottom: 14px;
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
  .table-head.file-grid {
    display: none;
  }

  .file-grid {
    min-width: 0;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px 18px;
  }

  .table-row.file-grid {
    grid-template-areas:
      "name actions"
      "category size"
      "status uploaded";
    align-items: stretch;
    padding-top: 18px;
    padding-bottom: 18px;
  }

  .file-cell {
    display: grid;
    gap: 4px;
  }

  .file-cell::before {
    content: attr(data-label);
    color: #72829b;
    font-size: 12px;
    line-height: 1.4;
    font-weight: 500;
  }

  .file-cell--name {
    grid-area: name;
    gap: 0;
    font-size: 14px;
    line-height: 1.65;
  }

  .file-cell--category {
    grid-area: category;
  }

  .file-cell--size {
    grid-area: size;
  }

  .file-cell--status {
    grid-area: status;
  }

  .file-cell--uploaded {
    grid-area: uploaded;
  }

  .file-cell--actions {
    grid-area: actions;
    gap: 8px;
    justify-self: end;
    justify-content: flex-end;
    align-content: start;
    text-align: right;
  }

  .file-cell--name::before {
    display: none;
  }

  .file-cell--actions::before {
    display: none;
  }

  .file-cell--category,
  .file-cell--size,
  .file-cell--status,
  .file-cell--uploaded {
    padding: 10px 12px;
    border: 1px solid #e4edf8;
    border-radius: 10px;
    background: #f8fbff;
  }

  .file-preview-fallback {
    flex-direction: column;
    align-items: stretch;
  }
}

@media (max-width: 760px) {
  .file-grid {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .table-row.file-grid {
    grid-template-areas:
      "name"
      "category"
      "size"
      "status"
      "uploaded"
      "actions";
  }

  .file-cell--name {
    gap: 4px;
  }

  .file-cell--name::before,
  .file-cell--actions {
    display: grid;
  }

  .file-cell--actions::before {
    display: block;
  }

  .file-cell--actions {
    justify-self: stretch;
    justify-content: flex-start;
    text-align: left;
  }
}
</style>
