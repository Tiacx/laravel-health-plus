<?php

namespace Tiacx\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Symfony\Component\Process\Process;
use Tiacx\Health\Traits\HasMessages;

class CpuLoadCheck extends Check
{
    use HasMessages;

    protected ?float $warnWhenLoadIncreasesRatio = null; // 增加倍数
    protected ?int $warnWhenLoadAbove = null; // 负载值
    protected float $absoluteIncreaseThreshold = 15.0; // 百分比绝对值，默认 15%
    protected int $topProcessesLimit = 5;
    protected string $cpuStatPath = '/sys/fs/cgroup/cpuacct/cpuacct.stat';
    protected ?int $systemHz = null;

    /** @var array<string, string> */
    protected array $messageTemplates = [
        'loadAbove' => 'CPU使用率超过阈值：{value}%%',
        'loadIncreasing' => 'CPU使用率急剧上升, ratio: {ratio}',
    ];

    /**
     * 设置 CPU 使用率统计路径
     */
    public function cpuStatPath(string $path): self
    {
        $this->cpuStatPath = $path;

        return $this;
    }

    /**
     * 设置告警的负载上升倍数（例如 3 表示当前使用率 > 上次使用率 * 3）
     * @param float $ratio
     * @return $this
     */
    public function warnWhenLoadIsIncreasing(float $ratio): self
    {
        $this->warnWhenLoadIncreasesRatio = $ratio;

        return $this;
    }

    /**
     * 设置告警的负载值（例如 80 表示当前使用率 > 80%）
     * @param int $limit
     * @return $this
     */
    public function warnWhenLoadIsAbove(int $limit): self
    {
        $this->warnWhenLoadAbove = $limit;

        return $this;
    }

    /**
     * 设置绝对增加阈值（百分点），默认 20%
     * @param float $threshold
     * @return $this
     */
    public function absoluteIncreaseThreshold(float $threshold): self
    {
        $this->absoluteIncreaseThreshold = $threshold;

        return $this;
    }

    /**
     * 设置返回的 Top 进程数量
     * @param int $limit
     * @return $this
     */
    public function topProcessesLimit(int $limit): self
    {
        $this->topProcessesLimit = $limit;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        // 1. 获取上次存储的使用率
        $lastCheck = HealthCheckResultHistoryItem::query()
            ->where('check_name', '=', 'CpuLoad')
            ->orderByDesc('id')
            ->first();

        // 2. 获取当前容器 CPU 使用率
        $currentCheck = $this->getCpuUsage($lastCheck?->meta);

        // 3. 存储本次使用率（供下次对比）
        $result->meta($currentCheck);

        // 4. 如果没有历史数据（首次运行），直接返回健康状态
        if (empty($lastCheck) || !array_key_exists('usage',$lastCheck->meta)) {
            $result->shortSummary('first run');
            return $result->ok();
        }

        // 6. 判断负载是否急剧上升
        $lastUsage = $lastCheck->meta['usage'];
        $currentUsage = $currentCheck['usage'];
        $ratio = $currentUsage / max($lastUsage, 0.01);
        $absoluteIncrease = $currentUsage - $lastUsage;

        $result->shortSummary(sprintf('Last: %.2f%%, Current: %.2f%%', $lastUsage, $currentUsage));

        // 负载绝对值告警
        if (!is_null($this->warnWhenLoadAbove) && $currentUsage >= $this->warnWhenLoadAbove) {
            $result->warning($this->getMessage('loadAbove', ['value' => $this->warnWhenLoadAbove]));
            $topProcesses = $this->getTopCpuProcesses($this->topProcessesLimit);
            if (!empty($topProcesses)) {
                $result->appendMeta(['top_cpu_processes' => $topProcesses]);
            }
            return $result;
        }

        // 负载急剧上升告警
        if (!is_null($this->warnWhenLoadIncreasesRatio) &&
            $ratio >= $this->warnWhenLoadIncreasesRatio &&
            $absoluteIncrease > $this->absoluteIncreaseThreshold
        ) {
            $result->warning($this->getMessage('loadIncreasing', ['ratio' => round($ratio, 2)]));
            $topProcesses = $this->getTopCpuProcesses($this->topProcessesLimit);
            if (!empty($topProcesses)) {
                $result->appendMeta(['top_cpu_processes' => $topProcesses]);
            }
            return $result;
        }

        return $result->ok();
    }

    /**
     * 获取系统节拍率 HZ
     * @return int
     */
    private function getSystemHz(): int
    {
        if (!is_null($this->systemHz)) {
            return $this->systemHz;
        }

        $process = Process::fromShellCommandline('getconf CLK_TCK 2>/dev/null');
        $process->run();
        $hz = (int) $process->getOutput();
        return $hz > 0 ? $hz : 100;
    }

