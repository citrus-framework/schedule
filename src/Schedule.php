<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Citrus;

use Citrus\Console\OptionKind;
use Citrus\Schedule\ScheduleException;
use Citrus\Schedule\Weekday;
use DateTimeInterface;

/**
 * スケジュールの指定(どのコマンドを、どの引数で、いつ実行するか)
 */
class Schedule
{
    /** @var array<string, int[]|null> 実行する値(null はすべての値に合う) */
    private array $values = [
        'minute' => null,
        'hour' => null,
        'day' => null,
        'month' => null,
        'weekday' => null,
    ];

    /**
     * constructor
     * @param string $class     指定したコマンドのクラス名
     * @param array<string, string|true> $arguments 指定したコマンドに渡す引数(名前 => 値)
     */
    private function __construct(private readonly string $class, private readonly array $arguments)
    {
    }

    /**
     * スケジュールの指定を作る
     * @param string $class     指定したコマンドのクラス名
     * @param array<string, string|true> $arguments 指定したコマンドに渡す引数(名前 => 値)
     * @return self
     * @throws ScheduleException
     */
    public static function command(string $class, array $arguments = []): self
    {
        self::validateClass($class);
        $kinds = (new $class())->optionKinds();
        foreach ($arguments as $name => $value)
        {
            self::validateArgument($class, $kinds, $name, $value);
        }

        return new self($class, $arguments);
    }

    /**
     * コマンドのクラス名から `--command=` の値を作る(クラス名の最後の部分から、終わりの Command を外したもの)
     * @param string $class コマンドのクラス名
     * @return string `--command=` の値
     */
    public static function commandName(string $class): string
    {
        return preg_replace('/Command$/', '', self::lastName($class));
    }

    /**
     * 指定したコマンドのクラス名
     * @return string クラス名
     */
    public function commandClass(): string
    {
        return $this->class;
    }

    /**
     * 指定したコマンドに渡す引数
     * @return array<string, string|true> 引数(名前 => 値)
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * 分(複数)
     * @param int[] $values 0〜59
     * @return self
     * @throws ScheduleException
     */
    public function minutes(array $values): self
    {
        return $this->setInts('minute', $values, 0, 59);
    }

    /**
     * 分
     * @param int $value 0〜59
     * @return self
     * @throws ScheduleException
     */
    public function minute(int $value): self
    {
        return $this->setInts('minute', [$value], 0, 59);
    }

    /**
     * 時(複数)
     * @param int[] $values 0〜23
     * @return self
     * @throws ScheduleException
     */
    public function hours(array $values): self
    {
        return $this->setInts('hour', $values, 0, 23);
    }

    /**
     * 時
     * @param int $value 0〜23
     * @return self
     * @throws ScheduleException
     */
    public function hour(int $value): self
    {
        return $this->setInts('hour', [$value], 0, 23);
    }

    /**
     * 日(複数)
     * @param int[] $values 1〜31
     * @return self
     * @throws ScheduleException
     */
    public function days(array $values): self
    {
        return $this->setInts('day', $values, 1, 31);
    }

    /**
     * 日
     * @param int $value 1〜31
     * @return self
     * @throws ScheduleException
     */
    public function day(int $value): self
    {
        return $this->setInts('day', [$value], 1, 31);
    }

    /**
     * 月(複数)
     * @param int[] $values 1〜12
     * @return self
     * @throws ScheduleException
     */
    public function months(array $values): self
    {
        return $this->setInts('month', $values, 1, 12);
    }

    /**
     * 月
     * @param int $value 1〜12
     * @return self
     * @throws ScheduleException
     */
    public function month(int $value): self
    {
        return $this->setInts('month', [$value], 1, 12);
    }

    /**
     * 曜日(複数)
     * @param Weekday[] $values 曜日
     * @return self
     * @throws ScheduleException
     */
    public function weekdays(array $values): self
    {
        $ints = [];
        foreach ($values as $value)
        {
            if (($value instanceof Weekday) === false)
            {
                throw new ScheduleException('weekday の値が Weekday ではありません');
            }
            $ints[] = $value->value;
        }
        return $this->setInts('weekday', $ints, 0, 6);
    }

    /**
     * 曜日
     * @param Weekday $value 曜日
     * @return self
     * @throws ScheduleException
     */
    public function weekday(Weekday $value): self
    {
        return $this->setInts('weekday', [$value->value], 0, 6);
    }

    /**
     * 今の分に合うか(秒は見ない。日と曜日の両方に合う日だけに合う)
     * @param DateTimeInterface $time 今の時刻
     * @return bool true:合う
     */
    public function isDue(DateTimeInterface $time): bool
    {
        $current = [
            'minute' => (int)$time->format('i'),
            'hour' => (int)$time->format('G'),
            'day' => (int)$time->format('j'),
            'month' => (int)$time->format('n'),
            'weekday' => (int)$time->format('w'),
        ];
        foreach ($this->values as $field => $values)
        {
            if ($values !== null and in_array($current[$field], $values, true) === false)
            {
                return false;
            }
        }
        return true;
    }

