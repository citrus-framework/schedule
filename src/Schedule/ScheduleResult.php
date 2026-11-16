<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Citrus\Schedule;

use DateTimeImmutable;

/**
 * 起動の結果(`ScheduleCommand::onResult()` に渡す)
 */
class ScheduleResult
{
    /**
     * @param ScheduleEvent               $event        出来事の種類
     * @param string|null                 $commandClass 指定したコマンドのクラス(分からないときは null)
     * @param array<string, string|true>  $arguments    引数(分からないときは空)
     * @param int|null                    $exitCode     終了コード(FINISHED のときだけ)
     * @param float|null                  $seconds      かかった時間(FINISHED のときだけ)
     * @param string[]                    $lines        指定したコマンドが出した文の終わりの 20 行
     * @param string|null                 $error        エラーの文
     * @param DateTimeImmutable|null      $at           スケジュールの指定を選んだ時刻(分まで。分からないときは null)
     */
    public function __construct(
        public readonly ScheduleEvent $event,
        public readonly ?string $commandClass = null,
        public readonly array $arguments = [],
        public readonly ?int $exitCode = null,
        public readonly ?float $seconds = null,
        public readonly array $lines = [],
        public readonly ?string $error = null,
        public readonly ?DateTimeImmutable $at = null,
    ) {
    }
}
