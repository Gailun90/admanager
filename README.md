# admanager — GLPI 插件

**admanager** 是一个 GLPI 10.x 插件，为企业 IT 管理员提供资产管理、AD 域同步、终端远程控制和软件部署的一体化管理界面。

## 功能概览

### 🖥️ 终端资产管理
- 终端列表展示（在线状态、主机名、IP、操作系统、CPU、内存）
- 终端手动导入到 GLPI（Computer 对象）
- 终端分组管理（创建、编辑、成员分配）
- 终端远程桌面（浏览器内实时画面 + 鼠标键盘控制）
- 终端实时在线状态标识

### 📦 软件部署
- 安装包上传管理（支持 EXE / MSI 等格式）
- 创建部署任务（全部 / 分组 / 单台终端）
- 静默安装、交互安装、安装后重启等参数配置
- 任务进度实时轮询（待执行 / 运行中 / 成功 / 失败）
- 失败原因与完整安装日志查看
- 软件卸载任务

### 🏢 AD 域管理
- Active Directory 用户浏览与搜索
- OU（组织单元）树形结构展示
- 用户同步到 GLPI（创建/更新 User 对象）
- 用户创建模板管理（批量创建 AD 用户）
- IM 账号绑定（企业微信 / 钉钉 / 飞书）

### 🔔 消息通知
- 企业微信机器人通知
- 钉钉机器人通知
- 飞书机器人通知
- 支持自定义通知模板

### 📋 审计日志
- 终端可执行文件操作审计
- 操作记录按终端/时间段过滤查询

## 系统要求

| 环境 | 版本要求 |
|---|---|
| GLPI | 10.x |
| PHP | 8.1+ |
| itasset-api | v1.0.0+ |

## 安装方法

1. 将 `admanager` 目录放入 GLPI 的 `plugins/` 目录
2. 在 GLPI 管理后台 → 插件 → 安装并启用 admanager
3. 进入插件设置，填写 itasset-api 的服务地址和 Token

## 配置说明

插件设置页（GLPI → 设置 → 插件 → admanager）：

| 配置项 | 说明 |
|---|---|
| FastAPI 服务地址 | itasset-api 的内网地址，如 `http://192.168.1.100:8000` |
| API Token | 与 itasset-api `.env` 中 `AGENT_INITIAL_TOKEN` 一致 |
| AD 域控制器 | LDAP 地址 |
| AD 绑定账户 | 有读取权限的域账户 |

## 目录结构

```
admanager/
├── ajax/           # AJAX 接口（部署数据、终端操作等）
├── css/            # 样式文件
├── front/          # 页面入口（import, deploy, groups, about...）
├── inc/            # PHP 类文件（核心逻辑）
├── js/             # 前端脚本
├── templates/      # Twig 模板
├── setup.php       # 插件注册入口
└── hook.php        # GLPI 钩子
```

## 相关项目

- [itasset-api](https://github.com/Gailun90/itasset-api) — FastAPI 后端服务
- [ITAsset4](https://github.com/Gailun90/ITAsset4) — Windows 客户端 Agent

## License

MIT
