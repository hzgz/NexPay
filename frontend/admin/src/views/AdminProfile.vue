<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getAdminProfile,
  resolveAdminAvatarUrl,
  saveAdminPassword,
  saveAdminProfile,
  setAdminSessionUser,
  uploadAdminAvatar,
} from '../lib/api'

const profile = reactive<Record<string, any>>({
  nickname: '',
  email: '',
  phone: '',
  avatar: '',
  username: '',
})

const password = reactive({
  old_password: '',
  new_password: '',
})

const avatarUploading = ref(false)
const avatarInputRef = ref<HTMLInputElement | null>(null)

const avatarPreview = computed(() => {
  const avatar = String(profile.avatar || '').trim()
  if (avatar) {
    return resolveAdminAvatarUrl(avatar, profile.avatar_version)
  }

  const letter = String(profile.nickname || profile.username || 'A').trim().charAt(0).toUpperCase() || 'A'
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="112" height="112" viewBox="0 0 112 112"><rect width="112" height="112" rx="24" fill="#0d66ff"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="42" font-weight="700">${letter}</text></svg>`
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
})

function syncSessionUser(payload: Record<string, any>) {
  const nextPayload = { ...(payload || {}) }
  if (String(nextPayload.avatar || '').trim() !== '') {
    nextPayload.avatar_version = Date.now()
  }
  setAdminSessionUser(nextPayload)
}

async function load() {
  const resp = await getAdminProfile()
  if (resp.code === 0 && resp.data) {
    profile.nickname = resp.data.nickname || ''
    profile.email = resp.data.email || ''
    profile.phone = resp.data.phone || ''
    profile.avatar = resp.data.avatar || ''
    profile.username = resp.data.username || ''
  }
}

async function submitProfile() {
  const resp = await saveAdminProfile(profile)
  if (resp.code === 0) {
    syncSessionUser(resp.data || {})
    ElMessage.success(resp.message || '资料已保存')
    await load()
    return
  }

  ElMessage.error(resp.message || '资料保存失败')
}

async function submitPassword() {
  const resp = await saveAdminPassword(password)
  if (resp.code === 0) {
    password.old_password = ''
    password.new_password = ''
    ElMessage.success(resp.message || '密码修改成功')
    return
  }

  ElMessage.error(resp.message || '密码修改失败')
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
    const resp = await uploadAdminAvatar(file)
    if (resp.code === 0 && resp.data?.avatar) {
      profile.avatar = String(resp.data.avatar)
      ;(profile as Record<string, any>).avatar_version = Date.now()
      syncSessionUser(resp.data.user || { avatar: profile.avatar })
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
      <div class="settings-block settings-workspace__body">
        <div class="settings-block-head settings-block-head--split">
          <div>
            <h3 class="settings-block-title">基础资料</h3>
            <p class="settings-block-copy">头像、昵称和联系方式统一在这里维护。</p>
          </div>
          <div class="toolbar-actions profile-actions">
            <button class="primary-btn" type="button" @click="submitProfile">保存资料</button>
          </div>
        </div>

        <div class="profile-hero-row">
          <div class="admin-avatar-card">
            <img class="admin-avatar-card__preview" :src="avatarPreview" alt="admin avatar preview" />
            <div class="admin-avatar-card__meta">
              <strong>{{ profile.nickname || profile.username || '系统管理员' }}</strong>
              <span>{{ profile.username || 'admin' }}</span>
            </div>
          </div>

          <label class="profile-avatar-field">
            <span class="field-label profile-avatar-field__label">头像地址</span>
            <input v-model="profile.avatar" class="profile-avatar-field__input" type="text" />
          </label>

          <div class="profile-avatar-actions">
            <button class="ghost-btn avatar-upload-btn" :disabled="avatarUploading" type="button" @click="openAvatarPicker">
              {{ avatarUploading ? '上传中...' : '上传图片' }}
            </button>
            <input ref="avatarInputRef" class="avatar-input-row__native" type="file" accept="image/*" @change="handleAvatarChange" />
          </div>
        </div>

        <div class="field-grid compact">
          <label class="field">
            <span class="field-label">显示名称</span>
            <input v-model="profile.nickname" type="text" />
          </label>
          <label class="field">
            <span class="field-label">登录账号</span>
            <input :value="profile.username" type="text" readonly />
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
            <button class="primary-btn" type="button" @click="submitPassword">更新密码</button>
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

.profile-hero-row {
  display: grid;
  grid-template-columns: minmax(260px, auto) minmax(0, 1fr) auto;
  gap: 16px;
  align-items: center;
  margin-bottom: 20px;
  padding: 16px 18px;
  border: 1px solid var(--brand-border);
  border-radius: 16px;
  background: #fff;
}

.admin-avatar-card {
  display: flex;
  align-items: center;
  gap: 16px;
  min-width: 0;
}

.admin-avatar-card__preview {
  width: 72px;
  height: 72px;
  border-radius: 18px;
  object-fit: cover;
  flex: none;
  border: 1px solid rgba(13, 102, 255, 0.12);
}

.admin-avatar-card__meta {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.admin-avatar-card__meta strong {
  color: #21344d;
  font-size: 15px;
}

.admin-avatar-card__meta span {
  color: #7c8ea6;
  font-size: 12px;
}

.profile-avatar-field {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr);
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.profile-avatar-field__label {
  white-space: nowrap;
}

.profile-avatar-field__input {
  min-width: 0;
}

.profile-avatar-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.avatar-upload-btn {
  width: 132px;
  min-width: 132px;
  justify-content: center;
}

.avatar-input-row__native {
  display: none;
}

@media (max-width: 820px) {
  .settings-block-head--split {
    flex-direction: column;
    align-items: stretch;
  }

  .settings-block-head--split .toolbar-actions {
    justify-content: flex-start;
  }

  .profile-hero-row {
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .admin-avatar-card {
    align-items: flex-start;
  }

  .profile-avatar-field {
    grid-template-columns: 1fr;
  }

  .profile-avatar-actions {
    justify-content: flex-start;
  }

  .avatar-upload-btn {
    width: 100%;
    min-width: 0;
  }
}
</style>
