<?php

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Mail\TestEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestEmailController extends Controller
{
    private string $dummy_email = 'ernesto@97thfloor.com';
    private string $dummy_name = 'John Doe';
    private string $dummy_message = 'This is a pre-loaded test email to verify that mail delivery is working correctly through the queue.';

    /**
     * Browser-friendly GET endpoint — dispatches a test email with pre-loaded dummy data.
     * Open directly in the browser: GET /api/test/send-email
     */
    public function quickTestEmail(): JsonResponse
    {
        $mailable = new TestEmail($this->dummy_name, $this->dummy_message);

        SendEmailJob::dispatch($mailable, $this->dummy_email);

        return response()->json([
            'success' => true,
            'message' => 'Test email dispatched to the queue using pre-loaded dummy data.',
            'data' => [
                'recipient_email' => $this->dummy_email,
                'recipient_name' => $this->dummy_name,
                'queue' => 'emails',
                'mailer' => config('mail.default'),
            ],
        ]);
    }

    /**
     * Send a test email synchronously (no queue) so any delivery error is returned immediately.
     * Uses pre-loaded dummy data — open directly in the browser: GET /api/test/send-email-realtime
     */
    public function sendTestEmailRealtime(): JsonResponse
    {
        $recipient_email = $this->dummy_email;
        $recipient_name = $this->dummy_name;

        try {
            Mail::to($recipient_email)->send(new TestEmail($recipient_name));

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully (real-time, no queue).',
                'data' => [
                    'recipient_email' => $recipient_email,
                    'recipient_name' => $recipient_name,
                    'mailer' => config('mail.default'),
                ],
            ]);
        }
        catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email.',
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }
    }

    /**
     * Dispatch a test email with custom data via POST request.
     *
     * POST /api/test/send-email
     * Body (optional): { "recipient_email": "you@example.com", "recipient_name": "Your Name" }
     */
    public function sendTestEmail(Request $request): JsonResponse
    {
        $recipient_email = $request->input('recipient_email', config('mail.from.address'));
        $recipient_name = $request->input('recipient_name', 'Test User');

        $mailable = new TestEmail($recipient_name);

        SendEmailJob::dispatch($mailable, $recipient_email);

        return response()->json([
            'success' => true,
            'message' => 'Test email dispatched to the queue.',
            'data' => [
                'recipient_email' => $recipient_email,
                'recipient_name' => $recipient_name,
                'queue' => 'emails',
                'mailer' => config('mail.default'),
            ],
        ]);
    }

    /**
     * GET /api/test/send-payment-successful-email
     *
     * Test the payment successful confirmation email with hardcoded data.
     * This method queues the email to the 'emails' queue via Laravel Horizon.
     *
     * HORIZON SETUP:
     * - Horizon must be running: php artisan horizon
     * - Or via Supervisor: sudo supervisorctl start base_clients_api:*
     * - Monitor queue: http://yourdomain.com/horizon
     *
     * The email will be processed by the 'supervisor-emails' worker defined in config/horizon.php
     */
    public function sendPaymentSuccessfulEmail(): JsonResponse
    {
        try {
            // Fetch the first real user from database (required for queue serialization)
            $user = User::first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No users found in database. Please create a user first.',
                    'instructions' => [
                        'Create a user account or use an existing user',
                        'Example: User::create([...])',
                        'Then try this endpoint again',
                    ],
                ], 404);
            }

            // Create hardcoded test payment data (as simple array, not Eloquent models)
            $invoice_number = 'INV-2026-001234';
            $currency_type = 'usd';
            $is_credits = $currency_type === 'credits';

            $test_payment_data = [
                'user_name'        => $user->full_name,
                'user_email'       => $user->email,
                'invoice_number'   => $invoice_number,
                'invoice_url'      => config('app.frontend_url') . '/invoices/INV-2026-001234',
                'payment_date'     => now()->format('F j, Y \a\t g:i A'),
                'payment_method'   => 'Credit Card',
                'currency_type'    => $currency_type,
                'is_credits'       => $is_credits,
                'subtotal_amount'  => 1500.00,
                'discount_amount'  => 150.00,
                'credit_amount'    => 0.00,
                'total_amount'     => 1350.00,
                'line_items'       => [
                    [
                        'name'        => 'DA 40+ Link Building',
                        'description' => 'High-quality backlink from DA 40+ website',
                        'price'       => 250.00,
                        'quantity'    => 3,
                        'item_total'  => 750.00,
                    ],
                    [
                        'name'        => 'DA 30+ Link Building',
                        'description' => 'Quality backlink from DA 30+ website',
                        'price'       => 150.00,
                        'quantity'    => 4,
                        'item_total'  => 600.00,
                    ],
                    [
                        'name'        => 'Premium Setup Fee',
                        'description' => 'One-time setup and optimization fee',
                        'price'       => 150.00,
                        'quantity'    => 1,
                        'item_total'  => 150.00,
                    ],
                ],
                'coupon_discounts' => [],
                'billed_to'        => [
                    'company_name'        => 'Digital Marketing Agency Co.',
                    'company_description' => 'Digital Marketing Services',
                    'address_line_1'      => '123 Main Street',
                    'address_line_2'      => 'Suite 500',
                    'state'               => 'California',
                    'country'             => 'United States',
                ],
                'app_name'         => config('app.name'),
            ];

            // Queue the payment successful email with test data (no Eloquent models serialization)
            Mail::to($user->email)->queue(new PaymentSuccessfulEmail(
                user: $user,
                invoice: null,
                test_data: $test_payment_data
            ));

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmation email queued successfully via Horizon!',
                'data' => [
                    'recipient_email' => $user->email,
                    'recipient_name' => $user->full_name,
                    'invoice_number' => $invoice_number,
                    'total_amount' => 1350.00,
                    'currency' => $currency_type,
                    'queue' => 'emails',
                    'horizon_worker' => 'supervisor-emails',
                    'mailer' => config('mail.default'),
                    'horizon_dashboard_url' => config('app.frontend_url') . '/horizon',
                    'instructions' => [
                        '1. Ensure Horizon is running: php artisan horizon',
                        '2. Or use Supervisor: sudo supervisorctl start base_clients_api:*',
                        '3. Monitor queue in Horizon dashboard: http://yourdomain.com/horizon',
                        '4. Email will be processed by supervisor-emails worker',
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to queue payment confirmation email.',
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }
    }
}
