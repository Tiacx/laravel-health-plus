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
    ]],
    [RequestCheck::class, [
        'warnWhenRpsIsIncreases' => 3, // 每秒请求数剧增超过3倍
        'warnWhenDurationIsIncreases' => 10, // 请求响应时间剧增超过10倍
    ]],
    [PhpFpmCheck::class, [
        'statusUrl' => 'http://localhost/fpm-status',
        'warnWhenActiveProcessesIsAbovePercentOfMaxChildren' => 80,
        'failWhenActiveProcessesIsAbovePercentOfMaxChildren' => 95,
        'warnWhenListenQueueIsAbove' => 5,
        'failWhenListenQueueIsAbove' => 10,
        'warnWhenSlowRequestsIsAbove' => 5,
        'failWhenSlowRequestsIsAbove' => 10,
    ]],
    [LogCheck::class, [
        'failWhenErrorLogsAbove' => 0, // 有错误日志
        'warnWhenWarningLogsIsAbove' => 100, // 警告日志超过100条
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

## 更多说明

请参考 [spatie/laravel-health 官方文档](https://spatie.be/docs/laravel-health/v1/introduction)。
