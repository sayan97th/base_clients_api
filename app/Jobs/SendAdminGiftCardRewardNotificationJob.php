<?php

namespace App\Jobs;

use App\Mail\AdminGiftCardRewardNotification;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Services\EmailNotificationSettingService;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emails every admin recipient configured in Email Notification Settings
 * when a client tells us where to send their promo gift card.
 */
class SendAdminGiftCardRewardNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    private const PRODUCT_TYPE_LABELS = [
        'link_building'        => 'Link Building',
        'new_content'          => 'New Content',
        'content_optimization' => 'Content Optimization',
        'content_brief'        => 'Content Briefs',
    ];

    public function __construct(
        public string $claim_id,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $claim = GiftCardRewardClaim::with(['user', 'coupon'])->find($this->claim_id);

        if (! $claim || ! $claim->recipient_email || ! $claim->gift_card_brand) {
            return;
        }

        $recipients     = EmailNotificationSettingService::resolveAdminRecipients();
        $client         = $claim->user;
        $client_name    = $client?->full_name ?: ($client?->email ?? 'Unknown client');
        $client_email   = $client?->email ?? '';
        $client_company = $client?->company ?? '';
        $first_order_id = $claim->order_ids[0] ?? null;

        $products = implode(', ', array_map(
            fn (string $type) => self::PRODUCT_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)),
            $claim->product_types ?? [],
        ));

        $view_order_url = count($claim->order_ids ?? []) > 1
            ? FrontendUrl::to('/admin/orders/session/' . $claim->session_id)
            : FrontendUrl::to('/admin/orders/' . $first_order_id);

        $view_claims_url = $claim->coupon_id
            ? FrontendUrl::to('/admin/coupons/' . $claim->coupon_id . '/edit#gift-card-claims')
            : FrontendUrl::to('/admin/coupons');

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminGiftCardRewardNotification(
                    recipient_name:     $recipient['name'],
                    recipient_email:    $recipient['email'],
                    client_name:        $client_name,
                    client_email:       $client_email,
                    client_company:     $client_company,
                    client_initials:    $this->buildInitials($client_name),
                    promo_code:         $claim->coupon_code,
                    promotion_name:     $claim->coupon?->name ?? $claim->coupon_code,
                    gift_card_brand:    $claim->gift_card_brand,
                    gift_card_label:    $claim->brandLabel() ?? $claim->gift_card_brand,
                    gift_card_email:    $claim->recipient_email,
                    reward_amount:      '$' . number_format($claim->reward_amount, 2),
                    reward_percentage:  rtrim(rtrim(number_format($claim->reward_percentage, 2), '0'), '.') . '%',
                    order_total:        '$' . number_format($claim->order_total, 2),
                    order_reference:    strtoupper(substr($claim->session_id, 0, 8)),
                    products:           $products ?: 'Order',
                    is_payment_pending: $this->isPaymentPending($claim),
                    submitted_at:       ($claim->submitted_at ?? now())->format('F j, Y \a\t g:i A'),
                    view_order_url:     $view_order_url,
                    view_claims_url:    $view_claims_url,
                    settings_url:       FrontendUrl::to('/admin/email-notifications'),
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendAdminGiftCardRewardNotificationJob failed', [
            'claim_id' => $this->claim_id,
            'error'    => $exception->getMessage(),
        ]);
    }

    /**
     * Pay later checkouts earn the reward too, so admins are told when the
     * order has not been paid yet before they send out the gift card.
     */
    private function isPaymentPending(GiftCardRewardClaim $claim): bool
    {
        $order_ids = $claim->order_ids ?? [];

        foreach ([LinkBuildingOrder::class, NewContentOrder::class, ContentOptimizationOrder::class, ContentBriefOrder::class] as $model) {
            if ($model::whereIn('id', $order_ids)->where('status', 'payment_pending')->exists()) {
                return true;
            }
        }

        return false;
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
