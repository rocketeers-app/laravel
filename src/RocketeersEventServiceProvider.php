<?php

namespace Rocketeers\Laravel;

use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Rocketeers\Laravel\Listeners\LogJobException;

class RocketeersEventServiceProvider extends EventServiceProvider
{
    protected $listen = [
        JobExceptionOccurred::class => [
            LogJobException::class,
        ],
    ];
}
