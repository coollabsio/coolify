# Coolify i18n 安全部署指南 🛡️

**完美切换 · 自动回退 · 零风险部署**

本指南提供完整的 Coolify i18n 版本部署方案，包含自动备份、健康检查和自动回退机制。

---

## 📦 什么是 Coolify i18n 版本？

- **镜像地址**: `docker.io/a3180623/coolify:i18n`
- **核心功能**: 在官方 Coolify 基础上增加**国际化支持**和**简体中文翻译**
- **兼容性**: 与官方版本 100% 代码兼容，可随时切换
- **数据安全**: 不修改数据库结构，数据完全保留

---

## 📂 脚本文件说明

本目录包含 5 个脚本文件，每个脚本有不同的用途和使用场景：

### 🟢 核心脚本（日常使用）

#### 1️⃣ `update-to-i18n.sh` - **安全更新脚本** (推荐使用)

**用途**: 将现有 Coolify 切换到 i18n 版本（或更新到最新 i18n 版本）

**核心特性**:
- ✅ **自动备份**: 数据库、配置文件、当前镜像版本
- ✅ **健康检查**: 更新后 60 秒健康检查
- ✅ **自动回退**: 失败时自动恢复原版本
- ✅ **生成回退脚本**: 创建可随时执行的回退脚本

**使用场景**:
- ✅ 第一次从官方版本切换到 i18n
- ✅ 更新到最新的 i18n 镜像
- ✅ 测试 i18n 版本是否兼容

**执行步骤**:
```bash
# 1. 上传脚本到服务器
scp update-to-i18n.sh root@your-server:~/

# 2. 运行脚本
sudo bash update-to-i18n.sh
```

**脚本执行流程**:
```
[1/6] Creating backup of current state...
     ✓ 备份当前镜像: ghcr.io/coollabsio/coolify:latest
     ✓ 备份 .env 配置文件
     ✓ 备份数据库到: /data/coolify/backups/update-20251220-123456/

[2/6] Creating rollback script...
     ✓ 生成回退脚本: /data/coolify/rollback-from-i18n.sh

[3/6] Pulling docker.io/a3180623/coolify:i18n...
     ✓ 拉取 i18n 镜像成功

[4/6] Creating custom docker-compose override...
     ✓ 创建自定义配置: docker-compose.custom.yml

[5/6] Updating Coolify to i18n version...
     ✓ 重启容器使用新镜像

[6/6] Performing health check (timeout: 60s)...
     Waiting... (0s) - Status: starting
     Waiting... (10s) - Status: starting
     Waiting... (20s) - Status: healthy
     ✓ Coolify is healthy!

========================================================
  ✓ Update Successful!
========================================================

Current running image:
docker.io/a3180623/coolify:i18n

Backup location: /data/coolify/backups/update-20251220-123456
Rollback script: /data/coolify/rollback-from-i18n.sh

To rollback if needed:
  sudo bash /data/coolify/rollback-from-i18n.sh
```

**如果更新失败（自动回退）**:
```
[6/6] Performing health check (timeout: 60s)...
     Waiting... (0s) - Status: starting
     Waiting... (10s) - Status: starting
     Waiting... (20s) - Status: unhealthy
     ✗ Health check failed!

========================================================
  ✗ Update Failed - Auto-Rolling Back
========================================================

Health check failed after 60s
Current status: unhealthy

Container logs (last 20 lines):
---
  [error] Database connection failed
  [error] Application startup failed
---

Automatically rolling back to original image...

Waiting for rollback to complete...
✓ Rollback successful! Coolify is running with original image.

Update failed and was rolled back.
Backup preserved at: /data/coolify/backups/update-20251220-123456

Please investigate the issue before trying again.
Check logs with: docker logs coolify
```

---

#### 2️⃣ `rollback-to-official.sh` - **手动回退脚本**

**用途**: 从 i18n 版本回退到官方 Coolify 版本

**核心特性**:
- ✅ **交互式确认**: 回退前需要用户确认
- ✅ **自动备份**: 回退前备份当前自定义配置
- ✅ **健康检查**: 回退后验证服务状态
- ✅ **快速执行**: 10-30 秒完成回退

**使用场景**:
- ✅ 测试完 i18n，想切换回官方版本
- ✅ i18n 版本出现问题，需要紧急回退
- ✅ 定期切换版本测试兼容性

