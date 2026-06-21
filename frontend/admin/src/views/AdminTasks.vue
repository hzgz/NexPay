<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getAdminTaskLogs, getAdminTasks, runAdminTask, saveAdminTask } from '../lib/api'

type TaskItem = {
  key: string
  name: string
  cron?: string
  status?: string
  last_run?: string
}

type TaskRun = {
  task_key: string
  task_name: string
  operator?: string
  executed_at?: string
  status?: string
  result?: string
}

const data = ref<{ items: TaskItem[]; runs: TaskRun[] }>({
  items: [],
  runs: [],
})

const logDialogVisible = ref(false)
const cronDialogVisible = ref(false)
const selectedTaskKey = ref('')
const selectedTaskName = ref('')
const logRuns = ref<TaskRun[]>([])
const cronForm = reactive({
  key: '',
  name: '',
  cron: '',
})

let logTimer: number | undefined

const filteredRuns = computed(() => {
  if (logRuns.value.length > 0) {
    return logRuns.value
  }

  return data.value.runs.filter((item) => item.task_key === selectedTaskKey.value)
})

async function load() {
  const resp = await getAdminTasks()
  if (resp.code === 0 && resp.data) {
    data.value = {
      items: Array.isArray(resp.data.items) ? resp.data.items : [],
      runs: Array.isArray(resp.data.runs) ? resp.data.runs : [],
    }
  }
}

async function loadLogs() {
  if (!selectedTaskKey.value) return

  const resp = await getAdminTaskLogs(selectedTaskKey.value)
  if (resp.code === 0 && resp.data) {
    logRuns.value = Array.isArray(resp.data.runs) ? resp.data.runs : []
  } else {
    logRuns.value = []
  }
}

async function runTask(key: string) {
  const resp = await runAdminTask(key)
  if (resp.code === 0) {
    ElMessage.success(resp.message || '任务执行成功')
    await load()
    if (selectedTaskKey.value === key && logDialogVisible.value) {
      await loadLogs()
    }
  }
}

async function openLogs(item: TaskItem) {
  selectedTaskKey.value = item.key
  selectedTaskName.value = item.name
  logDialogVisible.value = true
  await Promise.all([load(), loadLogs()])

  if (logTimer) {
    window.clearInterval(logTimer)
  }

  logTimer = window.setInterval(() => {
    void load()
    void loadLogs()
  }, 3000)
}

function closeLogs() {
  logDialogVisible.value = false
  selectedTaskKey.value = ''
  selectedTaskName.value = ''
  logRuns.value = []
  if (logTimer) {
    window.clearInterval(logTimer)
    logTimer = undefined
  }
}

function openCronDialog(item: TaskItem) {
  cronForm.key = item.key
  cronForm.name = item.name
  cronForm.cron = item.cron || ''
  cronDialogVisible.value = true
}

async function saveCron() {
  const resp = await saveAdminTask({
    key: cronForm.key,
    cron: cronForm.cron,
  })

  if (resp.code === 0) {
    ElMessage.success(resp.message || '任务配置已保存')
    cronDialogVisible.value = false
    await load()
  }
}

function statusClass(status?: string) {
  if (!status) return 'muted'
  if (status.includes('成功') || status.includes('执行中')) return 'success'
  if (status.includes('失败')) return 'danger'
  if (status.includes('停用')) return 'muted'
  return 'warning'
}

