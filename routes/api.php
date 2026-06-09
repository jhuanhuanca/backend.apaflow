<?php

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ApaSettingsController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingDemoController;
use App\Http\Controllers\Api\CareerController;
use App\Http\Controllers\Api\ClientConfigController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\GuestDocumentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PaddleWebhookController;
use App\Http\Controllers\Api\PaymentOptionsController;
use App\Http\Controllers\Api\UniversityController;
use Illuminate\Support\Facades\Route;

/** Configuración pública y salud (sin auth). */
Route::get('/config', ClientConfigController::class);
Route::get('/health', HealthController::class);
Route::get('/ads/placements', [AdController::class, 'placements']);

Route::get('/user', [AuthController::class, 'me'])->middleware('subscription.current');

Route::get('/payment-options', PaymentOptionsController::class);
Route::post('/webhooks/paddle', PaddleWebhookController::class);

Route::middleware('registration.checkout')->group(function (): void {
    Route::get('/universities', [UniversityController::class, 'index']);
    Route::get('/universities/{university}/careers', [UniversityController::class, 'careers']);
    Route::get('/careers/{career}/resolve', [CareerController::class, 'resolve']);
    Route::post('/career/select', [CareerController::class, 'select']);
    Route::get('/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'show']);
});

Route::get('/guest/trials-remaining', [GuestDocumentController::class, 'trialsRemaining']);
Route::get('/guest/documents', [GuestDocumentController::class, 'index']);
Route::post('/guest/upload-document', [GuestDocumentController::class, 'upload']);
Route::get('/guest/document/{id}/download', [GuestDocumentController::class, 'download'])->whereNumber('id');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'subscription.current'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/billing/checkout-status', [BillingDemoController::class, 'checkoutStatus']);
    Route::post('/billing/complete-registration-checkout', [BillingDemoController::class, 'completeRegistrationCheckout']);
    Route::post('/billing/complete-document-payment', [BillingController::class, 'completeDocumentPayment']);
    Route::get('/billing/payments/{payment}', [BillingController::class, 'paymentStatus']);
    Route::post('/billing/pro-subscription/initiate', [BillingController::class, 'initiateProSubscription']);
    Route::post('/billing/pro-subscription/confirm', [BillingController::class, 'confirmProSubscription']);
    Route::post('/billing/upgrade-to-pro', [BillingController::class, 'upgradeToPro']);

    Route::get('/apa-settings', [ApaSettingsController::class, 'show']);
    Route::put('/apa-settings', [ApaSettingsController::class, 'update']);

    Route::middleware('registration.checkout')->group(function (): void {
        Route::post('/upload-document', [DocumentController::class, 'upload']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/document/{id}', [DocumentController::class, 'show'])->whereNumber('id');
        Route::get('/document/{id}/download', [DocumentController::class, 'download'])->whereNumber('id');
    });
});
