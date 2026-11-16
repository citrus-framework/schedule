<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Citrus\Schedule;

use Citrus\Console;
use Citrus\Logger;
use Citrus\Schedule;
use DateTimeImmutable;
use Throwable;

/**
 * スケジュールのコマンドの基底のクラス
 *
 * cron から毎分起動し(`--run` 無し)、今の分に合うスケジュールの指定ごとに、自身を `--run=<key>` の形で別のプロセスとして起動する。
 * `--run=<key>` の形では、鍵を取って指定したコマンドを起動し、終わるまで待つ。
 */
abstract class ScheduleCommand extends Console
{
    /** @var string[] command options(--run は1つの指定を実行する形のスケジュールの指定の見分け) */
    protected array $options = ['run:'];

    /**
     * {@inheritDoc}
     */
    public function execute(): void
    {
        parent::execute();
        try
        {
            ScheduleSetting::sharedInstance()->loadConfigures($this->configures);

            $run = $this->parameter('run');
            if ($run === null)
            {
                $this->dispatch();
                return;
            }
            $this->runOne($run);
        }
        catch (Throwable $e)
        {
            // cron から起動した出力は捨てられるので、捕まえていない例外もログに残す
            Logger::error('%s', $e->getMessage());
        }
    }

    /**
     * スケジュールの指定の一覧
     * @return Schedule[]
     */
    abstract protected function schedules(): array;

    /**
     * 今の時刻
     * @return DateTimeImmutable 今の時刻(PHP の既定のタイムゾーン)
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    /**
     * コマンドラインを、終わるのを待たない別のプロセスとして起動する
     * @param string $commandLine コマンドライン
     */
    protected function spawn(string $commandLine): void
    {
        exec($commandLine . ' > /dev/null 2>&1 &');
    }

