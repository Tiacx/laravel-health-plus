<?php

use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;
use Tiacx\Health\Checks\CpuLoadCheck;
use Tiacx\Health\Checks\LogCheck;
use Tiacx\Health\Checks\PhpFpmCheck;
use Tiacx\Health\Checks\RequestCheck;
use Tiacx\Health\Checks\UsedDiskSpaceCheck;
use Tiacx\Health\Notifications\HealthCheckNotifiable;
use Tiacx\Health\Notifications\HealthCheckNotification;

return [
    /*
     * A result store is responsible for saving the results of the checks. The
     * `EloquentHealthResultStore` will save results in the database. You
     * can use multiple stores at the same time.
     */
    'result_stores' => [
        EloquentHealthResultStore::class => [
            'connection' => env('HEALTH_DB_CONNECTION', env('DB_CONNECTION')),
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => env('HEALTH_CHECK_KEEP_HISTORY', 7),
        ],

        /*
        Spatie\Health\ResultStores\CacheHealthResultStore::class => [
            'store' => 'file',
        ],

        Spatie\Health\ResultStores\JsonFileHealthResultStore::class => [
            'disk' => 's3',
            'path' => 'health.json',
        ],

        Spatie\Health\ResultStores\InMemoryHealthResultStore::class,
        */
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     */
    'notifications' => [
        /*
         * Notifications will only get sent if this option is set to `true`.
         */
        'enabled' => true,

        'notifications' => [
            HealthCheckNotification::class => ['webhook'],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => HealthCheckNotifiable::class,

        /*
         * When checks start failing, you could potentially end up getting
         * a notification every minute.
         *
         * With this setting, notifications are throttled. By default, you'll
         * only get one notification per hour.
         */
        'throttle_notifications_for_minutes' => env('HEALTH_CHECK_THROTTLE_NOTIFICATIONS_FOR_MINUTES', 60),
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        /*
         * When set to true, notifications will only be sent when at least one
         * check has a 'failed' status. Warnings will be ignored.
         */
        'only_on_failure' => false,

        'mail' => [
            'to' => 'your@example.com',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'webhook' => [
            'url' => env('HEALTH_NOTIFICATION_WEBHOOK_URL', ''),
        ],
    ],

    /*
     * You can let Oh Dear monitor the results of all health checks. This way, you'll
     * get notified of any problems even if your application goes totally down. Via
     * Oh Dear, you can also have access to more advanced notification options.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,

        /*
         * When this option is enabled, the checks will run before sending a response.
         * Otherwise, we'll send the results from the last time the checks have run.
         */
        'always_send_fresh_results' => true,

        /*
         * The secret that is displayed at the Application Health settings at Oh Dear.
         */
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),

        /*
         * The URL that should be configured in the Application health settings at Oh Dear.
         */
        'url' => '/oh-dear-health-check-results',
    ],

    /*
     * You can specify a heartbeat URL for the Horizon check.
     * This URL will be pinged if the Horizon check is successful.
     * This way you can get notified if Horizon goes down.
     */
    'horizon' => [
        'heartbeat_url' => env('HORIZON_HEARTBEAT_URL'),
    ],

    /*
     * You can specify a heartbeat URL for the Schedule check.
     * This URL will be pinged if the Schedule check is successful.
     * This way you can get notified if the schedule fails to run.
     */
    'schedule' => [
        'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),
    ],

    /*
     * You can set a theme for the local results page
     *
     * - light: light mode
     * - dark: dark mode
     */
    'theme' => 'light',

    /*
     * When enabled, completed `HealthQueueJob`s will be displayed
     * in Horizon's silenced jobs screen.
     */
    'silence_health_queue_job' => true,

    /*
     * The response code to use for HealthCheckJsonResultsController when a health
     * check has failed
     */
    'json_results_failure_status' => 200,

    /*
     * You can specify a secret token that needs to be sent in the X-Secret-Token for secured access.
     */
    'secret_token' => env('HEALTH_SECRET_TOKEN'),

    /**
     * By default, conditionally skipped health checks are treated as failures.
     * You can override this behavior by uncommenting the configuration below.
     *
     * @link https://spatie.be/docs/laravel-health/v1/basic-usage/conditionally-running-or-modifying-checks
     */
    // 'treat_skipped_as_failure' => false,

    /**
     * You can specify a list of checks to run.
     */
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
            'warnWhenRpsIsIncreases' => 3, // 每秒请求数剧增超过3倍
            'warnWhenDurationIsIncreases' => 10, // 请求响应时间剧增超过10倍
            'messages' => [
                'fetchFailed' => '无法获取请求日志：{error}',
                'emptyData' => '请求日志数据为空',
                'rpsIncrease' => '突发流量激增, Rps5m: {rps5m}, Rps1h: {rps1h}',
                'durationIncrease' => '响应时间劣化, AvgDuration5m: {duration5m}ms, AvgDuration1h: {duration1h}ms',
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
            'messages' => [
                'fetchFailed' => '无法访问 PHP-FPM 状态页面：{error}',
                'httpError' => 'PHP-FPM 状态页面响应错误（HTTP {status}）',
                'invalidResponse' => "PHP-FPM 状态页面内容无效，请检查 pm.status_path 配置",
                'activePercentFail' => '活动进程数占比 {percent}% 超过阈值 {threshold}%',
                'activePercentWarn' => '活动进程数占比 {percent}% 超过阈值 {threshold}%',
                'activeProcessesFail' => '活动进程数 ({active}) 超过上限 ({limit})',
                'idleProcessesFail' => '空闲进程数 ({idle}) 低于最小值 ({limit})',
                'slowRequestsFail' => '慢请求数 {count} 超过阈值 {limit}',
                'listenQueueFail' => '监听队列长度 {size} 超过允许值 {limit}',
            ],
        ]],
        [LogCheck::class, [
            'failWhenErrorLogsAbove' => 0, // 有错误日志
            'warnWhenWarningLogsIsAbove' => 100, // 警告日志超过100条
            'messages' => [
                'fetchFailed' => '无法获取错误日志：{error}',
                'errorLogs' => '程序异常：{logs}',
                'warningLogs' => 'WARNING日志过多({count}条)，建议检查',
            ],
        ], 'everyFifteenMinutes'], // 15分钟一次
    ],
];
