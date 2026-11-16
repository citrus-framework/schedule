# Citrus Schedule
php schedule library

## Install

```
composer require citrus-framework/schedule
```

## Usage

### スケジュールの指定を書く

案件の `Gateway\Command` の直下に、`Citrus\Schedule\ScheduleCommand` を継いだ `ScheduleCommand` を作る。`schedules()` に `Schedule::command(...)` を並べる。

```php
namespace App\Gateway\Command;

use Citrus\Schedule;
use Citrus\Schedule\ScheduleCommand as BaseScheduleCommand;

class ScheduleCommand extends BaseScheduleCommand
{
    protected function schedules(): array
    {
        return [
            // 10分ごと
            Schedule::command(SyncCommand::class)->minutes(range(0, 59, 10)),
            // 毎日 3:30、引数を渡す
            Schedule::command(ReportCommand::class, ['mode' => 'daily', 'verbose' => true])->hour(3)->minute(30),
        ];
    }
}
```

指定するコマンドは、案件の `Gateway\Command`(citrus-framework/framework の `command_path` の既定の値)の直下に置く。起動は、クラス名の最後の部分から終わりの `Command` を外して作った `--command=<名前>` で行うため(例：`EventImportCommand` は `--command=EventImport`)。

- 下の階層には置けない。`--command=` の値を、クラス名の最後の部分だけで作るため
- ライブラリは名前空間を確かめないので、下の階層のクラスを指定しても `Schedule::command()` は例外にならない。起動されるのは、直下にある同じ名前のコマンド(無ければ、0 でない終了コードの `FINISHED` で `onResult()` に届く)
- `Citrus\Console` を継いだクラスにする
- クラス名の最後の部分は `Command` で終わらせる

時刻の指定は、複数形が配列、単数形が1つの値を取る。

| 項目 | 複数 | 1つ | 値 |
|---|---|---|---|
| 分 | `minutes()` | `minute()` | 0〜59 |
| 時 | `hours()` | `hour()` | 0〜23 |
| 日 | `days()` | `day()` | 1〜31 |
| 月 | `months()` | `month()` | 1〜12 |
| 曜日 | `weekdays()` | `weekday()` | `Citrus\Schedule\Weekday`(`weekdays()` は `Weekday` の配列、`weekday()` は `Weekday` を1つ) |

- 指定しない項目は、すべての値に合う
- `minute()` と `minutes()` は同じ項目(分)。ほかの項目も同じで、1つの項目を2回指定すると例外になる
- 日と曜日の両方を指定したときは、両方に合う日だけに合う(どちらかに合えば実行する cron とは違う)

引数の値の決まり。受け取り方は、指定したコマンドの `$options` の名前に付いたコロンの数で決まる。

- コロン無し(`dry-run`)は、値を取らない引数。値は `true`
- コロン1つ(`account:`)は、値が要る引数。値は空でない文字列
- コロン2つ(`level::`)は、値を省ける引数。値は空でない文字列か `true`

上の例の `['mode' => 'daily', 'verbose' => true]` は、`ReportCommand` の `$options` が `['mode:', 'verbose']` のときの指定。

例外になるもの。

- `Schedule::command()`
  - クラスが無い
  - `Citrus\Console` を継いでいない
  - クラス名の最後の部分が `Command` で終わらない
  - コマンドに無い引数の名前
  - 合わない値
- 時刻のメソッド
  - 配列を取るメソッド(`minutes()` など)で、配列の中身が int でない値(`weekdays()` は `Weekday` でない値)
  - 配列を取るメソッドで、配列の中身が範囲の外の値
  - 配列を取るメソッドで、空の配列
  - 1つの値を取るメソッドで、範囲の外の値
  - 同じ項目を2回指定

1つの値を取るメソッド(`minute(int)` など、`weekday(Weekday)`)の型は、PHP の型宣言で確かめる。

`schedules()` で例外が起きると、cron から起動した段階なら、その分はどの指定も起動されない。1つの指定を実行する段階なら、その指定だけが起動されない。どちらも `EXCEPTION` として `onResult()` に渡る。

### cron

毎分、`--command=Schedule` で1行だけ登録する。

```
* * * * * php /path/to/src/command.php --command=Schedule --domain=example.com
```

