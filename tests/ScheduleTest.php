<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Test;

use Citrus\Schedule;
use Citrus\Schedule\ScheduleException;
use Citrus\Schedule\Weekday;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Test\Fixture\PlainCommand;
use Test\Fixture\SampleCommand;
use Test\Fixture\SampleJob;

/**
 * スケジュールの指定のテスト
 */
class ScheduleTest extends TestCase
{
    /**
     * 指定の時刻
     */
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function schedule(): Schedule
    {
        return Schedule::command(SampleCommand::class);
    }

    #[Test]
    public function 呼ばなかったものはすべてに合う()
    {
        $schedule = $this->schedule();

        $this->assertTrue($schedule->isDue($this->at('2026-01-01 00:00:00')));
        $this->assertTrue($schedule->isDue($this->at('2026-12-31 23:59:59')));
    }

    #[Test]
    public function 分だけを指定する()
    {
        $schedule = $this->schedule()->minute(30);

        $this->assertTrue($schedule->isDue($this->at('2026-03-04 10:30:00')));
        $this->assertTrue($schedule->isDue($this->at('2026-03-04 11:30:59')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-04 10:31:00')));
    }

    #[Test]
    public function 時と分を指定する()
    {
        $schedule = $this->schedule()->hour(0)->minute(30);

        $this->assertTrue($schedule->isDue($this->at('2026-03-04 00:30:10')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-04 01:30:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-04 00:00:00')));
    }

    #[Test]
    public function 時だけを指定すると毎分に合う()
    {
        $schedule = $this->schedule()->hour(0);

        for ($minute = 0; $minute < 60; $minute++)
        {
            $this->assertTrue($schedule->isDue($this->at(sprintf('2026-03-04 00:%02d:00', $minute))));
        }
        $this->assertFalse($schedule->isDue($this->at('2026-03-04 01:00:00')));
    }

    #[Test]
    public function 月を指定する()
    {
        $schedule = $this->schedule()->month(2)->day(1)->hour(0)->minute(0);

        $this->assertTrue($schedule->isDue($this->at('2026-02-01 00:00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-01 00:00:00')));
    }

    #[Test]
    public function rangeの間隔で指定する()
    {
        $schedule = $this->schedule()->minutes(range(0, 59, 10));

        foreach ([0, 10, 20, 30, 40, 50] as $minute)
        {
            $this->assertTrue($schedule->isDue($this->at(sprintf('2026-03-04 05:%02d:00', $minute))));
        }
        foreach ([1, 9, 11, 59] as $minute)
        {
            $this->assertFalse($schedule->isDue($this->at(sprintf('2026-03-04 05:%02d:00', $minute))));
        }
    }

    #[Test]
    public function 曜日を指定する()
    {
        $schedule = $this->schedule()->weekdays([Weekday::MONDAY, Weekday::FRIDAY]);

        $this->assertTrue($schedule->isDue($this->at('2026-03-02 00:00:00'))); // 月曜
        $this->assertTrue($schedule->isDue($this->at('2026-03-06 00:00:00'))); // 金曜
        $this->assertFalse($schedule->isDue($this->at('2026-03-04 00:00:00'))); // 水曜
    }

    #[Test]
    public function 日曜日は0で土曜日は6()
    {
        $this->assertSame(0, Weekday::SUNDAY->value);
        $this->assertSame(1, Weekday::MONDAY->value);
        $this->assertSame(6, Weekday::SATURDAY->value);

        $schedule = $this->schedule()->weekday(Weekday::SUNDAY);
        $this->assertTrue($schedule->isDue($this->at('2026-03-01 00:00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-02 00:00:00')));
    }

    #[Test]
    public function 日と曜日の両方に合う日だけに合う()
    {
        $schedule = $this->schedule()->day(13)->weekday(Weekday::FRIDAY);

        $this->assertTrue($schedule->isDue($this->at('2026-02-13 00:00:00'))); // 13日の金曜
        $this->assertTrue($schedule->isDue($this->at('2026-03-13 00:00:00'))); // 13日の金曜
    }

    #[Test]
    public function 日だけ合う日と曜日だけ合う日には合わない()
    {
        $schedule = $this->schedule()->day(13)->weekday(Weekday::FRIDAY);

        $this->assertFalse($schedule->isDue($this->at('2026-04-13 00:00:00'))); // 月曜の13日
        $this->assertFalse($schedule->isDue($this->at('2026-04-10 00:00:00'))); // 金曜の10日
    }

    #[Test]
    public function 秒は見ない()
    {
        $schedule = $this->schedule()->minute(5);

        $this->assertTrue($schedule->isDue($this->at('2026-03-04 10:05:00')));
        $this->assertTrue($schedule->isDue($this->at('2026-03-04 10:05:59')));
    }

    #[Test]
    public function 範囲の端の値は受け取り外は例外()
    {
        $this->schedule()->minutes([0, 59]);
        $this->schedule()->hours([0, 23]);
        $this->schedule()->days([1, 31]);
        $this->schedule()->months([1, 12]);

        $cases = [
            'minutes' => [[-1], [60]],
            'hours' => [[-1], [24]],
            'days' => [[0], [32]],
            'months' => [[0], [13]],
        ];
        foreach ($cases as $method => $invalids)
        {
            foreach ($invalids as $values)
            {
                try
                {
                    $this->schedule()->$method($values);
                    $this->fail(sprintf('%s(%d) が例外にならない', $method, $values[0]));
                }
                catch (ScheduleException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    #[Test]
    public function 単数のメソッドの範囲の外は例外()
    {
        $cases = [
            ['minute', 60], ['minute', -1],
            ['hour', 24], ['hour', -1],
            ['day', 0], ['day', 32],
            ['month', 0], ['month', 13],
        ];
        foreach ($cases as [$method, $value])
        {
            try
            {
                $this->schedule()->$method($value);
                $this->fail(sprintf('%s(%d) が例外にならない', $method, $value));
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function 配列の中にintでないものがあると例外()
    {
        foreach (['minutes', 'hours', 'days', 'months'] as $method)
        {
            foreach ([['1'], [1.0], [null], [true]] as $values)
            {
                try
                {
                    $this->schedule()->$method($values);
                    $this->fail(sprintf('%s が例外にならない', $method));
                }
                catch (ScheduleException)
                {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    #[Test]
    public function 曜日にWeekday以外を渡すと例外()
    {
        $this->expectException(ScheduleException::class);

        $this->schedule()->weekdays([1, 5]);
    }

    #[Test]
    public function 空の配列は例外()
    {
        foreach (['minutes', 'hours', 'days', 'months', 'weekdays'] as $method)
        {
            try
            {
                $this->schedule()->$method([]);
                $this->fail(sprintf('%s([]) が例外にならない', $method));
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function 同じものに2回呼ぶと例外()
    {
        $calls = [
            fn (Schedule $s) => $s->minute(0)->minutes([30]),
            fn (Schedule $s) => $s->minute(0)->minute(1),
            fn (Schedule $s) => $s->hour(0)->hours([1]),
            fn (Schedule $s) => $s->day(1)->days([2]),
            fn (Schedule $s) => $s->month(1)->months([2]),
            fn (Schedule $s) => $s->weekday(Weekday::MONDAY)->weekdays([Weekday::FRIDAY]),
        ];
        foreach ($calls as $call)
        {
            try
            {
                $call($this->schedule());
                $this->fail('2回目の呼び出しが例外にならない');
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function 例外になった呼び出しは設定を残さない()
    {
        $schedule = $this->schedule();
        try
        {
            $schedule->minute(60);
        }
        catch (ScheduleException)
        {
        }

        $schedule->minute(5);

        $this->assertTrue($schedule->isDue($this->at('2026-03-04 10:05:00')));
    }

    #[Test]
    public function keyは引数の並びによらず同じ()
    {
        $a = Schedule::command(SampleCommand::class, ['account' => 'x', 'level' => 'y']);
        $b = Schedule::command(SampleCommand::class, ['level' => 'y', 'account' => 'x']);

        $this->assertSame($a->key(), $b->key());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $a->key());
    }

    #[Test]
    public function keyは引数が違えば違う()
    {
        $a = Schedule::command(SampleCommand::class, ['account' => 'x']);
        $b = Schedule::command(SampleCommand::class, ['account' => 'y']);
        $c = Schedule::command(SampleCommand::class);

        $this->assertNotSame($a->key(), $b->key());
        $this->assertNotSame($a->key(), $c->key());
    }

    #[Test]
    public function keyは実行の時刻の指定に影響されない()
    {
        $a = Schedule::command(SampleCommand::class)->minute(1);
        $b = Schedule::command(SampleCommand::class)->hour(2);

        $this->assertSame($a->key(), $b->key());
    }

    #[Test]
    public function commandはクラスが無いと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command('Test\Fixture\NothingCommand');
    }

    #[Test]
    public function commandはConsoleを継いでいないと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(PlainCommand::class);
    }

    #[Test]
    public function commandはクラス名がCommandで終わらないと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(SampleJob::class);
    }

    #[Test]
    public function commandは無い引数の名前だと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(SampleCommand::class, ['unknown' => 'x']);
    }

    #[Test]
    public function commandは値を取らない引数にtrueだけを受け取る()
    {
        $schedule = Schedule::command(SampleCommand::class, ['dry-run' => true]);

        $this->assertSame(['dry-run' => true], $schedule->arguments());
    }

    #[Test]
    public function commandは値を取らない引数にtrue以外だと例外()
    {
        foreach (['1', '', 'x', false, 1, 0, null, []] as $value)
        {
            try
            {
                Schedule::command(SampleCommand::class, ['dry-run' => $value]);
                $this->fail('例外になりませんでした: ' . var_export($value, true));
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function commandは値が要る引数にtrueだと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(SampleCommand::class, ['account' => true]);
    }

    #[Test]
    public function commandは値が要る引数にstring以外だと例外()
    {
        foreach ([false, 1, 0, null, []] as $value)
        {
            try
            {
                Schedule::command(SampleCommand::class, ['account' => $value]);
                $this->fail('例外になりませんでした: ' . var_export($value, true));
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function commandは値を省ける引数に文字列とtrueを受け取る()
    {
        $a = Schedule::command(SampleCommand::class, ['level' => '3']);
        $b = Schedule::command(SampleCommand::class, ['level' => true]);

        $this->assertSame(['level' => '3'], $a->arguments());
        $this->assertSame(['level' => true], $b->arguments());
    }

    #[Test]
    public function commandは値を省ける引数にstringでもtrueでもないと例外()
    {
        foreach ([false, 1, 0, null, [], ''] as $value)
        {
            try
            {
                Schedule::command(SampleCommand::class, ['level' => $value]);
                $this->fail('例外になりませんでした: ' . var_export($value, true));
            }
            catch (ScheduleException)
            {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function commandは引数の値がstringでないと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(SampleCommand::class, ['account' => 1]);
    }

    #[Test]
    public function commandはある引数の名前なら作れる()
    {
        $schedule = Schedule::command(SampleCommand::class, ['account' => 'x']);

        $this->assertSame(SampleCommand::class, $schedule->commandClass());
        $this->assertSame(['account' => 'x'], $schedule->arguments());
    }

    #[Test]
    public function commandNameはクラス名の終わりのCommandを外す()
    {
        $this->assertSame('Sample', Schedule::commandName(SampleCommand::class));
        $this->assertSame('EventImport', Schedule::commandName('Social\Gateway\Command\EventImportCommand'));
    }

    #[Test]
    public function commandは引数の値が空の文字列だと例外()
    {
        $this->expectException(ScheduleException::class);

        Schedule::command(SampleCommand::class, ['account' => '']);
    }

    #[Test]
    public function keyはUTF8として正しくない値でも値が違えば違う()
    {
        $a = Schedule::command(SampleCommand::class, ['account' => "\xff"]);
        $b = Schedule::command(SampleCommand::class, ['account' => "\xfe"]);
        $c = Schedule::command(SampleCommand::class, ['account' => "\xff"]);

        $this->assertNotSame($a->key(), $b->key());
        $this->assertSame($a->key(), $c->key());
    }

    #[Test]
    public function keyは日本語の値で違えば違う()
    {
        $a = Schedule::command(SampleCommand::class, ['account' => '東京']);
        $b = Schedule::command(SampleCommand::class, ['account' => '大阪']);
        $c = Schedule::command(SampleCommand::class, ['account' => '東京']);

        $this->assertNotSame($a->key(), $b->key());
        $this->assertSame($a->key(), $c->key());
    }

    #[Test]
    public function 複数の時と日と月を指定する()
    {
        $schedule = $this->schedule()->hours([1, 13])->days([5, 20])->months([2, 11])->minute(0);

        $this->assertTrue($schedule->isDue($this->at('2026-02-05 01:00:00')));
        $this->assertTrue($schedule->isDue($this->at('2026-11-20 13:00:00')));
        $this->assertTrue($schedule->isDue($this->at('2026-02-20 13:00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-02-05 02:00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-02-06 01:00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-03-05 01:00:00')));
    }
}
