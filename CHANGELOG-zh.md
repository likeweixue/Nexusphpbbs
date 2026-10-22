# 改造记录（相对 NexusPHP 1.10.2 论坛版）

## 版本历史

### 0.1.2（2026-10-22）— 修复登录按钮

- **根因**：0.1.1 打包时误排除了 `public/vendor/`（jquery.loading 等前端资源）和 `resources/views/vendor/`，登录页的挑战脚本在 `jQuery('body').loading()` 处报错，按钮点击后没有反应。
- 恢复 `public/vendor/` 与 `resources/views/vendor/` 全部资源。
- 登录按钮由 `type="button"` 改为 `type="submit"`，密码框始终带 `name="password"`，支持直接点按钮和回车提交。
- 新增 `public/challenge.php`：登录挑战不再依赖 Laravel 的 `/api/challenge` 路由，普通 PHP 环境即可使用。
- `takelogin.php` 同时支持“挑战-响应”和“密码”两种登录方式；挑战接口或前端脚本异常时，页面会自动回退为密码提交。
- 前端脚本增加加载插件缺失、layer 缺失、请求失败等情况的兜底与提示。

### 0.1.1（2026-10-22）— 论坛化 / 去 Redis / 免 Composer

- 移除种子上传、下载、Tracker 等功能入口，保留论坛、用户、消息、勋章、签到、考核、后台等模块。
- 文件缓存替代 Redis，移除 Horizon，队列改为 sync，安装仅需 PHP + MySQL。
- `vendor/` 内置生产依赖，服务器无需执行 `composer install`。
- 安装向导简化，自动生成 APP_KEY/APP_URL。

### 0.1.0（2026-10-22）— 初始论坛版本

- 用户提供的原始论坛版本（基于 NexusPHP 1.10.2）。

---

## 改造目标

改动目标：**去掉 Redis 依赖，服务器只需要 PHP + MySQL；同时保证不需要在服务器上安装 Composer**。论坛页面与后台布局保持原样。

## 一、总体方案

| 原实现 | 新实现 |
| --- | --- |
| Redis 作为缓存（页面缓存、设置缓存、用户缓存、限流、锁、计数器、Hash/List/Set） | 新增 `Nexus\Cache\FileStore`，用文件实现 phpredis 常用 API，数据目录 `storage/framework/cache/nexus/` |
| 传统页面 `class_cache_redis` | 改为 `classes/class_cache_file.php`，对外方法完全一致，内部改用 `FileStore` |
| Laravel Cache 使用 redis 驱动 | 新增 `nexus` 缓存驱动（`Nexus\Cache\FileCacheStore`），与旧页面共用同一个 `FileStore`，键名和值格式保持一致 |
| `NexusLock` 继承 RedisLock | 改为文件缓存 TTL 锁 + `flock()`，保留“短时间不能重复提交”的限流语义 |
| 队列 Redis + Horizon + Supervisor | `QUEUE_CONNECTION=sync`，任务在进程内执行；移除 Horizon 与 `horizon:snapshot` |
| Session Redis | Session 使用 Laravel 原生文件驱动 |
| 安装检查 Redis 扩展、版本、连接 | 全部移除，只检查 MySQL 和 PHP 扩展 |
| `vendor` 需要 `composer install` | 已内置生产依赖（`composer install --no-dev`），并移除开发依赖 |

## 二、主要文件

### 新增

- `nexus/Cache/FileStore.php` — 文件缓存核心，提供 get/set/setex/del/unlink/exists/expire/incr、hash、list、set、scan/hScan、publish、multi 等 phpredis 兼容方法。
- `nexus/Cache/FileCacheStore.php` — Laravel Cache Store 适配器（含原子 add、cache lock、touch）。
- `classes/class_cache_file.php` — 替代 `class_cache_redis.php`，API 不变。
- `app/Console/Commands/CacheGc.php` — `php artisan cache:gc`，清理过期缓存文件。
- `public/install/tailwind.min.css` — 安装向导本地样式，避免依赖外网 CDN。
- `INSTALL-zh.md`、`CHANGELOG-zh.md` — 部署与改造文档。

### 修改

