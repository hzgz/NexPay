<script setup lang="ts">
import { docOverview, docSections } from '../content'
</script>

<template>
  <main class="page-surface page-surface--document">
    <section class="doc-hero">
      <div class="doc-hero__copy">
        <span class="doc-hero__eyebrow">系统真实接口</span>
        <h1>{{ docOverview.title }}</h1>
        <p>{{ docOverview.subtitle }}</p>

        <div class="doc-hero__badges">
          <span v-for="item in docOverview.badges" :key="item">{{ item }}</span>
        </div>
      </div>

      <div class="doc-hero__quick">
        <strong>快捷入口</strong>
        <div class="doc-hero__quick-links">
          <a
            v-for="item in docOverview.quickLinks"
            :key="item.label"
            :href="item.target"
            :target="item.external ? '_blank' : undefined"
            :rel="item.external ? 'noopener' : undefined"
          >
            {{ item.label }}
          </a>
        </div>
      </div>
    </section>

    <section class="doc-shell">
      <aside class="doc-aside">
        <div class="doc-aside__panel">
          <span class="doc-aside__label">目录导航</span>
          <nav class="doc-aside__nav">
            <a v-for="item in docSections" :key="item.id" :href="`#${item.id}`">{{ item.menu }}</a>
          </nav>
        </div>
      </aside>

      <div class="doc-main">
        <article v-for="section in docSections" :id="section.id" :key="section.id" class="doc-panel">
          <div class="doc-panel__head">
            <div class="doc-panel__meta">
              <span>{{ section.eyebrow }}</span>
              <div class="doc-endpoint">
                <em>{{ section.method }}</em>
                <strong>{{ section.endpoint }}</strong>
              </div>
            </div>

            <div class="doc-panel__intro">
              <h2>{{ section.title }}</h2>
              <p>{{ section.summary }}</p>
            </div>
          </div>

          <div v-if="section.notes?.length" class="doc-note-list">
            <div v-for="item in section.notes" :key="item" class="doc-note-item">
              {{ item }}
            </div>
          </div>

          <section v-if="section.requestRows?.length" class="doc-section">
            <h3>请求参数</h3>
            <div class="doc-table-wrap">
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
                  <tr v-for="row in section.requestRows" :key="row.name">
                    <td>{{ row.name }}</td>
                    <td>{{ row.type }}</td>
                    <td>{{ row.required }}</td>
                    <td>{{ row.description }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="section.requestExample" class="doc-section">
            <h3>请求示例</h3>
            <pre class="code-block">{{ section.requestExample }}</pre>
          </section>

          <section v-if="section.responseRows?.length" class="doc-section">
            <h3>响应字段</h3>
            <div class="doc-table-wrap">
              <table class="doc-table">
                <thead>
                  <tr>
                    <th>字段名</th>
                    <th>示例</th>
                    <th>说明</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in section.responseRows" :key="row.name">
                    <td>{{ row.name }}</td>
                    <td>{{ row.example }}</td>
                    <td>{{ row.description }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="section.responseExample" class="doc-section">
            <h3>响应示例</h3>
            <pre class="code-block">{{ section.responseExample }}</pre>
          </section>

          <section v-if="section.errorRows?.length" class="doc-section">
            <h3>常见错误</h3>
            <div class="doc-table-wrap">
              <table class="doc-table">
                <thead>
                  <tr>
                    <th>错误码</th>
                    <th>说明</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in section.errorRows" :key="row.code">
                    <td>{{ row.code }}</td>
                    <td>{{ row.description }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </article>
      </div>
    </section>
  </main>
</template>
