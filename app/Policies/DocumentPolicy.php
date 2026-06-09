<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\SaaS\SubscriptionService;

class DocumentPolicy
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function view(User $user, Document $document): bool
    {
        return $document->user_id === $user->id;
    }

    public function download(User $user, Document $document): bool
    {
        return $this->subscriptions->canDownloadDocument($user, $document);
    }

    public function process(User $user, Document $document): bool
    {
        return $this->subscriptions->canProcessDocument($user, $document);
    }
}