- `include/core.php`、`nexus/Install/install_update_start.php` — 改用 `class_cache_file`。
- `nexus/Database/NexusDB.php` — `NexusDB::redis()` 返回 `FileStore`（保持旧调用点不变）。
- `nexus/Database/NexusLock.php` — 文件锁实现。
- `nexus/Nexus.php` — 移除 Redis QueueManager，任务改为容器队列/sync 或进程内执行；修复未设置 `REQUEST_SCHEME` 时的 TypeError。
- `app/Providers/AppServiceProvider.php` — 注册 `nexus` 缓存驱动。
- `config/cache.php`、`config/session.php`、`config/queue.php` — 默认值改为文件缓存/file/sync，并对旧 `.env` 中的 `redis` 自动回退。
- `config/app.php` — 移除 Horizon ServiceProvider。
- `app/Http/Kernel.php` — `throttle` 改为普通 `ThrottleRequests`（基于缓存）。
- `app/Repositories/{IpLog,Tracker,Torrent,Cleanup,RequireSeedTorrent,Message,Dashboard}Repository.php`、`app/Models/TrackerUrl.php` — Redis 类型提示/常量改为 `FileStore`。
- `app/Jobs/*`、`app/Utils/*` — 随 `NexusDB::redis()` 自动切换，无需业务改动。
- `nexus/Install/Install.php`、`public/install/install.php`、`nexus/Install/update/update.php`、`nexus/Install/install/install.php`、`app/Console/Commands/NexusUpdate.php` — 去掉 Redis 检查/字段/版本提示；自动生成 APP_KEY、APP_URL，强制 `CACHE_STORE=nexus`、`SESSION_DRIVER=file`、`QUEUE_CONNECTION=sync`；自动探测 PHP CLI 路径。
- `app/Console/Kernel.php` — 增加每日缓存 GC；移除 Meilisearch、Tracker pieces hash 等纯种子相关定时任务。
- `routes/console.php` — 移除 `horizon:snapshot`。
- `include/functions.php` — 调试栏 Redis 字样改为 cache。
- `resources/lang/*/dashboard.php` — 后台系统信息“Redis 版本”改为“缓存后端”。
- `.env.example` — 移除 REDIS_*，加入 `CACHE_STORE=nexus` 等。
- `composer.json` / `composer.lock` / `vendor/` — 移除 `laravel/horizon`、`ext-redis`、`ext-pcntl`、`ext-posix`、`ext-zend-opcache`；只保留生产依赖。

### 删除

- `classes/class_cache_redis.php`、`classes/class_cache.php`
- `app/Providers/NexusHorizonServiceProvider.php`、`config/horizon.php`
- `tests/`、`phpunit.xml`、`docker-compose.yml` 等开发/容器文件

## 三、缓存实现说明

- 每个 key 一个文件：`storage/framework/cache/nexus/<md5前2位>/<md5>.cache`。
- 写入使用临时文件 + `rename()` 原子替换；Hash/List/Set 的读改写使用 per-key `flock()`。
- 过期时间写在文件头，读取时惰性删除；写入时约 1/200 概率触发小规模 GC，另有每日 `cache:gc` 全量清理。
- 缓存目录不可写时自动回退到系统临时目录；仍不可用则缓存失效但不影响页面打开。
- Laravel 与传统页面共用同一存储，登录挑战（Laravel 写入、`takelogin.php` 读取）、后台改设置后前台生效等跨端场景已验证。

## 四、锁说明

- 带 TTL 的锁（发帖间隔、邀请、举报、确认邮件重发等）：写入 `nexus_lock:<name>`，到期自动失效，保持原来的限流效果。
- 无 TTL 的锁：使用 `flock()`，进程结束自动释放。

## 五、验证情况

已在本地实际安装验证（PHP 8.4.23 + MySQL 8.4.6，无 Redis 服务、无 phpredis 连接）：

- 安装向导 step 1–6 全流程通过，建表 124 张、设置 396 条、管理员创建成功；
- 首页、论坛版块/主题、用户资料、控制面板、消息、管理面板、FAQ、规则、搜索、喊话框等页面 200 且无 PHP 错误；
- 后台 Filament 登录/仪表盘正常，静态资源正常加载；
- 挑战-响应登录（Laravel API + 传统 PHP 页面共享缓存）成功；
- `php artisan schedule:run`、`cache:clear`、`cache:gc`、`public/cron.php`、强制全量清理 `docleanup.php` 均正常；
- IP 日志缓存落库、按 pattern 删除缓存、缓存锁/限流均通过测试。

## 六、注意事项

- 本方案的文件缓存是**单机缓存**，适合单台 Web 服务器。多机负载均衡需要共享 `storage/` 或另行改回 Redis。
- `vendor/` 是 Laravel 框架本体，不能删除；本包已去掉开发依赖，**上传即可运行，无需 Composer**。
- 依赖版本保留上游 1.10.2 的锁定版本；如需安全升级，请在本地 `composer update` 并完整测试后再上传。
