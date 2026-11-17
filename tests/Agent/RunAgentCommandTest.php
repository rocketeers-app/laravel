<?php

namespace Rocketeers\Laravel\Tests\Agent;

use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\Console\Commands\RunAgentCommand;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;

/** Gives up waiting at the first sleep, so the idle and lock loops can be tested. */
class RunAgentOnceCommand extends RunAgentCommand
{
    protected function sleepFor(int $seconds): void
    {
        $this->stopRequested = true;
    }
}

class RunAgentCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    protected array $config = [];

    protected string $directory;

    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('rocketeers.api_token', 'test-token');
        $app['config']->set('rocketeers.environments', ['testing']);
        $app['config']->set('rocketeers.ingest.uri', 'unix://'.$this->directory.'/agent.sock');

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        AgentAwareRocketeers::bypass(false);

        @unlink(storage_path('framework/'.RunAgentCommand::LOCK_FILE));
        TemporaryDirectory::remove($this->directory);

        parent::tearDown();
    }

    public function test_it_listens_until_stopped_and_takes_its_socket_along()
    {
        $this->artisan('rocketeers:agent', ['--max-memory' => 0])
            ->expectsOutputToContain('listening on unix://'.$this->directory.'/agent.sock')
            ->expectsOutputToContain('Memory use passed 1 MB')
            ->expectsOutputToContain('Stopped.')
            ->assertExitCode(0);

        $this->assertTrue(AgentAwareRocketeers::bypassed());
        $this->assertFileDoesNotExist($this->directory.'/agent.sock');
    }

    public function test_it_idles_instead_of_exiting_without_a_token()
    {
        $this->bootWith(['rocketeers.api_token' => null]);

        $this->artisan('rocketeers:agent')
            ->expectsOutputToContain('Rocketeers agent idle: ROCKETEERS_API_TOKEN is not set.')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->directory.'/agent.sock');
    }

    public function test_it_idles_when_ingest_is_disabled()
    {
        $this->bootWith(['rocketeers.ingest.enabled' => false]);

        $this->artisan('rocketeers:agent')
            ->expectsOutputToContain('Rocketeers agent idle: ingest is disabled')
            ->assertExitCode(0);
    }

    public function test_it_idles_in_an_environment_that_does_not_report()
    {
        $this->bootWith(['rocketeers.environments' => ['production']]);

        $this->artisan('rocketeers:agent')
            ->expectsOutputToContain('Rocketeers agent idle: the "testing" environment is not listed in rocketeers.environments.')
            ->assertExitCode(0);
    }

    public function test_it_refuses_a_socket_path_longer_than_100_bytes()
    {
        $this->bootWith(['rocketeers.ingest.uri' => 'unix:///tmp/'.str_repeat('a', 100).'.sock']);

        $this->artisan('rocketeers:agent')
            ->expectsOutputToContain('Set ROCKETEERS_INGEST_URI to a shorter unix:// path')
            ->assertExitCode(1);
    }

    public function test_a_second_agent_waits_for_the_first_to_let_go_of_the_lock()
    {
        $this->bootWith([]);

        $lock = fopen(storage_path('framework/'.RunAgentCommand::LOCK_FILE), 'c');
        flock($lock, LOCK_EX);

        try {
            $this->artisan('rocketeers:agent')
                ->expectsOutputToContain('Another Rocketeers agent holds')
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($this->directory.'/agent.sock');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function bootWith(array $config): void
    {
        $this->config = $config;

        $this->refreshApplication();

        $this->app->bind(RunAgentCommand::class, RunAgentOnceCommand::class);
    }
}
