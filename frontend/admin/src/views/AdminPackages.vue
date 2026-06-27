<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getAdminPackages, saveAdminPackage } from '../lib/api'
import AppPagination from '../components/AppPagination.vue'
import { usePagination } from '../lib/pagination'

const data = ref<Record<string, any>>({ items: [] })
const dialogVisible = ref(false)
const packageForm = reactive({
  name: '',
  price: '',
  duration_days: 30,
  benefits: '',
  status_code: 1,
})

const rows = computed(() => data.value.items || [])
const { pagination, total, pagedRows } = usePagination(() => rows.value, 20)
async function load() {
  const resp = await getAdminPackages()
  if (resp.code === 0 && resp.data) {
    data.value = resp.data
  }
}

function openCreateDialog() {
  Object.assign(packageForm, {
    name: '',
    price: '',
    duration_days: 30,
    benefits: '',
    status_code: 1,
  })
  dialogVisible.value = true
}

async function submitPackage() {
  const resp = await saveAdminPackage(packageForm)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '套餐保存成功')
    dialogVisible.value = false
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
              <h3 class="settings-block-title">套餐目录</h3>
              <p class="settings-block-copy">展示名称、价格、周期和套餐权益，方便统一核对上架状态。</p>
            </div>
            <div class="toolbar-actions">
              <button class="primary-btn" type="button" @click="openCreateDialog">新增套餐</button>
            </div>
          </div>
          <div class="table-wrap">
            <div class="table-head package-grid">
              <span>套餐名称</span>
              <span>价格</span>
              <span>周期</span>
              <span>权益</span>
              <span>状态</span>
            </div>
            <div v-for="item in pagedRows" :key="item.id || item.name" class="table-row package-grid">
              <strong>{{ item.name }}</strong>
              <span>{{ item.price }}</span>
              <span>{{ item.duration_days }} 天</span>
              <span>{{ Array.isArray(item.benefits) ? item.benefits.join(' / ') : item.benefits }}</span>
              <span>{{ item.status }}</span>
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
      </div>
    </article>

    <el-dialog v-model="dialogVisible" title="新增套餐" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">套餐信息</h3>
            <p class="settings-block-copy">录入套餐基础信息与权益文案，保存后沿用现有创建流程。</p>
          </div>
          <div class="field-grid compact">
            <label class="field">
              <span class="field-label">套餐名称</span>
              <input v-model="packageForm.name" type="text" />
            </label>
            <label class="field">
              <span class="field-label">套餐价格</span>
              <input v-model="packageForm.price" type="text" />
            </label>
            <label class="field">
              <span class="field-label">有效天数</span>
              <input v-model="packageForm.duration_days" type="number" min="1" />
            </label>
            <label class="field">
              <span class="field-label">状态</span>
              <select v-model="packageForm.status_code">
                <option :value="1">上架中</option>
                <option :value="0">已下架</option>
              </select>
            </label>
            <label class="field field-span-2">
              <span class="field-label">套餐权益</span>
              <textarea v-model="packageForm.benefits" rows="4" placeholder="每行一项权益" />
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="dialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="submitPackage">保存</button>
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

.package-grid {
  display: grid;
  grid-template-columns: 0.9fr 0.7fr 0.7fr 1.5fr 0.7fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

@media (max-width: 720px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }
}
</style>
