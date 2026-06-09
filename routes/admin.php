<?php

use App\Modules\Ads\Http\Controllers\AdminAdPlacementController;
use App\Modules\AIIntegration\Http\Controllers\AdminAiIntegrationController;
use App\Modules\Analytics\Http\Controllers\AdminAnalyticsController;
use App\Modules\AuthAdmin\Http\Controllers\AdminAuthController;
use App\Modules\Careers\Http\Controllers\AdminCareerController;
use App\Modules\ContentCategories\Http\Controllers\AdminContentCategoryController;
use App\Modules\DocumentTemplates\Http\Controllers\AdminDocumentTemplateController;
use App\Modules\Payments\Http\Controllers\AdminPaymentController;
use App\Modules\Universities\Http\Controllers\AdminUniversityController;
use App\Modules\Users\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API administración (prefijo aplicado en bootstrap: /api)
|--------------------------------------------------------------------------
| Auth: Sanctum guard `admin` + Bearer token (separado del SPA usuario).
*/

Route::prefix('admin')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:admin', 'admin.active'])->group(function (): void {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);

        Route::get('analytics/kpi', [AdminAnalyticsController::class, 'kpi']);
        Route::get('ai/capabilities', [AdminAiIntegrationController::class, 'capabilities']);

        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show']);
        Route::patch('users/{user}', [AdminUserController::class, 'update']);

        Route::apiResource('universities', AdminUniversityController::class);
        Route::apiResource('careers', AdminCareerController::class);
        Route::apiResource('content-categories', AdminContentCategoryController::class)->parameters([
            'content-categories' => 'content_category',
        ]);
        Route::apiResource('document-templates', AdminDocumentTemplateController::class);

        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::post('payments', [AdminPaymentController::class, 'store']);

        Route::apiResource('ad-placements', AdminAdPlacementController::class);
    });
});
