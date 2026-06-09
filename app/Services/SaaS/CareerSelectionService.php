<?php

namespace App\Services\SaaS;

use App\Enums\PlanAccess;
use App\Models\Career;
use App\Models\DocumentTemplate;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CareerSelectionService
{
    public function __construct(
        private readonly AccessControlService $access,
    ) {}

    /**
     * Catálogo de universidades visibles según plan (y activas).
     */
    public function universitiesFor(?User $user): Builder
    {
        $q = University::query()
            ->where('status', 'active')
            ->orderBy('name');

        return $q->where(function (Builder $w) use ($user) {
            $w->where('plan_access', PlanAccess::Free->value)
                ->orWhere('plan_access', PlanAccess::Both->value);
            if ($this->access->isProSubscriber($user)) {
                $w->orWhere('plan_access', PlanAccess::Pro->value);
            }
        });
    }

    /**
     * Carreras de una universidad filtradas por plan.
     */
    public function careersForUniversity(?User $user, University $university): Builder
    {
        $this->access->denyUnlessCanAccess($user, $university->plan_access ?? PlanAccess::Both);

        // Debe ser Eloquent\Builder: $university->careers() devuelve HasMany y rompe el tipo declarado (TypeError → 500).
        return Career::query()
            ->where('university_id', $university->id)
            ->where('status', 'active')
            ->where(function (Builder $w) use ($user) {
                $w->where('plan_access', PlanAccess::Free->value)
                    ->orWhere('plan_access', PlanAccess::Both->value);
                if ($this->access->isProSubscriber($user)) {
                    $w->orWhere('plan_access', PlanAccess::Pro->value);
                }
            })
            ->orderBy('name');
    }

    /**
     * Resuelve universidad + carrera + plantilla + settings (validación backend).
     *
     * @return array{university: University, career: Career, template: ?DocumentTemplate, template_settings: array|null}
     */
    public function resolveCareer(?User $user, int $careerId): array
    {
        $career = Career::query()
            ->with(['university', 'documentTemplate'])
            ->where('status', 'active')
            ->findOrFail($careerId);

        $university = $career->university;
        abort_unless($university && $university->status === 'active', 404);

        $this->access->denyUnlessCanAccess($user, $university->plan_access ?? PlanAccess::Both);
        $this->access->denyUnlessCanAccess($user, $career->plan_access ?? PlanAccess::Both);

        $template = $career->documentTemplate;
        if ($template) {
            if ($template->status !== 'active') {
                $template = null;
            } else {
                $this->access->denyUnlessCanAccess($user, $template->plan_access ?? PlanAccess::Both);
            }
        }

        return [
            'university' => $university,
            'career' => $career,
            'template' => $template,
            'template_settings' => $template?->settings,
        ];
    }

    public function toSelectionPayload(array $resolved): array
    {
        /** @var University $u */
        $u = $resolved['university'];
        /** @var Career $c */
        $c = $resolved['career'];
        /** @var DocumentTemplate|null $t */
        $t = $resolved['template'];

        return [
            'university' => $this->serializeUniversity($u),
            'career' => $this->serializeCareer($c),
            'template' => $t ? $this->serializeTemplate($t) : null,
            'template_settings' => $resolved['template_settings'],
        ];
    }

    public function serializeUniversity(University $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'country' => $u->country,
            'logo_url' => $this->publicUrl($u->logo_path),
            'institution_type' => $u->institution_type,
            'plan_access' => ($u->plan_access ?? PlanAccess::Both)->value,
        ];
    }

    public function serializeCareer(Career $c): array
    {
        return [
            'id' => $c->id,
            'university_id' => $c->university_id,
            'name' => $c->name,
            'description' => $c->description,
            'category' => $c->category,
            'logo_url' => $this->publicUrl($c->logo_path),
            'plan_access' => ($c->plan_access ?? PlanAccess::Both)->value,
            'document_template_id' => $c->document_template_id,
        ];
    }

    public function serializeTemplate(DocumentTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
            'plan_access' => ($t->plan_access ?? PlanAccess::Both)->value,
            'settings' => $t->settings,
            'version' => $t->version,
        ];
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }
}
