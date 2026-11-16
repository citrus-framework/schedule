<?php

declare(strict_types=1);

/**
 * @copyright   Copyright 2026, CitrusSchedule. All Rights Reserved.
 * @author      take64 <take64@citrus.tk>
 * @license     http://www.citrus.tk/
 */

namespace Citrus\Schedule;

use Citrus\Configure\Configurable;
use Citrus\Variable\Singleton;

/**
 * スケジュールの設定
 */
class ScheduleSetting extends Configurable
{
    use Singleton;

    /**
     * {@inheritDoc}
     */
    protected function configureKey(): string
    {
        return 'schedule';
    }

    /**
     * {@inheritDoc}
     */
    protected function configureDefaults(): array
    {
        return [
            'lock_directory' => sys_get_temp_dir(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function configureRequires(): array
    {
        return [];
    }
}
