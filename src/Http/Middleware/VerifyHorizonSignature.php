<?php

namespace Rocketeers\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rocketeers\Laravel\Support\HorizonSignature;
use Symfony\Component\HttpFoundation\Response;

class VerifyHorizonSignature
{
    /**
     * Reject anything without a live signature, and let the configured dashboard
     * origin read the response. The dashboard sends no custom request headers, so
     * this stays a CORS "simple request" and never triggers a preflight.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $valid = HorizonSignature::verify(
            $request->query('signature'),
            $request->query('expires'),
            config('rocketeers.horizon.secret'),
        );

        $response = $valid
            ? $next($request)
            : new JsonResponse(['message' => 'Invalid or expired signature.'], 403);

        return $this->withCorsHeaders($response);
    }

    /**
     * Allow the Rocketeers dashboard origin to read the body.
     */
    protected function withCorsHeaders(Response $response): Response
    {
        $origin = config('rocketeers.horizon.origin');

        if (! empty($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
