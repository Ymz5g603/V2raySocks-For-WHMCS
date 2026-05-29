# V2raySocks VLESS-Reality + xrayR 重构指南

## 📋 目录
1. [项目概述](#项目概述)
2. [架构设计](#架构设计)
3. [安装指南](#安装指南)
4. [配置说明](#配置说明)
5. [API 文档](#api-文档)
6. [故障排查](#故障排查)

---

## 项目概述

### 什么是 VLESS-Reality？
VLESS-Reality 是一个新型代理协议，基于 Xray 核心，提供：
- ✅ 更好的伪装性能
- ✅ 更低的延迟
- ✅ 更强的隐蔽性
- ✅ Reality 穿透能力

### 什么是 xrayR？
xrayR 是一个 Xray/V2Ray 的管理后端，提供：
- 🔧 API 驱动的用户管理
- 📊 实时流量统计
- 🌐 多节点支持
- 🔐 完整的鉴权机制

---

## 架构设计

### 系统架构
```
┌─────────────────────────────────────────────┐
│           WHMCS 前端系统                     │
│     (客户端管理、订单、套餐)                  │
└────────────┬────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────┐
│    V2raySocks 插件（重构版）                 │
│  - 协议检测 (VMESS / VLESS-Reality)         │
│  - 账户管理                                  │
│  - 流量统计                                  │
└────────────┬────────────────────────────────┘
             │
      ┌──────┴──────┐
      │             │
   ▼(VMESS)   ▼(VLESS-Reality)
   本地DB      xrayR API
   ├─V2Ray    ├─节点1
   ├─用户管理  ├─节点2
   └─流量统计  └─节点N
```

### 文件结构
```
Plugin/V2raySocks/
├── lib/
│   ├── config.php              # 配置管理
│   ├── functions.php           # 通用函数
│   ├── xrayR-api.php          # xrayR 客户端
│   ├── vless-reality.php      # VLESS-Reality 支持
│   └── Mobile_Detect.php
├── V2raySocks.php             # 原版主模块
├── V2raySocks_refactored.php  # 重构版主模块 ⭐
├── api.php
├── hooks.php
└── templates/
```

---

## 安装指南

### 前置条件
- WHMCS 8.0+ 
- PHP 7.2+
- MySQL 5.7+
- xrayR 服务器已部署

### 步骤 1: 下载文件
```bash
# 克隆特性分支
git clone -b feature/vless-reality-xrayR \
  https://github.com/Ymz5g603/V2raySocks-For-WHMCS.git
```

### 步骤 2: 部署文件
```bash
# 备份原文件
cp Plugin/V2raySocks/V2raySocks.php \
   Plugin/V2raySocks/V2raySocks.php.backup

# 使用重构版本
cp Plugin/V2raySocks/V2raySocks_refactored.php \
   Plugin/V2raySocks/V2raySocks.php
```

### 步骤 3: 创建必要文件
```bash
# 创建配置文件
touch Plugin/V2raySocks/lib/config.php

# 确保文件权限正确
chmod 644 Plugin/V2raySocks/lib/config.php
chmod 644 Plugin/V2raySocks/lib/xrayR-api.php
chmod 644 Plugin/V2raySocks/lib/vless-reality.php
```

### 步骤 4: 在 WHMCS 中激活
1. 登录 WHMCS 管理后台
2. 转到 Setup → Products/Services → Products
3. 创建或编辑套餐
4. 在 Module 中选择 "V2raySocks"
5. 配置模块选项（见下一节）

---

## 配置说明

### WHMCS 套餐配置

#### 选项 1: 数据库名称（本地 V2Ray）
```
描述: 存放 V2Ray 用户数据的数据库名
示例: v2ray_db / myv2ray
用途: 本地 V2Ray 方式使用
```

#### 选项 2: xrayR API URL
```
描述: xrayR 服务器地址
示例: http://127.0.0.1:7890
     http://xrayR.example.com:7890
     https://xrayR.example.com:7890 (带 SSL)
```

#### 选项 3: xrayR API Key
```
描述: xrayR 的 API 认证密钥
示例: your-secret-key-12345
说明: 保持机密，不要在日志中泄露
```

#### 选项 4: xrayR 节点配置（JSON 格式）
```json
[
  {
    "name": "香港节点1",
    "ip": "1.2.3.4",
    "port": 443,
    "nodeId": 1,
    "sni": "www.microsoft.com",
    "fingerprint": "chrome",
    "publicKey": "your-public-key-here",
    "shortId": "your-short-id",
    "spiderX": "/"
  },
  {
    "name": "新加坡节点2",
    "ip": "5.6.7.8",
    "port": 443,
    "nodeId": 2,
    "sni": "www.apple.com",
    "fingerprint": "firefox",
    "publicKey": "another-public-key",
    "shortId": "another-short-id",
    "spiderX": "/"
  }
]
```

**字段说明：**
- `name`: 节点显示名称
- `ip`: 节点服务器 IP
- `port`: 连接端口
- `nodeId`: xrayR 节点 ID
- `sni`: Server Name Indication (伪装域名)
- `fingerprint`: TLS 指纹 (chrome/firefox/safari)
- `publicKey`: Reality 公钥
- `shortId`: Reality 短 ID
- `spiderX`: 爬虫路径

#### 选项 5: 协议选择
```
- vmess          : 本地 V2Ray (原有)
- vless-reality  : xrayR VLESS-Reality (推荐)
- mixed          : 两者都支持
```

#### 选项 6: 重置带宽
```
- 月底          : 每月底自动重置
- 月初          : 每月初自动重置
- 结算日        : 按客户结算日重置
- 不重置        : 手动重置
```

### xrayR 服务器配置示例

编辑 xrayR 的 `config.json`:
```json
{
  "node": {
    "type": "vless",
    "api": {
      "services": ["HandlerService", "LoggerService"],
      "tag": "api"
    }
  },
  "inbounds": [
    {
      "port": 443,
      "protocol": "vless",
      "settings": {
        "clients": [],
        "decryption": "none"
      },
      "streamSettings": {
        "network": "tcp",
        "security": "reality",
        "realitySettings": {
          "publicKey": "your-generated-public-key",
          "shortIds": ["your-short-id"],
          "serverNames": ["www.microsoft.com", "www.apple.com"]
        }
      }
    }
  ],
  "outbounds": [
    {
      "protocol": "freedom",
      "tag": "direct"
    }
  ]
}
```

---

## API 文档

### XrayRClient 类

#### 初始化
```php
$client = new XrayRClient(
    'http://127.0.0.1:7890',  // baseUrl
    'api-key-here',             // apiKey
    1                           // nodeId
);
```

#### 创建用户
```php
$client->addUser(
    '550e8400-e29b-41d4-a716-446655440000',  // UUID
    'user@example.com',                       // email
    1099511627776,                            // traffic (bytes)
    0                                         // expiryTime (0 = never)
);
```

#### 删除用户
```php
$client->deleteUser('user@example.com');
```

#### 获取用户统计
```php
$stats = $client->getUserStats('user@example.com');
// 返回:
// [
//     'upload' => 1024000,
//     'download' => 2048000,
//     'traffic' => 1099511627776,
//     'expiryTime' => 1735689600
// ]
```

#### 更新用户信息
```php
$client->updateUser(
    '550e8400-e29b-41d4-a716-446655440000',  // UUID
    'user@example.com',
    2199023255552,                            // 新流量限制
    0
);
```

#### 重置用户流量
```php
$client->resetUserTraffic('user@example.com');
```

#### 健康检查
```php
if ($client->healthCheck()) {
    echo "节点正常";
} else {
    echo "节点离线";
}
```

### 函数式 API

#### 生成 VLESS-Reality 链接
```php
$url = V2raySocks_make_vless_reality(
    $nodeInfo,  // 节点配置数组
    $uuid,      // 用户 UUID
    '节点名称'  // 备注
);
// 返回: vless://uuid@ip:port?...parameters...
```

#### 创建账户
```php
$result = V2raySocks_CreateAccount($params);
// 返回: 'success' 或错误信息
```

#### 暂停账户
```php
$result = V2raySocks_SuspendAccount($params);
```

#### 恢复账户
```php
$result = V2raySocks_UnsuspendAccount($params);
```

#### 删除账户
```php
$result = V2raySocks_TerminateAccount($params);
```

---

## 故障排查

### 问题 1: xrayR 连接失败

**症状:**
```
错误: xrayR 配置不完整
```

**解决方案:**
1. 检查 xrayR API URL 是否正确
2. 验证 API Key 是否正确
3. 确认 xrayR 服务正在运行
4. 检查防火墙是否允许连接

```bash
# 测试 xrayR 连接
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://127.0.0.1:7890/api/health
```

### 问题 2: 节点配置解析失败

**症状:**
```
错误: 节点配置为空
```

**解决方案:**
1. 验证 JSON 格式是否正确 (使用 jsonlint.com)
2. 检查所有必需字段是否都有值
3. 确保没有特殊字符或编码问题

### 问题 3: 用户创建成功但无法连接

**症状:**
- UUID 正确
- 连接时超时或连接被拒绝

**调试步骤:**
```bash
# 1. 检查 xrayR 日志
tail -f /var/log/xrayR/error.log

# 2. 验证用户是否确实被创建
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://127.0.0.1:7890/api/user/user@example.com

# 3. 检查防火墙
iptables -L -n | grep 443

# 4. 验证 TLS 配置
openssl s_client -connect server-ip:443 -servername www.microsoft.com
```

### 问题 4: 流量统计不准确

**症状:**
- WHMCS 显示的流量与实际不符

**原因和解决:**
1. **同步延迟** - xrayR 可能需要时间更新统计
   - 等待 5-10 分钟后刷新

2. **多节点情况** - 流量在所有节点汇总
   - 检查 `V2raySocks_GetVlessRealityInfo()` 是否遍历所有节点

3. **时区问题** - WHMCS 和 xrayR 时区不同
   - 检查服务器时间同步: `ntpdate -s time.nist.gov`

### 问题 5: 协议选择不生效

**症状:**
- 选择了 VLESS-Reality 但仍然使用 VMESS

**排查步骤:**
```php
// 在 WHMCS 中添加调试代码
$protocol = $params['configoption4'] ?? 'vmess';
error_log("Selected protocol: " . $protocol);

// 检查 configoption4 是否正确映射
```

---

## 常见配置错误

### ❌ 错误 1: JSON 格式不对
```json
// 错误 - 尾逗号
[
  {
    "name": "Node1",
    "port": 443,
  }
]

// 正确
[
  {
    "name": "Node1",
    "port": 443
  }
]
```

### ❌ 错误 2: Reality 密钥格式不对
```
❌ 错误: "publicKey": "invalid-key-format"
✅ 正确: "publicKey": "8YLuI1v-qw7pHd8zK2mL9oP4qRsTuVwXyZ_aBcDeFg="
```

### ❌ 错误 3: 节点 ID 重复
```
❌ 错误: 多个节点使用相同的 nodeId
✅ 正确: 每个节点使用唯一的 nodeId
```

---

## 性能优化

### 1. 批量操作
当创建多个用户时，使用 xrayR 的批量 API：
```php
$users = [
    ['uuid' => 'uuid1', 'email' => 'user1@example.com', 'traffic' => 1099511627776],
    ['uuid' => 'uuid2', 'email' => 'user2@example.com', 'traffic' => 1099511627776]
];

$client->batchAddUsers($users);
```

### 2. 缓存策略
- 缓存节点配置，避免重复解析 JSON
- 缓存流量统计结果（5 分钟有效期）

### 3. 连接池
- 复用 HTTP 连接而不是每次创建新连接
- 配置合理的超时时间 (10 秒)

---

## 安全建议

### 1. API Key 管理
- 定期轮换 API Key
- 不要在日志中记录完整的 Key
- 使用强密码（32+ 字符）

### 2. TLS 验证
- 生产环境应使用自签名证书
- 或者配置 SSL_VERIFYPEER = true

### 3. 访问控制
- 限制 xrayR API 访问 IP
- 使用防火墙保护 xrayR 端口

### 4. 日志记录
- 记录所有用户创建/删除操作
- 定期审计日志以检测异常

---

## 数据库扩展

### 推荐的新表结构
```sql
CREATE TABLE IF NOT EXISTS `xrayR_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sid` int(11) NOT NULL COMMENT 'Service ID',
  `node_id` int(11) COMMENT 'xrayR Node ID',
  `email` varchar(255) COMMENT 'User Email',
  `upload` bigint(20) COMMENT 'Upload bytes',
  `download` bigint(20) COMMENT 'Download bytes',
  `sync_time` int(10) COMMENT 'Last sync timestamp',
  `status` varchar(20) COMMENT 'Sync status',
  PRIMARY KEY (`id`),
  KEY `sid` (`sid`),
  KEY `sync_time` (`sync_time`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

---

## 版本历史

### v0.9.0 (当前)
- ✨ 添加 VLESS-Reality 支持
- ✨ 集成 xrayR API
- ✨ 支持多协议混合
- ✨ 实时流量统计
- 🐛 修复本地 V2Ray 兼容性

### v0.8.2 (原版)
- 原始 VMESS 功能
- 本地数据库管理

---

## 许可证

MIT License - 详见 LICENSE 文件

## 支持

- 📧 Email: waskomsrock3@outlook.com
- 🐛 Issues: GitHub Issues
- 💬 Discussions: GitHub Discussions
