<?php

namespace Rocketeers\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class RocketeersHorizonServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if (! class_exists(Horizon::class)) {
            return;
        }

        // Wait until every provider has booted, so the callback registered by
        // the application's own Horizon provider is the one we wrap.
        $this->app->booted(function () {
            $this->authorizeUsingApiToken();
        });
    }

    /**
     * Let Rocketeers authenticate against Horizon with the API token, while
     * leaving the application's own Horizon authorization intact.
     */
    protected function authorizeUsingApiToken(): void
    {
        if (! config('rocketeers.horizon.enabled', true)) {
            return;
        }

        $previous = Horizon::$authUsing;

        Horizon::auth(function ($request) use ($previous) {
            if ($this->hasValidApiToken($request)) {
                return true;
            }

            return $previous instanceof Closure
                ? (bool) $previous($request)
                : app()->environment('local');
        });
    }

    /**
     * Determine whether the request carries the Rocketeers API token as a
     * bearer token.
     */
    protected function hasValidApiToken(Request $request): bool
    {
        $token = config('rocketeers.api_token');
        $bearerToken = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return false;
        }

        if (! is_string($bearerToken) || $bearerToken === '') {
            return false;
        }

        return hash_equals($token, $bearerToken);
    }
}
