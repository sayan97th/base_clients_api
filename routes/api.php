<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Client\NewContent\NewContentTierController;
use App\Http\Controllers\Client\NewContent\NewContentOrderController;
use App\Http\Controllers\Admin\BacklinkOrder\BacklinkOrderController as AdminBacklinkOrderController;
use App\Http\Controllers\Admin\ContentRefresh\AdminContentRefreshTierController;
use App\Http\Controllers\Admin\NewsPlacement\NewsPlacementController as AdminNewsPlacementController;
use App\Http\Controllers\Admin\Coupon\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\Dashboard\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DrTier\AdminLinkBuildingTierController;
use App\Http\Controllers\Admin\News\NewsController as AdminNewsController;
use App\Http\Controllers\Admin\Resource\AdminResourceController;
use App\Http\Controllers\Admin\Resource\AdminResourceFileController;
use App\Http\Controllers\Admin\Invitation\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\Notification\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\Invoice\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\Invoice\InvoiceShareLinkController as AdminInvoiceShareLinkController;
use App\Http\Controllers\Admin\LinkBuilding\OrderController as AdminLinkBuildingOrderController;
use App\Http\Controllers\Admin\LinkBuilding\OrderUpdateController as AdminOrderUpdateController;
use App\Http\Controllers\Admin\ContentOptimization\AdminContentOptimizationTierController;
use App\Http\Controllers\Admin\NewContent\AdminNewContentTierController;
use App\Http\Controllers\Admin\Order\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\Order\OrderReportController as AdminOrderReportController;
use App\Http\Controllers\Admin\Order\ReportTableController as AdminReportTableController;
use App\Http\Controllers\Admin\Order\ReportRowController as AdminReportRowController;
use App\Http\Controllers\Admin\Tracking\TrackingController as AdminTrackingController;
use App\Http\Controllers\Admin\WebSocket\WebSocketController as AdminWebSocketController;
use App\Http\Controllers\Admin\Organization\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Admin\Service\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\User\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\Client\Coupon\CouponValidationController;
use App\Http\Controllers\Client\News\NewsController;
use App\Http\Controllers\Client\Resource\ResourceController;
use App\Http\Controllers\Client\Stripe\StripeController;
use App\Http\Controllers\Client\PaymentProfile\PaymentProfileController;
use App\Http\Controllers\Client\LinkBuilding\InvoiceController;
use App\Http\Controllers\Client\LinkBuilding\CartController;
use App\Http\Controllers\Client\LinkBuilding\OrderController as LinkBuildingOrderController;
use App\Http\Controllers\Client\LinkBuilding\OrderPlacementsController as LinkBuildingOrderPlacementsController;
use App\Http\Controllers\Client\LinkBuilding\DeliverableController as LinkBuildingDeliverableController;
use App\Http\Controllers\Client\LinkBuilding\OrderReportController as ClientOrderReportController;
use App\Http\Controllers\Client\LinkBuilding\OrderUpdateController as ClientOrderUpdateController;
use App\Http\Controllers\Client\Notification\NotificationController;
use App\Http\Controllers\Client\Notification\NotificationPreferenceController;
use App\Http\Controllers\Client\Organization\OrganizationController;
use App\Http\Controllers\Client\ScheduledCall\ScheduledCallController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Admin\SmeContent\SmeAuthoredServiceController;
use App\Http\Controllers\Admin\SmeContent\SmeCollaborationServiceController;
use App\Http\Controllers\Admin\SmeContent\SmeEnhancedServiceController;
use App\Http\Controllers\Admin\SmeAppointment\AdminSmeAppointmentController;
use App\Http\Controllers\Client\SmeAppointment\SmeAppointmentController;
use App\Http\Controllers\Admin\PremiumMentions\AdminPremiumMentionsPlanController;
use App\Http\Controllers\Client\PremiumMentions\OrderController as PremiumMentionsOrderController;
use App\Http\Controllers\Client\PremiumMentions\PlanController as PremiumMentionsPlanController;
use App\Http\Controllers\Client\SmeContent\AuthoredContentController;
use App\Http\Controllers\Client\SmeContent\InternalCollaborationController;
use App\Http\Controllers\Client\SmeContent\EnhancedContentController;
use App\Http\Controllers\Client\SupportTicket\SupportTicketController;
use App\Http\Controllers\Client\Team\TeamController;
use App\Http\Controllers\Client\Team\TeamInvitationController;
use App\Http\Controllers\Client\Team\TeamMemberController;
use App\Http\Controllers\Admin\ContentBrief\AdminContentBriefTierController;
use App\Http\Controllers\Admin\SeoPackages\AdminSeoPackageController;
use App\Http\Controllers\Client\ContentBrief\ContentBriefTierController;
use App\Http\Controllers\Client\ContentBrief\ContentBriefOrderController;
use App\Http\Controllers\Client\ContentOptimization\ContentOptimizationTierController;
use App\Http\Controllers\Client\ContentOptimization\ContentOptimizationOrderController;
use App\Http\Controllers\Client\Cart\CartController as UnifiedCartController;
use App\Http\Controllers\Client\ContentRefresh\ContentRefreshTierController;
use App\Http\Controllers\Client\LinkBuilding\LinkBuildingTierController;
use App\Http\Controllers\Client\SeoPackages\SeoPackageController;
use App\Http\Controllers\Client\SeoPackages\SeoSubscriptionController;
use App\Http\Controllers\Public\PublicInvoiceController;
use App\Http\Controllers\Test\TestEmailController;

