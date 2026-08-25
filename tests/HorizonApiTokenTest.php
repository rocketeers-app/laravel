<?php

namespace Rocketeers\Laravel\Tests;

use Closure;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Orchestra\Testbench\TestCase;
use Rocketeers\Laravel\RocketeersLoggerServiceProvider;

class HorizonApiTokenTest extends TestCase
{
    protected bool $horizonEnabled = true;

    protected ?Closure $applicationAuthCallback = null;

    protected function setUp(): void
    {
        if (! class_exists(Horizon::class)) {
            $this->markTestSkipped('laravel/horizon is not installed.');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Horizon::$authUsing = null;

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [RocketeersLoggerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('rocketeers.api_token', 'secret-token');
        $app['config']->set('rocketeers.horizon.enabled', $this->horizonEnabled);

        // Runs before the providers boot, so the package wraps this callback
        // just like it would wrap the application's own Horizon provider.
        Horizon::$authUsing = $this->applicationAuthCallback;
    }

    public function test_it_grants_access_with_the_api_token_as_bearer_token()
    {
        $this->assertTrue(Horizon::check($this->requestWithBearerToken('secret-token')));
    }

    public function test_it_denies_access_with_a_wrong_bearer_token()
    {
        $this->assertFalse(Horizon::check($this->requestWithBearerToken('wrong-token')));
    }

    public function test_it_denies_access_without_a_bearer_token()
    {
        $this->assertFalse(Horizon::check($this->request()));
    }

    public function test_it_denies_access_when_no_api_token_is_configured()
    {
        config(['rocketeers.api_token' => null]);

        $this->assertFalse(Horizon::check($this->requestWithBearerToken('secret-token')));
    }

    public function test_it_can_be_disabled()
    {
        $this->horizonEnabled = false;
        $this->refreshApplication();

        $this->assertFalse(Horizon::check($this->requestWithBearerToken('secret-token')));
    }

    public function test_it_keeps_the_authorization_registered_by_the_application()
    {
        $this->applicationAuthCallback = fn ($request) => $request->header('X-Allowed') === 'yes';
        $this->refreshApplication();

        $this->assertTrue(Horizon::check($this->requestWithBearerToken('secret-token')));
        $this->assertTrue(Horizon::check($this->request(['HTTP_X_ALLOWED' => 'yes'])));
        $this->assertFalse(Horizon::check($this->request()));
    }

    protected function requestWithBearerToken(string $token): Request
    {
        return $this->request(['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    protected function request(array $server = []): Request
    {
        return Request::create('/horizon/api/stats', 'GET', [], [], [], $server);
    }
}