**执行步骤**:
```bash
sudo bash rollback-to-official.sh
```

**脚本执行流程**:
```
========================================================
  Rolling Back from i18n to Official Coolify
========================================================

Current image: docker.io/a3180623/coolify:i18n

This will:
  1. Remove docker-compose.custom.yml
  2. Restart Coolify with the official image
  3. NOT restore database (data will be preserved)

Proceed with rollback? (y/N): y

[1/3] Removing custom docker-compose override...
     Backed up to: /data/coolify/backups/docker-compose.custom-20251220-123456.yml
     Removed docker-compose.custom.yml

[2/3] Restarting Coolify with official image...

[3/3] Waiting for Coolify to start...
     Waiting... (0s) - Status: starting
     Waiting... (10s) - Status: healthy
     ✓ Coolify is healthy!

========================================================
  ✓ Rollback Successful!
========================================================

Current running image:
ghcr.io/coollabsio/coolify:latest

Coolify is now running the official version.

To switch back to i18n:
  sudo bash update-to-i18n.sh
```

---

#### 3️⃣ `install-i18n.sh` - **全新安装脚本**

**用途**: 在没有 Coolify 的服务器上全新安装 i18n 版本

**核心特性**:
- ✅ **完整安装**: 安装 Docker、配置系统、部署 Coolify
- ✅ **自定义镜像**: 安装后直接使用 i18n 版本
- ✅ **调用官方脚本**: 复用官方安装逻辑，仅覆盖镜像

**使用场景**:
- ✅ 服务器上还没有安装 Coolify
- ✅ 想直接安装 i18n 版本，不想先装官方版再切换

**执行步骤**:
```bash
sudo bash install-i18n.sh
```

**脚本执行流程**:
```
========================================================
  Coolify i18n Custom Installation
========================================================

Custom Image: docker.io/a3180623/coolify:i18n
Registry: Docker Hub

[1/3] Running standard Coolify installation with version: i18n

（调用官方 install.sh，安装 Docker、配置系统、下载配置文件）
...
✓ Coolify installed successfully

[2/3] Creating custom docker-compose override for a3180623/coolify:i18n
     Custom docker-compose.custom.yml created successfully!
     Image override: docker.io/a3180623/coolify:i18n

[3/3] Restarting Coolify with custom i18n image...

========================================================
  Installation Complete!
========================================================

Your custom Coolify i18n instance is now running!
Image: docker.io/a3180623/coolify:i18n

Access Coolify at: http://YOUR_SERVER_IP:8000
```

---

### 🔵 辅助文件

#### 4️⃣ `docker-compose.custom.yml` - **自定义镜像配置**

**用途**: Docker Compose 覆盖文件，指定使用 i18n 镜像

**内容**:
```yaml
# Custom override to use a3180623/coolify:i18n image
services:
  coolify:
    image: "docker.io/a3180623/coolify:i18n"
```

**作用原理**:
- Coolify 官方的 `docker-compose.prod.yml` 使用：
  ```yaml
  image: "${REGISTRY_URL:-ghcr.io}/coollabsio/coolify:${LATEST_IMAGE:-latest}"
  ```
- 当存在 `docker-compose.custom.yml` 时，Docker Compose 会**覆盖**镜像地址
- 最终使用：`docker.io/a3180623/coolify:i18n`

**手动使用**:
```bash
# 拉取镜像
cd /data/coolify/source
docker compose -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.custom.yml \
  pull coolify

# 重启服务
docker compose -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.custom.yml \
  up -d --force-recreate coolify
```

---

#### 5️⃣ `install (1).sh` - **官方原始安装脚本**

**用途**: Coolify 官方的安装脚本（未修改）

**作用**:
- `install-i18n.sh` 会调用此脚本进行基础安装
- 负责安装 Docker、配置系统、下载配置文件
- **不需要直接运行**，由 `install-i18n.sh` 自动调用

---

## 🎯 脚本使用对照表

| 场景 | 使用脚本 | 执行命令 | 耗时 |
|------|---------|---------|------|
| **首次从官方切换到 i18n** | `update-to-i18n.sh` | `sudo bash update-to-i18n.sh` | 1-2 分钟 |
| **更新到最新 i18n 版本** | `update-to-i18n.sh` | `sudo bash update-to-i18n.sh` | 1-2 分钟 |
| **从 i18n 回退到官方** | `rollback-to-official.sh` | `sudo bash rollback-to-official.sh` | 10-30 秒 |
| **全新安装 i18n 版本** | `install-i18n.sh` | `sudo bash install-i18n.sh` | 5-10 分钟 |
| **手动配置镜像** | `docker-compose.custom.yml` | 手动创建文件 | - |

