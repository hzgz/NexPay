<script setup lang="ts">
const currentPath = window.location.pathname

function isActive(path: string) {
  return currentPath === path
}
</script>

<template>
  <header class="auth-site-header">
    <a class="auth-site-header__brand" href="/">
      <span class="auth-site-header__wordmark">NexPay 支付</span>
    </a>

    <nav class="auth-site-header__nav" aria-label="前台导航">
      <a href="/" :class="{ 'is-active': isActive('/') }">首页</a>
      <a href="/doc" :class="{ 'is-active': isActive('/doc') }">开发文档</a>
      <a href="/demo" :class="{ 'is-active': isActive('/demo') }">支付测试</a>
    </nav>

    <div class="auth-site-header__actions">
      <a href="/user/login" :class="{ 'is-active': isActive('/user/login') }">商户登录</a>
      <a href="/user/register" :class="{ 'is-active': isActive('/user/register') }">商户注册</a>
    </div>
  </header>
</template>
