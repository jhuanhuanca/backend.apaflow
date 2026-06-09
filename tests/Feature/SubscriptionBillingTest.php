<?php

namespace Tests\Feature;

use App\Enums\DocumentBillingStatus;
use App\Enums\PaymentFlow;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserPlan;
use App\Models\Payment;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Usuario Test',
            'email' => uniqid('user_', true).'@test.local',
            'password' => Hash::make('password'),
            'plan' => UserPlan::Free,
            'subscription_status' => SubscriptionStatus::Active,
            'registration_checkout_completed_at' => now(),
        ], $overrides));
    }

    private function makeActiveProUser(array $overrides = []): User
    {
        return $this->makeUser(array_merge([
            'plan' => UserPlan::Pro,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_started_at' => now(),
            'subscription_expires_at' => now()->addDays(30),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payments.demo_upgrade_enabled', true);
        Storage::fake('local');
        Queue::fake();
    }

    public function test_free_user_upload_requires_payment_before_processing(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $file = UploadedFile::fake()->create('tesis.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($user)->post('/api/upload-document', [
            'file' => $file,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('code', 'PAYMENT_REQUIRED');

        $document = Document::query()->first();
        $this->assertSame(DocumentBillingStatus::PendingPayment, $document->billing_status);
        Queue::assertNothingPushed();
    }

    public function test_pro_user_upload_processes_without_payment(): void
    {
        $user = $this->makeActiveProUser();

        $file = UploadedFile::fake()->create('tesis.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->actingAs($user)->post('/api/upload-document', [
            'file' => $file,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $response->assertStatus(202);

        $document = Document::query()->first();
        $this->assertSame(DocumentBillingStatus::IncludedInPro, $document->billing_status);
        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_document_payment_dispatches_processing_job(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $document = Document::create([
            'user_id' => $user->id,
            'original_file' => 'documents/1/test.docx',
            'status' => Document::STATUS_PENDING,
            'billing_status' => DocumentBillingStatus::PendingPayment,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $response = $this->actingAs($user)->postJson('/api/billing/complete-document-payment', [
            'document_id' => $document->id,
            'channel' => 'card',
            'card_number' => '4242424242424242',
            'card_exp' => '12/30',
            'card_cvc' => '123',
        ]);

        $response->assertOk();

        $document->refresh();
        $this->assertSame(DocumentBillingStatus::Paid, $document->billing_status);
        $this->assertNotNull($document->payment_id);
        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_free_user_cannot_update_apa_settings(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $this->actingAs($user)
            ->putJson('/api/apa-settings', ['settings' => ['line_spacing' => 1.5]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'UPGRADE_REQUIRED');
    }

    public function test_pro_user_can_update_apa_settings(): void
    {
        $user = $this->makeActiveProUser();

        $this->actingAs($user)
            ->putJson('/api/apa-settings', ['settings' => ['line_spacing' => 1.5]])
            ->assertOk();
    }

    public function test_subscription_service_capabilities_for_free_user(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);
        $service = app(SubscriptionService::class);

        $caps = $service->capabilitiesFor($user);

        $this->assertFalse($caps['unlimited_documents']);
        $this->assertTrue($caps['requires_payment_per_document']);
        $this->assertFalse($caps['can_customize_apa']);
        $this->assertSame(99, $caps['document_price_cents']);
    }

    public function test_pro_initiate_keeps_user_on_free_plan(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $response = $this->actingAs($user)->postJson('/api/billing/pro-subscription/initiate');

        $response->assertCreated()
            ->assertJsonPath('payment.status', PaymentStatus::Pending->value)
            ->assertJsonPath('user.plan', UserPlan::Free->value);

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);

        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertNotNull($payment);
        $this->assertSame(PaymentFlow::ProSubscription->value, $payment->metadata['flow'] ?? null);
    }

    public function test_pro_confirm_after_payment_activates_pro(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $init = $this->actingAs($user)->postJson('/api/billing/pro-subscription/initiate');
        $paymentId = $init->json('payment.id');

        $confirm = $this->actingAs($user)->postJson('/api/billing/pro-subscription/confirm', [
            'payment_id' => $paymentId,
            'channel' => 'card',
            'card_number' => '4242424242424242',
            'card_exp' => '12/30',
            'card_cvc' => '123',
        ]);

        $confirm->assertOk()
            ->assertJsonPath('user.plan', UserPlan::Pro->value);

        $user->refresh();
        $this->assertSame(UserPlan::Pro, $user->plan);
        $this->assertNotNull($user->subscription_expires_at);
        $this->assertTrue($user->subscription_expires_at->isFuture());

        $payment = Payment::query()->find($paymentId);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_deprecated_upgrade_to_pro_endpoint_returns_gone(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $this->actingAs($user)
            ->postJson('/api/billing/upgrade-to-pro', [
                'channel' => 'card',
                'card_number' => '4242424242424242',
                'card_exp' => '12/30',
                'card_cvc' => '123',
            ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'USE_PRO_SUBSCRIPTION_FLOW');

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);
    }

    public function test_document_payment_does_not_upgrade_plan_to_pro(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $document = Document::create([
            'user_id' => $user->id,
            'original_file' => 'documents/1/test.docx',
            'status' => Document::STATUS_PENDING,
            'billing_status' => DocumentBillingStatus::PendingPayment,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $this->actingAs($user)->postJson('/api/billing/complete-document-payment', [
            'document_id' => $document->id,
            'channel' => 'card',
            'card_number' => '4242424242424242',
            'card_exp' => '12/30',
            'card_cvc' => '123',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);
    }

    public function test_expired_pro_user_has_free_capabilities(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subDay(),
        ]);

        $service = app(SubscriptionService::class);

        $this->assertFalse($service->isProActive($user));
        $this->assertSame('free', $service->planSlug($user));

        $caps = $service->capabilitiesFor($user);
        $this->assertFalse($caps['pro_active']);
        $this->assertTrue($caps['requires_payment_per_document']);
        $this->assertTrue($caps['show_ads']);
    }

    public function test_expired_pro_user_downgraded_on_authenticated_request(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('plan', UserPlan::Free->value)
            ->assertJsonPath('capabilities.pro_active', false);

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);
        $this->assertSame(SubscriptionStatus::Expired, $user->subscription_status);
    }

    public function test_lifecycle_command_expires_due_pro_users(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subMinutes(5),
        ]);

        $this->artisan('subscriptions:process-pro-lifecycle')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);
        $this->assertSame(SubscriptionStatus::Expired, $user->subscription_status);
    }

    public function test_pro_renewal_extends_subscription_from_current_expiry(): void
    {
        $expires = now()->addDays(10);
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => $expires,
        ]);

        $init = $this->actingAs($user)->postJson('/api/billing/pro-subscription/initiate');
        $paymentId = $init->json('payment.id');

        $this->actingAs($user)->postJson('/api/billing/pro-subscription/confirm', [
            'payment_id' => $paymentId,
            'channel' => 'card',
            'card_number' => '4242424242424242',
            'card_exp' => '12/30',
            'card_cvc' => '123',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(UserPlan::Pro, $user->plan);
        $this->assertTrue($user->subscription_expires_at->greaterThan($expires));
    }

    public function test_expired_pro_cannot_update_apa_settings(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/apa-settings', ['settings' => ['line_spacing' => 1.5]])
            ->assertStatus(403)
            ->assertJsonPath('code', 'UPGRADE_REQUIRED');
    }

    public function test_expired_pro_user_history_purged_on_authenticated_request(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subHour(),
            'apa_settings' => ['line_spacing' => 1.5],
        ]);

        $document = Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/tesis.docx",
            'processed_file' => "documents/{$user->id}/processed_1_tesis.docx",
            'status' => Document::STATUS_COMPLETED,
            'billing_status' => DocumentBillingStatus::IncludedInPro,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        Storage::disk('local')->put($document->original_file, 'original');
        Storage::disk('local')->put($document->processed_file, 'processed');
        $document->addLog('Procesado bajo plan PRO.');

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('plan', UserPlan::Free->value);

        $user->refresh();
        $this->assertSame(UserPlan::Free, $user->plan);
        $this->assertNull($user->apa_settings);
        $this->assertNotNull($user->subscription_notifications_sent['history_purged_at'] ?? null);

        $this->assertSame(0, Document::query()->where('user_id', $user->id)->count());
        Storage::disk('local')->assertMissing($document->original_file);
        Storage::disk('local')->assertMissing($document->processed_file);
    }

    public function test_lifecycle_command_purges_documents_for_expired_pro(): void
    {
        $user = $this->makeActiveProUser([
            'subscription_expires_at' => now()->subMinutes(5),
        ]);

        Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/tesis.docx",
            'status' => Document::STATUS_PENDING,
            'billing_status' => DocumentBillingStatus::IncludedInPro,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $this->artisan('subscriptions:process-pro-lifecycle')
            ->assertSuccessful();

        $this->assertSame(0, Document::query()->where('user_id', $user->id)->count());
    }

    public function test_free_user_recent_documents_are_not_purged(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/tesis.docx",
            'status' => Document::STATUS_PENDING,
            'billing_status' => DocumentBillingStatus::PendingPayment,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('plan', UserPlan::Free->value);

        $this->assertSame(1, Document::query()->where('user_id', $user->id)->count());
    }

    public function test_free_user_documents_older_than_retention_are_purged(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $recent = Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/recent.docx",
            'status' => Document::STATUS_COMPLETED,
            'billing_status' => DocumentBillingStatus::Paid,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $old = Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/old.docx",
            'processed_file' => "documents/{$user->id}/processed_old.docx",
            'status' => Document::STATUS_COMPLETED,
            'billing_status' => DocumentBillingStatus::Paid,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        Storage::disk('local')->put($old->original_file, 'old-original');
        Storage::disk('local')->put($old->processed_file, 'old-processed');

        Document::query()->whereKey($old->id)->update([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $this->assertSame(1, Document::query()->where('user_id', $user->id)->count());
        $this->assertTrue(Document::query()->whereKey($recent->id)->exists());
        $this->assertFalse(Document::query()->whereKey($old->id)->exists());
        Storage::disk('local')->assertMissing($old->original_file);
        Storage::disk('local')->assertMissing($old->processed_file);
    }

    public function test_lifecycle_command_purges_stale_free_documents(): void
    {
        $user = $this->makeUser(['plan' => UserPlan::Free]);

        $document = Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/old.docx",
            'status' => Document::STATUS_COMPLETED,
            'billing_status' => DocumentBillingStatus::Paid,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        Document::query()->whereKey($document->id)->update([
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);

        $this->artisan('subscriptions:process-pro-lifecycle')
            ->assertSuccessful();

        $this->assertSame(0, Document::query()->where('user_id', $user->id)->count());
    }

    public function test_history_purge_is_idempotent(): void
    {
        $user = $this->makeUser([
            'plan' => UserPlan::Free,
            'subscription_status' => SubscriptionStatus::Expired,
            'subscription_started_at' => now()->subDays(60),
            'subscription_expires_at' => now()->subDay(),
            'subscription_notifications_sent' => [
                'history_purged_at' => now()->subHour()->toIso8601String(),
            ],
        ]);

        Document::create([
            'user_id' => $user->id,
            'original_file' => "documents/{$user->id}/tesis.docx",
            'status' => Document::STATUS_COMPLETED,
            'billing_status' => DocumentBillingStatus::IncludedInPro,
            'university' => 'General',
            'career' => 'Formateo APA 7',
        ]);

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $this->assertSame(1, Document::query()->where('user_id', $user->id)->count());
    }
}
