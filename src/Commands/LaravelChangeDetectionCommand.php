<?php

namespace Ameax\LaravelChangeDetection\Commands;

use Illuminate\Console\Command;

class LaravelChangeDetectionCommand extends Command
{
    public $signature = 'laravel-change-detection';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
