# Wei Alipay Payment Gateway for WooCommerce

Wei Alipay Payment Gateway for WooCommerce 是由 [Weixiaoduo.com](https://weixiaoduo.com/) 维护的 WooCommerce 支付宝网关。项目面向中国大陆支付宝商户，主网关支持电脑网站支付、手机网站支付、退款、异步通知和人民币换算，并提供若干按商户签约能力启用的扩展模块。

> 4.0.1 是 FeiCode 发布候选；mall.weixiaoduo.com 当前仍运行 4.0.0，生产升级需要独立备份、验证与回滚门。FeiCode 是开发上游；当前 GitHub `Weixiaoduo/woo-alipay` 只作为公开下游；远端完成审批改名后目标为 `Weixiaoduo/wei-alipay`。

## 运行要求

- PHP 7.4 或更高版本
- WordPress 6.6 或更高版本
- WooCommerce 8.3 或更高版本
- 支持 HTTPS 的站点
- 已签约相应支付产品的中国大陆支付宝商户应用

4.0.1 候选已在 WordPress 7.0.2、WooCommerce 10.9.4、PHP 8.3 的隔离环境验证激活、主网关注册、HPOS 开启及 F2F/花呗 Checkout Blocks 资源注册。该克隆因订单未同步而拒绝关闭 HPOS；最低版本组合尚未完成真实运行验证，均不能写成已通过矩阵。

## 功能边界

### 主网关

- 电脑网站支付和手机网站支付
- RSA2 请求签名与通知验签
- `app_id`、卖家、商户订单号、支付宝交易号、金额和通知时间绑定
- 重复与并发通知幂等处理
- WooCommerce 后台退款
- 非人民币商店按配置汇率换算成人民币
- HPOS 与传统订单存储
- Classic Checkout 与 Checkout Blocks

### 可选模块

仓库还包含当面付、花呗分期、条码支付、小程序、跨境支付、订阅签约、资金授权、分账和账单对账。模块存在不表示商户已获得对应能力，也不表示每个模块都完成生产验收。只启用已经签约、在支付宝沙箱或离线桩中验证并纳入运维监控的模块。

小程序协议默认使用 RSA2。旧 MD5 只允许在同时完成站点确认和运维常量授权的过渡模式下启用，系统不会从 RSA2 自动降级。

## 安装与配置

1. 将发布 ZIP 安装到 WordPress；不要从开发工作树直接覆盖生产插件。
2. 启用 WooCommerce，再启用本插件。
3. 打开 **WooCommerce → 设置 → 付款 → Alipay**。
4. 配置支付宝应用 ID、支付宝公钥和应用私钥。
5. 首次联调必须启用支付宝沙箱，确认订单创建、跳转、异步通知、重复通知和退款桩流程。
6. 在支付宝开放平台将异步通知地址设置为：

   ```text
   https://example.com/wc-api/WC_Alipay/
   ```

7. 生产启用前核对商户应用、卖家账号、币种、汇率、回调域名、证书和监控告警。

支付宝公钥与应用公钥不是同一个值。应用私钥不得写入工单、截图、日志、仓库、CI 变量输出或普通导出文件。

## 密钥与数据

插件将支持的私钥设置迁移到版本化 AES-256-GCM 密文，并在无法解密时失败关闭。生产环境应通过 `WEI_ALIPAY_ENCRYPTION_KEY` 或受控外部密钥提供器提供独立密钥；不要依赖可与数据库一起导出的默认材料。

以下运行身份在 4.0.1 中仍保持稳定，以保护商城订单与回调数据：

- 网关 ID：`alipay`
- 设置项：`woocommerce_alipay_settings`
- 订单元数据：`_alipay_*`
- 回调路由：`WC_Alipay`
- 计划任务与钩子：`woo_alipay_*`

默认卸载会保留支付设置和订单财务记录。只有经过明确授权的按站点清理流程才会删除插件设置；卸载不会删除订单。

## 模板覆盖

主题可以按优先级在下列目录提供 `redirected-pay.php`：

1. `plugins/wei-alipay/`
2. `wei-alipay/`
3. `woocommerce/wei-alipay/`
4. 主题根目录

升级前应盘点商城当前使用的模板覆盖，避免插件目录改名后静默回退到默认模板。

## 开发与维护

- [开发指南](docs/DEVELOPMENT.md)
- `AI原生配置.md`（仅开发源，不进入发布 ZIP）
- [SDK 维护与更新评估](docs/SDK-MAINTENANCE.md)
- [FeiCode → GitHub 下游镜像规则](docs/governance/github-downstream-mirror.md)
- [SBOM](docs/sbom/wei-alipay.spdx.json)

## 源码、问题与安全报告

开发、评审、CI、标签和 Release 以 FeiCode `Weixiaoduo/woo-alipay` 的精确提交为准；仓库正式改名完成前不要根据显示名称猜测远端地址。GitHub（当前 `Weixiaoduo/woo-alipay`，计划改名为 `Weixiaoduo/wei-alipay`）仅接收审批后精确 SHA 的单向快进同步，不接受反向开发。

问题报告不得包含真实私钥、应用密钥、完整通知报文、客户身份信息或可用交易凭据。支付安全问题请通过 Weixiaoduo.com 的私有支持渠道提交脱敏复现材料。

## 许可证

第一方插件代码按 GPL-3.0-only 分发。内置第三方组件适用各自许可证；发布前必须以 [SBOM](docs/sbom/wei-alipay.spdx.json) 和 SDK 维护文档复核来源与许可证。当前支付宝 v2 SDK 上游仓库没有可确认的随附许可证，因此仍是发布门，不得用插件 GPL 声明覆盖第三方许可缺口。
