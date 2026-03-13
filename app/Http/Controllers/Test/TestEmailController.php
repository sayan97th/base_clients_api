<?php

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\TestEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestEmailController extends Controller
{
    private string $dummy_email = 'test@example.com';
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
}