// ─── Test routes (remove in production) ──────────────────────────────────────
Route::get('/test/send-email', [TestEmailController::class, 'quickTestEmail']);
Route::post('/test/send-email', [TestEmailController::class, 'sendTestEmail']);
Route::get('/test/send-email-realtime', [TestEmailController::class, 'sendTestEmailRealtime']);
Route::get('/test/send-payment-successful-email', [TestEmailController::class, 'sendPaymentSuccessfulEmail']);

// ─── Auth routes ──────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('2fa-challenge', [AuthController::class, 'twoFactorChallenge']);
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('reset-password',  [PasswordResetController::class, 'resetPassword']);

    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// ─── Public news feed (no auth required) ─────────────────────────────────────
Route::get('/news', [NewsController::class, 'index']);
Route::get('/news/{id}', [NewsController::class, 'show']);

// ─── Public invoice view (no auth required) ───────────────────────────────────
Route::get('/invoices/{invoice_id}/view', [PublicInvoiceController::class, 'show']);
Route::post('/invoices/{invoice_id}/pay', [PublicInvoiceController::class, 'pay']);

// ─── Admin public routes (no auth required) ───────────────────────────────────
Route::prefix('admin')->group(function () {
    Route::prefix('invitations')->group(function () {
        Route::get('{token}/validate', [AdminInvitationController::class, 'validateToken']);
        Route::post('accept', [AdminInvitationController::class, 'accept']);
    });

    // News placements CSV export — auth handled via ?token= query parameter
    Route::get('news-placements/export', [AdminNewsPlacementController::class, 'export']);
});

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware(['auth:api', 'active'])->group(function () {

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

        // Order tracking dashboard — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('tracking')->group(function () {
            Route::get('orders', [AdminTrackingController::class, 'orders']);
        });

        // WebSocket test panel — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('websocket')->group(function () {
            Route::get('status',    [AdminWebSocketController::class, 'status']);
            Route::post('broadcast', [AdminWebSocketController::class, 'broadcast']);
            Route::get('channels',  [AdminWebSocketController::class, 'channels']);
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

        // SME Appointments — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('sme-content/appointments')->group(function () {
            Route::get('stats',                          [AdminSmeAppointmentController::class, 'stats']);
            Route::get('export',                         [AdminSmeAppointmentController::class, 'export']);
            Route::get('/',                              [AdminSmeAppointmentController::class, 'index']);
            Route::get('{appointment_id}',               [AdminSmeAppointmentController::class, 'show']);
            Route::patch('{appointment_id}/status',      [AdminSmeAppointmentController::class, 'updateStatus']);
            Route::put('{appointment_id}',               [AdminSmeAppointmentController::class, 'update']);
            Route::delete('{appointment_id}',            [AdminSmeAppointmentController::class, 'destroy']);
        });

        // SEO Packages — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('seo-packages')->group(function () {
            Route::get('/',        [AdminSeoPackageController::class, 'index']);
            Route::post('/',       [AdminSeoPackageController::class, 'store']);
            Route::get('/{id}',    [AdminSeoPackageController::class, 'show']);
            Route::patch('/{id}',  [AdminSeoPackageController::class, 'update']);
            Route::delete('/{id}', [AdminSeoPackageController::class, 'destroy']);
        });

        // Premium Mentions plans — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('premium-mentions')->group(function () {
            Route::get('plans',        [AdminPremiumMentionsPlanController::class, 'index']);
            Route::get('plans/{id}',   [AdminPremiumMentionsPlanController::class, 'show']);
            Route::post('plans',       [AdminPremiumMentionsPlanController::class, 'store']);
            Route::patch('plans/{id}', [AdminPremiumMentionsPlanController::class, 'update']);
            Route::delete('plans/{id}', [AdminPremiumMentionsPlanController::class, 'destroy']);
        });

        // SME Content service tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('sme-content')->group(function () {
            Route::get('collaboration-services',                [SmeCollaborationServiceController::class, 'index']);
            Route::post('collaboration-services',               [SmeCollaborationServiceController::class, 'store']);
            Route::put('collaboration-services/{service_id}',   [SmeCollaborationServiceController::class, 'update']);
            Route::delete('collaboration-services/{service_id}', [SmeCollaborationServiceController::class, 'destroy']);

            Route::get('authored-services',                [SmeAuthoredServiceController::class, 'index']);
            Route::post('authored-services',               [SmeAuthoredServiceController::class, 'store']);
            Route::put('authored-services/{service_id}',   [SmeAuthoredServiceController::class, 'update']);
            Route::delete('authored-services/{service_id}', [SmeAuthoredServiceController::class, 'destroy']);

            Route::get('enhanced-services',                [SmeEnhancedServiceController::class, 'index']);
            Route::post('enhanced-services',               [SmeEnhancedServiceController::class, 'store']);
            Route::put('enhanced-services/{service_id}',   [SmeEnhancedServiceController::class, 'update']);
            Route::delete('enhanced-services/{service_id}', [SmeEnhancedServiceController::class, 'destroy']);
        });

        // Services — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('services')->group(function () {
            Route::get('/', [AdminServiceController::class, 'index']);
            Route::get('/{id}', [AdminServiceController::class, 'show']);
            Route::post('/', [AdminServiceController::class, 'store']);
            Route::patch('/{id}', [AdminServiceController::class, 'update']);
            Route::delete('/{id}', [AdminServiceController::class, 'destroy']);
        });

        // Resources — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('resources')->group(function () {
            Route::get('/',                          [AdminResourceController::class, 'index']);
            Route::post('/',                         [AdminResourceController::class, 'store']);
            Route::get('/{id}',                      [AdminResourceController::class, 'show']);
            Route::patch('/{id}',                    [AdminResourceController::class, 'update']);
            Route::delete('/{id}',                   [AdminResourceController::class, 'destroy']);
            Route::post('/{id}/files',               [AdminResourceFileController::class, 'store']);
            Route::delete('/{id}/files/{file_id}',   [AdminResourceFileController::class, 'destroy']);
        });

        // News & Promos — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('news')->group(function () {
            Route::post('upload', [AdminNewsController::class, 'uploadImage']);
            Route::get('/', [AdminNewsController::class, 'index']);
            Route::post('/', [AdminNewsController::class, 'store']);
            Route::get('/{id}', [AdminNewsController::class, 'show']);
            Route::patch('/{id}', [AdminNewsController::class, 'update']);
            Route::delete('/{id}', [AdminNewsController::class, 'destroy']);
        });

        // Coupons — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('coupons')->group(function () {
            Route::get('/', [AdminCouponController::class, 'index']);
            Route::post('/', [AdminCouponController::class, 'store']);
            Route::get('/{id}', [AdminCouponController::class, 'show']);
            Route::patch('/{id}', [AdminCouponController::class, 'update']);
            Route::delete('/{id}', [AdminCouponController::class, 'destroy']);
        });

        // Content Brief Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('content-brief-tiers')->group(function () {
            Route::get('/',        [AdminContentBriefTierController::class, 'index']);
            Route::post('/',       [AdminContentBriefTierController::class, 'store']);
            Route::get('/{id}',    [AdminContentBriefTierController::class, 'show']);
            Route::patch('/{id}',  [AdminContentBriefTierController::class, 'update']);
            Route::delete('/{id}', [AdminContentBriefTierController::class, 'destroy']);
        });

        // Content Optimization Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('content-optimization-tiers')->group(function () {
            Route::get('/', [AdminContentOptimizationTierController::class, 'index']);
            Route::post('/', [AdminContentOptimizationTierController::class, 'store']);
            Route::get('/{id}', [AdminContentOptimizationTierController::class, 'show']);
            Route::patch('/{id}', [AdminContentOptimizationTierController::class, 'update']);
            Route::delete('/{id}', [AdminContentOptimizationTierController::class, 'destroy']);
        });

        // New Content Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('new-content-tiers')->group(function () {
            Route::get('/', [AdminNewContentTierController::class, 'index']);
            Route::post('/', [AdminNewContentTierController::class, 'store']);
            Route::get('/{id}', [AdminNewContentTierController::class, 'show']);
            Route::patch('/{id}', [AdminNewContentTierController::class, 'update']);
            Route::delete('/{id}', [AdminNewContentTierController::class, 'destroy']);
        });

        // Content Refresh Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('content-refresh-tiers')->group(function () {
            Route::get('/', [AdminContentRefreshTierController::class, 'index']);
            Route::post('/', [AdminContentRefreshTierController::class, 'store']);
            Route::patch('/{id}', [AdminContentRefreshTierController::class, 'update']);
            Route::delete('/{id}', [AdminContentRefreshTierController::class, 'destroy']);
        });

        // DR Tiers — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('dr-tiers')->group(function () {
            Route::get('/', [AdminLinkBuildingTierController::class, 'index']);
            Route::post('/', [AdminLinkBuildingTierController::class, 'store']);
            Route::get('/{id}', [AdminLinkBuildingTierController::class, 'show']);
            Route::patch('/{id}', [AdminLinkBuildingTierController::class, 'update']);
            Route::delete('/{id}', [AdminLinkBuildingTierController::class, 'destroy']);
        });

        // Dashboard — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->prefix('dashboard')->group(function () {
            Route::get('/summary',       [AdminDashboardController::class, 'summary']);
            Route::get('/team-capacity', [AdminDashboardController::class, 'teamCapacity']);
            Route::get('/team-health',   [AdminDashboardController::class, 'teamHealth']);
        });

        // Backlink Orders — super_admin, admin, staff
        // Note: search and export are registered before the generic POST store to avoid route shadowing.
        Route::middleware('role:super_admin,admin,staff')->group(function () {
            Route::post('/backlink-orders/search',     [AdminBacklinkOrderController::class, 'search']);
            Route::post('/backlink-orders/export',     [AdminBacklinkOrderController::class, 'export']);
            Route::post('/backlink-orders',            [AdminBacklinkOrderController::class, 'store']);
            Route::put('/backlink-orders/{id}',        [AdminBacklinkOrderController::class, 'update']);
            Route::delete('/backlink-orders/{id}',     [AdminBacklinkOrderController::class, 'destroy']);
        });

        // News Placements — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->group(function () {
            Route::get('/news-placements',         [AdminNewsPlacementController::class, 'index']);
            Route::post('/news-placements',        [AdminNewsPlacementController::class, 'store']);
            Route::put('/news-placements/{id}',    [AdminNewsPlacementController::class, 'update']);
            Route::delete('/news-placements/{id}', [AdminNewsPlacementController::class, 'destroy']);
        });

        // Staff portal — super_admin, admin, staff
        Route::middleware('role:super_admin,admin,staff')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::get('users/{user_id}', [AdminUserController::class, 'show']);
            Route::get('users/{user_id}/orders', [AdminUserController::class, 'orders']);

            // Ban / unban — super_admin and admin only
            Route::middleware('role:super_admin,admin')->group(function () {
                Route::patch('users/{user_id}/ban',   [AdminUserController::class, 'ban']);
                Route::patch('users/{user_id}/unban', [AdminUserController::class, 'unban']);
            });
            Route::get('organizations', [AdminOrganizationController::class, 'index']);
            Route::get('organizations/{id}', [AdminOrganizationController::class, 'show']);
            Route::put('organizations/{id}', [AdminOrganizationController::class, 'update']);
            Route::post('organizations/{id}/assets', [AdminOrganizationController::class, 'uploadAsset']);
            Route::get('orders',          [AdminOrderController::class, 'index']);
            Route::get('orders/{order}',  [AdminOrderController::class, 'show']);
            Route::get('invoices', [AdminInvoiceController::class, 'index']);
            Route::post('invoices', [AdminInvoiceController::class, 'store']);
            Route::get('invoices/{invoice_id}/history', [AdminInvoiceController::class, 'history']);
            Route::get('invoices/{invoice_id}/share-links', [AdminInvoiceShareLinkController::class, 'show']);
            Route::patch('invoices/{invoice_id}/share-links', [AdminInvoiceShareLinkController::class, 'update']);
            Route::post('invoices/{invoice_id}/mark-paid', [AdminInvoiceController::class, 'markPaid']);
            Route::post('invoices/{invoice_id}/mark-unpaid', [AdminInvoiceController::class, 'markUnpaid']);
            Route::post('invoices/{invoice_id}/mark-overdue', [AdminInvoiceController::class, 'markOverdue']);
            Route::post('invoices/{invoice_id}/refund', [AdminInvoiceController::class, 'refundInvoice']);
            Route::post('invoices/{invoice_id}/void', [AdminInvoiceController::class, 'voidInvoice']);
            Route::post('invoices/{invoice_id}/duplicate', [AdminInvoiceController::class, 'duplicate']);
            Route::post('invoices/{invoice_id}/send-reminder', [AdminInvoiceController::class, 'sendReminder']);
            Route::patch('invoices/{invoice_id}/billing', [AdminInvoiceController::class, 'updateBilling']);
            Route::patch('invoices/{invoice_id}', [AdminInvoiceController::class, 'update']);
            Route::get('invoices/{invoice_id}', [AdminInvoiceController::class, 'show']);
            Route::delete('invoices/{invoice_id}', [AdminInvoiceController::class, 'destroy']);
            Route::get('invitations', [AdminInvitationController::class, 'index']);

            // Order tracking — list updates, create update
            Route::get('orders/{order_id}/updates', [AdminOrderUpdateController::class, 'index']);
            Route::post('orders/{order_id}/updates', [AdminOrderUpdateController::class, 'store']);

            // Order status — direct update without creating a tracking entry
            Route::patch('orders/{order}/status', [AdminOrderController::class, 'updateStatus']);

            // Order reports
            Route::get('orders/{order}/report',          [AdminOrderReportController::class, 'show']);
            Route::post('orders/{order}/report/send',    [AdminOrderReportController::class, 'send']);
            Route::post('orders/{order}/report/import',  [AdminOrderReportController::class, 'importItems']);

            // Report tables
            Route::post('orders/{order}/report/tables',                [AdminReportTableController::class, 'store']);
            Route::patch('orders/{order}/report/tables/{table}',       [AdminReportTableController::class, 'update']);
            Route::delete('orders/{order}/report/tables/{table}',      [AdminReportTableController::class, 'destroy']);

            // Report rows
            Route::post('orders/{order}/report/tables/{table}/rows',                [AdminReportRowController::class, 'store']);
            Route::patch('orders/{order}/report/tables/{table}/rows/{row}',         [AdminReportRowController::class, 'update']);
            Route::delete('orders/{order}/report/tables/{table}/rows/{row}',        [AdminReportRowController::class, 'destroy']);

            // Invitation management — super_admin and admin only
            Route::middleware('role:super_admin,admin')->group(function () {
                Route::post('invitations', [AdminInvitationController::class, 'store']);
                Route::delete('invitations/{id}', [AdminInvitationController::class, 'destroy']);

                // Order update delete — super_admin and admin only
                Route::delete('orders/{order_id}/updates/{update_id}', [AdminOrderUpdateController::class, 'destroy']);
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

    // Stripe
    Route::prefix('stripe')->group(function () {
        Route::post('/create-payment-intent', [StripeController::class, 'createPaymentIntent']);
    });

    // Coupon validation
    Route::post('/coupons/validate', [CouponValidationController::class, 'validate']);

    // Resources
    Route::get('/resources', [ResourceController::class, 'index']);
    Route::get('/resources/{id}', [ResourceController::class, 'show']);

    // DR Tiers catalog
    Route::get('/dr-tiers', [LinkBuildingTierController::class, 'index']);

    // New Content Tiers catalog
    Route::get('/new-content-tiers', [NewContentTierController::class, 'index']);

    // New Content orders
    Route::prefix('new-content')->group(function () {
        Route::get('/orders',  [NewContentOrderController::class, 'index']);
        Route::post('/orders', [NewContentOrderController::class, 'store']);
    });

    // Content Brief Tiers catalog
    Route::get('/content-brief-tiers', [ContentBriefTierController::class, 'index']);

    // Content Brief orders
    Route::prefix('content-briefs')->group(function () {
        Route::get('/orders',            [ContentBriefOrderController::class, 'index']);
        Route::post('/orders',           [ContentBriefOrderController::class, 'store']);
        Route::get('/orders/{order_id}', [ContentBriefOrderController::class, 'show']);
    });

    // Content Optimization Tiers catalog
    Route::get('/content-optimization-tiers', [ContentOptimizationTierController::class, 'index']);

    // Content Optimization orders
    Route::prefix('content-optimization')->group(function () {
        Route::get('/orders',  [ContentOptimizationOrderController::class, 'index']);
        Route::post('/orders', [ContentOptimizationOrderController::class, 'store']);
    });

    // Content Refresh Tiers catalog
    Route::get('/content-refresh-tiers', [ContentRefreshTierController::class, 'index']);

    // Unified cart
    Route::prefix('cart')->group(function () {
        Route::get('/',      [UnifiedCartController::class, 'show']);
        Route::put('/',      [UnifiedCartController::class, 'upsert']);
        Route::delete('/',   [UnifiedCartController::class, 'destroy']);
        Route::post('/checkout', [UnifiedCartController::class, 'checkout']);
    });

    // Link building cart
    Route::prefix('link-building')->group(function () {
        Route::get('/cart',    [CartController::class, 'show']);
        Route::put('/cart',    [CartController::class, 'upsert']);
        Route::delete('/cart', [CartController::class, 'destroy']);
    });

    // Link building orders
    Route::prefix('link-building')->group(function () {
        Route::get('/orders', [LinkBuildingOrderController::class, 'index']);
        Route::post('/orders', [LinkBuildingOrderController::class, 'store']);
        Route::get('/orders/{id}', [LinkBuildingOrderController::class, 'show']);
        Route::get('/orders/{order_id}/updates', [ClientOrderUpdateController::class, 'index']);
        Route::get('/orders/{order_id}/report', [ClientOrderReportController::class, 'show']);
        Route::get('/deliverables', [LinkBuildingDeliverableController::class, 'index']);
        Route::get('/order-placements', [LinkBuildingOrderPlacementsController::class, 'index']);
    });

    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{unique_id}', [InvoiceController::class, 'show']);
    });

    // Payment profiles
    Route::prefix('payment-profiles')->group(function () {
        Route::get('/',               [PaymentProfileController::class, 'index']);
        Route::post('/',              [PaymentProfileController::class, 'store']);
        Route::delete('/{id}',        [PaymentProfileController::class, 'destroy']);
        Route::patch('/{id}/default', [PaymentProfileController::class, 'setDefault']);
    });

    // Profile — available to all authenticated users (admin, staff, client)
    Route::prefix('profile')->group(function () {
        Route::get('/',        [ProfileController::class,      'show']);
        Route::put('/',        [ProfileController::class,      'update']);
        Route::patch('/',      [ProfileController::class,      'partialUpdate']);
        Route::post('/photo',  [ProfilePhotoController::class, 'store']);
        Route::delete('/photo', [ProfilePhotoController::class, 'destroy']);
        Route::put('/password', [PasswordController::class,     'update']);
    });

    // Premium Mentions
    Route::prefix('premium-mentions')->group(function () {
        Route::get('plans', [PremiumMentionsPlanController::class, 'index']);
        Route::post('orders', [PremiumMentionsOrderController::class, 'store']);
    });

    // SEO Packages
    Route::prefix('seo-packages')->group(function () {
        Route::get('/',             [SeoPackageController::class,      'index']);
        Route::post('subscriptions', [SeoSubscriptionController::class, 'store']);
    });



    // SME Content — client routes
    Route::prefix('sme-content')->group(function () {
        // Appointments
        Route::get('appointments',         [SmeAppointmentController::class, 'index']);
        Route::post('appointments',        [SmeAppointmentController::class, 'store']);
        Route::get('appointments/{id}',    [SmeAppointmentController::class, 'show']);
        Route::delete('appointments/{id}', [SmeAppointmentController::class, 'destroy']);

        // Service tier listings
        Route::get('collaboration-services', [InternalCollaborationController::class, 'index']);
        Route::get('authored-services',      [AuthoredContentController::class,       'index']);
        Route::get('enhanced-services',      [EnhancedContentController::class,       'index']);
    });

    // Broadcasting auth (JWT-based)
    Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

    // Two-factor authentication
    Route::prefix('2fa')->group(function () {
        Route::get('/status',  [TwoFactorController::class, 'status']);
        Route::post('/setup',  [TwoFactorController::class, 'setup']);
        Route::post('/verify', [TwoFactorController::class, 'verify']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
    });
});
