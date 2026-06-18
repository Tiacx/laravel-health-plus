<?php

namespace Tiacx\Health\Checks;

use Spatie\Health\Checks\Result;
use Symfony\Component\Process\Process;

class UsedDiskSpaceCheck extends \Spatie\Health\Checks\Checks\UsedDiskSpaceCheck
{
    public function run(): Result
    {
        $result = parent::run();

        $extraMeta = $this->getDiskSpaceDetails();

        $result->appendMeta($extraMeta);

        return $result;
    }

    /**
     * @return array<string, int>
     */
    protected function getDiskSpaceDetails(): array
    {
        $process = Process::fromShellCommandline('df -P '.($this->filesystemName ?: '.'));

        $process->run();

        $output = $process->getOutput();

        $lines = explode(PHP_EOL, trim($output));

        $fields = preg_split('/\s+/', $lines[1] ?? '');
        if (count($fields) < 5) {
            return [];
        }

        return [
            'disk_space_total'       => round(($fields[1] ?? 0) / 1048576, 2), // GB
            'disk_space_used'        => round(($fields[2] ?? 0) / 1048576, 2), // GB
            'disk_space_available'   => round(($fields[3] ?? 0) / 1048576, 2), // GB
        ];
    }
}
