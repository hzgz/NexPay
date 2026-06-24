<script setup lang="ts">
import type { PageKind } from '../content'

const props = defineProps<{
  pageKind: PageKind
  logoSrc: string
}>()
</script>

<template>
  <header class="site-header site-header--home">
    <a class="brand-link" href="/">
      <span class="brand-wordmark">NexPay 支付</span>
    </a>

    <nav class="site-nav" aria-label="前台导航">
      <a href="/" :class="{ 'is-active': props.pageKind === 'home' }" :aria-current="props.pageKind === 'home' ? 'page' : undefined">首页</a>
      <a href="/doc" :class="{ 'is-active': props.pageKind === 'doc' }" :aria-current="props.pageKind === 'doc' ? 'page' : undefined">开发文档</a>
      <a href="/demo" :class="{ 'is-active': props.pageKind === 'demo' }" :aria-current="props.pageKind === 'demo' ? 'page' : undefined">支付测试</a>
    </nav>

    <div class="site-actions">
      <a class="text-link" href="/user/login">商户登录</a>
      <a class="text-link text-link--strong" href="/user/register">商户注册</a>
    </div>
  </header>
</template>
