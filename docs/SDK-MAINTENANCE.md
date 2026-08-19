# 支付宝 SDK 维护与更新评估

评估日期：2026-08-19。范围仅为仓库内置 SDK 与插件实际引用面；未连接支付宝生产环境。

## 结论

**本版需要补齐来源固定和许可证处理，但不建议把 SDK 整包升级混入 4.0.0 文档/改名候选。**

内置 v2 SDK 的核心签名、验签和 HTTP 客户端代码与官方 2026-06-18 master 相比没有功能变化，插件实际引用的 15 个 Request 类中，11 个差异也只有自动生成时间注释；整包升级却会引入 945 个新文件、修改 1,378 个文件。当前升级收益主要是增加本插件未使用的 OpenAPI 模型，同时显著扩大审查与发布包范围。

建议顺序：

1. 4.0.0 先固定现有 SDK 的官方来源、局部修补和摘要；许可证缺口未解决前保持发布门。
2. 下一独立变更将 SDK 收敛为“核心客户端 + 15 个已用 Request 类 + 它们的传递 domain/model 依赖”，生成 allowlist 和可复现脚本。
3. 在收敛后再更新到经审核的官方提交，并完成离线和支付宝沙箱回归。
4. v3 SDK 是协议迁移，不是 v2 文件替换；另开版本设计，不与 4.0.0 混合。

## 当前内置版本与来源

| 项目 | 事实 |
| --- | --- |
| 内嵌版本 | `alipay-sdk-PHP-4.20.807.ALL` |
| 官方仓库 | `https://github.com/alipay/alipay-sdk-php-all` |
| 对应官方提交 | `d37c264fd5fd94a6291f8c682e905e6cdb193866`（2025-09-12） |
| 官方 `v2` tree | `b708b3d0bdc49e7014cdf532b16d2bf2a9f2dfad` |
| GitHub codeload archive SHA-256 | `66f3571eb14121254791b944826a677eb749216785ce1ee57ed3b07133b8c2ab` |
| 官方 archive 大小 | 4,144,062 bytes |
| 本地 `lib/alipay` | 8,829 files；约 36 MiB |

与该官方提交逐文件比对：

- 官方 `v2` 的 8,815 个非 `.DS_Store` 文件全部存在；
- 8,812 个文件逐字节一致；
- 3 个文件有本地差异：`AlipayConfig.php` 去除开头空行，`AopClient.php` 与 `AopCertClient.php` 修复 PHP 8 必选参数位于可选参数之后的问题；
- 本地额外 14 个文件为 `AopSdk.php` 及历史 pagepay/wappay builder/service 辅助文件。

这证明了主 SDK 的来源，但 14 个额外文件仍需单独固定来源。

## 官方当前状态

2026-08-19 审计时，官方 master 为：

| 项目 | 事实 |
| --- | --- |
| 提交 | `437f7d635da21127c48099d04faca1178de07a84` |
| 提交时间 | 2026-06-18 |
| v2 内嵌版本 | `alipay-sdk-PHP-4.20.1258.ALL` |
| 相对当前对应提交 | 945 added、1,378 modified，共 2,323 个路径 |
| v2 文件规模 | 9,762 files / 19,359,901 uncompressed bytes |

核心 `AopClient.php` 和 `AopCertClient.php` 只改变上报的 SDK 版本字符串。插件显式引用 15 个 Request 类；其中 11 个被重新生成，但内容差异只有 `@since` 时间，4 个无差异。因此没有证据表明整包升级会修复当前支付路径的确定性缺陷。

官方仓库同时提供 v2 与 v3。v3 使用 REST/JSON 和不同的整报文签名方式，不能视为 v2 的透明升级。

## 许可证状态

官方仓库根目录和 v2 tree 未包含可确认的 LICENSE/COPYING 文件；根 `composer.json` 的 `unlicense` 描述对应 v3 生成包，不能自动外推到 v2 SDK。仓库和内置副本中的版权/许可证据不足，因此 SBOM 保持：

```text
licenseDeclared: NOASSERTION
licenseConcluded: NOASSERTION
```

在支付宝或 Ant Group 提供适用于该 v2 源码和历史辅助文件的明确许可前，公共发布仍为 `BLOCKED`。不得将插件的 GPL-3.0-only 声明覆盖到来源不明的第三方 SDK。

## 受控升级步骤

1. 固定官方 commit，不跟随浮动 master。
2. 下载 codeload archive，核验 SHA-256 和 Git tree。
3. 由脚本生成插件实际引用类及传递依赖 allowlist。
4. 对旧/新 allowlist 运行 API、签名串、RSA2 签名/验签、证书模式、HTTP 超时与错误映射差分。
5. 重新应用或替换 PHP 8 参数顺序修补，并记录补丁。
6. 更新 SBOM、manifest、SDK tree hash、第三方 notices 和发布 ZIP。
7. 重跑 PHP 7.4/8.3、PHPStan、第一方 PHPCS、Plugin Check 分类及可复现构建。
8. 运行 HPOS on/off、Blocks、并发/乱序通知、查询、关单、退款和可选模块离线桩。
9. 使用无生产密钥的支付宝沙箱完成端到端验收。
10. 由 Board 审批精确 SHA；SDK 更新、合并、发布和商城切换分别授权。

## 不采用的捷径

- 不直接复制官方 master 覆盖 `lib/alipay`；
- 不只改 `alipaySdkVersion` 字符串；
- 不把 36 MiB SDK 排除扫描后宣称整包 PASS；
- 不未经依赖分析删除未显式 `require_once` 的 domain/model；
- 不用真实商城私钥或真实交易验证升级；
- 不因官方仓库公开就推定许可证允许再分发。