    /**
     * コマンドラインを起動して終わるまで待つ(標準エラー出力は標準出力にまとめ、1行ずつ読む)
     * @param string   $commandLine コマンドライン
     * @param callable $onLine      1行読むごとに呼ぶ処理(引数は行の文字列)
     * @param callable $onStart     起動できたときに、最初の行を読む前に呼ぶ処理
     * @return int|null 終了コード(起動できなかったときは null)
     */
    protected function runProcess(string $commandLine, callable $onLine, callable $onStart): ?int
    {
        $process = proc_open($commandLine . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
        if ($process === false)
        {
            return null;
        }
        try
        {
            $onStart();
            while (($line = fgets($pipes[1])) !== false)
            {
                $onLine(rtrim($line, "\r\n"));
            }
        }
        finally
        {
            // 例外で抜けるときも、子のプロセスが終わるのを待ってから抜ける(鍵を子が動いているうちに放さないため)
            fclose($pipes[1]);
            $code = proc_close($process);
        }
        return $code;
    }

    /**
     * cron から起動した形(今の分に合うスケジュールの指定ごとに、1つの指定を実行する形を起動する)
     */
    private function dispatch(): void
    {
        $now = $this->now();
        $schedules = $this->schedules();

        $name = Schedule::commandName(static::class);
        $spawned = [];
        foreach ($schedules as $schedule)
        {
            $key = $schedule->key();
            // 同じ key の指定が複数合っても、1回だけ起動する
            if ($schedule->isDue($now) === true and array_key_exists($key, $spawned) === false)
            {
                $spawned[$key] = true;
                $this->spawn($this->commandLine($name, ['run' => $key]));
            }
        }
    }

    /**
     * 1つの指定を実行する形
     * @param string $key スケジュールの指定の見分け
     */
    private function runOne(string $key): void
    {
        $lock = null;
        try
        {
            $target = $this->findSchedule($key);
            if ($target === null)
            {
                return;
            }
            $lock = $this->lock($key, $this->detail($target));
            if ($lock === null)
            {
                return;
            }
            $this->launch($target, $this->detail($target));
        }
        catch (Throwable $e)
        {
            Logger::error('%s', $e->getMessage());
        }
        finally
        {
            if ($lock !== null)
            {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * 見分けが合うスケジュールの指定を探す(例外や見つからないときはエラーをログに書く)
     * @param string $key スケジュールの指定の見分け
     * @return Schedule|null スケジュールの指定
     */
    private function findSchedule(string $key): ?Schedule
    {
        try
        {
            foreach ($this->schedules() as $schedule)
            {
                if ($schedule->key() === $key)
                {
                    return $schedule;
                }
            }
        }
        catch (Throwable $e)
        {
            Logger::error('%s', $e->getMessage());
            return null;
        }
        Logger::error('スケジュールの指定が見つかりません: %s', $key);
        return null;
    }

    /**
     * 鍵を取る(取れなければログに書く)
     * @param string $key    スケジュールの指定の見分け
     * @param string $detail ログに書くコマンドと引数
     * @return resource|null 鍵(取れなければ null)
     */
    private function lock(string $key, string $detail)
    {
        $directory = rtrim(ScheduleSetting::sharedInstance()->configures['lock_directory'], '/');
        $path = $directory . '/schedule-' . $key . '.lock';
        // 鍵のファイルがあれば読み取りで開く(別の OS ユーザーが作ったファイルは書き込みで開けないため。flock は読み取りでも使える)
        $lock = false;
        if (is_dir($directory) === true)
        {
            $lock = fopen($path, (file_exists($path) === true ? 'r' : 'c'));
        }
        if ($lock === false)
        {
            Logger::error('鍵のファイルが開けません: %s', $path);
            return null;
        }
        if ($this->flockOrLog($lock, $path, $detail) === false)
        {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /**
     * 鍵を flock で取る(取れなければログに書く)
     * @param resource $lock   鍵のファイル
     * @param string   $path   鍵のファイルのパス
     * @param string   $detail ログに書くコマンドと引数
     * @return bool true:取れた
     */
    private function flockOrLog($lock, string $path, string $detail): bool
    {
        if (flock($lock, LOCK_EX | LOCK_NB, $wouldBlock) === true)
        {
            return true;
        }
        // ほかのプロセスが持っているから取れないのか、flock 自体が失敗したのかを見分ける
        if ($wouldBlock === 1)
        {
            Logger::info('鍵が取れなかったので起動しなかった: %s', $detail);
            return false;
        }
        Logger::error('鍵が取れませんでした: %s', $path);
        return false;
    }

    /**
     * 指定したコマンドを起動して、結果をログに書く
     * @param Schedule $schedule スケジュールの指定
     * @param string   $detail   ログに書くコマンドと引数
     */
    private function launch(Schedule $schedule, string $detail): void
    {
        $started = microtime(true);
        $name = Schedule::commandName($schedule->commandClass());
        $commandLine = $this->commandLine($name, $schedule->arguments());
        $prefix = $this->linePrefix($schedule);
        $code = $this->runProcess(
            $commandLine,
            function (string $line) use ($prefix): void {
                Logger::info('%s', $prefix . $line);
            },
            function () use ($detail): void {
                Logger::info('起動した: %s', $detail);
            }
        );
        if ($code === null)
        {
            Logger::error('起動できなかった: %s', $detail);
            return;
        }
        $seconds = round(microtime(true) - $started, 2);
        if ($code === 0)
        {
            Logger::info('終わった: %s 終了コード=%d 時間=%s秒', $detail, $code, $seconds);
            return;
        }
        Logger::error('終わった: %s 終了コード=%d 時間=%s秒', $detail, $code, $seconds);
    }

    /**
     * 指定したコマンドが出した行の頭に付ける、どの指定の行かを表す文字列
     * @param Schedule $schedule スケジュールの指定
     * @return string `[<--command= の値> <name=value の空白つなぎ(値が true のときは name だけ)>] `
     */
    private function linePrefix(Schedule $schedule): string
    {
        $items = [Schedule::commandName($schedule->commandClass())];
        foreach ($schedule->arguments() as $key => $value)
        {
            $items[] = ($value === true ? $key : $key . '=' . $value);
        }
        return '[' . implode(' ', $items) . '] ';
    }

    /**
     * ログに書くコマンドと引数
     * @param Schedule $schedule スケジュールの指定
     * @return string コマンドと引数
     */
    private function detail(Schedule $schedule): string
    {
        return sprintf(
            'command=%s arguments=%s',
            $schedule->commandClass(),
            json_encode($schedule->arguments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * コマンドラインを作る
     * @param string $name      `--command=` の値
     * @param array<string, string|true> $arguments 引数(名前 => 値)
     * @return string コマンドライン
     */
    private function commandLine(string $name, array $arguments): string
    {
        $argv = ($_SERVER['argv'] ?? ['']);
        // getopt は、オプションでないトークンに当たると読むのをやめるため、引き継ぐ引数は最後に置く
        $parts = [PHP_BINARY, $argv[0], '--command=' . $name];
        foreach ($arguments as $key => $value)
        {
            $parts[] = ($value === true ? sprintf('--%s', $key) : sprintf('--%s=%s', $key, $value));
        }
        $parts = [...$parts, ...$this->inheritedArguments(array_slice($argv, 1))];
        return implode(' ', array_map('escapeshellarg', $parts));
    }

    /**
     * スケジュールのコマンドに付いていた引数のうち、引き継ぐもの(`--command`・`--run` は、`=` の形も空白で区切った形も除く)
     * @param string[] $rest 入口のスクリプトより後ろの引数
     * @return string[] 引き継ぐ引数
     */
    private function inheritedArguments(array $rest): array
    {
        $inherited = [];
        $count = count($rest);
        for ($i = 0; $i < $count; $i++)
        {
            // `--command Schedule` のような空白で区切った形は、次のトークンもまとめて除く
            if ($rest[$i] === '--command' or $rest[$i] === '--run')
            {
                $i++;
                continue;
            }
            if (preg_match('/^--(command|run)=/', $rest[$i]) !== 1)
            {
                $inherited[] = $rest[$i];
            }
        }
        return $inherited;
    }
}
