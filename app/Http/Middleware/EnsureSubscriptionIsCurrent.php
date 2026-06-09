<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SaaS\SubscriptionExpirationService;
use App\Support\ApiUserResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sincroniza vencimiento PRO en cada petición autenticada (fallback si el scheduler falla).
 */
class EnsureSubscriptionIsCurrent
{
    public function __construct(
        private readonly SubscriptionExpirationService $expiration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = ApiUserResolver::resolve($request);

        if ($user instanceof User) {
            $this->expiration->syncAuthenticatedUser($user);
        }

        return $next($request);
    }
}