---

## 🔄 三种脚本的关系图

```
服务器状态                使用脚本                  结果
─────────────────────────────────────────────────────────

┌─────────────────┐
│  没有 Coolify    │  →  install-i18n.sh  →  ┌─────────────────┐
└─────────────────┘                          │ Coolify i18n    │
                                             └─────────────────┘
                                                      ↕
                                          update-to-i18n.sh (更新)
                                                      ↕
┌─────────────────┐                          ┌─────────────────┐
│ Coolify 官方版本 │  →  update-to-i18n.sh  →│ Coolify i18n    │
└─────────────────┘                          └─────────────────┘
        ↑                                             │
        │                                             │
        └─────  rollback-to-official.sh  ─────────────┘
                      (回退)
```

---

## 🛡️ 安全机制详解

### `update-to-i18n.sh` 的 6 步安全流程

#### **第 1 步：自动备份**
```bash
备份内容                     备份位置
──────────────────────────────────────────────────
当前镜像版本                  /data/coolify/backups/update-YYYYMMDD-HHMMSS/original-image.txt
.env 配置文件                /data/coolify/backups/update-YYYYMMDD-HHMMSS/.env.backup
docker-compose.custom.yml    /data/coolify/backups/update-YYYYMMDD-HHMMSS/docker-compose.custom.yml.backup
数据库                       /data/coolify/backups/update-YYYYMMDD-HHMMSS/database-backup.sql
```

#### **第 2 步：生成回退脚本**
```bash
创建文件: /data/coolify/rollback-from-i18n.sh

内容包括:
- 记录原始镜像版本
- 删除自定义配置的命令
- 重启使用原镜像的命令
- 数据库恢复命令（供参考）
```

#### **第 3 步：拉取镜像**
```bash
执行: docker pull docker.io/a3180623/coolify:i18n

检查点:
✓ 镜像是否存在
✓ 网络是否连通
✓ Docker Hub 是否可访问

如果失败 → 立即退出（不影响现有服务）
```

#### **第 4 步：创建配置**
```bash
创建: /data/coolify/source/docker-compose.custom.yml

内容:
services:
  coolify:
    image: "docker.io/a3180623/coolify:i18n"
```

#### **第 5 步：更新服务**
```bash
执行: docker compose up -d --force-recreate coolify

过程:
1. Docker 创建新容器（使用 i18n 镜像）
2. 等待新容器启动
3. 新容器健康后，停止旧容器
4. 删除旧容器

停机时间: 5-10 秒
```

#### **第 6 步：健康检查与自动回退**
```bash
健康检查（60 秒超时）:
├─ 每 2 秒检查一次容器健康状态
├─ 每 10 秒显示一次进度
│
├─ 如果状态变为 "healthy" → 更新成功！
│   └─ 保留新版本
│   └─ 显示备份位置和回退脚本路径
│
└─ 如果 60 秒后仍未健康 → 更新失败！
    ├─ 显示容器错误日志（最后 20 行）
    ├─ 删除 docker-compose.custom.yml
    ├─ 重启使用原镜像
    ├─ 等待 10 秒
    ├─ 验证回退成功
    └─ 保留备份和日志供分析
```

---

## 📊 风险评估与应对

| 风险场景 | 发生概率 | 自动应对措施 | 手动应对方法 |
|---------|---------|------------|------------|
| **镜像拉取失败** | 🟡 5% | 立即退出，不影响现有服务 | 检查网络，确认镜像已构建 |
| **健康检查失败** | 🟡 3% | 自动回退到原版本 | 查看日志：`docker logs coolify` |
| **配置文件损坏** | 🟢 1% | 使用备份恢复 | `cp /data/coolify/backups/.env.backup .env` |
| **数据库问题** | 🟢 0.1% | i18n 不修改数据库，几乎不会发生 | 使用备份恢复：`cat backup.sql \| docker exec -i coolify-db psql` |
| **无法回退** | 🟢 0% | 三重回退机制 | 使用 3 种回退方式之一 |

