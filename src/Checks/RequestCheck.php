<?php

namespace Tiacx\Health\Checks;

use App\Services\AliyunLog\AliyunLogService;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class RequestCheck extends Check
{
    protected ?float $warnWhenRpsIncreasesRatio = null;

    protected ?float $warnWhenDurationIncreasesRatio = null;

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

        $service = app(AliyunLogService::class);

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

        $pvOfLastFiveMinutes = intval(data_get($lastFiveMinutes, '0.pv'));
        $uvOfLastFiveMinutes = intval(data_get($lastFiveMinutes, '0.uv'));
        $avgDurationOfLastFiveMinutes = round(floatval(data_get($lastFiveMinutes, '0.avg_duration')), 2);
        $pvOfLastHour = intval(data_get($lastHour, '0.pv'));
        $uvOfLastHour = intval(data_get($lastHour, '0.uv'));
        $avgDurationOfLastHour = round(floatval(data_get($lastHour, '0.avg_duration')), 2);

        $result->ok()
            ->shortSummary("Pv5m: $pvOfLastFiveMinutes, Uv5m: $uvOfLastFiveMinutes, AvgDuration5m: $avgDurationOfLastFiveMinutes")
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
            return $result->warning("突发流量激增, Rps5m: $rpsOfLastFiveMinutes, Rps1h: $rpsOfLastHour");
        }

        // 响应时间劣化
        if ($this->warnWhenDurationIncreasesRatio && $avgDurationOfLastFiveMinutes >= 1 && $avgDurationOfLastFiveMinutes > $avgDurationOfLastHour * $this->warnWhenDurationIncreasesRatio) {
            return $result->warning("响应时间劣化, AvgDuration5m: $avgDurationOfLastFiveMinutes, AvgDuration1h: $avgDurationOfLastHour");
        }

        return $result;
    }
}
