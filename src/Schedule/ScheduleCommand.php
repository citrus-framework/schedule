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
    /** 指定したコマンドが出した文のうち、結果に入れる終わりの行数 */
    private const TAIL_LINES = 20;

    /** `--at=` の時刻の形(分まで) */
    private const AT_FORMAT = 'Y-m-d\TH:i';

    /** @var string[] command options(--run は1つの指定を実行する形のスケジュールの指定の見分け、--at はスケジュールの指定を選んだ時刻) */
    protected array $options = ['run:', 'at:'];

    /**
     * {@inheritDoc}
     */
    public function execute(): void
    {
        parent::execute();
        $run = $this->parameter('run');
        // 1つの指定を実行する形は --at を先に読む(設定の読み込みなどの例外の結果にも入れるため)
        $at = ($run === null ? null : $this->parseAt($this->parameter('at')));
        try
        {
            ScheduleSetting::sharedInstance()->loadConfigures($this->configures);

            if ($run === null)
            {
                // cron から起動した形は、今の時刻を取ったあとの例外の結果に、その時刻を入れる
                $at = $this->now();
                $at = $at->setTime((int)$at->format('H'), (int)$at->format('i'));
                $this->dispatch($at);
                return;
            }
            $this->runOne($run, $at);
        }
        catch (Throwable $e)
        {
            // cron から起動した出力は捨てられるので、捕まえていない例外もログに残す
            Logger::error('%s', $e->getMessage());
            $this->notify(new ScheduleResult(ScheduleEvent::EXCEPTION, error: $e->getMessage(), at: $at));
        }
    }

    /**
     * 起動の結果を受け取る(何もしない)
     *
     * 案件のスケジュールのコマンドが上書きして使う。ログに書いたあと(指定したコマンドの結果は、鍵を放したあと)に呼ばれる。
     * ここで起きた例外は捕まえてログに書き、ほかの処理は続ける。
     * @param ScheduleResult $result 起動の結果
     */
    protected function onResult(ScheduleResult $result): void
    {
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
     * @param DateTimeImmutable $now 今の時刻(分まで)
     */
    private function dispatch(DateTimeImmutable $now): void
    {
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
                $this->spawn($this->commandLine($name, ['run' => $key, 'at' => $now->format(self::AT_FORMAT)]));
            }
        }
    }

    /**
     * 1つの指定を実行する形
     * @param string                 $key スケジュールの指定の見分け
     * @param DateTimeImmutable|null $at  スケジュールの指定を選んだ時刻(分まで)
     */
    private function runOne(string $key, ?DateTimeImmutable $at): void
    {
        $result = $this->findAndLaunch($key, $at);
        // 案件の処理の時間の分だけ鍵を持つ時間が延びないように、鍵を放したあとに渡す
        if ($result !== null)
        {
            $this->notify($result);
        }
    }

    /**
     * スケジュールの指定を探し、鍵を取って指定したコマンドを起動し、鍵を放す
     * @param string                 $key スケジュールの指定の見分け
     * @param DateTimeImmutable|null $at  スケジュールの指定を選んだ時刻(分まで)
     * @return ScheduleResult|null 案件に渡す結果(渡さないときは null)
     */
    private function findAndLaunch(string $key, ?DateTimeImmutable $at): ?ScheduleResult
    {
        $found = $this->findSchedule($key, $at);
        if ($found instanceof ScheduleResult)
        {
            return $found;
        }
        $lock = null;
        try
        {
            $locked = $this->lock($found, $at);
            if (is_resource($locked) === false)
            {
                return $locked;
            }
            $lock = $locked;
            return $this->launch($found, $this->detail($found), $at);
        }
        catch (Throwable $e)
        {
            Logger::error('%s', $e->getMessage());
            return $this->result(ScheduleEvent::EXCEPTION, $found, $at, $e->getMessage());
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
     * 見分けが合うスケジュールの指定を探す(例外や見つからないときはエラーをログに書き、結果を作る)
     * @param string                 $key    スケジュールの指定の見分け
     * @param DateTimeImmutable|null $at     スケジュールの指定を選んだ時刻
     * @return Schedule|ScheduleResult スケジュールの指定(見つからないとき・例外のときは結果)
     */
    private function findSchedule(string $key, ?DateTimeImmutable $at): Schedule|ScheduleResult
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
            return $this->result(ScheduleEvent::EXCEPTION, null, $at, $e->getMessage());
        }
        $message = sprintf('スケジュールの指定が見つかりません: %s', $key);
        Logger::error('%s', $message);
        return $this->result(ScheduleEvent::NOT_FOUND, null, $at, $message);
    }

    /**
     * 鍵を取る(取れなければログに書く)
     * @param Schedule               $schedule スケジュールの指定
     * @param DateTimeImmutable|null $at       スケジュールの指定を選んだ時刻
     * @return resource|ScheduleResult|null 鍵(鍵が取れなかったので起動しないときは null、
     *                                      鍵のファイルが開けないとき・flock が失敗したときは結果)
     */
    private function lock(Schedule $schedule, ?DateTimeImmutable $at)
    {
        $directory = rtrim(ScheduleSetting::sharedInstance()->configures['lock_directory'], '/');
        $path = $directory . '/schedule-' . $schedule->key() . '.lock';
        $lock = $this->openLockFile($directory, $path);
        if ($lock === false)
        {
            $message = sprintf('鍵のファイルが開けません: %s', $path);
            Logger::error('%s', $message);
            return $this->result(ScheduleEvent::LOCK_FILE_FAILED, $schedule, $at, $message);
        }
        $acquired = $this->flockOrLog($lock, $path, $schedule, $at);
        if ($acquired !== true)
        {
            fclose($lock);
            return ($acquired === false ? null : $acquired);
        }
        return $lock;
    }

    /**
     * 鍵のファイルを開く
     * @param string $directory 鍵のファイルを置くフォルダ
     * @param string $path      鍵のファイルのパス
     * @return resource|false 鍵のファイル(開けなければ false)
     */
    private function openLockFile(string $directory, string $path)
    {
        if (is_dir($directory) === false)
        {
            return false;
        }
        // 鍵のファイルがあれば読み取りで開く(別の OS ユーザーが作ったファイルは書き込みで開けないため。flock は読み取りでも使える)
        return fopen($path, (file_exists($path) === true ? 'r' : 'c'));
    }

    /**
     * 鍵を flock で取る(取れなければログに書く)
     * @param resource               $lock     鍵のファイル
     * @param string                 $path     鍵のファイルのパス
     * @param Schedule               $schedule スケジュールの指定
     * @param DateTimeImmutable|null $at       スケジュールの指定を選んだ時刻
     * @return bool|ScheduleResult true:取れた、false:ほかのプロセスが持っているので取れなかった、結果:flock が失敗した
     */
    private function flockOrLog($lock, string $path, Schedule $schedule, ?DateTimeImmutable $at): bool|ScheduleResult
    {
        if (flock($lock, LOCK_EX | LOCK_NB, $wouldBlock) === true)
        {
            return true;
        }
        // ほかのプロセスが持っているから取れないのか、flock 自体が失敗したのかを見分ける
        if ($wouldBlock === 1)
        {
            Logger::info('鍵が取れなかったので起動しなかった: %s', $this->detail($schedule));
            return false;
        }
        $message = sprintf('鍵が取れませんでした: %s', $path);
        Logger::error('%s', $message);
        return $this->result(ScheduleEvent::LOCK_FAILED, $schedule, $at, $message);
    }

    /**
     * 指定したコマンドを起動して、結果をログに書く
     * @param Schedule               $schedule スケジュールの指定
     * @param string                 $detail   ログに書くコマンドと引数
     * @param DateTimeImmutable|null $at       スケジュールの指定を選んだ時刻
     * @return ScheduleResult 起動の結果
     */
    private function launch(Schedule $schedule, string $detail, ?DateTimeImmutable $at): ScheduleResult
    {
        $started = microtime(true);
        $name = Schedule::commandName($schedule->commandClass());
        $commandLine = $this->commandLine($name, $schedule->arguments());
        $prefix = $this->linePrefix($schedule);
        // 出した文は、終わりの行だけをためる(止まらずに動き続けるコマンドで、メモリが増え続けないように)
        $tail = [];
        $code = $this->runProcess(
            $commandLine,
            function (string $line) use ($prefix, &$tail): void {
                Logger::info('%s', $prefix . $line);
                $tail[] = $line;
                if (count($tail) > self::TAIL_LINES)
                {
                    array_shift($tail);
                }
            },
            function () use ($detail): void {
                Logger::info('起動した: %s', $detail);
            }
        );
        if ($code === null)
        {
            $message = sprintf('起動できなかった: %s', $detail);
            Logger::error('%s', $message);
            return $this->result(ScheduleEvent::START_FAILED, $schedule, $at, $message);
        }
        $seconds = round(microtime(true) - $started, 2);
        $this->logFinished($detail, $code, $seconds);
        return $this->finished($schedule, $at, $code, $seconds, $tail);
    }

    /**
     * 指定したコマンドが終わったことをログに書く(終了コードが 0 でなければエラー)
     * @param string $detail  ログに書くコマンドと引数
     * @param int    $code    終了コード
     * @param float  $seconds かかった時間
     */
    private function logFinished(string $detail, int $code, float $seconds): void
    {
        if ($code === 0)
        {
            Logger::info('終わった: %s 終了コード=%d 時間=%s秒', $detail, $code, $seconds);
            return;
        }
        Logger::error('終わった: %s 終了コード=%d 時間=%s秒', $detail, $code, $seconds);
    }

    /**
     * 指定したコマンドが終わったときの結果を作る
     * @param Schedule               $schedule スケジュールの指定
     * @param DateTimeImmutable|null $at       スケジュールの指定を選んだ時刻
     * @param int                    $code     終了コード
     * @param float                  $seconds  かかった時間
     * @param string[]               $tail     指定したコマンドが出した文の終わりの行
     * @return ScheduleResult 起動の結果
     */
    private function finished(
        Schedule $schedule,
        ?DateTimeImmutable $at,
        int $code,
        float $seconds,
        array $tail
    ): ScheduleResult {
        return new ScheduleResult(
            ScheduleEvent::FINISHED,
            $schedule->commandClass(),
            $schedule->arguments(),
            $code,
            $seconds,
            $tail,
            null,
            $at
        );
    }

    /**
     * 指定したコマンドが終わった以外の、起動の結果を作る
     * @param ScheduleEvent          $event    出来事の種類
     * @param Schedule|null          $schedule スケジュールの指定(分からないときは null)
     * @param DateTimeImmutable|null $at       スケジュールの指定を選んだ時刻
     * @param string                 $error    エラーの文
     * @return ScheduleResult 起動の結果
     */
    private function result(
        ScheduleEvent $event,
        ?Schedule $schedule,
        ?DateTimeImmutable $at,
        string $error
    ): ScheduleResult {
        return new ScheduleResult(
            $event,
            $schedule?->commandClass(),
            ($schedule?->arguments() ?? []),
            error: $error,
            at: $at
        );
    }

    /**
     * 起動の結果を案件に渡す(`onResult()` で起きた例外は、捕まえてログに書く)
     * @param ScheduleResult $result 起動の結果
     */
    private function notify(ScheduleResult $result): void
    {
        try
        {
            $this->onResult($result);
        }
        catch (Throwable $e)
        {
            Logger::error('%s', $e->getMessage());
        }
    }

    /**
     * `--at=` の値を時刻にする
     * @param string|null $value `Y-m-d\TH:i` の形の文字列
     * @return DateTimeImmutable|null 時刻(無い・読めないときは null)
     */
    private function parseAt(?string $value): ?DateTimeImmutable
    {
        if ($value === null)
        {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!' . self::AT_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($at === false or ($errors !== false and $errors['warning_count'] + $errors['error_count'] > 0))
        {
            return null;
        }
        return $at;
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
     * スケジュールのコマンドに付いていた引数のうち、引き継ぐもの(`--command`・`--run`・`--at` は、`=` の形も空白で区切った形も除く)
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
            if (in_array($rest[$i], ['--command', '--run', '--at'], true) === true)
            {
                $i++;
                continue;
            }
            if (preg_match('/^--(command|run|at)=/', $rest[$i]) !== 1)
            {
                $inherited[] = $rest[$i];
            }
        }
        return $inherited;
    }
}
