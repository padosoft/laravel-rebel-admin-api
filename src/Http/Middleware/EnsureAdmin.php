<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin-api routes: the request must be authenticated on the configured
 * admin guard, and (optionally) pass a configured authorization ability. Failures are
 * returned as normalized JSON (401/403), never as an HTML redirect.
 */
final class EnsureAdmin
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Gate $gate,
        private readonly Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->stringConfig('rebel-admin-api.guard');
        $user = $this->auth->guard($guard === '' ? null : $guard)->user();

        if ($user === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $ability = $this->stringConfig('rebel-admin-api.ability');

        if ($ability !== '' && $this->gate->forUser($user)->denies($ability)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return $next($request);
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }
}
