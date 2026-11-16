<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Citrus\Schedule;

/**
 * 起動の結果として案件に渡す出来事の種類
 */
enum ScheduleEvent
{
    /** 指定したコマンドが終わった(終了コードは 0 でも 0 でなくても。終了コードは結果に入る) */
    case FINISHED;

    /** 指定したコマンドを起動できなかった */
    case START_FAILED;

    /** `schedules()` などで例外が起きた */
    case EXCEPTION;

    /** スケジュールの指定が見つからなかった */
    case NOT_FOUND;

    /** 鍵のファイルが開けなかった */
    case LOCK_FILE_FAILED;

    /** 鍵が取れなかった(`flock` の失敗) */
    case LOCK_FAILED;
}
