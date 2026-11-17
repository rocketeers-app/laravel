<?php

namespace Rocketeers\Laravel\Tests\Ingest;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Mockery;
use Rocketeers\Laravel\Ingest\AgentAwareRocketeers;
use Rocketeers\Laravel\Ingest\AgentConnection;
use Rocketeers\Laravel\Ingest\ExecutionContext;
use Rocketeers\Laravel\Ingest\Frame;
use Rocketeers\Laravel\Ingest\RecordBuffer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class IngestLifecycleTest extends IngestTestCase
{
    public function test_records_reach_the_agent_once_the_app_terminates()
    {
        $this->http()->fake();
        $this->http()->get('https://api.example.com/a');
        $this->http()->get('https://api.example.com/b');

        $this->assertSame([], $this->agent->frames);

        $this->app->terminate();

        $this->assertCount(1, $this->agent->frames);
        $this->assertSame(['/a', '/b'], array_map(function ($line) {
            return json_decode($line, true)['path'];
        }, $this->agent->frames[0]));
    }

    public function test_a_queue_worker_flushes_between_jobs_and_keeps_running()
    {
        $this->http()->fake();
        $this->http()->get('https://api.example.com/a');

        $result = $this->app['events']->until(new Looping('redis', 'default'));

        $this->assertNull($result);
        $this->assertCount(1, $this->agent->frames);
    }

    public function test_a_stopping_worker_flushes()
    {
        $this->http()->fake();
        $this->http()->get('https://api.example.com/a');

        $this->app['events']->dispatch(new WorkerStopping(0));

        $this->assertCount(1, $this->agent->frames);
    }

    public function test_a_full_buffer_is_flushed_at_500_records()
    {
        $buffer = $this->app->make(RecordBuffer::class);

        for ($i = 1; $i < RecordBuffer::MAX_LINES; $i++) {
            $buffer->push('{"t":"outgoing_request","v":1}');
        }

        $this->assertSame([], $this->agent->frames);

        $buffer->push('{"t":"outgoing_request","v":1}');

        $this->assertCount(1, $this->agent->frames);
        $this->assertCount(500, $this->agent->frames[0]);
        $this->assertSame(0, $buffer->count());
    }

    public function test_records_the_agent_refuses_are_dropped()
    {
        $this->agent->reply = Frame::ERR_PAUSED;

        $buffer = $this->app->make(RecordBuffer::class);
        $buffer->push('{"t":"outgoing_request","v":1}');

        $this->assertSame(Frame::ERR_PAUSED, $buffer->flush());
        $this->assertSame(0, $buffer->count());
        $this->assertNull($buffer->flush());
        $this->assertCount(1, $this->agent->frames);
    }

    public function test_a_job_gets_a_trace_of_its_own_named_after_its_class()
    {
        $context = $this->app->make(ExecutionContext::class);
        $trace = $context->traceId();

        $this->app['events']->dispatch(new JobProcessing('redis', $this->job('App\Jobs\SyncOrders')));

        $this->assertSame(ExecutionContext::JOB, $context->type());
        $this->assertSame('App\Jobs\SyncOrders', $context->name());
        $this->assertNotSame($trace, $context->traceId());
    }

    public function test_a_job_run_by_the_sync_driver_stays_part_of_what_dispatched_it()
    {
        $context = $this->app->make(ExecutionContext::class);
        $before = $context->snapshot();

        $this->app['events']->dispatch(new JobProcessing('sync', $this->job('App\Jobs\SyncOrders')));

        $this->assertSame($before, $context->snapshot());
    }

    public function test_a_command_gets_a_trace_of_its_own_and_hands_the_previous_one_back()
    {
        $context = $this->app->make(ExecutionContext::class);
        $before = $context->snapshot();

        $this->app['events']->dispatch(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

        $this->assertSame(ExecutionContext::COMMAND, $context->type());
        $this->assertSame('orders:sync', $context->name());
        $this->assertNotSame($before['trace_id'], $context->traceId());

        $this->app['events']->dispatch(new CommandFinished('orders:sync', new ArrayInput([]), new NullOutput, 0));

        $this->assertSame($before, $context->snapshot());
    }

    public function test_the_agent_command_never_delivers_to_itself_and_records_nothing()
    {
        $this->app['events']->dispatch(new CommandStarting('rocketeers:agent', new ArrayInput([]), new NullOutput));

        $this->assertTrue(AgentAwareRocketeers::bypassed());
        $this->assertFalse($this->app->make(ExecutionContext::class)->sampled());

        $this->http()->fake();
        $this->http()->get('https://api.example.com/a');

        $this->assertSame(0, $this->app->make(RecordBuffer::class)->count());
    }

    public function test_an_octane_request_starts_a_new_trace()
    {
        $context = $this->app->make(ExecutionContext::class);
        $context->start(ExecutionContext::JOB, 'App\Jobs\SyncOrders');
        $trace = $context->traceId();

        $this->app['events']->dispatch('Laravel\Octane\Events\RequestReceived', [new \stdClass]);

        $this->assertSame(ExecutionContext::REQUEST, $context->type());
        $this->assertNotSame($trace, $context->traceId());
    }

    public function test_sampling_is_decided_once_per_execution()
    {
        $this->bootWith(['rocketeers.ingest.outgoing_requests.sample_rate' => 0.5]);

        $context = $this->app->make(ExecutionContext::class);
        $decisions = [];

        for ($i = 0; $i < 200; $i++) {
            $context->start(ExecutionContext::JOB, 'App\Jobs\SyncOrders');
            $sampled = $context->sampled();

            $this->assertSame($sampled, $context->sampled());

            $decisions[] = $sampled;
        }

        $this->assertContains(true, $decisions);
        $this->assertContains(false, $decisions);
    }

    public function test_octane_request_clones_share_the_buffer_and_context_of_the_booted_app()
    {
        $this->refreshApplication();

        $this->assertTrue($this->app->resolved(RecordBuffer::class));
        $this->assertTrue($this->app->resolved(ExecutionContext::class));

        $sandbox = clone $this->app;

        $this->assertSame($this->app->make(RecordBuffer::class), $sandbox->make(RecordBuffer::class));
        $this->assertSame($this->app->make(ExecutionContext::class), $sandbox->make(ExecutionContext::class));
    }

    public function test_nothing_is_built_without_a_token()
    {
        $this->config = ['rocketeers.api_token' => ''];

        $this->refreshApplication();

        $this->assertFalse($this->app->resolved(RecordBuffer::class));
        $this->assertFalse($this->app->resolved(ExecutionContext::class));
        $this->assertFalse($this->app->resolved(AgentConnection::class));
    }

    public function test_nothing_is_hooked_in_without_a_token()
    {
        $listeners = count($this->app['events']->getListeners(Looping::class));

        $this->bootWith(['rocketeers.api_token' => '']);

        $this->assertCount($listeners - 1, $this->app['events']->getListeners(Looping::class));
        $this->assertFalse($this->app['events']->hasListeners('Laravel\Octane\Events\RequestReceived'));
        $this->assertSame([], $this->http()->getGlobalMiddleware());
    }

    protected function job(string $name): Job
    {
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('resolveName')->andReturn($name);

        return $job;
    }
}
