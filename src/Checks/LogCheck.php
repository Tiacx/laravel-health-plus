<?php

namespace Tiacx\Health\Checks;

use App\Services\AliyunLog\AliyunLogService;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

class LogCheck extends Check
{
    // 强制通知
    public bool $mustNotifyOnFailure = true;

    protected int $timeRange = 15; // 查询时间范围，单位：分钟
    protected int $failWhenErrorLogsAbove = 0; // 错误日志阈值
    protected ?int $warnWhenWarningLogsAbove = null; // 警告日志阈值

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

    public function run(): Result
    {
        $result = Result::make();

        $shortSummary = [];
        $notificationMessages = [];

        $errorLogs = $this->getLogs('LevelName:ERROR');

        $errorLogs = array_map(function ($log) {
            $context = json_decode($log['Context'], true);
            $file = data_get($context, 'context.exception.file', '');
            $message = data_get($context, 'context.exception.message') ?: $log['Message'];
            return $file ? "$file | $message" : "$message";
        }, $errorLogs);

        $errorLogs = array_values(array_unique($errorLogs));
        $errorCount = count($errorLogs);

        $shortSummary[] = "ErrorCount: $errorCount";

        if ($errorCount > $this->failWhenErrorLogsAbove) {
            $notificationMessages[] = '程序异常：' . json_encode($errorLogs, JSON_UNESCAPED_UNICODE);
        }

        $warningLogs = $this->getLogs('LevelName:WARNING|select distinct Message from log limit 100');
        $warningLogs = array_column($warningLogs, 'Message');
        $warningCount = count($warningLogs);

        $shortSummary[] = "WarningCount: $warningCount";

        if (!is_null($this->warnWhenWarningLogsAbove) && $warningCount > $this->warnWhenWarningLogsAbove) {
            $notificationMessages[] = "WARNING日志过多({$warningCount}条)，建议检查";
        }

        if (!empty($notificationMessages)) {
            $result->status = $errorCount > 0 ? Status::failed() : Status::warning();
            $result->notificationMessage(implode('; ', $notificationMessages));
        }

        return $result->shortSummary(implode(', ', $shortSummary))
            ->meta([
                'error_logs' => $errorLogs,
                'error_count' => $errorCount,
                'warning_logs' => $warningLogs,
                'warning_count' => $warningCount,
                'query_url' => strtr("{host}/kibana/app/discover#/?_g=(filters:!(),refreshInterval:(pause:!t,value:0),time:(from:'{form}',to:'{to}'))&_a=(columns:!(Level,Message,Context.context.exception.file),filters:!(),hideChart:!t,index:'{index}',interval:auto,query:(language:kuery,query:'LevelName%20:%20ERROR%20or%20LevelName%20:%20WARNING'),sort:!(!('@timestamp',desc)))", [
                    '{host}' => config('logging.channels.aliyun.kibana_host'),
                    '{form}' => now()->subMinutes(15)->toIso8601ZuluString(),
                    '{to}' => now()->toIso8601ZuluString(),
                    '{index}' => config('logging.channels.aliyun.kibana_index'),
                ]),
            ]);
    }

    public function getLogs(string $query): array
    {
        return app(AliyunLogService::class)->getLogs(
            time() - ($this->timeRange * 60),
            time(),
            $query,
            store: config('logging.channels.aliyun.store_logging')
        );
    }
}
