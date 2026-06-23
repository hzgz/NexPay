<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { buyUserPackage, getUserPackages } from '../lib/api'

const market = ref<Array<Record<string, any>>>([])
const myPackages = ref<Array<Record<string, any>>>([])
const paymentMethods = ref<Array<Record<string, any>>>([])
const buyingId = ref(0)
const selectedMethod = ref('')
const purchaseDialogVisible = ref(false)
const pendingPackage = ref<Record<string, any> | null>(null)

const selectedMethodName = computed(() => {
  return paymentMethods.value.find((item) => String(item.method_code || item.code || '') === selectedMethod.value)?.name || ''
})

async function loadPackages() {
  const resp = await getUserPackages()
  if (resp.code === 0 && resp.data) {
    market.value = resp.data.market || []
    myPackages.value = resp.data.my_packages || []
    paymentMethods.value = Array.isArray(resp.data.payment_methods) ? resp.data.payment_methods : []

    if (!selectedMethod.value && paymentMethods.value.length > 0) {
      selectedMethod.value = String(paymentMethods.value[0].method_code || paymentMethods.value[0].code || '')
    }
  }
}

function openPurchaseDialog(item: Record<string, any>) {
  const id = Number(item.id || 0)
  if (!id) return

  if (!paymentMethods.value.length) {
    ElMessage.warning('当前暂无可用支付方式')
    return
  }

  pendingPackage.value = item
  if (!selectedMethod.value && paymentMethods.value.length > 0) {
    selectedMethod.value = String(paymentMethods.value[0].method_code || paymentMethods.value[0].code || '')
  }
  purchaseDialogVisible.value = true
}

function closePurchaseDialog() {
  purchaseDialogVisible.value = false
  pendingPackage.value = null
}

async function buyPackage(item: Record<string, any>) {
  const id = Number(item.id || 0)
  if (!id) return

  if (!selectedMethod.value) {
    ElMessage.warning('请先选择支付方式')
    return
  }

  buyingId.value = id
  const resp = await buyUserPackage(id, selectedMethod.value)
  buyingId.value = 0

  if (resp.code === 0 && resp.data) {
    if (resp.data.payment_required && resp.data.pay_url) {
      closePurchaseDialog()
      ElMessage.success(`已创建支付订单，请在新窗口使用${selectedMethodName.value || '所选方式'}完成支付`)
      window.open(String(resp.data.pay_url), '_blank', 'noopener')
      await loadPackages()
      return
    }

    closePurchaseDialog()
    ElMessage.success(resp.data.message || '套餐购买成功')
    await loadPackages()
    return
  }

  if (resp.message) {
    ElMessage.error(resp.message)
  }
}

onMounted(loadPackages)
</script>