    /**
     * 获取当前容器 CPU 信息
     * @return array
     */
    private function getCpuInfo(): array
    {
        if (!file_exists($this->cpuStatPath)) {
            return ['user_time' => 0, 'system_time' => 0];
        }

        $content = @file_get_contents($this->cpuStatPath);
        if (!preg_match('/user\s+(\d+)/', $content, $user) || !preg_match('/system\s+(\d+)/', $content, $sys)) {
            return ['user_time' => 0, 'system_time' => 0];
        }

        return [
            'user_time' => (int) $user[1],
            'system_time' => (int) $sys[1],
        ];
    }

    /**
     * 获取当前容器 CPU 使用率
     * @param array|null $lastUsage
     * @return array
     */
    private function getCpuUsage(?array $lastUsage): array
    {
        ['user_time' => $userTime, 'system_time' => $systemTime] = $this->getCpuInfo();

        if (empty($lastUsage) || !isset($lastUsage['user_time'])) {
            return [
                'user_time' => $userTime,
                'system_time' => $systemTime,
                'total_delta' => 0.0,
                'usage' => 0.0,
                'timestamp' => microtime(true),
            ];
        }

        // 重新部署后，可能会出现这种情况，需要重新计算
        if ($userTime < $lastUsage['user_time'] || $systemTime < $lastUsage['system_time']) {
            $lastUsage = [
                'user_time' => $userTime,
                'system_time' => $systemTime,
                'timestamp' => microtime(true),
            ];
            sleep(3); // 等待 3 秒，重新计算
            ['user_time' => $userTime, 'system_time' => $systemTime] = $this->getCpuInfo();
        }

        $systemHz = $this->getSystemHz();
        $nowMicro = microtime(true);

        $userDelta = $userTime - $lastUsage['user_time'];
        $sysDelta = $systemTime - $lastUsage['system_time'];
        $totalDelta = $userDelta + $sysDelta;
        $elapsed = $nowMicro - $lastUsage['timestamp'];

        $usage = round(($totalDelta / $systemHz) / $elapsed * 100.0, 2);

        return [
            'user_time' => $userTime,
            'system_time' => $systemTime,
            'total_delta' => $totalDelta,
            'usage' => $usage,
            'timestamp' => $nowMicro,
        ];
    }

    /**
     * 获取 CPU 占用最高的 N 个进程（基于进程启动以来的累计 CPU 时间）
     * 注意：这不是实时使用率，而是总 CPU 时间消耗的排序，用于辅助定位。
     * @param int $limit
     * @return array
     */
    protected function getTopCpuProcesses(int $limit): array
    {
        if (!is_dir('/proc')) {
            return [];
        }

        $processes = [];

        foreach (glob('/proc/[0-9]*') as $pidPath) {
            $pid = basename($pidPath);
            $statContent = @file_get_contents("{$pidPath}/stat");
            if (!$statContent) {
                continue;
            }

            // 解析 stat：字段 14-17 分别是 utime, stime, cutime, cstime
            $closeParen = strrpos($statContent, ')');
            if ($closeParen === false) {
                continue;
            }

            $fields = explode(' ', substr($statContent, $closeParen + 2));
            if (count($fields) < 4) {
                continue;
            }

            $utime = (int) $fields[0];
            $stime = (int) $fields[1];
            $cutime = (int) $fields[2];
            $cstime = (int) $fields[3];
            $totalTicks = $utime + $stime + $cutime + $cstime;

            if ($totalTicks === 0) {
                continue;
            }

            $processes[] = [
                'pid'  => (int) $pid,
                'name' => $this->getProcessName($pid),
                'total_ticks' => $totalTicks,
            ];
        }

        // 按累计 ticks 降序排序
        usort($processes, fn($a, $b) => $b['total_ticks'] <=> $a['total_ticks']);

        return array_slice($processes, 0, $limit);
    }

    /**
     * 获取进程名称
     * @param string $pid
     * @return string
     */
    protected function getProcessName(string $pid): string
    {
        $commPath = "/proc/{$pid}/comm";
        if (is_readable($commPath)) {
            $comm = @file_get_contents($commPath);
            if ($comm !== false) {
                return trim($comm) ?: 'unknown';
            }
        }

        // 回退：从 stat 解析
        $statContent = @file_get_contents("/proc/{$pid}/stat");
        if ($statContent) {
            $closeParen = strrpos($statContent, ')');
            if ($closeParen !== false) {
                return trim(substr($statContent, 1, $closeParen - 1)) ?: 'unknown';
            }
        }

        return 'unknown';
    }
}
