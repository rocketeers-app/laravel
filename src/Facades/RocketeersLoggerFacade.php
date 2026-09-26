<?php

namespace Rocketeers\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

class RocketeersLoggerFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'rocketeers.logger';
    }
}