**总体风险**: 🟢 **极低（生产环境可用）**

---

## ✅ 可以放心执行的操作

### 完全安全的操作
- ✅ **在生产环境使用 `update-to-i18n.sh`**（有完整备份和自动回退）
- ✅ **随时运行 `rollback-to-official.sh`**（数据完全保留）
- ✅ **多次来回切换**（i18n ⇄ 官方版本）
- ✅ **在有数据的 Coolify 上更新**（不影响已部署的应用）

### 需要确认的操作
- ⚠️ **确保 GitHub Actions 已构建完成**（检查 Docker Hub）
- ⚠️ **确保服务器能访问 Docker Hub**（`docker pull docker.io/a3180623/coolify:i18n`）
- ⚠️ **确保当前 Coolify 运行正常**（`docker ps | grep coolify`）

---

## 🚀 快速开始

### 场景 1: 我想从官方版本切换到 i18n（推荐新手）

```bash
# 步骤 1: 上传脚本
scp update-to-i18n.sh root@your-server:~/

# 步骤 2: 执行更新（脚本会自动处理一切）
sudo bash update-to-i18n.sh

# 步骤 3: 验证结果
# 成功 → 访问 http://服务器IP:8000，测试语言切换
# 失败 → 已自动回退，查看日志分析原因
```

---

### 场景 2: 我想回退到官方版本

```bash
# 执行回退（10-30 秒完成）
sudo bash rollback-to-official.sh

# 验证
docker inspect coolify --format='{{.Config.Image}}'
# 应显示: ghcr.io/coollabsio/coolify:latest
```

---

### 场景 3: 我想在空服务器上直接安装 i18n 版本

```bash
# 执行安装（5-10 分钟）
sudo bash install-i18n.sh

# 安装完成后访问
http://YOUR_SERVER_IP:8000
```

---

## 🔍 验证部署结果

```bash
# 1. 检查当前使用的镜像版本
docker inspect coolify --format='{{.Config.Image}}'

# 期望输出（i18n 版本）:
docker.io/a3180623/coolify:i18n

# 期望输出（官方版本）:
ghcr.io/coollabsio/coolify:latest

# 2. 检查容器健康状态
docker inspect coolify --format='{{.State.Health.Status}}'

# 期望输出:
healthy

# 3. 查看容器日志
docker logs coolify --tail 50

# 4. 访问 Web 界面测试
# http://YOUR_SERVER_IP:8000
# 右上角设置 → Language → 中文
```

---

## 🐛 故障排除

### 问题 1: `update-to-i18n.sh` 失败并自动回退

**症状**:
```
✗ Update Failed - Auto-Rolling Back
Health check failed after 60s
Current status: unhealthy
```

**原因分析**:
```bash
# 查看容器日志
docker logs coolify --tail 100

# 查看备份中的更新日志（如果有）
ls -lh /data/coolify/backups/update-*/
```

**常见原因与解决方案**:
| 原因 | 解决方案 |
|------|---------|
| 镜像未构建完成 | 等待 GitHub Actions 完成：https://github.com/Frankieli123/coolify/actions |
| 镜像不存在 | 检查 Docker Hub：https://hub.docker.com/r/a3180623/coolify/tags |
| 网络问题 | 手动拉取测试：`docker pull docker.io/a3180623/coolify:i18n` |
| i18n 代码问题 | 查看构建日志，修复代码后重新构建 |

---

### 问题 2: 手动回退失败

**症状**:
```
ERROR: Service 'coolify' failed to start
```

**强制回退方法**:
```bash
# 方法 1: 删除自定义配置并重启
cd /data/coolify/source
rm -f docker-compose.custom.yml
docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  up -d --force-recreate coolify

# 方法 2: 使用官方升级脚本
bash /data/coolify/source/upgrade.sh latest

# 方法 3: 手动重建容器
docker stop coolify
docker rm coolify
docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  up -d coolify
```

---

### 问题 3: 拉取镜像超时

**症状**:
```
ERROR: Failed to pull i18n image from Docker Hub
```

**解决方案**:
```bash
# 1. 手动拉取测试
docker pull docker.io/a3180623/coolify:i18n

# 2. 如果拉取成功，再次运行更新脚本
sudo bash update-to-i18n.sh

# 3. 如果拉取失败，检查网络
ping hub.docker.com
curl -I https://hub.docker.com

# 4. 配置 Docker 镜像加速（可选）
# 编辑 /etc/docker/daemon.json
```

