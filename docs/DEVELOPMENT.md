# Wei Alipay 开发指南

本指南适用于第一方插件代码、测试、发布候选和受控镜像维护。支付代码的本地通过不等于生产验收。

## 1. 仓库与变更边界

- FeiCode 精确仓库是代码上游；GitHub 是单向公开下游。
- 开工前记录 `git status --short --branch`、精确 HEAD、上游分支和共享 WIP。
- 共享工作树有改动时使用隔离 worktree/分支，不覆盖或清理他人内容。
- 未绑定 Board 审批的精确 SHA 不得 push；不得自行 merge、tag、release、同步 GitHub 或部署商城。
- 测试禁止真实支付宝生产请求、真实交易和真实退款。

## 2. 本地依赖

最低运行目标为 PHP 7.4、WordPress 6.6、WooCommerce 8.3。开发分析依赖通过 Composer 安装：

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
```

`vendor/` 只服务开发检查，不进入发布 ZIP。内置支付宝 SDK 目前不是 Composer 依赖；它按独立的第三方来源门管理。

## 3. 代码地图

| 路径 | 责任 |
| --- | --- |
| `wei-alipay.php` | 插件头、版本、主入口、兼容声明 |
| `inc/class-wei-alipay.php` | 启动、网关/Blocks 注册、模板与生命周期 |
| `inc/class-wc-alipay.php` | 主网关、支付、通知、退款 |
| `inc/class-alipay-sdk-helper.php` | SDK 客户端、查询与关单边界 |
| `inc/class-wc-alipay-order-query.php` | 传统订单存储与 HPOS 精确查询 |
| `inc/class-wei-alipay-secret-store.php` | 密钥迁移、加密、轮换、回滚与失败关闭 |
| `inc/modules/` | 可选支付与清结算模块 |
| `lib/alipay/` | 第三方支付宝 v2 SDK 与历史辅助文件，不属于第一方 PHPCS 范围 |
| `tests/` | 合约、静态分析引导和安全测试 |
| `scripts/` | 本地 CI、可复现构建和下游镜像脚本 |

## 4. 不可随意改名的运行身份

即使产品名、目录和 text domain 改为 Wei Alipay，以下身份仍与商城数据相连：

```text
gateway ID                 alipay
settings option            woocommerce_alipay_settings
order metadata             _alipay_*
callback                   wc-api/WC_Alipay/
schedules and hooks        woo_alipay_*
```

修改这些值前必须提供可重入迁移、克隆站数据样本、回滚和旧入口过渡方案。不得只做字符串替换。

## 5. 支付代码要求

### 入站通知

顺序必须是：读取有限字段 → 验签 → 校验编码与时间 → 绑定 `app_id`/卖家/商户订单/支付宝交易/金额/币种 → 获取并发锁 → 重新读取订单 → 执行允许的状态转换 → 持久化幂等结果 → 返回固定响应。

禁止在验签和商户绑定之前修改订单、写入交易号或触发退款。金额使用十进制最小单位比较，不使用二进制浮点相等判断。

### 出站请求

- 默认 RSA2，不允许算法自动降级。
- 设置连接与响应超时；重试只能用于可证明幂等的请求。
- URL 必须来自固定支付宝端点或经过允许列表验证的配置。
- 日志只写脱敏标识、状态和内部关联 ID。

### 订单状态

重复、并发和乱序通知必须安全。已完成订单不得被迟到的关闭通知回退；退款、关单和成功通知的竞态必须用原订单和交易绑定处理。

## 6. 自动检查

第一方基础检查：

```bash
./scripts/tests/run-tests.sh all
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --no-progress
composer validate --strict
git diff --check
```

本地等价 CI 与可复现候选：

```bash
./scripts/ci-local.sh
SOURCE_DATE_EPOCH="$(git show -s --format=%ct HEAD)" \
  ./scripts/build-release-candidate.sh /tmp/wei-alipay-build-a
SOURCE_DATE_EPOCH="$(git show -s --format=%ct HEAD)" \
  ./scripts/build-release-candidate.sh /tmp/wei-alipay-build-b
sha256sum /tmp/wei-alipay-build-{a,b}/*.zip
```

两份 ZIP 必须逐字节一致。发布包还要运行 `unzip -t`、秘密模式扫描、manifest/SBOM 校验和干净 WordPress 安装。

## 7. WordPress/WooCommerce 运行矩阵

至少记录以下每个样本的版本、命令、退出码和断言：

- PHP 7.4 与当前支持 PHP
- WordPress 6.6 与当前测试版本
- WooCommerce 8.3 与当前测试版本
- HPOS 关闭/开启
- Classic Checkout/Checkout Blocks
- 正常通知、精确重放、并发重放、跨订单伪造、金额不符、过期/未来通知、无效 UTF-8、RSA 降级
- 支付成功与关单乱序、退款重复和退款/关单竞态
- 单站点和 Multisite 的激活、升级、停用、默认保留卸载、显式清理

没有样本就写 `SKIP` 或 `BLOCKED`，不能写 PASS。

## 8. PHPCS、PHPStan 与 Plugin Check

- PHPStan 使用 `phpstan.neon.dist`、WordPress/WooCommerce stub 和明确的 SDK 边界；历史 baseline 不能吞掉新错误。
- PHPCS 优先检查支付回调、nonce/权限、SQL、输出、文件和网络安全；不为了数字归零机械格式化第三方 SDK。
- Plugin Check 对发布包执行，并将第一方、更新器政策和第三方 SDK 分开分类。
- 私有更新器的 `plugin_updater_detected` 对 WordPress.org 分发是政策错误，不能用 ignore 隐藏。

## 9. SDK 变更流程

SDK 结论、固定提交、哈希、许可缺口和升级步骤见 [SDK-MAINTENANCE.md](SDK-MAINTENANCE.md)。任何 SDK 更新必须单独提交，并至少完成：

1. 固定官方提交与下载摘要；
2. 比较插件实际引用的请求类；
3. 保留已记录的 PHP 兼容修补或给出替代；
4. 更新 SBOM、文件清单和许可证；
5. 离线签名/验签、查询、关单、退款和并发回调回归；
6. 沙箱验收；禁止用生产商户验证候选。

## 10. 提交与发布门

提交前更新 `[CX]` HANDOFF，记录精确 HEAD、测试命令/退出码、工件 SHA-256、未验证项。普通 push 也必须由 Board change 绑定精确 SHA；CI 审批、合并、Release、GitHub 同步和商城切换分别审批。
