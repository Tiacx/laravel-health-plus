# Laravel Health Plus

## 说明
[spatie/laravel-health](https://spatie.be/docs/laravel-health/v1/introduction) 的增强扩展包，为你的 Laravel 应用提供额外的健康检查与通知功能。

## 安装

```bash
composer require tiacx/laravel-health-plus
```

## 配置

#### 发布配置文件：

```bash
php artisan vendor:publish --tag=health-plus-config
```

该命令会发布 `config/health.php` 配置文件。

#### 配置检查项：

发布配置后，修改 `config/health.php` 中的 `checks` 数组来启用或配置你需要的检查项。默认配置如下：

```php
// config/health.php
'checks' => [
    DatabaseCheck::class,
    RedisCheck::class,
    [UsedDiskSpaceCheck::class, [
        'warnWhenUsedSpaceIsAbovePercentage' => 85,
        'failWhenUsedSpaceIsAbovePercentage' => 95,
    ]],
    [DatabaseConnectionCountCheck::class, [
        'warnWhenMoreConnectionsThan' => 2000,
        'failWhenMoreConnectionsThan' => 5000,
    ]],
    [CpuLoadCheck::class, [
        'warnWhenLoadIsIncreasing' => 3.0, // 系统负载剧增超过3倍
        'topProcessesLimit' => 5, // Top5 进程
        'messages' => [
            'loadAbove' => 'CPU使用率超过阈值：{value}%%',
            'loadIncreasing' => 'CPU使用率急剧上升, ratio: {ratio}',
        ],
    ]],
    [RequestCheck::class, [
        'requestLogsQuery' => '*|select count(*) as pv, count(distinct userId) as uv, avg(duration) as avg_duration, max(duration) as max_duration from log',
        'shortWindow' => 300, // 近期统计窗口，单位：秒
        'longWindow' => 3600, // 长期对比窗口，单位：秒
        'warnWhenRpsIsIncreases' => 3, // 每秒请求数剧增超过3倍
        'warnWhenDurationIsIncreases' => 10, // 请求响应时间剧增超过10倍
        'warnWhenMaxDurationIsAbove' => 30, // 最大响应时长超过30秒告警
        'messages' => [
            'fetchFailed' => '无法获取请求日志：{error}',
            'emptyData' => '请求日志数据为空',
            'rpsIncrease' => '突发流量激增, Rps5m: {rps5m}, Rps1h: {rps1h}',
            'durationIncrease' => '响应时间劣化, AvgDuration5m: {duration5m}s, AvgDuration1h: {duration1h}s',
            'maxDurationFail' => '最大响应时长 {maxDuration}s 超过阈值 {threshold}s',
        ],
    ]],
    [PhpFpmCheck::class, [
        'statusUrl' => 'http://localhost/fpm-status',
        'maxChildren' => env('FPM_PM_MAX_CHILDREN', 40),
        'warnWhenActiveProcessesIsAbovePercentOfMaxChildren' => 80,
        'failWhenActiveProcessesIsAbovePercentOfMaxChildren' => 95,
        'warnWhenListenQueueIsAbove' => 5,
        'failWhenListenQueueIsAbove' => 10,
        'warnWhenSlowRequestsIsAbove' => 5,
        'failWhenSlowRequestsIsAbove' => 10,
    ]],
    [LogCheck::class, [
        'errorLogsQuery' => 'LevelName:ERROR', // 错误日志查询语句
        'warningLogsQuery' => 'LevelName:WARNING|select distinct Message from log limit 100', // 警告日志查询语句
        'failWhenErrorLogsAbove' => 0, // 有错误日志
        'warnWhenWarningLogsIsAbove' => 100, // 警告日志超过100条
        'messages' => [
            'fetchFailed' => '无法获取错误日志：{error}',
            'errorLogs' => '程序异常：{logs}',
            'warningLogs' => 'WARNING日志过多({count}条)，建议检查',
        ],
    ], 'everyFifteenMinutes'], // 15分钟一次
],
```

#### 配置通知：

发布配置后，修改 `config/health.php` 中的 `notifications` 数组来启用或配置你需要的通知方式，默认使用 `webhook` 通知

```php
'notifications' => [
    'enabled' => true,
    'notifications' => [
        HealthCheckNotification::class => ['webhook'],
    ],
    'notifiable' => HealthCheckNotifiable::class,
    'webhook' => [
        'url' => env('HEALTH_NOTIFICATION_WEBHOOK_URL', ''),
    ],
],
```

注：通过 `HEALTH_NOTIFICATION_WEBHOOK_URL` 环境变量来配置 Webhook URL。

#### 配置阿里云日志服务：

RequestCheck 与 LogCheck 通过阿里云日志服务（SLS）查询日志，需要在应用中实现日志服务类，并在 `config/health.php` 中配置：

```php
// 日志服务类，需在应用中实现 getLogs() 方法，默认为 App\Services\AliyunLog\AliyunLogService
'aliyun_log_service' => env('ALIYUN_LOG_SERVICE', \App\Services\AliyunLog\AliyunLogService::class),

// 阿里云日志查询配置（LogCheck 使用）
'aliyun_log' => [
    // 查询的日志库
    'store_logging' => env('ALIYUN_LOG_STORE_ACCESS'),
    // Kibana 地址与索引模式，两者同时配置时才会生成日志查询链接
    'kibana_host' => env('ALIYUN_LOG_KIBANA_HOST', ''),
    'kibana_index' => env('ALIYUN_LOG_KIBANA_INDEX', ''),
],
```

注：
- 日志服务类需提供 `getLogs(开始时间戳, 结束时间戳, 查询语句, store: 日志库)` 方法，返回日志数组
- `kibana_host` 与 `kibana_index` 同时配置后，LogCheck 的检查结果会附带 Kibana 查询链接（`query_url`），通知消息中可直接点击查看日志详情

#### 其他可用 `Check` 类：

https://spatie.be/docs/laravel-health/v1/available-checks/overview


## 迁移

```bash
php artisan vendor:publish --tag="health-migrations"
```

```bash
php artisan migrate
```

## 使用

手动运行健康检查：

```bash
php artisan health:check
```

定时运行健康检查：

`app/Console/Kernel.php` 中添加以下代码：

```php
$schedule->command(RunHealthChecksCommand::class)->everyMinute();
```

或者在 `routes/console.php` 中添加以下代码：

```php
Schedule::command(RunHealthChecksCommand::class)->everyMinute();
```

## 自定义告警消息

支持多语言配置，通过 `messages` 配置项自定义各 Check 类的告警消息模板。模板使用 `{placeholder}` 占位符。

### CpuLoadCheck 消息模板

| Key | 默认值 | 参数 |
|-----|-------|------|
| `loadAbove` | CPU使用率超过阈值：{value}%% | `{value}` - 阈值 |
| `loadIncreasing` | CPU使用率急剧上升, ratio: {ratio} | `{ratio}` - 增长倍数 |

### RequestCheck 消息模板

| Key | 默认值 | 参数 |
|-----|-------|------|
| `fetchFailed` | 无法获取请求日志：{error} | `{error}` - 错误信息 |
| `emptyData` | 请求日志数据为空 | - |
| `rpsIncrease` | 突发流量激增, Rps5m: {rps5m}, Rps1h: {rps1h} | `{rps5m}` - 5分钟RPS, `{rps1h}` - 1小时RPS |
| `durationIncrease` | 响应时间劣化, AvgDuration5m: {duration5m}s, AvgDuration1h: {duration1h}s | 响应时间 |
| `maxDurationFail` | 最大响应时长 {maxDuration}s 超过阈值 {threshold}s | `{maxDuration}` - 最大响应时长, `{threshold}` - 阈值 |

### LogCheck 消息模板

| Key | 默认值 | 参数 |
|-----|-------|------|
| `fetchFailed` | 无法获取错误日志：{error} | `{error}` - 错误信息 |
| `errorLogs` | 程序异常：{logs} | `{logs}` - 错误日志JSON |
| `warningLogs` | WARNING日志过多({count}条)，建议检查 | `{count}` - 警告数量 |

### PhpFpmCheck 消息模板

| Key | 默认值 | 参数 |
|-----|-------|------|
| `fetchFailed` | 无法访问 PHP-FPM 状态页面：{error} | `{error}` - 错误信息 |
| `httpError` | PHP-FPM 状态页面响应错误（HTTP {status}） | `{status}` - HTTP 状态码 |
| `invalidResponse` | PHP-FPM 状态页面内容无效... | - |
| `activePercentFail` | 活动进程数占比 {percent}% 超过阈值 {threshold}% | `{percent}` - 占比, `{threshold}` - 阈值 |
| `activePercentWarn` | 活动进程数占比 {percent}% 超过阈值 {threshold}% | `{percent}` - 占比, `{threshold}` - 阈值 |
| `activeProcessesFail` | 活动进程数 ({active}) 超过上限 ({limit}) | `{active}` - 当前值, `{limit}` - 上限 |
| `idleProcessesFail` | 空闲进程数 ({idle}) 低于最小值 ({limit}) | `{idle}` - 当前值, `{limit}` - 下限 |
| `slowRequestsFail` | 慢请求数 {count} 超过阈值 {limit} | `{count}` - 当前值, `{limit}` - 阈值 |
| `listenQueueFail` | 监听队列长度 {size} 超过允许值 {limit} | `{size}` - 当前值, `{limit}` - 上限 |

## 更多说明

请参考 [spatie/laravel-health 官方文档](https://spatie.be/docs/laravel-health/v1/introduction)。