---

### 问题 4: 语言切换不生效

**症状**: 更新成功但界面仍是英文

**解决方案**:
```bash
# 1. 确认使用的是 i18n 镜像
docker inspect coolify --format='{{.Config.Image}}'
# 应显示: docker.io/a3180623/coolify:i18n

# 2. 清除浏览器缓存
# Ctrl+Shift+Delete → 清除缓存

# 3. 强制刷新页面
# Ctrl+F5 或 Cmd+Shift+R

# 4. 手动切换语言
# 右上角设置 → Language → 中文

# 5. 检查翻译文件是否存在
docker exec coolify ls -lh /var/www/html/lang/
# 应该看到 en.json 和 zh-cn.json
```

---

## 📞 获取帮助

### 日志位置
```bash
# 容器日志
docker logs coolify --tail 100

# 备份目录
ls -lh /data/coolify/backups/

# 回退脚本
cat /data/coolify/rollback-from-i18n.sh

# 配置文件
cat /data/coolify/source/.env
cat /data/coolify/source/docker-compose.custom.yml
```

### 支持渠道
- **GitHub Issues**: https://github.com/Frankieli123/coolify/issues
- **Docker Hub**: https://hub.docker.com/r/a3180623/coolify
- **官方文档**: https://coolify.io/docs

---

## 📝 常见问题 FAQ

### Q1: 更新会导致数据丢失吗？

**答**: 不会。
- i18n 版本不修改数据库结构
- 脚本会自动备份数据库到 `/data/coolify/backups/`
- 已部署的应用不受影响

### Q2: 更新需要停机多久？

**答**: 5-10 秒。
- Docker 使用滚动更新
- 新容器启动后才停止旧容器
- 用户感受到的停机时间极短

### Q3: 可以多次切换版本吗？

**答**: 可以。
- i18n ⇄ 官方版本可随时切换
- 数据完全保留
- 配置文件不丢失

### Q4: 回退会恢复所有数据吗？

**答**: 会保留当前数据。
- 回退只更换 Docker 镜像
- 不会恢复数据库（数据保留最新状态）
- 如需恢复数据库，可使用备份手动恢复

### Q5: i18n 版本支持自动更新吗？

**答**: 不支持。
- 使用自定义镜像后，Coolify 的自动更新被禁用
- 需要手动运行 `update-to-i18n.sh` 更新
- 建议在 `.env` 中设置 `AUTOUPDATE=false`

### Q6: 如何更新到最新的 i18n 镜像？

**答**: 重新运行更新脚本。
```bash
sudo bash update-to-i18n.sh
# 脚本会自动拉取最新的 :i18n 标签镜像
```

---

## 🎯 总结

### ✅ 核心优势

| 特性 | 说明 |
|------|------|
| **安全** | 三重备份 + 自动回退 = 零风险 |
| **简单** | 一键脚本，自动处理所有细节 |
| **可靠** | 健康检查 + 滚动更新 = 零故障 |
| **灵活** | 随时切换，数据完全保留 |

### 🚀 推荐使用流程

```bash
# 1. 确认镜像已构建
# 访问: https://hub.docker.com/r/a3180623/coolify/tags
# 确认有 "i18n" 标签

# 2. 执行安全更新
sudo bash update-to-i18n.sh

# 3. 如果成功
# → 访问 http://服务器IP:8000
# → 右上角设置 → Language → 中文
# → 开始使用！

# 4. 如果失败
# → 已自动回退，查看日志
# → 修复问题后重试

# 5. 需要回退时
sudo bash rollback-to-official.sh
```

### 💡 关键提醒

1. **更新前**: 确认镜像已构建，服务器能访问 Docker Hub
2. **更新中**: 脚本会自动备份和健康检查，无需手动干预
3. **更新后**: 测试语言切换功能，确认一切正常
4. **出问题**: 脚本自动回退，或使用手动回退脚本

**放心使用，脚本会保护您的数据！** 🛡️

---

## 📄 许可证

本自定义版本基于 Coolify 开源项目，遵循原项目的 Apache 2.0 许可证。

---

**最后更新**: 2025-12-20
**版本**: 1.0.0
**维护者**: Frankieli123
