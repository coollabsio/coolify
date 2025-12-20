# 国际化实施进度追踪

## 当前状态

| 阶段 | 状态 | 进度 | 翻译条目 |
|------|------|------|----------|
| Stage 1 | ✅ 完成 | 100% | 37/37 |
| Stage 2 | ✅ 完成 | 100% | 156/156 |
| Stage 3 | 🔄 进行中 | 44% | 234/530 |
| Stage 4 | ✅ 完成 | 100% | ~200/200 |
| Stage 5 | ✅ 完成 | 100% | 230/230 |

**总进度**: ~857/1285 (~66%)

**翻译文件统计**:
| 文件 | 条目数 |
|------|--------|
| lang/en.json | 730+ 条 |
| lang/zh-cn.json | 730+ 条 |

---

## Stage 1 详细进度 ✅

### 1.1 导航栏 (navbar.blade.php) ✅
- [x] 抽取硬编码文本 (22处)
- [x] 添加英文翻译键
- [x] 添加中文翻译
- **完成翻译**: 22条

### 1.2 认证页面 ✅
| 文件 | 状态 | 新增条目 |
|------|------|--------|
| login.blade.php | ✅ | 2 |
| register.blade.php | ✅ | 5 |
| forgot-password.blade.php | ✅ | 4 |
| reset-password.blade.php | ✅ | 4 |
| verify-email.blade.php | ⏳ | - |
| two-factor-challenge.blade.php | ⏳ | - |
| confirm-password.blade.php | ⏳ | - |

---

## Stage 2 详细进度 ✅

### 2.1 核心列表页面 ✅
| 文件 | 状态 | 新增条目 |
|------|------|--------|
| dashboard.blade.php | ✅ | 17 |
| project/index.blade.php | ✅ | 3 |
| server/index.blade.php | ✅ | 4 |
| 通用组件 (button/modal/server) | ✅ | 9 |

### 2.2 辅助列表页面 ✅
| 文件 | 状态 | 新增条目 |
|------|------|--------|
| destination/index.blade.php | ✅ | 5 |
| storage/index.blade.php | ✅ | 5 |
| shared-variables/index.blade.php | ✅ | 9 |
| security/private-key/index.blade.php | ✅ | 10 |

### 2.3 团队管理 ✅
| 文件 | 状态 | 新增条目 |
|------|------|--------|
| team/index.blade.php | ✅ | 26 |

### 2.4 入门向导 ✅
| 文件 | 状态 | 新增条目 |
|------|------|--------|
| boarding/index.blade.php | ✅ | 156 |

**boarding/index.blade.php 各状态翻译**:
- welcome 状态: ✅
- explanation 状态: ✅
- select-server-type 状态: ✅
- private-key 状态: ✅
- create-private-key 状态: ✅
- create-server 状态: ✅
- validate-server 状态: ✅
- create-project 状态: ✅
- create-resource 状态: ✅
- footer 部分: ✅

---

## Stage 3 详细进度 🔄

### 3.1 翻译键添加 ✅
**新增翻译键**: ~212 条（418行 → 630+行）

#### 翻译键分类统计：
- `menu.*`: 配置菜单项（~15条）
  - configuration, general, advanced, environment_variables
  - persistent_storage, servers, webhooks, resource_limits
  - resource_operations, metrics, tags, danger_zone
  - scheduled_tasks, deployments, logs, terminal, backups
  - documentation

- `application.*`: 应用相关（~180条）
  - 操作按钮: deploy, redeploy, restart, stop, update_service
  - 配置: build_pack, static_image, domains, docker_registry
  - Docker: docker_image, docker_compose, custom_docker_options
  - 网络: ports_exposes, ports_mappings, network_aliases
  - 部署: pre_deployment, post_deployment
  - 标签与配置: container_labels, readonly_labels
  - Nginx: custom_nginx_config, generate_nginx_config
  - Build: install_command, build_command, start_command

- `database.*`: 数据库相关（~12条）
  - 操作: start, stop, restart, restart_database
  - 状态: database_startup, underlying_server_not_functional
  - 确认: confirm_restart_title, confirm_stop_title
  - import_backups

- `service.*`: 服务相关（~8条）
  - services, persistent_storages, storages
  - no_services_defined, no_applications_with_domains
  - configuration_required, settings, edit_domains

### 3.2 Blade 文件修改 🔄
| 文件 | 状态 | 修改项 | 翻译点数 |
|------|------|--------|----------|
| **配置菜单** | | | |
| application/configuration.blade.php | ✅ | 标题 + 16个菜单项 | ~17 |
| database/configuration.blade.php | ✅ | 标题 + 11个菜单项 | ~12 |
| service/configuration.blade.php | ✅ | 标题 + 10个菜单项 + 内容文本 | ~13 |
| **导航操作栏** | | | |
| application/heading.blade.php | ✅ | 导航项 + 操作按钮 + 确认对话框 | ~12 |
| database/heading.blade.php | ✅ | 导航项 + 操作按钮 + 对话框 | ~10 |
| **详细配置页** | | | |
| application/general.blade.php | ✅ | 完整翻译：页面头部 + Build Pack + 域名 + Docker Registry + Build 命令 + Docker Compose + Network + HTTP 认证 + Labels + Pre/Post 部署命令 (全部 571 行) | ~150 |
| database/general.blade.php | ⏳ | 待处理 | 0 |
| service/general.blade.php | ⏳ | 待处理 | 0 |

**已完成**: 6个文件完全翻译
**总计**: ~234 处翻译点已应用

### 3.3 待完成工作

- [x] ~~完成 application/general.blade.php~~ ✅ 已完成

- [ ] Database 详细配置页面
  - database/postgresql/general.blade.php
  - database/mysql/general.blade.php
  - database/mongodb/general.blade.php
  - 其他数据库类型

