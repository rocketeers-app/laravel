<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\Ingest\AgentEndpoint;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;
use Rocketeers\Laravel\Tests\Support\TemporaryDirectory;

class AgentEndpointTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    public function test_it_defaults_to_a_socket_in_the_real_framework_storage_path()
    {
        $endpoint = $this->app->make(AgentEndpoint::class);

        $this->assertSame('unix://'.realpath(storage_path('framework')).'/rocketeers.sock', $endpoint->uri());
        $this->assertTrue($endpoint->isUnix());
        $this->assertSame(realpath(storage_path('framework')).'/rocketeers.sock', $endpoint->path());
    }

    public function test_the_default_resolves_symlinks_so_every_release_finds_the_same_socket()
    {
        $directory = TemporaryDirectory::create();
        mkdir($directory.'/persistent');
        symlink($directory.'/persistent', $directory.'/current');

        try {
            $endpoint = AgentEndpoint::fromConfig(null, $directory.'/current');

            $this->assertSame('unix://'.realpath($directory.'/persistent').'/rocketeers.sock', $endpoint->uri());
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public function test_the_configured_uri_wins()
    {
        config(['rocketeers.ingest.uri' => 'unix:///tmp/rocketeers-test.sock']);

        $this->assertSame('unix:///tmp/rocketeers-test.sock', $this->app->make(AgentEndpoint::class)->uri());
    }

    public function test_tcp_and_bare_addresses()
    {
        $this->assertSame('tcp://127.0.0.1:2407', (new AgentEndpoint('tcp://127.0.0.1:2407'))->uri());
        $this->assertSame('tcp://agent:2407', (new AgentEndpoint('agent:2407'))->uri());
        $this->assertSame('unix:///run/rocketeers.sock', (new AgentEndpoint('/run/rocketeers.sock'))->uri());
        $this->assertSame('tcp://agent:2407', AgentEndpoint::fromConfig(' agent:2407 ', '/unused')->uri());
        $this->assertSame('tcp://agent:2407', (new AgentEndpoint('agent:2407'))->listenAddress());
    }

    public function test_a_tcp_agent_counts_as_present_until_a_connect_says_otherwise()
    {
        $endpoint = new AgentEndpoint('tcp://127.0.0.1:2407');

        $this->assertFalse($endpoint->isUnix());
        $this->assertNull($endpoint->path());
        $this->assertFalse($endpoint->tooLong());
        $this->assertTrue($endpoint->present());
    }

    public function test_a_unix_agent_is_present_when_its_socket_file_exists()
    {
        $directory = TemporaryDirectory::create();
        $endpoint = new AgentEndpoint('unix://'.$directory.'/agent.sock');

        try {
            $this->assertFalse($endpoint->present());

            touch($directory.'/agent.sock');

            $this->assertTrue($endpoint->present());
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public function test_a_socket_path_over_100_bytes_counts_as_no_agent()
    {
        $directory = TemporaryDirectory::create();
        $path = $directory.'/'.str_repeat('a', 101 - strlen($directory) - 1);

        try {
            touch($path);

            $endpoint = new AgentEndpoint('unix://'.$path);

            $this->assertSame(101, strlen($path));
            $this->assertTrue($endpoint->tooLong());
            $this->assertFalse($endpoint->present());
            $this->assertFalse((new AgentEndpoint('unix://'.substr($path, 0, 100)))->tooLong());
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }
}
