<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use Illuminate\Http\Client\Factory;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\RecordBuffer;
use Rocketeers\Laravel\Ingest\RecordOutgoingRequests;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Laravel\Tests\Support\FakeAgentConnection;
use Rocketeers\Rocketeers;

/**
 * An app that reports (token set, "testing" listed) and has an agent, unless a test boots it otherwise.
 */
abstract class IngestTestCase extends TestCase
{
    /** @var array<string, mixed> */
    protected array $config = [];

    protected FakeAgentConnection $agent;

    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('rocketeers.api_token', 'test-token');
        $app['config']->set('rocketeers.environments', ['testing']);

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->useAgent($this->agent = new FakeAgentConnection);
    }

    protected function tearDown(): void
    {
        AgentAwareRocketeers::bypass(false);
        AgentConnection::forgetAbsence();
        Rocketeers::setBaseUrl('https://api.rocketeersapp.com/v1');

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function bootWith(array $config): void
    {
        $this->config = $config;

        $this->refreshApplication();

        $this->useAgent($this->agent = new FakeAgentConnection);
    }

    protected function useAgent(AgentConnection $agent): void
    {
        $this->app->instance(AgentConnection::class, $agent);

        foreach ([RecordBuffer::class, RecordOutgoingRequests::class, 'rocketeers.client'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function http(): Factory
    {
        return $this->app->make(Factory::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buffered(): array
    {
        return array_map(function (string $line) {
            return json_decode($line, true);
        }, $this->app->make(RecordBuffer::class)->pull());
    }
}
