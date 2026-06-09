<?php

namespace App\Services\SaaS;

use App\Enums\PlanAccess;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Control de visibilidad y acceso según plan del recurso (free/pro/both) y plan del usuario.
 */
class AccessControlService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function isProSubscriber(?User $user): bool
    {
        return $this->subscriptions->hasProAccess($user);
    }

    /**
     * Invitado (sin usuario) se trata como plan Free para contenido PRO.
     */
    public function canAccessPlanAccess(?User $user, PlanAccess|string $resourceAccess): bool
    {
        $access = $resourceAccess instanceof PlanAccess
            ? $resourceAccess
            : PlanAccess::tryFrom((string) $resourceAccess) ?? PlanAccess::Both;

        return match ($access) {
            PlanAccess::Free, PlanAccess::Both => true,
            PlanAccess::Pro => $this->isProSubscriber($user),
        };
    }

    public function denyUnlessCanAccess(?User $user, PlanAccess|string $resourceAccess): void
    {
        if ($this->canAccessPlanAccess($user, $resourceAccess)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Esta carrera o plantilla requiere plan Pro.',
            'code' => 'UPGRADE_REQUIRED',
        ], 403));
    }
}
