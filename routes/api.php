<?php

use App\Http\Controllers\Admin\Coupon\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DrTier\DrTierController as AdminDrTierController;
use App\Http\Controllers\Admin\Invitation\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\Notification\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\Invoice\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\LinkBuilding\OrderController as AdminLinkBuildingOrderController;
use App\Http\Controllers\Admin\Order\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\Organization\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Admin\Service\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\User\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\Client\Coupon\CouponValidationController;
use App\Http\Controllers\Client\LinkBuilding\DrTierController;
use App\Http\Controllers\Client\LinkBuilding\InvoiceController;
use App\Http\Controllers\Client\LinkBuilding\OrderController as LinkBuildingOrderController;
use App\Http\Controllers\Client\Notification\NotificationController;
use App\Http\Controllers\Client\Notification\NotificationPreferenceController;
use App\Http\Controllers\Client\Organization\OrganizationController;
use App\Http\Controllers\Client\ScheduledCall\ScheduledCallController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Client\SupportTicket\SupportTicketController;
use App\Http\Controllers\Client\Team\TeamController;
use App\Http\Controllers\Client\Team\TeamInvitationController;
use App\Http\Controllers\Client\Team\TeamMemberController;
use App\Http\Controllers\Test\TestEmailController;
use Illuminate\Support\Facades\Route;

// ─── Test routes (remove in production) ──────────────────────────────────────
Route::get('/test/send-email', [TestEmailController::class, 'quickTestEmail']);
Route::post('/test/send-email', [TestEmailController::class, 'sendTestEmail']);
Route::get('/test/send-email-realtime', [TestEmailController::class, 'sendTestEmailRealtime']);

// ─── Auth routes ──────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// ─── Admin public routes (no auth required) ───────────────────────────────────
Route::prefix('admin')->group(function () {
    Route::prefix('invitations')->group(function () {
        Route::get('{token}/validate', [AdminInvitationController::class, 'validateToken']);
        Route::post('accept', [AdminInvitationController::class, 'accept']);
    });
});

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {

    // ── Admin routes (/admin/*) ────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {

        // Role management — super_admin and owner only
        Route::middleware('role:super_admin,owner')->prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('/users/{user}/assign', [RoleController::class, 'assignRole']);
            Route::post('/users/{user}/revoke', [RoleController::class, 'revokeRole']);
        });

        // Link building orders — super_admin only
        Route::middleware('role:super_admin')->prefix('link-building')->group(function () {
            Route::get('orders', [AdminLinkBuildingOrderController::class, 'index']);
        });

        // Admin notifications — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('notifications')->group(function () {
            Route::get('/', [AdminNotificationController::class, 'index']);
            Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
            Route::patch('/read-all', [AdminNotificationController::class, 'markAllAsRead']);
            Route::patch('/{notification}/read', [AdminNotificationController::class, 'markAsRead']);
            Route::patch('/{notification}/archive', [AdminNotificationController::class, 'archive']);
            Route::patch('/{notification}/unarchive', [AdminNotificationController::class, 'unarchive']);
        });

        // Services — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('services')->group(function () {
            Route::get('/', [AdminServiceController::class, 'index']);
            Route::get('/{id}', [AdminServiceController::class, 'show']);
            Route::post('/', [AdminServiceController::class, 'store']);
            Route::patch('/{id}', [AdminServiceController::class, 'update']);
            Route::delete('/{id}', [AdminServiceController::class, 'destroy']);
        });

        // Coupons — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('coupons')->group(function () {
            Route::get('/', [AdminCouponController::class, 'index']);
            Route::post('/', [AdminCouponController::class, 'store']);
            Route::get('/{id}', [AdminCouponController::class, 'show']);
            Route::patch('/{id}', [AdminCouponController::class, 'update']);
            Route::delete('/{id}', [AdminCouponController::class, 'destroy']);
        });

        // DR Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('dr-tiers')->group(function () {
            Route::get('/', [AdminDrTierController::class, 'index']);
            Route::post('/', [AdminDrTierController::class, 'store']);
            Route::get('/{id}', [AdminDrTierController::class, 'show']);
            Route::patch('/{id}', [AdminDrTierController::class, 'update']);
            Route::delete('/{id}', [AdminDrTierController::class, 'destroy']);
        });

        // Staff portal — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::get('users/{user_id}', [AdminUserController::class, 'show']);
            Route::get('users/{user_id}/orders', [AdminUserController::class, 'orders']);
            Route::get('organizations', [AdminOrganizationController::class, 'index']);
            Route::get('organizations/{id}', [AdminOrganizationController::class, 'show']);
            Route::put('organizations/{id}', [AdminOrganizationController::class, 'update']);
            Route::post('organizations/{id}/assets', [AdminOrganizationController::class, 'uploadAsset']);
            Route::get('orders',       [AdminOrderController::class, 'index']);
            Route::get('orders/{id}',  [AdminOrderController::class, 'show']);
            Route::get('invoices', [AdminInvoiceController::class, 'index']);
            Route::get('invoices/{invoice_id}', [AdminInvoiceController::class, 'show']);
            Route::get('invitations', [AdminInvitationController::class, 'index']);

            // Invitation management — super_admin and admin only
            Route::middleware('role:super_admin,admin')->group(function () {
                Route::post('invitations', [AdminInvitationController::class, 'store']);
                Route::delete('invitations/{id}', [AdminInvitationController::class, 'destroy']);
            });
        });
    });

    // ── Client routes ──────────────────────────────────────────────────────────

    // Organizations
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

    // Teams
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

    // Team invitation accept/decline (token-based)
    Route::post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    Route::post('/invitations/{token}/decline', [TeamInvitationController::class, 'decline']);

    // User's pending team invitations
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
        Route::patch('/{notification}/unarchive', [NotificationController::class, 'unarchive']);
        Route::patch('/{notification}/snooze', [NotificationController::class, 'snooze']);
    });

    // Notification preferences
    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'show']);
        Route::put('/', [NotificationPreferenceController::class, 'update']);
    });

    // Coupon validation
    Route::post('/coupons/validate', [CouponValidationController::class, 'validate']);

    // DR Tiers catalog
    Route::get('/dr-tiers', [DrTierController::class, 'index']);

    // Link building orders
    Route::prefix('link-building')->group(function () {
        Route::get('/orders', [LinkBuildingOrderController::class, 'index']);
        Route::post('/orders', [LinkBuildingOrderController::class, 'store']);
        Route::get('/orders/{id}', [LinkBuildingOrderController::class, 'show']);
    });

    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{unique_id}', [InvoiceController::class, 'show']);
    });

    // Profile — available to all authenticated users (admin, staff, client)
    Route::prefix('profile')->group(function () {
        Route::get('/',        [ProfileController::class,      'show']);
        Route::put('/',        [ProfileController::class,      'update']);
        Route::post('/photo',  [ProfilePhotoController::class, 'store']);
        Route::delete('/photo',[ProfilePhotoController::class, 'destroy']);
        Route::put('/password',[PasswordController::class,     'update']);
    });

    // Broadcasting auth (JWT-based)
    Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
});