<template>
  <section class="workspace-page">
    <section class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>套餐市场</h2>
          <p>点击立即购买后选择支付方式，再创建套餐购买订单。</p>
        </div>
      </header>

      <div class="table-wrap">
        <div class="table-head package-grid">
          <span>套餐名称</span>
          <span>周期</span>
          <span>价格</span>
          <span>权益</span>
          <span>操作</span>
        </div>
        <div v-for="item in market" :key="item.id || item.name" class="table-row package-grid">
          <strong>{{ item.name }}</strong>
          <span>{{ item.duration }}</span>
          <span class="price">{{ item.price }}</span>
          <div class="benefits-line">
            <span v-for="benefit in item.benefits || []" :key="benefit">{{ benefit }}</span>
          </div>
          <button class="primary-btn package-action" type="button" :disabled="buyingId === Number(item.id || 0)" @click="openPurchaseDialog(item)">
            {{ buyingId === Number(item.id || 0) ? '处理中...' : '立即购买' }}
          </button>
        </div>
      </div>
    </section>

    <section class="workspace-section">
      <header class="workspace-section__head">
        <div>
          <h2>我的套餐</h2>
          <p>已生效的套餐统一展示在这里。</p>
        </div>
      </header>

      <div class="table-wrap">
        <div class="table-head row-grid">
          <span>套餐名称</span>
          <span>状态</span>
          <span>开始时间</span>
          <span>结束时间</span>
        </div>
        <div v-for="item in myPackages" :key="`${item.name}-${item.start_time}`" class="table-row row-grid">
          <strong>{{ item.name }}</strong>
          <span>{{ item.status }}</span>
          <span>{{ item.start_time }}</span>
          <span>{{ item.end_time }}</span>
        </div>
      </div>
    </section>

    <el-dialog v-model="purchaseDialogVisible" title="选择支付方式" width="520px" @closed="pendingPackage = null">
      <div class="purchase-dialog">
        <div v-if="pendingPackage" class="purchase-dialog__summary">
          <div>
            <strong>{{ pendingPackage.name || '套餐购买' }}</strong>
            <span>{{ pendingPackage.duration || '-' }}</span>
          </div>
          <strong class="purchase-dialog__price">{{ pendingPackage.price || '0.00' }}</strong>
        </div>

        <div v-if="paymentMethods.length" class="package-methods package-methods--dialog">
          <label
            v-for="item in paymentMethods"
            :key="item.key || item.code || item.method_code"
            class="package-method"
            :class="{ 'is-active': selectedMethod === String(item.method_code || item.code || '') }"
          >
            <input v-model="selectedMethod" type="radio" :value="String(item.method_code || item.code || '')" />
            <img v-if="item.icon" :src="`/${String(item.icon).replace(/^\/+/, '')}`" :alt="item.name" />
            <span>{{ item.name }}</span>
          </label>
        </div>
        <div v-else class="empty-note">暂无可用支付方式。</div>
      </div>

      <template #footer>
        <button class="ghost-btn" type="button" @click="closePurchaseDialog">取消</button>
        <button
          class="primary-btn"
          type="button"
          :disabled="!pendingPackage || !selectedMethod || buyingId === Number(pendingPackage?.id || 0)"
          @click="pendingPackage && buyPackage(pendingPackage)"
        >
          {{ buyingId === Number(pendingPackage?.id || 0) ? '处理中...' : '确定' }}
        </button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.workspace-page {
  display: grid;
  gap: 16px;
}

.workspace-section {
  border: 1px solid var(--brand-border);
  background: #fff;
}

.workspace-section__head {
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--brand-border);
}

.workspace-section__head h2 {
  margin: 0;
  font-size: 16px;
}

.workspace-section__head p {
  margin: 8px 0 0;
  color: var(--brand-subtle);
  font-size: 12px;
  line-height: 1.75;
}

.package-methods {
  display: grid;
  gap: 10px;
}

.package-method {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  min-height: 48px;
  padding: 0 14px;
  border: 1px solid #d8e3f2;
  color: #27405f;
  background: #fff;
  cursor: pointer;
}

.package-method.is-active {
  border-color: #2f6bff;
  background: #f4f8ff;
}

.package-method input {
  display: none;
}

.package-method img {
  width: 18px;
  height: 18px;
  object-fit: contain;
}

.package-methods--dialog {
  grid-template-columns: 1fr;
}

.package-grid {
  display: grid;
  grid-template-columns: 1fr 0.9fr 0.8fr 1.8fr 0.9fr;
  gap: 12px;
  align-items: center;
}

.row-grid {
  display: grid;
  grid-template-columns: 1fr 0.8fr 1fr 1fr;
  gap: 12px;
  align-items: center;
}

.price {
  font-size: 16px;
  font-weight: 800;
}

.benefits-line {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  color: #667085;
}

.benefits-line span {
  padding: 4px 10px;
  border: 1px solid #e6eef9;
  background: #f8fbff;
  font-size: 12px;
}

.package-action {
  justify-self: start;
}

.purchase-dialog {
  display: grid;
  gap: 16px;
}

.purchase-dialog__summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  border: 1px solid var(--brand-border);
  background: #f8fbff;
}

.purchase-dialog__summary div {
  display: grid;
  gap: 6px;
}

.purchase-dialog__summary strong {
  color: #20344f;
  font-size: 14px;
}

.purchase-dialog__summary span {
  color: #72829b;
  font-size: 12px;
}

.purchase-dialog__price {
  font-size: 20px;
  font-weight: 800;
}

.empty-note {
  padding: 14px 16px;
  border: 1px dashed #d8e3f2;
  color: #72829b;
  font-size: 12px;
}

@media (max-width: 960px) {
  .package-grid,
  .row-grid {
    grid-template-columns: 1fr;
  }

  .purchase-dialog__summary {
    display: grid;
  }
}
</style>