- スケジュールのコマンドに付けたほかの引数(`--domain=` など)は、起動するコマンドにもそのまま付く(`--command`・`--run`・`--at` は除く)
- 同じ指定で起動したものが終わっていないうちは、次の分になっても起動しない(ファイルの鍵)
  - 「同じ指定」は、コマンドのクラスと引数の組が同じもの。時刻の違う2つの指定でも、クラスと引数が同じなら同じ指定になる
  - 同じ分に両方合っても、起動は1回
- 結果は citrus-framework/logger でログに書く

### 設定

案件の `citrus-configure.php` の `'schedule'` に、鍵のファイルを置くフォルダを書く。

```php
'schedule' => [
    'lock_directory' => '/var/tmp/schedule',
],
```

`citrus-configure.php` を `'default'` とドメインごとの key に分けているときは、`'schedule'` をその中(`'default'` かドメインの key)に書く。

```php
'default' => [
    'schedule' => [
        'lock_directory' => '/var/tmp/schedule',
    ],
],
```

- 書かなければ OS の一時フォルダ
- ライブラリは鍵のフォルダを作らない。前もって作り、スケジュールのコマンドを動かす OS ユーザーが書き込めるようにする
- フォルダが無いと、どの指定も `LOCK_FILE_FAILED` になり、起動しない
- 鍵のファイルの名前は、コマンドのクラスと引数の組だけで決まる。cron の行に付けた引数(`--domain=` など)は入らない。同じ案件を複数のドメインで動かして cron を複数行登録するときは、ドメインごとの key に別の `lock_directory` を書く。同じフォルダだと、ほかのドメインで同じ指定が動いているあいだは起動しない

### 起動の結果を受け取る

案件の `ScheduleCommand` で `onResult(ScheduleResult $result)` を上書きする。

- ログに書いて鍵を放したあとに呼ばれる
- 中で起きた例外は捕まえて、ログに書く
- 呼ばれないもの
  - ほかのプロセスが鍵を持っていたので起動しなかったとき(ログには書く)
  - 指定したコマンドを起動したこと(終われば `FINISHED` で呼ばれる)
  - 指定したコマンドが出した1行ごと
- `flock` 自体が失敗したときは、`LOCK_FAILED` で呼ばれる

```php
namespace App\Gateway\Command;

use Citrus\Schedule\ScheduleCommand as BaseScheduleCommand;
use Citrus\Schedule\ScheduleEvent;
use Citrus\Schedule\ScheduleResult;

class ScheduleCommand extends BaseScheduleCommand
{
    protected function schedules(): array
    {
        return [];
    }

    protected function onResult(ScheduleResult $result): void
    {
        if ($result->event === ScheduleEvent::FINISHED and $result->exitCode !== 0)
        {
            // 終了コードが 0 でないときの処理
        }
    }
}
```

`$result->event` の値(`Citrus\Schedule\ScheduleEvent`)。

| ケース | 意味 |
|---|---|
| `FINISHED` | 指定したコマンドが終わった(終了コードは 0 でも 0 でなくても。終了コードは結果に入る) |
| `START_FAILED` | 指定したコマンドのプロセスを作れなかった(`proc_open()` の失敗)。コマンドのクラスが見つからない・PHP が致命的なエラーで止まった、などは `FINISHED`(終了コードが 0 でない)で届く |
| `EXCEPTION` | `schedules()` などで例外が起きた |
| `NOT_FOUND` | スケジュールの指定が見つからなかった |
| `LOCK_FILE_FAILED` | 鍵のファイルが開けなかった |
| `LOCK_FAILED` | 鍵が取れなかった(`flock` の失敗) |

`ScheduleResult` の項目。

| 項目 | 型 | 意味 |
|---|---|---|
| `event` | `ScheduleEvent` | 出来事の種類 |
| `commandClass` | `string\|null` | 指定したコマンドのクラス(分からないときは null) |
| `arguments` | `array<string, string\|true>` | 引数(分からないときは空) |
| `exitCode` | `int\|null` | 終了コード(`FINISHED` のときだけ) |
| `seconds` | `float\|null` | かかった時間(`FINISHED` のときだけ) |
| `lines` | `string[]` | 指定したコマンドが出した文の終わりの 20 行(`FINISHED` のときだけ。ほかは空の配列) |
| `error` | `string\|null` | エラーの文(`FINISHED` のときは null。終了コードが 0 でなくても) |
| `at` | `DateTimeImmutable\|null` | スケジュールの指定を選んだ時刻(分まで。分からないときは null) |
