<script setup lang="ts">
import { docErrorRows, docMenus, docRequestRows, docResponseRows } from '../content'
</script>

<template>
  <main class="page-surface">
    <section class="surface-shell surface-shell--doc">
      <aside class="doc-sidebar">
        <div class="doc-sidebar__head">
          <strong>开发者文档</strong>
          <span>统一下单（支付接口）</span>
        </div>

        <div class="doc-search">
          <input type="text" placeholder="搜索文档" />
        </div>

        <nav class="doc-menu">
          <a v-for="item in docMenus" :key="item" href="#payment-api" :class="{ 'is-active': item === '统一下单' }">
            {{ item }}
          </a>
        </nav>
      </aside>

      <article class="doc-content">
        <div class="doc-head">
          <div class="doc-path">
            <span>POST</span>
            <strong>/v1/payments</strong>
          </div>
          <h1>统一下单（支付接口）</h1>
          <p>创建支付订单时，提交以下参数即可获得支付链接或收银台地址。</p>
        </div>

        <section id="payment-api" class="doc-block">
          <h2>请求参数</h2>
          <table class="doc-table">
            <thead>
              <tr>
                <th>参数名</th>
                <th>类型</th>
                <th>必填</th>
                <th>说明</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in docRequestRows" :key="item[0]">
                <td>{{ item[0] }}</td>
                <td>{{ item[1] }}</td>
                <td>{{ item[2] }}</td>
                <td>{{ item[3] }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="doc-block">
          <h2>请求示例</h2>
          <pre class="code-block">{
  "merchant_id": "170000000001",
  "out_trade_no": "TEST20250620000001",
  "amount": 10000,
  "currency": "CNY",
  "subject": "NexPay 测试商品",
  "notify_url": "https://your-domain.com/callback",
  "return_url": "https://your-domain.com/success"
}</pre>
        </section>

        <section class="doc-block">
          <h2>响应示例</h2>
          <table class="doc-table">
            <thead>
              <tr>
                <th>字段名</th>
                <th>示例</th>
                <th>说明</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in docResponseRows" :key="item[0]">
                <td>{{ item[0] }}</td>
                <td>{{ item[1] }}</td>
                <td>{{ item[2] }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="doc-block">
          <h2>错误码</h2>
          <table class="doc-table">
            <thead>
              <tr>
                <th>错误码</th>
                <th>说明</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in docErrorRows" :key="item[0]">
                <td>{{ item[0] }}</td>
                <td>{{ item[1] }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </article>
    </section>
  </main>
</template>
