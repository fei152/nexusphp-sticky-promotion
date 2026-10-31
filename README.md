# NexusPHP 置顶促销

独立重新实现的插件，适用于 NexusPHP 1.10.2 / Filament 5，不依赖已消失的原插件仓库，也不冒用原作者包名。

源码仓库：https://github.com/fei152/nexusphp-sticky-promotion

## 功能

- 启用后，种子详情「行为」栏新增「★ 种子促销」，点击以 layer 弹窗打开。
- 支持 Free、2X、2X Free、50%、2X 50%、30% 六种促销。
- 表单设置时长、竞价魔力、奖励魔力、奖励人数，实时显示预计扣费。
- 占位费＝后台基数 × 小时数 × 当前种子的系统时魔系数 × 所选促销收费倍率，提交时服务端重算。
- 高于一级置顶，同类按竞价排序；在 SQL LIMIT 前排序，保留搜索、分类与访问过滤。SQL 列表按竞价全局排序；Meilisearch 搜索保持原分页和匹配结果，仅对当前页排序。
- 种子列表显示特殊标识与奖励魔力，详情简介前显示促销信息。
- 发起人可追加时长、竞价、奖励和人数，不能减少或修改已选促销类型。
- 每 15 秒更新参与人数、排名、预估奖励。前 N 名按有效上传比例瓜分奖励，最后一名承担0.1 魔力舍入差，奖励总额守恒。
- 真实上传计量不含促销倍率；发起人不参加分奖；重复汇报按 peer 累计计数去重。
- 开始前的汇报区间按时间比例排除；结束后收到的汇报不计入。客户端无法提供精确到截止秒的上传事件，此规则会低估跨截止区间的上传。
- 到期由 Laravel 调度器每分钟结算，站内信通知获奖用户。重复执行不重复发奖。
- 全程不退款，包括无人参与、种子被删除、发起人未下载完成。
- 费用流水、奖励到账记录和请求幂等键持久保存。

## 安装

在站点根目录下载源码并安装：

```sh
git clone https://github.com/fei152/nexusphp-sticky-promotion.git packages/nexus-sticky-promotion
composer config repositories.sticky-promotion path packages/nexus-sticky-promotion
composer require 'local/nexusphp-sticky-promotion:*'
php artisan plugin install local/nexusphp-sticky-promotion
php artisan optimize:clear
```

不要安装旧的 `xiaomlove/nexusphp-sticky-promotion` 包。已有旧插件数据不会自动迁移。

安装会创建四张 `plugin_sticky_*` 表并发布一个新的促销后台页面。仅使用现有 `torrent_list`、`torrent_promotion`、`torrent_detail_actions`、`announced` 等钩子，不修改已有 PHP 脚本或其他插件。用户、站内信、种子表必须为 InnoDB，以保证事务与锁有效；安装器不会自动转换原表。

后台入口：**新后台 → System（系统）→ 置顶促销**，也可在站点设置的「置顶促销」标签配置。默认基数 100、最小时长 1 小时、最低等级 0。只有有新后台权限的用户可以保存配置。

六种收费倍率均可在后台独立调整；默认值如下。这些是本实现的默认定价，不是原插件公布的定价。

| 类型 | 默认收费倍率 |
| --- | ---: |
| Free | 1.5 |
| 2X | 2 |
| 2X Free | 3 |
| 50% | 1.25 |
| 2X 50% | 2.5 |
| 30% | 1.35 |

设置为 0 仅免占位费，竞价和奖励魔力仍单独扣除。修改后新购买与追加使用新倍率，已支付费用不追溯。追加沿用原促销类型，不能通过提交其他类型改变计价。上传、下载的流量倍率与收费倍率是独立概念；现有种子或全站促销优惠不会因本插件而降低。

## 升级

```sh
git -C packages/nexus-sticky-promotion pull --ff-only
php artisan plugin install local/nexusphp-sticky-promotion
php artisan optimize:clear
```

重复安装只补充缺失设置，不覆盖管理员已经保存的基数或倍率。更新后刷新已打开的促销弹窗，以加载最新预估参数。

站点现有 `php artisan schedule:run` 每分钟调度必须正常运行；无需新增外部定时任务。促销到期立即停止置顶和流量优惠，奖励最多等待一个调度周期；队列/调度异常时恢复后补结算。打开促销弹窗也会尝试结算到期记录。

**停用**请将后台开关设为关闭：隐藏新发起入口，禁止购买/追加，已购买促销仍履约和结算。彻底卸载前必须等待促销全部结算，卸载器会拒绝存在未结算促销的情况。财务历史表不会随卸载删除。

## 测试

```sh
php packages/nexus-sticky-promotion/tests/integration.php vendor/autoload.php
php packages/nexus-sticky-promotion/tests/render.php vendor/autoload.php /tmp/sticky-promotion-ui
```

集成测试使用独立内存 SQLite、实际迁移与 Illuminate 查询构建器；不启动站点、不读取 `.env`，不连接生产数据库。覆盖购买扣费、失败回滚、重试、追加权限、停用、重复汇报、起止边界、排序分页、奖励守恒与重复结算。魔力金额与站点字段一致，最小单位为 0.1。SQLite 测试不替代 MySQL 并发压测。

`tests/render.php` 使用虚构数据渲染界面。`tests/inspect-host.php` 和 `tests/live-smoke.php` 是可选的站点维护工具：会启动指定站点，后者需要管理员账号和可见种子，并在回滚事务中验证购买；请由站点维护者按需执行，不作为普通 CI 测试。
