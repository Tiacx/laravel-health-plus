<?php

namespace Tiacx\Health\Checks;

use Exception;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use Tiacx\Health\Traits\HasMessages;

class LogCheck extends Check
{
    use HasMessages;

    /** Kibana Discover 查询 URL 默认模板（占位符：{host}、{from}、{to}、{index}） */
    protected const DEFAULT_KIBANA_URL_TEMPLATE = '{host}/kibana/app/discover#/?_g=(filters:!(),refreshInterval:(pause:!t,value:0),time:(from:\'{from}\',to:\'{to}\'))&_a=(columns:!(Level,Message,Context.context.exception.file),filters:!(),hideChart:!t,index:\'{index}\',interval:auto,query:(language:kuery,query:\'LevelName%20:%20ERROR%20or%20LevelName%20:%20WARNING\'),sort:!((\'@timestamp\',desc)))';

    // 强制通知
    public bool $mustNotifyOnFailure = true;

    protected int $timeRange = 15; // 查询时间范围，单位：分钟
    protected int $failWhenErrorLogsAbove = 0; // 错误日志阈值
    protected ?int $warnWhenWarningLogsAbove = null; // 警告日志阈值
    protected int $maxErrorLogsInNotification = 10; // 通知中最多显示的错误日志条数
    protected string $errorLogsQuery = 'LevelName:ERROR'; // 错误日志查询语句
    protected string $warningLogsQuery = 'LevelName:WARNING|select distinct Message from log limit 100'; // 警告日志查询语句
    protected ?string $kibanaUrlTemplate = null; // Kibana 查询 URL 模板，null 时使用默认模板

    /** @var array<string, string> */
    protected array $messageTemplates = [
        'fetchFailed' => '无法获取错误日志：{error}',
        'errorLogs' => '程序异常：{logs}',
        'warningLogs' => 'WARNING日志过多({count}条)，建议检查',
    ];

    public function timeRange(int $timeRange): static
    {
        $this->timeRange = $timeRange;

        return $this;
    }

    public function failWhenErrorLogsAbove(int $errorThreshold): static
    {
        $this->failWhenErrorLogsAbove = $errorThreshold;

        return $this;
    }

    public function warnWhenWarningLogsIsAbove(int $warningThreshold): static
    {
        $this->warnWhenWarningLogsAbove = $warningThreshold;

        return $this;
    }

    /**
     * 设置通知中最多显示的错误日志条数
     */
    public function maxErrorLogsInNotification(int $count): static
    {
        $this->maxErrorLogsInNotification = $count;

        return $this;
    }

    /**
     * 设置错误日志查询语句
     */
    public function errorLogsQuery(string $query): static
    {
        $this->errorLogsQuery = $query;

        return $this;
    }

    /**
     * 设置警告日志查询语句
     */
    public function warningLogsQuery(string $query): static
    {
        $this->warningLogsQuery = $query;

        return $this;
    }

    /**
     * 设置 Kibana 查询 URL 模板（占位符：{host}、{from}、{to}、{index}）
     */
    public function kibanaUrlTemplate(string $template): static
    {
        $this->kibanaUrlTemplate = $template;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        $shortSummary = [];
        $notificationMessages = [];

        try {
            $errorLogs = $this->getLogs($this->errorLogsQuery);
        } catch (Exception $e) {
            return $result->warning($this->getMessage('fetchFailed', ['error' => $e->getMessage()]));
        }

        $errorLogs = array_map(function ($log) {
            $context = json_decode($log['Context'] ?? '{}', true);
            $file = data_get($context, 'context.exception.file', '');
            $message = data_get($context, 'context.exception.message') ?: ($log['Message'] ?? '');
            $message = Str::limit($message, 200, '...');
            return $file ? "$file | $message" : $message;
        }, $errorLogs);

        $errorLogs = array_values(array_unique($errorLogs));
        $errorCount = count($errorLogs);

        $shortSummary[] = "ErrorCount: $errorCount";

        if ($errorCount > $this->failWhenErrorLogsAbove) {
            // 截断错误日志列表，避免通知消息过长
            $displayLogs = array_slice($errorLogs, 0, $this->maxErrorLogsInNotification);
            $truncated = $errorCount > $this->maxErrorLogsInNotification;
            $logSummary = json_encode($displayLogs, JSON_UNESCAPED_UNICODE);
            if ($truncated) {
                $logSummary = rtrim($logSummary, ']') . ', ...] (' . ($errorCount - $this->maxErrorLogsInNotification) . ' more)';
            }
            $notificationMessages[] = $this->getMessage('errorLogs', ['logs' => $logSummary]);
        }

        try {
            $warningLogs = $this->getLogs($this->warningLogsQuery);
        } catch (Exception $e) {
            $warningLogs = [];
        }
        $warningLogs = array_column($warningLogs, 'Message');
        $warningCount = count($warningLogs);

        $shortSummary[] = "WarningCount: $warningCount";

        if (!is_null($this->warnWhenWarningLogsAbove) && $warningCount > $this->warnWhenWarningLogsAbove) {
            $notificationMessages[] = $this->getMessage('warningLogs', ['count' => $warningCount]);
        }

        if (!empty($notificationMessages)) {
            $result->status = $errorCount > 0 ? Status::failed() : Status::warning();
            $result->notificationMessage(implode('; ', $notificationMessages));
        }

        // 构建查询 URL，处理配置缺失的情况
        $kibanaHost = config('health.aliyun_log.kibana_host');
        $kibanaIndex = config('health.aliyun_log.kibana_index');
        $queryUrl = null;

        if ($kibanaHost && $kibanaIndex) {
            $queryUrl = strtr($this->kibanaUrlTemplate ?? self::DEFAULT_KIBANA_URL_TEMPLATE, [
                '{host}' => $kibanaHost,
                '{from}' => now()->subMinutes($this->timeRange)->toIso8601ZuluString(),
                '{to}' => now()->toIso8601ZuluString(),
                '{index}' => $kibanaIndex,
            ]);
        }

        return $result->shortSummary(implode(', ', $shortSummary))
            ->meta([
                'error_logs' => $errorLogs,
                'error_count' => $errorCount,
                'warning_logs' => $warningLogs,
                'warning_count' => $warningCount,
                'query_url' => $queryUrl,
            ]);
    }

    public function getLogs(string $query): array
    {
        return app(config('health.aliyun_log_service'))->getLogs(
            time() - ($this->timeRange * 60),
            time(),
            $query,
            store: config('health.aliyun_log.store_logging')
        );
    }
}