    /**
     * スケジュールの指定の見分け(`--run=` の値と、鍵のファイルの名前に使う)
     * @return string sha1 の値
     */
    public function key(): string
    {
        $arguments = $this->arguments;
        ksort($arguments);
        return sha1($this->class . "\0" . serialize($arguments));
    }

    /**
     * 実行する値を設定する
     * @param string $field  分・時・日・月・曜日のどれか
     * @param array  $values 値
     * @param int    $min    最小値
     * @param int    $max    最大値
     * @return self
     * @throws ScheduleException
     */
    private function setInts(string $field, array $values, int $min, int $max): self
    {
        if ($this->values[$field] !== null)
        {
            throw new ScheduleException(sprintf('%s は設定済みです', $field));
        }
        if (count($values) === 0)
        {
            throw new ScheduleException(sprintf('%s の値が空の配列です', $field));
        }
        foreach ($values as $value)
        {
            self::validateRange($field, $value, $min, $max);
        }
        $this->values[$field] = array_values($values);
        return $this;
    }

    /**
     * 値の型と範囲を確かめる
     * @param string $field 分・時・日・月・曜日のどれか
     * @param mixed  $value 値(任意の型)
     * @param int    $min   最小値
     * @param int    $max   最大値
     * @throws ScheduleException
     */
    private static function validateRange(string $field, mixed $value, int $min, int $max): void
    {
        if (is_int($value) === false)
        {
            throw new ScheduleException(sprintf('%s の値が int ではありません', $field));
        }
        if ($value < $min or $max < $value)
        {
            throw new ScheduleException(sprintf('%s の値が範囲(%d〜%d)の外です: %d', $field, $min, $max, $value));
        }
    }

    /**
     * コマンドのクラスを確かめる
     * @param string $class コマンドのクラス名
     * @throws ScheduleException
     */
    private static function validateClass(string $class): void
    {
        if (class_exists($class) === false)
        {
            throw new ScheduleException(sprintf('コマンドのクラスがありません: %s', $class));
        }
        if (is_subclass_of($class, Console::class) === false)
        {
            throw new ScheduleException(sprintf('コマンドのクラスが %s を継いでいません: %s', Console::class, $class));
        }
        if (str_ends_with(self::lastName($class), 'Command') === false)
        {
            throw new ScheduleException(sprintf('コマンドのクラス名の最後の部分が Command で終わっていません: %s', $class));
        }
    }

    /**
     * 引数の名前と値を、受け取り方ごとに確かめる
     * @param string                    $class コマンドのクラス名
     * @param array<string, OptionKind> $kinds コマンドが受け取る引数ごとの受け取り方
     * @param int|string                $name  引数の名前
     * @param mixed                     $value 引数の値(任意の型)
     * @throws ScheduleException
     */
    private static function validateArgument(string $class, array $kinds, int|string $name, mixed $value): void
    {
        if (array_key_exists($name, $kinds) === false)
        {
            throw new ScheduleException(sprintf('コマンド %s が受け取らない引数の名前です: %s', $class, $name));
        }
        $kind = $kinds[$name];
        if ($kind === OptionKind::FLAG)
        {
            self::validateFlagValue($name, $value);
            return;
        }
        if ($kind === OptionKind::OPTIONAL and $value === true)
        {
            return;
        }
        self::validateStringValue($name, $value, $kind === OptionKind::OPTIONAL);
    }

    /**
     * 値を取らない引数の値を確かめる
     * @param int|string $name  引数の名前
     * @param mixed      $value 引数の値(任意の型)
     * @throws ScheduleException
     */
    private static function validateFlagValue(int|string $name, mixed $value): void
    {
        if ($value !== true)
        {
            throw new ScheduleException(sprintf('引数 %s の値が true ではありません', $name));
        }
    }

    /**
     * 文字列の引数の値を確かめる
     * @param int|string $name      引数の名前
     * @param mixed      $value     引数の値(任意の型)
     * @param bool       $allowTrue true:true も受け取る引数(値を省ける)
     * @throws ScheduleException
     */
    private static function validateStringValue(int|string $name, mixed $value, bool $allowTrue): void
    {
        if (is_string($value) === false)
        {
            $expected = ($allowTrue === true ? 'string でも true でも' : 'string では');
            throw new ScheduleException(sprintf('引数 %s の値が %sありません', $name, $expected));
        }
        if ($value === '')
        {
            throw new ScheduleException(sprintf('引数 %s の値が空の文字列です', $name));
        }
    }

    /**
     * クラス名の最後の部分
     * @param string $class クラス名
     * @return string 最後の部分
     */
    private static function lastName(string $class): string
    {
        $position = strrpos($class, '\\');
        return ($position === false ? $class : substr($class, $position + 1));
    }
}
