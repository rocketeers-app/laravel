<?php

namespace Rocketeers\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;

class ExampleTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }
}
