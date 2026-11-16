<?php

declare(strict_types=1);

/**
 * `Console::runner()` を通して `--run=` の値が `parameter('run')` で読めることを確かめるための入口
 * (getopt は実際のコマンドラインを読むので、別の PHP のプロセスで起動する)
 */

require __DIR__ . '/../../vendor/autoload.php';

\Test\Fixture\RunnerProbeCommand::runner([]);
