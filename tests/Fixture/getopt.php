<?php

declare(strict_types=1);

/**
 * 実際のコマンドラインを getopt で読み、読めた値を JSON で出力するための入口
 * (作ったコマンドラインが、本物の子のプロセスで読めることの確認に使う)
 */

echo json_encode(getopt('', ['command:', 'run:', 'at:', 'account:', 'level::', 'dry-run', 'domain:', 'other:']));
