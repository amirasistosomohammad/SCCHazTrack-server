<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HazardController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\AdminBackupController;
use App\Http\Controllers\SystemManagerController;

Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'service' => 'scc-haztrack-api',
        'time' => now()->toISOString(),
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});

Route::get('/categories', [LookupController::class, 'categories']);
Route::get('/locations', [LookupController::class, 'locations']);
Route::get('/statuses', [LookupController::class, 'statuses']);

Route::middleware('auth:sanctum')->group(function () {
    // Profile management (match ATIn-client contract)
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);

    Route::get('/hazards/my', [HazardController::class, 'my']);
    Route::post('/hazards', [HazardController::class, 'store']);
    Route::get('/hazards', [HazardController::class, 'index']);
    Route::get('/hazards/{id}', [HazardController::class, 'show']);
    Route::get('/hazards/{id}/attachments/{attachmentId}', [HazardController::class, 'downloadAttachment']);
    Route::patch('/hazards/{id}', [HazardController::class, 'update']);
    Route::delete('/hazards/{id}', [HazardController::class, 'destroy']);
    Route::post('/hazards/{id}/status', [HazardController::class, 'changeStatus']);
    Route::post('/hazards/{id}/attachments', [HazardController::class, 'addAttachments']);
    Route::delete('/hazards/{id}/attachments/{attachmentId}', [HazardController::class, 'removeAttachment']);

    // Notification center
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationsController::class, 'markRead']);

    Route::get('/metrics/summary', [MetricsController::class, 'summary']);
    Route::get('/metrics/dashboard', [MetricsController::class, 'dashboard']);

    Route::get('/admin/backup/schedule', [AdminBackupController::class, 'schedule']);
    Route::put('/admin/backup/schedule', [AdminBackupController::class, 'updateSchedule']);
    Route::get('/admin/backup/list', [AdminBackupController::class, 'listBackups']);
    Route::get('/admin/backup/download/latest', [AdminBackupController::class, 'downloadLatest']);
    Route::get('/admin/backup/download/file/{filename}', [AdminBackupController::class, 'downloadFile'])
        ->where('filename', '[a-zA-Z0-9._-]+\.sql');
    Route::get('/admin/backup', [AdminBackupController::class, 'download']);

    Route::prefix('manager')->group(function () {
        Route::get('/users', [SystemManagerController::class, 'listUsers']);
        Route::post('/users', [SystemManagerController::class, 'createUser']);
        Route::patch('/users/{id}', [SystemManagerController::class, 'updateUser']);
        Route::post('/users/{id}/deactivate', [SystemManagerController::class, 'deactivateUser']);
        Route::delete('/users/{id}', [SystemManagerController::class, 'deleteUser']);

        Route::get('/categories', [SystemManagerController::class, 'listCategories']);
        Route::post('/categories', [SystemManagerController::class, 'createCategory']);
        Route::patch('/categories/{id}', [SystemManagerController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [SystemManagerController::class, 'deleteCategory']);

        Route::get('/locations', [SystemManagerController::class, 'listLocations']);
        Route::post('/locations', [SystemManagerController::class, 'createLocation']);
        Route::patch('/locations/{id}', [SystemManagerController::class, 'updateLocation']);
        Route::delete('/locations/{id}', [SystemManagerController::class, 'deleteLocation']);
    });
});

