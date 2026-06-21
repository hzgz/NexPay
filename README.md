# NexPay

NexPay 是一个基于 `Webman + Vue 3` 的聚合支付系统，提供：

- 管理员后台
- 商户中心
- 首页与演示页
- 易支付 V1 / V2 兼容接口
- 插件化支付扩展能力

## 技术栈

### 后端

- PHP `8.1+`
- [Webman 2.x](https://www.workerman.net/webman)
- Think ORM
- Redis Queue
- Composer

### 前端

- Vue 3
- Vite
- TypeScript
- Element Plus
- Vue Router
- ECharts

## 运行环境要求

建议环境：

- Windows / Linux
- PHP `8.1+`
- Composer `2.x`
- Node.js `20+`
- npm `10+`
- MySQL `5.7+` / `8.0+`
- Redis `6+`

建议 PHP 扩展：

- `openssl`
- `pdo_mysql`
- `redis`
- `mbstring`
- `curl`
- `json`
- `fileinfo`

## 目录结构

```text
backend/                 Webman 后端主程序
  app/                   控制器、服务、模型、支付流程
  config/                路由、进程、数据库、会话等配置
  database/              数据库结构
  plugins/               支付及扩展插件
  public/                单端口静态发布目录
  support/               启动与基础支持代码

frontend/
  home/                  首页与演示页前端源码
  admin/                 管理员后台前端源码
  user/                  商户中心前端源码

scripts/                 构建与辅助脚本
```

## 接口入口

默认访问入口：

- 首页：`/`
- 演示：`/demo`
- 管理后台：`/admin`
- 商户中心：`/user`

后端接口分组：

- 管理后台：`/api/admin/*`
- 商户中心：`/api/merchant/*`
- 首页：`/api/home/*`

### 易支付 V1

- `POST /mapi.php`
- `POST /api.php`
- 页面兼容入口：
  - `/submit.php`
  - `/submit2.php`

### 易支付 V2

- `POST /api/pay/create`
- `POST /api/pay/query`
- `POST /api/pay/refund`
- `POST /api/pay/refundquery`
- `POST /api/pay/close`
- `POST /api/transfer/submit`
- `POST /api/transfer/query`
- `POST /api/transfer/balance`

## 安装与启动

### 1. 安装后端依赖

```powershell
cd backend
composer install
```

### 2. 配置环境变量

```powershell
Copy-Item .env.example .env
```

按实际环境修改：

- `APP_URL`
- `HTTP_PORT`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `REDIS_HOST`
- `REDIS_PORT`
- `TOKEN_SECRET`
- `PLATFORM_PUBLIC_KEY`
- `PLATFORM_PRIVATE_KEY`

### 3. 安装前端依赖

```powershell
cd frontend/home
npm install

cd ../admin
npm install

cd ../user
npm install
```

### 4. 启动后端

```powershell
cd backend
php windows.php
```

## 单端口部署

执行：

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\build-release.ps1
```

该脚本会构建：

- `frontend/home`
- `frontend/admin`
- `frontend/user`

并发布到：

- `backend/public`
- `backend/public/admin`
- `backend/public/user`

部署后启动：

```powershell
cd backend
php windows.php
```

## 说明

- 本项目包含大量插件与兼容接口，实际投产前请按所需链路完成单独验收。
- 请勿将本地密钥、运行日志、上传文件、依赖目录直接纳入公开仓库。

## 仓库

- [hzgz/NexPay](https://github.com/hzgz/NexPay)
