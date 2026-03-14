<?php

use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\StaffInvoiceController;
use App\Http\Controllers\Api\StaffOrderController;
use App\Http\Controllers\Api\StaffOrganizationController;
use App\Http\Controllers\Api\StaffUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\Invoice\InvoiceController;
use App\Http\Controllers\LinkBuilding\Admin\LinkBuildingOrderController as AdminLinkBuildingOrderController;
use App\Http\Controllers\LinkBuilding\DrTierController;
use App\Http\Controllers\LinkBuilding\LinkBuildingOrderController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\ScheduledCall\ScheduledCallController;
use App\Http\Controllers\SupportTicket\SupportTicketController;
use App\Http\Controllers\Team\TeamController;
use App\Http\Controllers\Team\TeamInvitationController;
use App\Http\Controllers\Team\TeamMemberController;
use App\Http\Controllers\Test\TestEmailController;
use App\Http\Controllers\UserProfile\ProfileController;
use Illuminate\Support\Facades\Route;

// Test routes — remove in production
Route::get('/test/send-email', [TestEmailController::class, 'quickTestEmail']);
Route::post('/test/send-email', [TestEmailController::class, 'sendTestEmail']);
Route::get('/test/send-email-realtime', [TestEmailController::class, 'sendTestEmailRealtime']);

// ─── Public invitation routes (token is the secret, no auth required) ────────
Route::group(['prefix' => 'invitations'], function () {
    Route::get('{token}/validate', [InvitationController::class, 'validateToken']);
    Route::post('accept', [InvitationController::class, 'accept']);
});

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

    // Support tickets
    Route::prefix('support-tickets')->group(function () {
        Route::get('/', [SupportTicketController::class, 'index']);
        Route::post('/', [SupportTicketController::class, 'store']);
        Route::get('/{support_ticket}', [SupportTicketController::class, 'show']);
        Route::patch('/{support_ticket}', [SupportTicketController::class, 'update']);
        Route::post('/{support_ticket}/messages', [SupportTicketController::class, 'storeMessage']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/', [NotificationController::class, 'store']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::patch('/{notification}/archive', [NotificationController::class, 'archive']);
        Route::patch('/{notification}/snooze', [NotificationController::class, 'snooze']);
    });

    // Notification preferences
    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'show']);
        Route::put('/', [NotificationPreferenceController::class, 'update']);
    });

    // DR Tiers catalog
    Route::get('/dr-tiers', [DrTierController::class, 'index']);

    // Link Building orders
    Route::prefix('link-building')->group(function () {
        Route::get('/orders', [LinkBuildingOrderController::class, 'index']);
        Route::post('/orders', [LinkBuildingOrderController::class, 'store']);
        Route::get('/orders/{id}', [LinkBuildingOrderController::class, 'show']);

        Route::middleware('role:super_admin')->group(function () {
            Route::get('/admin/orders', [AdminLinkBuildingOrderController::class, 'index']);
        });
    });

    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{unique_id}', [InvoiceController::class, 'show']);
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);

    // Broadcasting auth (JWT-based)
    Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

    // ─── Staff / Admin portal routes ──────────────────────────────────────────
    Route::group(['prefix' => 'staff', 'middleware' => ['role:super-admin,admin,staff']], function () {
        Route::get('users', [StaffUserController::class, 'index']);
        Route::get('organizations', [StaffOrganizationController::class, 'index']);
        Route::get('orders', [StaffOrderController::class, 'index']);
        Route::get('invoices', [StaffInvoiceController::class, 'index']);

        // All staff roles can list invitations
        Route::get('invitations', [InvitationController::class, 'index']);

        // Only super-admin and admin can create or revoke invitations
        Route::group(['middleware' => ['role:super-admin,admin']], function () {
            Route::post('invitations', [InvitationController::class, 'store']);
            Route::delete('invitations/{id}', [InvitationController::class, 'destroy']);
        });
    });
});