onMounted(load)
onBeforeUnmount(() => {
  if (logTimer) {
    window.clearInterval(logTimer)
  }
})
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-workspace__top">
        <div class="settings-workspace__intro">
          <span class="settings-workspace__eyebrow">任务中心</span>
          <h2 class="settings-workspace__title">任务列表</h2>
          <p class="settings-workspace__copy">支持直接执行任务、编辑定时表达式，并查看单任务实时日志。</p>
        </div>
      </div>

      <div class="settings-stack settings-workspace__body">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">任务目录</h3>
            <p class="settings-block-copy">任务可直接执行、调整定时表达式，并进入实时日志查看单任务运行情况。</p>
          </div>
          <div class="table-wrap">
            <div class="table-head task-grid">
              <span>任务名称</span>
              <span>定时表达式</span>
              <span>状态</span>
              <span>最近执行</span>
              <span>操作</span>
            </div>
            <div v-for="item in data.items" :key="item.key" class="table-row task-grid">
              <strong>{{ item.name }}</strong>
              <span class="cron-cell">{{ item.cron || '-' }}</span>
              <span>
                <span class="status-chip" :class="statusClass(item.status)">
                  {{ item.status || '-' }}
                </span>
              </span>
              <span>{{ item.last_run || '-' }}</span>
              <div class="inline-actions">
                <button class="link-action" type="button" @click="runTask(item.key)">立即执行</button>
                <button class="link-action" type="button" @click="openCronDialog(item)">编辑定时</button>
                <button class="link-action" type="button" @click="openLogs(item)">日志</button>
              </div>
            </div>
          </div>
        </div>

        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">最近执行记录</h3>
            <p class="settings-block-copy">方便快速查看最近一次执行人、时间和任务结果。</p>
          </div>
          <div class="table-wrap">
            <div class="table-head run-grid">
              <span>任务</span>
              <span>执行人</span>
              <span>执行时间</span>
              <span>结果</span>
            </div>
            <div v-for="item in data.runs" :key="`${item.task_key}-${item.executed_at}`" class="table-row run-grid">
              <strong>{{ item.task_name }}</strong>
              <span>{{ item.operator || '-' }}</span>
              <span>{{ item.executed_at || '-' }}</span>
              <span>{{ item.result || '-' }}</span>
            </div>
          </div>
        </div>
      </div>
    </article>

    <el-dialog v-model="cronDialogVisible" title="编辑定时表达式" width="560px">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">定时配置</h3>
            <p class="settings-block-copy">支持 5 位或 6 位定时表达式，用于控制任务执行频率。</p>
          </div>
          <div class="field-grid single">
            <label class="field">
              <span class="field-label">任务名称</span>
              <input :value="cronForm.name" type="text" readonly />
            </label>
            <label class="field">
              <span class="field-label">定时表达式</span>
              <input v-model="cronForm.cron" type="text" placeholder="例如 */1 * * * *" />
              <p class="field-note">保存后按照原有任务调度逻辑执行。</p>
            </label>
          </div>
        </div>
      </div>
      <template #footer>
        <button class="ghost-btn" type="button" @click="cronDialogVisible = false">取消</button>
        <button class="primary-btn" type="button" @click="saveCron">保存</button>
      </template>
    </el-dialog>

    <el-dialog v-model="logDialogVisible" :title="`${selectedTaskName} - 实时日志`" width="860px" @close="closeLogs">
      <div class="dialog-form">
        <div class="settings-block">
          <div class="settings-block-head">
            <h3 class="settings-block-title">实时执行日志</h3>
            <p class="settings-block-copy">弹窗打开后会自动刷新最近日志，用于跟踪当前任务的执行结果。</p>
          </div>
          <div class="info-box task-log-note">
            正在自动刷新最近日志。
          </div>
          <div class="table-wrap">
            <div class="table-head log-grid">
              <span>执行时间</span>
              <span>执行人</span>
              <span>状态</span>
              <span>结果</span>
            </div>
            <div v-for="item in filteredRuns" :key="`${item.task_key}-${item.executed_at}`" class="table-row log-grid">
              <span>{{ item.executed_at || '-' }}</span>
              <span>{{ item.operator || '-' }}</span>
              <span>
                <span class="status-chip" :class="item.status === 'failed' ? 'danger' : 'success'">
                  {{ item.status === 'failed' ? '失败' : '成功' }}
                </span>
              </span>
              <span>{{ item.result || '-' }}</span>
            </div>
            <p v-if="filteredRuns.length === 0" class="empty-note task-log-empty">暂无该任务日志。</p>
          </div>
        </div>
      </div>
    </el-dialog>
  </section>
</template>

<style scoped>
.task-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr 0.7fr 0.9fr 1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.run-grid {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr 1fr 1.1fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.log-grid {
  display: grid;
  grid-template-columns: 1fr 0.8fr 0.7fr 1.4fr;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.cron-cell {
  word-break: break-all;
}

.task-log-note,
.task-log-empty {
  margin: 0;
}

@media (max-width: 820px) {
  .task-log-note {
    margin-inline: 22px;
  }
}
</style>
