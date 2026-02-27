<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\ScheduledCallController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::middleware('role:super_admin,owner')->prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/users/{user}/assign', [RoleController::class, 'assignRole']);
        Route::post('/users/{user}/revoke', [RoleController::class, 'revokeRole']);
    });

    Route::prefix('organizations')->group(function () {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::get('/{organization}', [OrganizationController::class, 'show']);

        Route::middleware('role:super_admin')->group(function () {
            Route::post('/', [OrganizationController::class, 'store']);
            Route::delete('/{organization}', [OrganizationController::class, 'destroy']);
        });

        Route::middleware('role:super_admin,owner')->group(function () {
            Route::put('/{organization}', [OrganizationController::class, 'update']);
        });
    });

    // Team routes
    Route::prefix('teams')->group(function () {
        Route::get('/', [TeamController::class, 'index']);
        Route::post('/', [TeamController::class, 'store']);
        Route::get('/{team}', [TeamController::class, 'show']);
        Route::put('/{team}', [TeamController::class, 'update']);
        Route::delete('/{team}', [TeamController::class, 'destroy']);

        // Team members
        Route::get('/{team}/members', [TeamMemberController::class, 'index']);
        Route::put('/{team}/members/{user}', [TeamMemberController::class, 'update']);
        Route::delete('/{team}/members/{user}', [TeamMemberController::class, 'destroy']);
        Route::post('/{team}/leave', [TeamMemberController::class, 'leave']);

        // Team invitations
        Route::get('/{team}/invitations', [TeamInvitationController::class, 'index']);
        Route::post('/{team}/invitations', [TeamInvitationController::class, 'store']);
        Route::delete('/{team}/invitations/{invitation}', [TeamInvitationController::class, 'cancel']);
        Route::post('/{team}/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend']);
    });

    // Invitation accept/decline (token-based)
    Route::post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    Route::post('/invitations/{token}/decline', [TeamInvitationController::class, 'decline']);

    // User's pending invitations
    Route::get('/my-invitations', [TeamInvitationController::class, 'myInvitations']);

    // Scheduled calls
    Route::prefix('scheduled-calls')->group(function () {
        Route::get('/', [ScheduledCallController::class, 'index']);
        Route::post('/', [ScheduledCallController::class, 'store']);
        Route::get('/{scheduled_call}', [ScheduledCallController::class, 'show']);
        Route::put('/{scheduled_call}', [ScheduledCallController::class, 'update']);
        Route::delete('/{scheduled_call}', [ScheduledCallController::class, 'destroy']);
    });
});
