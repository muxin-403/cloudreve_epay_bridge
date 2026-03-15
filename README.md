# Cloudreve Epay Bridge

Cloudreve V4 的易支付收银桥接服务，提供订单创建、状态查询、收银台支付和后台管理能力。

## 当前版本

- 版本：`0.0.1`
- 核心变化：
- 收银台页面 `checkout.php` 已改为 Vue 前端驱动
- 管理后台页面 `admin.php`（登录 + 订单列表）已改为 Vue 前端驱动
- 新增单文件构建脚本与自动发布流水线

## 主要特性

- Cloudreve V4 接口兼容（创建订单 / 查询状态）
- Epay 多支付渠道支持（支付宝、微信、QQ 钱包、云闪付等）
- 后台订单查询、筛选、分页、统计、过期订单清理
- 可视化配置管理（`config_manager.php`）
- 环境识别与支付方式推荐 / 自动跳转
- Docker 构建支持
- 单文件运行包构建支持（standalone）

## 系统要求

- PHP `>= 8.0`（建议 8.2）
- SQLite `3`
- PHP 扩展：`pdo_sqlite`, `json`, `openssl`, `curl`, `zip`（构建单文件时）

## 快速开始

1. 将项目部署到 Web 根目录
2. 访问 `install.php` 完成初始化
3. 在 Cloudreve 中配置支付接口地址为：

```text
https://your-domain/api.php
```

## 本地运行（开发）

```bash
php -S 0.0.0.0:8080 -t .
```

然后访问 `http://127.0.0.1:8080/install.php`。

## Docker 运行

### 本地构建

```bash
docker build -t cloudreve-epay-bridge:0.0.1 .
docker run --rm -p 8080:80 cloudreve-epay-bridge:0.0.1
```

### GitHub Action 产物

流水线会生成 Docker 镜像 tar 包（artifact），可下载后加载：

```bash
docker load -i cloudreve_epay_bridge-v0.0.1.tar
```

## 单文件运行

### 构建

```bash
php scripts/build-single.php --version=0.0.1
```

输出文件：

```text
dist/cloudreve_epay_bridge-v0.0.1.php
```

### 运行

```bash
php dist/cloudreve_epay_bridge-v0.0.1.php --host=0.0.0.0 --port=8080
```

## GitHub Actions 自动构建

工作流文件：`.github/workflows/build-release.yml`

触发方式：

- 推送标签：`v*`（例如 `v0.0.1`）
- 手动触发：`workflow_dispatch`

自动产物：

- 单文件运行包：`cloudreve_epay_bridge-v<version>.php`
- 单文件校验：`cloudreve_epay_bridge-v<version>.php.sha256`
- Docker 镜像 tar artifact

### 可选：自动推送 GHCR

如需自动推送 `ghcr.io/<owner>/<repo>:<version>`，请在仓库 Secret 中配置：

- `GHCR_PAT`（需要 `write:packages` 权限）

未配置时，工作流会跳过 GHCR 推送步骤，但仍会完成 Docker 构建与 artifact 上传。

## 发布流程（建议）

```bash
git add .
git commit -m "release: v0.0.1"
git tag -a v0.0.1 -m "Release v0.0.1"
git push origin main
git push origin v0.0.1
```

## 常见问题

- `write_package` 报错：说明 GHCR 推送权限不足，配置 `GHCR_PAT` 即可
- `strict_types declaration must be the very first statement`：检查 PHP 文件是否含 BOM
- SQLite 初始化失败：确认 `database/` 目录可写

## 许可证

本项目采用 `GPL-3.0` 许可证，详见 `LICENSE`。
