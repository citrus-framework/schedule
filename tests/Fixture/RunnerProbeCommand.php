<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Test\Fixture;

use Citrus\Schedule\ScheduleCommand;

/**
 * テスト用のスケジュールのコマンド(runner を通して読んだ `--run` の値を出力する)
 */
class RunnerProbeCommand extends ScheduleCommand
{
    /**
     * {@inheritDoc}
     */
    public function execute(): void
    {
        echo $this->parameter('run');
    }

    /**
     * {@inheritDoc}
     */
    protected function schedules(): array
    {
        return [];
    }
}