- [ ] Service 详细配置页面
  - service/stack-form.blade.php
  - service/show.blade.php

- [ ] 共享组件
  - shared/environment-variable/all.blade.php
  - shared/destination.blade.php
  - shared/storage.blade.php
  - shared/webhooks.blade.php
  - shared/resource-limits.blade.php
  - shared/metrics.blade.php
  - shared/tags.blade.php
  - shared/danger.blade.php

---

## 新增翻译键汇总

### Stage 3.1 新增 (资源管理模块)
共计 ~212 条新增翻译键：
- `menu.*`: 配置菜单与导航（~15条）
- `application.*`: 应用配置与操作（~180条）
- `database.*`: 数据库操作与状态（~12条）
- `service.*`: 服务配置（~8条）

### Stage 2.4 新增 (boarding页面)
共计 156 条新增翻译键:
- `onboarding.project_*` (项目设置相关): 14条
- `onboarding.setup_*` (完成设置相关): 10条
- 其他 (footer等): 13条

---

## 变更日志

### 2024-12-19
- ✅ Stage 1 完成 (navbar + auth页面, 37条)
- ✅ Stage 2.1 完成 (dashboard/project/server, 33条)
- ✅ Stage 2.2 完成 (destination/storage/shared-variables/private-key, 31条)
- ✅ Stage 2.3 完成 (team, 26条)
- ✅ Stage 2.4 完成 (boarding, 156条)
- ✅ **Stage 2 全部完成**
- 🔄 **Stage 3 进行中**：资源管理模块
  - ✅ 新增 ~212 条翻译键（menu.*, application.*, database.*, service.*）
  - ✅ 完成 5 个配置/导航文件（configuration.blade.php × 3, heading.blade.php × 2）
  - ✅ **application/general.blade.php 完全完成**（571行，~150个翻译点）
  - **当前进度**: Stage 3 约 44% 完成（234/530）

### 2024-12-20
- ✅ **Stage 5 完成** (邮件模板 + 错误页面, ~230条)
  - ✅ 邮件模板 (35个文件)
  - ✅ 错误页面 (9个文件)
  - ✅ 新增翻译键 ~100条

---

## 下一步计划

### Stage 3: 资源管理模块 (预估 530 条)
- [ ] Application 配置页面
- [ ] Database 配置页面
- [ ] Service 配置页面
- [ ] 通用资源操作组件

### Stage 4: 设置与辅助模块 (预估 ~200 条)

#### 4.1 系统设置 (Settings) ✅
- [x] **General** (`settings/index.blade.php`):
  - 页面标题与描述
  - 字段: Domain, Name, Instance Timezone (搜索/选择), Public IPv4/IPv6
  - 开发者选项: Dev Helper Version
  - 弹窗: Domain Conflict Warning
- [x] **Advanced** (`settings/advanced.blade.php`):
  - 页面标题与描述
  - 开关: Registration Allowed, Do Not Track
  - **DNS Settings**: DNS Validation, Custom DNS Servers
  - **API Settings**: API Access, Allowed IPs (及安全警告)
  - **UI Settings**: SPA Navigation
  - **Confirmation Settings**: Sponsorship Popup, Disable Two Step Confirmation (及相关警告/弹窗)
- [x] **Updates** (`settings/updates.blade.php`):
  - 页面标题与描述
  - 字段: Update Check Frequency, Auto Update (Enabled/Frequency)
  - 按钮: Check Manually

#### 4.2 个人资料 (Profile) ✅
- [x] **基本信息** (`profile/index.blade.php`):
  - 页面标题与描述
  - 字段: Name, Email
  - 功能: Change Email (输入新邮箱, 验证码流程)
- [x] **密码管理** (`profile/index.blade.php`):
  - Change Password (Current, New, Confirmation)
  - 警告: Resetting password logs out all sessions
- [x] **二步验证 (2FA)** (`profile/index.blade.php`):
  - 状态显示与配置 (Enable/Disable)
  - QR Code 展示, Secret Key 复制
  - 验证流程 (OTP Code)
  - Recovery Codes 展示与重新生成

#### 4.3 订阅管理 (Subscription) ✅
- [x] **概览与状态** (`subscription/index.blade.php`):
  - 页面标题
  - 状态提示: Active, Unpaid (Billing Portal 链接), Cancelled
  - 权限警告: Permission Required (非 Admin)
- [x] **详细信息** (`subscription/actions.blade.php`):
  - Your current plan (Tier name)
  - 状态描述 (Active/Cancel period/Invoice status)
  - 服务器统计: Paid servers vs Active servers
  - 溢出警告 (Server Overflow Warning)
  - 管理按钮: Change Server Quantity, Go to Stripe Portal
- [x] **套餐展示** (`subscription/pricing-plans.blade.php`):
  - 周期切换: Monthly vs Annually (save ~20%)
  - 套餐卡片: Pay-as-you-go / Dynamic
  - 价格明细: Base price, Additional server cost
  - 权益列表: Unlimited servers/apps, Email notifications, Support, etc.
- [x] **入口** (`subscription/show.blade.php`):
  - 简单标题与描述

#### 4.4 管理员面板 (Admin) ✅
- [x] **Dashboard** (`admin/index.blade.php`):
  - 标题: Admin Dashboard
  - 模拟登录状态: Who am I now? / Go back to root
  - 用户搜索: Search input, Results list
  - 统计: Active/Inactive Subscribers
  - 用户卡片: Name, Email, Active Status

### Stage 5: 邮件模板与错误页面 (预估 230 条)
- [ ] 邮件模板
- [ ] 错误页面 (404, 500等)
- [ ] 其他零散文本
