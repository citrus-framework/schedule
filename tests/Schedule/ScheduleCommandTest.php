<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Test\Schedule;

use Citrus\Logger;
use Citrus\Logger\File;
use Citrus\Logger\Level;
use Citrus\Schedule;
use Citrus\Schedule\ScheduleException;
use Citrus\Schedule\ScheduleSetting;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Test\Fixture\SampleCommand;
use Test\Fixture\TestScheduleCommand;

/**
 * スケジュールのコマンドのテスト(ログはテスト用の一時フォルダのファイルに書かせて読む)
 */
class ScheduleCommandTest extends TestCase
{
    private string $directory;

    private array $argv;

    private function loadLogger(Level $level): void
    {
        Logger::sharedInstance()->loadConfigures([
            'type' => File::class,
            'directory' => $this->directory . '/log/',
            'filename' => 'schedule',
            'level' => $level,
            'owner' => posix_getpwuid(posix_geteuid())['name'],
            'group' => posix_getgrgid(posix_getegid())['name'],
            'mode' => 0666,
        ]);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/citrus-schedule-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/lock', 0777, true);
        mkdir($this->directory . '/log', 0777, true);
        $this->loadLogger(Level::INFO);
        $this->argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = [
            'src/command.php', '--domain=example.com', '--command=Schedule', '--run=abc', '--other=a b',
        ];
        TestScheduleCommand::$schedulesCallback = fn (): array => [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        TestScheduleCommand::$schedulesCallback = null;
        foreach (glob($this->directory . '/*/*') as $file)
        {
            unlink($file);
        }
        rmdir($this->directory . '/lock');
        rmdir($this->directory . '/log');
        rmdir($this->directory);
    }

    private function configures(): array
    {
        return ['schedule' => ['lock_directory' => $this->directory . '/lock']];
    }

    private function logs(): string
    {
        $text = '';
        foreach (glob($this->directory . '/log/*') as $file)
        {
            $text .= file_get_contents($file);
        }
        return $text;
    }

    private function command(string $time = '2026-03-04 10:30:00'): TestScheduleCommand
    {
        $command = new TestScheduleCommand();
        $command->time = new DateTimeImmutable($time);
        return $command;
    }

    #[Test]
    public function 今の分に合う指定だけを起動する()
    {
        $due = Schedule::command(SampleCommand::class, ['account' => 'a'])->minute(30);
        $notDue = Schedule::command(SampleCommand::class, ['account' => 'b'])->minute(31);
        $always = Schedule::command(SampleCommand::class, ['account' => 'c']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$due, $notDue, $always];
        $command = $this->command();

        $command->go($this->configures(), []);

        $this->assertCount(2, $command->spawned);
        $this->assertStringContainsString('--run=' . $due->key(), $command->spawned[0]);
        $this->assertStringContainsString('--run=' . $always->key(), $command->spawned[1]);
        $this->assertSame([], $command->processed);
    }

    #[Test]
    public function 合う指定が無ければ何も起動しない()
    {
        TestScheduleCommand::$schedulesCallback = fn (): array => [Schedule::command(SampleCommand::class)->minute(1)];
        $command = $this->command();

        $command->go($this->configures(), []);

        $this->assertSame([], $command->spawned);
    }

    #[Test]
    public function 指定を1つ実行する形のコマンドラインの形()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();

        $command->go($this->configures(), []);

        $expected = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            'src/command.php',
            '--command=TestSchedule',
            '--run=' . $schedule->key(),
            '--domain=example.com',
            '--other=a b',
        ]));
        $this->assertSame([$expected], $command->spawned);
    }

    #[Test]
    public function 同じkeyの指定が複数合っても1回だけ起動する()
    {
        $a = Schedule::command(SampleCommand::class, ['account' => 'a'])->minute(30);
        $b = Schedule::command(SampleCommand::class, ['account' => 'a']);
        $c = Schedule::command(SampleCommand::class, ['account' => 'c']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$a, $b, $c];
        $command = $this->command();

        $command->go($this->configures(), []);

        $this->assertCount(2, $command->spawned);
        $this->assertStringContainsString('--run=' . $a->key(), $command->spawned[0]);
        $this->assertStringContainsString('--run=' . $c->key(), $command->spawned[1]);
    }

    #[Test]
    public function 作ったコマンドラインは本物の子のプロセスでgetoptで読める()
    {
        $script = __DIR__ . '/../Fixture/getopt.php';
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a b', 'level' => '3']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $_SERVER['argv'] = [$script, '--domain', 'example.com', '--command', 'Schedule', '--other=a b'];

        $cron = $this->command();
        $cron->go($this->configures(), []);
        $run = $this->command();
        $run->go($this->configures(), ['run' => $schedule->key()]);

        $parsedRun = json_decode((string)shell_exec($cron->spawned[0]), true);
        $parsedCommand = json_decode((string)shell_exec($run->processed[0]), true);
        $this->assertSame('TestSchedule', $parsedRun['command']);
        $this->assertSame($schedule->key(), $parsedRun['run']);
        $this->assertSame('example.com', $parsedRun['domain']);
        $this->assertSame('a b', $parsedRun['other']);
        $this->assertSame('Sample', $parsedCommand['command']);
        $this->assertSame('a b', $parsedCommand['account']);
        $this->assertSame('3', $parsedCommand['level']);
        $this->assertSame('example.com', $parsedCommand['domain']);
        $this->assertSame('a b', $parsedCommand['other']);
        $this->assertArrayNotHasKey('run', $parsedCommand);
    }

    #[Test]
    public function 値がtrueの引数のコマンドラインは本物の子のプロセスでgetoptで読める()
    {
        $script = __DIR__ . '/../Fixture/getopt.php';
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a', 'level' => true, 'dry-run' => true]);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $_SERVER['argv'] = [$script, '--domain', 'example.com'];

        $run = $this->command();
        $run->go($this->configures(), ['run' => $schedule->key()]);

        $parsedCommand = json_decode((string)shell_exec($run->processed[0]), true);
        $this->assertSame('Sample', $parsedCommand['command']);
        $this->assertSame('a', $parsedCommand['account']);
        $this->assertArrayHasKey('level', $parsedCommand);
        $this->assertFalse($parsedCommand['level']);
        $this->assertArrayHasKey('dry-run', $parsedCommand);
        $this->assertFalse($parsedCommand['dry-run']);
        $this->assertSame('example.com', $parsedCommand['domain']);
    }

    #[Test]
    public function 指定したコマンドのコマンドラインの形と引数の付け方()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => "it's a b", 'level' => '3']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $expected = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            'src/command.php',
            '--command=Sample',
            "--account=it's a b",
            '--level=3',
            '--domain=example.com',
            '--other=a b',
        ]));
        $this->assertSame([$expected], $command->processed);
        $this->assertSame([], $command->spawned);
    }

    #[Test]
    public function 引数が無いときは名前だけを付ける()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $_SERVER['argv'] = ['src/command.php'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $expected = implode(' ', array_map('escapeshellarg', [PHP_BINARY, 'src/command.php', '--command=Sample']));
        $this->assertSame([$expected], $command->processed);
    }

    #[Test]
    public function 起動と出力の行と終了がログに書かれる()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->lines = ['succeeded: 100% done', 'second line'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $logs = $this->logs();
        $this->assertStringContainsString(
            '起動した: command=' . SampleCommand::class . ' arguments={"account":"a"}',
            $logs
        );
        $this->assertStringContainsString('[Sample account=a] succeeded: 100% done', $logs);
        $this->assertStringContainsString('[Sample account=a] second line', $logs);
        $this->assertStringContainsString('終わった: ', $logs);
        $this->assertStringContainsString('終了コード=0', $logs);
        $this->assertStringContainsString('秒', $logs);
    }

    #[Test]
    public function 終了コードが0でなければ終わったことが書かれる()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->exitCode = 3;

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertStringContainsString('終了コード=3', $this->logs());
    }

    #[Test]
    public function 鍵が取れないときは起動しない()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $lock = fopen($this->directory . '/lock/schedule-' . $schedule->key() . '.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertSame([], $command->processed);
        $logs = $this->logs();
        $this->assertStringContainsString('鍵が取れなかったので起動しなかった', $logs);
        $this->assertStringContainsString(SampleCommand::class, $logs);
        $this->assertStringContainsString('{"account":"a"}', $logs);

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    #[Test]
    public function 終わったら鍵を放し鍵のファイルは残る()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $path = $this->directory . '/lock/schedule-' . $schedule->key() . '.lock';
        $this->assertFileExists($path);
        $lock = fopen($path, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    #[Test]
    public function 鍵のフォルダの既定はOSの一時フォルダ()
    {
        ScheduleSetting::sharedInstance()->loadConfigures([]);

        $this->assertSame(sys_get_temp_dir(), ScheduleSetting::sharedInstance()->configures['lock_directory']);
    }

    #[Test]
    public function 見つからない指定はエラーに書いて起動しない()
    {
        $command = $this->command();

        $command->go($this->configures(), ['run' => 'nothing']);

        $this->assertSame([], $command->processed);
        $this->assertStringContainsString('スケジュールの指定が見つかりません: nothing', $this->logs());
    }

    #[Test]
    public function cronから起動した形でschedulesの例外はログに書く()
    {
        TestScheduleCommand::$schedulesCallback = function (): array {
            throw new ScheduleException('書き間違いのテスト');
        };
        $command = $this->command();

        $command->go($this->configures(), []);

        $this->assertSame([], $command->spawned);
        $this->assertStringContainsString('書き間違いのテスト', $this->logs());
    }

    #[Test]
    public function 指定を1つ実行する形でschedulesの例外はログに書く()
    {
        TestScheduleCommand::$schedulesCallback = function (): array {
            throw new ScheduleException('書き間違いのテスト2');
        };
        $command = $this->command();

        $command->go($this->configures(), ['run' => 'abc']);

        $this->assertSame([], $command->processed);
        $this->assertStringContainsString('書き間違いのテスト2', $this->logs());
    }

    #[Test]
    public function 本物の待つ起動は標準出力と標準エラー出力を1行ずつ読み終了コードを返す()
    {
        $command = $this->command();
        $lines = [];
        $started = 0;

        $code = $command->realProcess(
            "sh -c 'echo out1; echo err1 >&2; echo out2; exit 3'",
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            function () use (&$started): void {
                $started++;
            }
        );

        $this->assertSame(3, $code);
        $this->assertSame(['out1', 'err1', 'out2'], $lines);
        $this->assertSame(1, $started);
    }

    #[Test]
    public function 空白で区切った形の_command_と_run_も除く()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $_SERVER['argv'] = [
            'src/command.php', '--domain', 'example.com', '--command', 'Schedule', '--run', 'abc', '--other=1',
        ];
        $command = $this->command();

        $command->go($this->configures(), []);

        $expected = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            'src/command.php',
            '--command=TestSchedule',
            '--run=' . $schedule->key(),
            '--domain',
            'example.com',
            '--other=1',
        ]));
        $this->assertSame([$expected], $command->spawned);
    }

    #[Test]
    public function 鍵のファイルがあるときは読み取りで開いて鍵が取れる()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $path = $this->directory . '/lock/schedule-' . $schedule->key() . '.lock';
        touch($path);
        chmod($path, 0444);
        $command = $this->command();

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertCount(1, $command->processed);
        $this->assertStringContainsString('起動した', $this->logs());
    }

    #[Test]
    public function schedulesがScheduleでない要素を返したときはエラーに書く()
    {
        $this->loadLogger(Level::ERROR);
        TestScheduleCommand::$schedulesCallback = fn (): array => ['not a schedule'];
        $command = $this->command();

        $command->go($this->configures(), []);

        $this->assertSame([], $command->spawned);
        $this->assertNotSame('', $this->logs());
    }

    #[Test]
    public function 終了コードが0でなくても鍵を放す()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->exitCode = 1;

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertLockReleased($schedule);
    }

    #[Test]
    public function 起動できなかったときも鍵を放す()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->startFails = true;

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertLockReleased($schedule);
    }

    #[Test]
    public function 本物の待たない起動でコマンドが実際に動く()
    {
        $path = $this->directory . '/spawned.txt';

        $this->command()->realSpawn('touch ' . escapeshellarg($path));

        for ($i = 0; $i < 50 and file_exists($path) === false; $i++)
        {
            usleep(100000);
        }
        $this->assertFileExists($path);
        unlink($path);
    }

    #[Test]
    public function 本物のnowはPHPの既定のタイムゾーンの時刻を返す()
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try
        {
            $tokyo = $this->command()->realNow();
            date_default_timezone_set('America/New_York');
            $newYork = $this->command()->realNow();
        }
        finally
        {
            date_default_timezone_set($original);
        }

        $this->assertSame('Asia/Tokyo', $tokyo->getTimezone()->getName());
        $this->assertSame('America/New_York', $newYork->getTimezone()->getName());
    }

    #[Test]
    public function 指定したコマンドが動いている間は鍵を持っている()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = new class () extends TestScheduleCommand {
            public string $lockPath = '';

            public ?bool $acquiredWhileRunning = null;

            protected function runProcess(string $commandLine, callable $onLine, callable $onStart): ?int
            {
                $other = fopen($this->lockPath, 'r');
                $this->acquiredWhileRunning = flock($other, LOCK_EX | LOCK_NB);
                fclose($other);
                return parent::runProcess($commandLine, $onLine, $onStart);
            }
        };
        $command->time = new DateTimeImmutable('2026-03-04 10:30:00');
        $command->lockPath = $this->directory . '/lock/schedule-' . $schedule->key() . '.lock';

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertFalse($command->acquiredWhileRunning);
        $this->assertLockReleased($schedule);
    }

    #[Test]
    public function 指定を1つ実行する形は今の分に合うかを見直さずに実行する()
    {
        $schedule = Schedule::command(SampleCommand::class)->minute(31);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command('2026-03-04 10:30:00');

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertCount(1, $command->processed);
    }

    #[Test]
    public function 行を読む処理が例外を投げても子が終わるまで鍵を放さない()
    {
        $this->loadLogger(Level::ERROR);
        $marker = $this->directory . '/child-done.txt';
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = new class () extends TestScheduleCommand {
            public string $childCommand = '';

            public string $marker = '';

            public ?bool $childDoneBeforeUnlock = null;

            protected function runProcess(string $commandLine, callable $onLine, callable $onStart): ?int
            {
                try
                {
                    return $this->realProcess(
                        $this->childCommand,
                        function (string $line): void {
                            throw new \RuntimeException('行の処理の例外');
                        },
                        $onStart
                    );
                }
                finally
                {
                    // runOne の finally で鍵を放す前に、子が終わっていること
                    $this->childDoneBeforeUnlock = file_exists($this->marker);
                }
            }
        };
        $command->time = new DateTimeImmutable('2026-03-04 10:30:00');
        $command->marker = $marker;
        $command->childCommand = sprintf("sh -c 'echo x; sleep 1; touch %s'", $marker);

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertTrue($command->childDoneBeforeUnlock);
        $this->assertStringContainsString('行の処理の例外', $this->logs());
        $this->assertLockReleased($schedule);
        unlink($marker);
    }

    private function assertLockReleased(Schedule $schedule): void
    {
        $path = $this->directory . '/lock/schedule-' . $schedule->key() . '.lock';
        $lock = fopen($path, 'r');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    #[Test]
    public function 指定したコマンドの行の頭は引数が無ければコマンド名だけ()
    {
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->lines = ['hello'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertStringContainsString('[Sample] hello', $this->logs());
    }

    #[Test]
    public function 引数の値がtrueのときはコマンドラインに名前だけを付ける()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a', 'level' => true, 'dry-run' => true]);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $_SERVER['argv'] = ['src/command.php'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $expected = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            'src/command.php',
            '--command=Sample',
            '--account=a',
            '--level',
            '--dry-run',
        ]));
        $this->assertSame([$expected], $command->processed);
    }

    #[Test]
    public function 引数の値がtrueのときは行の頭に名前だけが付く()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a', 'dry-run' => true]);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->lines = ['hello'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertStringContainsString('[Sample account=a dry-run] hello', $this->logs());
    }

    #[Test]
    public function 指定したコマンドの行の頭に複数の引数が空白つなぎで付く()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'a', 'level' => '2']);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->lines = ['hello'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertStringContainsString('[Sample account=a level=2] hello', $this->logs());
    }

    #[Test]
    public function 起動できなかったときは起動したも終わったも書かずエラーを書く()
    {
        $this->loadLogger(Level::ERROR);
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->startFails = true;

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $logs = $this->logs();
        $this->assertStringContainsString('起動できなかった', $logs);
        $this->assertStringNotContainsString('起動した', $logs);
        $this->assertStringNotContainsString('終わった', $logs);
    }

    #[Test]
    public function 終了コードが0でない終わったはerrorで書かれinfoは書かれない()
    {
        $this->loadLogger(Level::ERROR);
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();
        $command->exitCode = 2;
        $command->lines = ['output line'];

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $logs = $this->logs();
        $this->assertStringContainsString('終わった: ', $logs);
        $this->assertStringContainsString('終了コード=2', $logs);
        $this->assertStringNotContainsString('起動した', $logs);
        $this->assertStringNotContainsString('output line', $logs);
    }

    #[Test]
    public function 終了コードが0の終わったはinfoなのでerrorの設定では書かれない()
    {
        $this->loadLogger(Level::ERROR);
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertSame('', $this->logs());
    }

    #[Test]
    public function schedulesの例外はerrorで書かれる()
    {
        $this->loadLogger(Level::ERROR);
        TestScheduleCommand::$schedulesCallback = function (): array {
            throw new ScheduleException('エラー確認');
        };

        $this->command()->go($this->configures(), []);
        $this->command()->go($this->configures(), ['run' => 'abc']);

        $this->assertSame(2, substr_count($this->logs(), 'エラー確認'));
    }

    #[Test]
    public function 鍵のファイルが開けないときはエラーが書かれる()
    {
        $this->loadLogger(Level::ERROR);
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = $this->command();

        $command->go(['schedule' => ['lock_directory' => $this->directory . '/nothing']], ['run' => $schedule->key()]);

        $this->assertSame([], $command->processed);
        $this->assertStringContainsString('鍵のファイルが開けません', $this->logs());
    }

    #[Test]
    public function 指定を1つ実行する形で捕まえていない例外はエラーに書き鍵を放す()
    {
        $this->loadLogger(Level::ERROR);
        $schedule = Schedule::command(SampleCommand::class);
        TestScheduleCommand::$schedulesCallback = fn (): array => [$schedule];
        $command = new class extends TestScheduleCommand {
            protected function runProcess(string $commandLine, callable $onLine, callable $onStart): ?int
            {
                throw new \RuntimeException('想定外のエラー');
            }
        };
        $command->time = new DateTimeImmutable('2026-03-04 10:30:00');

        $command->go($this->configures(), ['run' => $schedule->key()]);

        $this->assertStringContainsString('想定外のエラー', $this->logs());
        $lock = fopen($this->directory . '/lock/schedule-' . $schedule->key() . '.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    #[Test]
    public function cronから起動した形は今の時刻をschedulesより前に取る()
    {
        $command = new class extends TestScheduleCommand {
            public array $order = [];

            protected function now(): DateTimeImmutable
            {
                $this->order[] = 'now';
                return parent::now();
            }
        };
        $command->time = new DateTimeImmutable('2026-03-04 10:30:00');
        TestScheduleCommand::$schedulesCallback = function () use (&$command): array {
            $command->order[] = 'schedules';
            return [];
        };

        $command->go($this->configures(), []);

        $this->assertSame(['now', 'schedules'], $command->order);
    }

    #[Test]
    public function runnerを通して_run_の値がparameterで読める()
    {
        $script = __DIR__ . '/../Fixture/runner.php';

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --run=abc123');

        $this->assertSame('abc123', $output);
    }

    #[Test]
    public function 本物の待たない起動は終わるのを待たない()
    {
        $command = $this->command();

        $started = microtime(true);
        $command->realSpawn('sleep 2');
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(1.0, $elapsed);
    }
}
