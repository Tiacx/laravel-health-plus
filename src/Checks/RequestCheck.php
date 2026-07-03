<?php

namespace Tiacx\Health\Checks;

use Exception;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Tiacx\Health\Traits\HasMessages;

class RequestCheck extends Check
{
    use HasMessages;

    protected ?float $warnWhenRpsIncreasesRatio = null;

    protected ?float $warnWhenDurationIncreasesRatio = null;

    /** @var array<string, string> */
    protected array $messageTemplates = [
        'fetchFailed' => '无法获取请求日志：{error}',
        'emptyData' => '请求日志数据为空',
        'rpsIncrease' => '突发流量激增, Rps5m: {rps5m}, Rps1h: {rps1h}',
        'durationIncrease' => '响应时间劣化, AvgDuration5m: {duration5m}ms, AvgDuration1h: {duration1h}ms',
    ];

    public function warnWhenRpsIsIncreases(float $ratio): self
    {
        $this->warnWhenRpsIncreasesRatio = $ratio;

        return $this;
    }

    public function warnWhenDurationIsIncreases(float $ratio): self
    {
        $this->warnWhenDurationIncreasesRatio = $ratio;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            $service = app(\App\Services\AliyunLog\AliyunLogService::class);

            $lastFiveMinutes = $service->getLogs(
                time() - 300,
                time(),
                '*|select count(*) as pv, count(distinct userId) as uv, avg(duration) as avg_duration from log'
            );

            $lastHour = $service->getLogs(
                time() - 3600,
                time(),
                '*|select count(*) as pv, count(distinct userId) as uv, avg(duration) as avg_duration from log'
            );
        } catch (Exception $e) {
            return $result->warning($this->getMessage('fetchFailed', ['error' => $e->getMessage()]));
        }

        if (empty($lastFiveMinutes) || empty($lastHour)) {
            return $result->warning($this->getMessage('emptyData'));
        }

        $pvOfLastFiveMinutes = intval(data_get($lastFiveMinutes, '0.pv', 0));
        $uvOfLastFiveMinutes = intval(data_get($lastFiveMinutes, '0.uv', 0));
        $avgDurationOfLastFiveMinutes = round(floatval(data_get($lastFiveMinutes, '0.avg_duration', 0)), 2);
        $pvOfLastHour = intval(data_get($lastHour, '0.pv', 0));
        $uvOfLastHour = intval(data_get($lastHour, '0.uv', 0));
        $avgDurationOfLastHour = round(floatval(data_get($lastHour, '0.avg_duration', 0)), 2);

        $result->ok()
            ->shortSummary("Pv5m: $pvOfLastFiveMinutes, Uv5m: $uvOfLastFiveMinutes, AvgDuration5m: {$avgDurationOfLastFiveMinutes}ms")
            ->meta([
                'pv_5m' => $pvOfLastFiveMinutes,
                'uv_5m' => $uvOfLastFiveMinutes,
                'pv_1h' => $pvOfLastHour,
                'uv_1h' => $uvOfLastHour,
                'avg_duration_5m' => $avgDurationOfLastFiveMinutes,
                'avg_duration_1h' => $avgDurationOfLastHour,
            ]);

        // RPS 计算
        $rpsOfLastFiveMinutes = round($pvOfLastFiveMinutes / 300, 2);
        $rpsOfLastHour = round($pvOfLastHour / 3600, 2);

        // 突发流量激增
        if ($this->warnWhenRpsIncreasesRatio && $rpsOfLastFiveMinutes >= 10 && $rpsOfLastFiveMinutes > $rpsOfLastHour * $this->warnWhenRpsIncreasesRatio) {
            return $result->warning($this->getMessage('rpsIncrease', [
                'rps5m' => $rpsOfLastFiveMinutes,
                'rps1h' => $rpsOfLastHour,
            ]));
        }

        // 响应时间劣化
        if ($this->warnWhenDurationIncreasesRatio && $avgDurationOfLastFiveMinutes >= 1 && $avgDurationOfLastFiveMinutes > $avgDurationOfLastHour * $this->warnWhenDurationIncreasesRatio) {
            return $result->warning($this->getMessage('durationIncrease', [
                'duration5m' => $avgDurationOfLastFiveMinutes,
                'duration1h' => $avgDurationOfLastHour,
            ]));
        }

        return $result;
    }
}
