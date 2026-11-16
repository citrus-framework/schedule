<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Test\Fixture;

use Citrus\Schedule\ScheduleCommand;
use DateTimeImmutable;

/**
 * テスト用のスケジュールのコマンド(今の時刻、起動を差し替える)
 */
class TestScheduleCommand extends ScheduleCommand
{
    /** @var callable|null schedules() の中身 */
    public static $schedulesCallback = null;

    /** @var DateTimeImmutable 今の時刻 */
    public DateTimeImmutable $time;

    /** @var string[] 待たない起動したコマンドライン */
    public array $spawned = [];

    /** @var string[] 待つ起動したコマンドライン */
    public array $processed = [];

    /** @var string[] 待つ起動で、標準出力に出たことにする行 */
    public array $lines = [];

    /** @var bool true:待つ起動が起動できなかったことにする */
    public bool $startFails = false;

    /** @var int 待つ起動の終了コード */
    public int $exitCode = 0;

    /**
     * 設定と引数を渡して実行する
     * @param array $configures 設定
     * @param array $parameters 引数
     */
    public function go(array $configures, array $parameters): void
    {
        $this->configures = $configures;
        $this->parameters = $parameters;
        $this->execute();
    }

    /**
     * {@inheritDoc}
     */
    protected function schedules(): array
    {
        return (self::$schedulesCallback)();
    }

    /**
     * {@inheritDoc}
     */
    protected function now(): DateTimeImmutable
    {
        return $this->time;
    }

    /**
     * {@inheritDoc}
     */
    protected function spawn(string $commandLine): void
    {
        $this->spawned[] = $commandLine;
    }

    /**
     * {@inheritDoc}
     */
    protected function runProcess(string $commandLine, callable $onLine, callable $onStart): ?int
    {
        $this->processed[] = $commandLine;
        if ($this->startFails === true)
        {
            return null;
        }
        $onStart();
        foreach ($this->lines as $line)
        {
            $onLine($line);
        }
        return $this->exitCode;
    }

    /**
     * 差し替えていない本物の今の時刻を呼ぶ
     * @return DateTimeImmutable 今の時刻
     */
    public function realNow(): DateTimeImmutable
    {
        return parent::now();
    }

    /**
     * 差し替えていない本物の待たない起動を呼ぶ
     * @param string $commandLine コマンドライン
     */
    public function realSpawn(string $commandLine): void
    {
        parent::spawn($commandLine);
    }

    /**
     * 差し替えていない本物の待つ起動を呼ぶ
     * @param string   $commandLine コマンドライン
     * @param callable $onLine      1行読むごとに呼ぶ処理
     * @param callable $onStart     起動できたときに呼ぶ処理
     * @return int|null 終了コード
     */
    public function realProcess(string $commandLine, callable $onLine, callable $onStart): ?int
    {
        return parent::runProcess($commandLine, $onLine, $onStart);
    }
}
