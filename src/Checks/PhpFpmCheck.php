<?php

namespace Tiacx\Health\Checks;

use Exception;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class PhpFpmCheck extends Check
{
    protected string $statusUrl = 'http://localhost/status?plain';

    protected ?int $failWhenActiveProcessesAbove = null;

    protected ?int $failWhenIdleProcessesBelow = null;

    protected ?int $warnWhenActiveProcessesAbovePercentOfMaxChildren = null;

    protected ?int $failWhenActiveProcessesAbovePercentOfMaxChildren = null;

    protected bool $failWhenMaxChildrenReached = true;

    protected ?int $warnWhenSlowRequestsAbove = null;

    protected ?int $failWhenSlowRequestsAbove = null;

    protected ?int $warnWhenListenQueueAbove = null;

    protected ?int $failWhenListenQueueAbove = null;

    /**
     * 设置 PHP-FPM 状态页面的 URL
     */
    public function statusUrl(string $url): self
    {
        $this->statusUrl = $url;

        return $this;
    }

    /**
     * 当活动进程数超过指定值时判定为失败
     * @param int $limit
     * @return $this
     */
    public function failWhenActiveProcessesIsAbove(int $limit): self
    {
        $this->failWhenActiveProcessesAbove = $limit;

        return $this;
    }

    /**
     * 当空闲进程数低于指定值时判定为失败
     * @param int $limit
     * @return $this
     */
    public function failWhenIdleProcessesIsBelow(int $limit): self
    {
        $this->failWhenIdleProcessesBelow = $limit;

        return $this;
    }

    /**
     * 当活动进程数占比超过指定百分比时触发告警
     * @param int $percent
     * @return $this
     */
    public function warnWhenActiveProcessesIsAbovePercentOfMaxChildren(int $percent): self
    {
        $this->warnWhenActiveProcessesAbovePercentOfMaxChildren = $percent;

        return $this;
    }

    /**
     * 当活动进程数占比超过指定百分比时判定为失败
     * @param int $percent
     * @return $this
     */
    public function failWhenActiveProcessesIsAbovePercentOfMaxChildren(int $percent): self
    {
        $this->failWhenActiveProcessesAbovePercentOfMaxChildren = $percent;

        return $this;
    }

    /**
     * 当慢请求数超过指定值时触发告警
     * @param int $limit
     * @return $this
     */
    public function warnWhenSlowRequestsIsAbove(int $limit): self
    {
        $this->warnWhenSlowRequestsAbove = $limit;

        return $this;
    }

    /**
     * 当慢请求数超过指定值时判定为失败
     * @param int $limit
     * @return $this
     * @return Result
     */
    public function failWhenSlowRequestsIsAbove(int $limit): self
    {
        $this->failWhenSlowRequestsAbove = $limit;

        return $this;
    }

    /**
     * 当监听队列长度超过指定值时触发告警
     * @param int $limit
     * @return $this
     */
    public function warnWhenListenQueueIsAbove(int $limit): self
    {
        $this->warnWhenListenQueueAbove = $limit;

        return $this;
    }

    /**
     * 当监听队列长度超过指定值时判定为失败
     * @param int $limit
     * @return $this
     * @return Result
     */
    public function failWhenListenQueueIsAbove(int $limit): self
    {
        $this->failWhenListenQueueAbove = $limit;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            $response = Http::timeout(5)->get($this->statusUrl);
        } catch (Exception $e) {
            return $result->failed("无法访问 PHP-FPM 状态页面：{$e->getMessage()}");
        }

        if ($response->failed()) {
            return $result->failed("PHP-FPM 状态页面响应错误（HTTP {$response->status()}）");
        }

        $metrics = $this->parsePlainStatus($response->body());

        if (empty($metrics['pool'])) {
            return $result->failed("PHP-FPM 状态页面内容无效，未获取到 'pool' 字段，请检查 pm.status_path 配置");
        }

        $result->ok()
            ->shortSummary("Pool: {$metrics['pool']}, Active: {$metrics['active_processes']}, Idle: {$metrics['idle_processes']}")
            ->meta($metrics);

        if ($this->failWhenMaxChildrenReached && ($metrics['max_children_reached'] ?? 0) > 0) {
            return $result->failed("已达到 max_children 限制，发生过 {$metrics['max_children_reached']} 次");
        }

        $maxChildren = env('FPM_PM_MAX_CHILDREN', 5);

        if ($this->failWhenActiveProcessesAbovePercentOfMaxChildren && isset($maxChildren)) {
            $activePercent = ($metrics['active_processes'] / $maxChildren) * 100;
            if ($activePercent > $this->failWhenActiveProcessesAbovePercentOfMaxChildren) {
                return $result->failed("活动进程数占比 {$activePercent}% 超过阈值 {$this->failWhenActiveProcessesAbovePercentOfMaxChildren}%");
            }
        }

        if ($this->warnWhenActiveProcessesAbovePercentOfMaxChildren && isset($maxChildren)) {
            $activePercent = ($metrics['active_processes'] / $maxChildren) * 100;
            if ($activePercent > $this->warnWhenActiveProcessesAbovePercentOfMaxChildren) {
                return $result->warning("活动进程数占比 {$activePercent}% 超过阈值 {$this->warnWhenActiveProcessesAbovePercentOfMaxChildren}%");
            }
        }

        if ($this->failWhenActiveProcessesAbove !== null) {
            if ($metrics['active_processes'] > $this->failWhenActiveProcessesAbove) {
                return $result->failed(
                    "活动进程数 ({$metrics['active_processes']}) 超过了允许的上限 ({$this->failWhenActiveProcessesAbove})"
                );
            }
        }

        if ($this->failWhenIdleProcessesBelow !== null) {
            if ($metrics['idle_processes'] < $this->failWhenIdleProcessesBelow) {
                return $result->failed(
                    "空闲进程数 ({$metrics['idle_processes']}) 低于允许的最小值 ({$this->failWhenIdleProcessesBelow})"
                );
            }
        }

        if ($this->failWhenSlowRequestsAbove !== null && ($metrics['slow_requests'] ?? 0) > $this->failWhenSlowRequestsAbove) {
            return $result->failed("慢请求数 {$metrics['slow_requests']} 超过阈值 {$this->failWhenSlowRequestsAbove}");
        }

        if ($this->failWhenListenQueueAbove !== null && ($metrics['listen_queue'] ?? 0) > $this->failWhenListenQueueAbove) {
            return $result->failed("监听队列长度 {$metrics['listen_queue']} 超过允许值 {$this->failWhenListenQueueAbove}");
        }

        return $result;
    }

    /**
     * 解析 PHP-FPM 状态页面的纯文本格式响应
     *
     * 示例响应：
     * pool: www
     * process manager: dynamic
     * start time: 1565027516
     * active processes: 2
     * idle processes: 10
     * ...
     *
     * @return array<string, mixed>
     */
    protected function parsePlainStatus(string $content): array
    {
        $metrics = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // 将常用字段转换为数字类型
            if (in_array($key, [
                'start since', 'accepted conn', 'listen queue', 'max listen queue',
                'listen queue len', 'idle processes', 'active processes',
                'total processes', 'max active processes', 'max children reached',
                'slow requests',
            ])) {
                $value = is_numeric($value) ? (int) $value : $value;
            }

            // 将 key 转换为 snake_case 便于 meta 输出
            $metricKey = str_replace(' ', '_', $key);
            $metrics[$metricKey] = $value;
        }

        return $metrics;
    }
}
