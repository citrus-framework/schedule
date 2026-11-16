<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Test\Fixture;

use Citrus\Console;

/**
 * テスト用のコマンド(引数 account(値が要る)、level(値を省ける)、dry-run(値を取らない)を受け取る)
 */
class SampleCommand extends Console
{
    /** @var string[] command options */
    protected array $options = ['account:', 'level::', 'dry-run'];
}
