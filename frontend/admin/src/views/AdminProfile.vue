<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { getAdminProfile, saveAdminPassword, saveAdminProfile } from '../lib/api'

const profile = reactive({
  nickname: '',
  email: '',
  phone: '',
  avatar: '',
})

const password = reactive({
  old_password: '',
  new_password: '',
})

async function load() {
  const resp = await getAdminProfile()
  if (resp.code === 0 && resp.data) {
    profile.nickname = resp.data.nickname || ''
    profile.email = resp.data.email || ''
    profile.phone = resp.data.phone || ''
    profile.avatar = resp.data.avatar || ''
  }
}

async function submitProfile() {
  const resp = await saveAdminProfile(profile)
  if (resp.code === 0) {
    sessionStorage.setItem('admin:user', JSON.stringify(resp.data || {}))
    ElMessage.success(resp.message || '资料已保存')
  }
}

async function submitPassword() {
  const resp = await saveAdminPassword(password)
  if (resp.code === 0) {
    password.old_password = ''
    password.new_password = ''
    ElMessage.success(resp.message || '密码修改成功')
  }
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">基础资料</h3>
            <p class="settings-block-copy">头像、昵称和联系方式统一在这里维护。</p>
          </div>
          <div class="toolbar-actions profile-actions">
            <button class="primary-btn" @click="submitProfile">保存资料</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">头像地址</span>
            <input v-model="profile.avatar" type="text" />
          </label>
          <label class="field">
            <span class="field-label">显示名称</span>
            <input v-model="profile.nickname" type="text" />
          </label>
          <label class="field">
            <span class="field-label">邮箱</span>
            <input v-model="profile.email" type="email" />
          </label>
          <label class="field">
            <span class="field-label">手机号</span>
            <input v-model="profile.phone" type="text" />
          </label>
        </div>
      </div>
    </article>

    <article class="metric-card settings-panel settings-workspace">
      <div class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">修改密码</h3>
            <p class="settings-block-copy">建议定期更新密码。</p>
          </div>
          <div class="toolbar-actions profile-actions">
            <button class="primary-btn" @click="submitPassword">更新密码</button>
          </div>
        </div>
        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">原密码</span>
            <input v-model="password.old_password" type="password" />
          </label>
          <label class="field">
            <span class="field-label">新密码</span>
            <input v-model="password.new_password" type="password" />
          </label>
        </div>
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

.profile-actions {
  justify-content: flex-end;
  margin-top: 0;
}

@media (max-width: 820px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }
}
</style>
