<?php

namespace Tests\Unit\Services;

use App\Services\StripeService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Verifies the exact parameters StripeService sends to the Stripe API for the
 * invoice card flows, and how Stripe responses / errors are mapped. Requests
 * never leave the process: a fake HTTP client records them and replies with
 * canned Stripe responses.
 */
class StripeServiceInvoiceCardTest extends TestCase
{
    private FakeStripeHttpClient $http_client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake']);

        $this->http_client = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->http_client);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function paymentIntentBody(array $overrides = []): array
    {
        return array_merge([
            'id'            => 'pi_test_123',
            'object'        => 'payment_intent',
            'client_secret' => 'pi_test_123_secret_abc',
            'status'        => 'requires_payment_method',
            'amount'        => 25000,
            'currency'      => 'usd',
        ], $overrides);
    }

    // ─── createInvoiceCardPaymentIntent ─────────────────────────────────────

    public function test_create_invoice_payment_intent_is_card_only_with_manual_capture(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody());

        $result = (new StripeService())->createInvoiceCardPaymentIntent(
            amount_cents: 25000,
            metadata: ['invoice_unique_id' => 'ABC123'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pi_test_123_secret_abc', $result['client_secret']);
        $this->assertSame('pi_test_123', $result['payment_intent_id']);

        $request = $this->http_client->lastRequest();
        $this->assertSame('post', $request['method']);
        $this->assertStringEndsWith('/v1/payment_intents', $request['url']);
        $this->assertSame(25000, $request['params']['amount']);
        $this->assertSame('usd', $request['params']['currency']);
        $this->assertSame('manual', $request['params']['capture_method']);
        $this->assertSame(['card'], $request['params']['payment_method_types']);
        $this->assertSame('ABC123', $request['params']['metadata']['invoice_unique_id']);
        $this->assertArrayNotHasKey('customer', $request['params']);
        $this->assertArrayNotHasKey('payment_method', $request['params']);
        $this->assertArrayNotHasKey('setup_future_usage', $request['params']);
        $this->assertArrayNotHasKey('automatic_payment_methods', $request['params']);
    }

    public function test_create_invoice_payment_intent_with_saved_card_attaches_customer_and_payment_method(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody());

        (new StripeService())->createInvoiceCardPaymentIntent(
            amount_cents: 25000,
            metadata: [],
            stripe_customer_id: 'cus_123',
            stripe_payment_method_id: 'pm_saved',
            save_for_future: true,
        );

        $params = $this->http_client->lastRequest()['params'];
        $this->assertSame('cus_123', $params['customer']);
        $this->assertSame('pm_saved', $params['payment_method']);
        // A card that is already saved is never set up again.
        $this->assertArrayNotHasKey('setup_future_usage', $params);
    }

    public function test_create_invoice_payment_intent_sets_up_new_card_for_future_use(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody());

        (new StripeService())->createInvoiceCardPaymentIntent(
            amount_cents: 25000,
            metadata: [],
            stripe_customer_id: 'cus_123',
            save_for_future: true,
        );

        $params = $this->http_client->lastRequest()['params'];
        $this->assertSame('off_session', $params['setup_future_usage']);
        $this->assertSame('cus_123', $params['customer']);
    }

    public function test_create_invoice_payment_intent_ignores_save_without_customer(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody());

        (new StripeService())->createInvoiceCardPaymentIntent(
            amount_cents: 25000,
            metadata: [],
            save_for_future: true,
        );

        $this->assertArrayNotHasKey('setup_future_usage', $this->http_client->lastRequest()['params']);
    }

    public function test_create_invoice_payment_intent_returns_failure_on_stripe_error(): void
    {
        $this->http_client->queueResponse([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Amount must be at least $0.50 usd'],
        ], 400);

        $result = (new StripeService())->createInvoiceCardPaymentIntent(amount_cents: 10, metadata: []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Amount must be at least', $result['message']);
    }

    // ─── chargeSavedCardOffSession ──────────────────────────────────────────

    public function test_charge_saved_card_confirms_off_session_with_manual_capture(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody(['status' => 'requires_capture']));

        $result = (new StripeService())->chargeSavedCardOffSession(
            amount_cents: 25000,
            stripe_customer_id: 'cus_123',
            stripe_payment_method_id: 'pm_saved',
            metadata: ['invoice_unique_id' => 'ABC123'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pi_test_123', $result['payment_intent_id']);

        $params = $this->http_client->lastRequest()['params'];
        $this->assertSame(25000, $params['amount']);
        $this->assertSame('cus_123', $params['customer']);
        $this->assertSame('pm_saved', $params['payment_method']);
        $this->assertSame('manual', $params['capture_method']);
        $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertContains($params['off_session'], [true, 'true']);
        $this->assertContains($params['confirm'], [true, 'true']);
        $this->assertSame('ABC123', $params['metadata']['invoice_unique_id']);
    }

    public function test_charge_saved_card_fails_when_intent_needs_further_action(): void
    {
        $this->http_client->queueResponse($this->paymentIntentBody(['status' => 'requires_action']));

        $result = (new StripeService())->chargeSavedCardOffSession(25000, 'cus_123', 'pm_saved', []);

        $this->assertFalse($result['success']);
        $this->assertSame('requires_action', $result['error_code']);
        $this->assertSame('pi_test_123', $result['payment_intent_id']);
        $this->assertStringContainsString('pay this invoice from their portal', $result['message']);
    }

    public function test_charge_saved_card_maps_decline_code_to_friendly_message(): void
    {
        $this->http_client->queueResponse([
            'error' => [
                'type'           => 'card_error',
                'code'           => 'card_declined',
                'decline_code'   => 'insufficient_funds',
                'message'        => 'Your card has insufficient funds.',
                'payment_intent' => $this->paymentIntentBody(['id' => 'pi_declined']),
            ],
        ], 402);

        $result = (new StripeService())->chargeSavedCardOffSession(25000, 'cus_123', 'pm_saved', []);

        $this->assertFalse($result['success']);
        $this->assertSame('insufficient_funds', $result['error_code']);
        $this->assertSame(
            StripeService::getUserFriendlyErrorMessage('insufficient_funds'),
            $result['message']
        );
        $this->assertSame('pi_declined', $result['payment_intent_id']);
    }

    public function test_charge_saved_card_explains_authentication_required(): void
    {
        $this->http_client->queueResponse([
            'error' => [
                'type'         => 'card_error',
                'code'         => 'authentication_required',
                'decline_code' => 'authentication_required',
                'message'      => 'This payment requires authentication.',
            ],
        ], 402);

        $result = (new StripeService())->chargeSavedCardOffSession(25000, 'cus_123', 'pm_saved', []);

        $this->assertFalse($result['success']);
        $this->assertSame('authentication_required', $result['error_code']);
        $this->assertStringContainsString('requires authentication by the cardholder', $result['message']);
    }

    public function test_charge_saved_card_handles_non_card_api_errors(): void
    {
        $this->http_client->queueResponse([
            'error' => [
                'type'    => 'invalid_request_error',
                'code'    => 'resource_missing',
                'message' => 'No such PaymentMethod: pm_saved',
            ],
        ], 400);

        $result = (new StripeService())->chargeSavedCardOffSession(25000, 'cus_123', 'pm_saved', []);

        $this->assertFalse($result['success']);
        $this->assertSame('resource_missing', $result['error_code']);
        $this->assertStringContainsString('No such PaymentMethod', $result['message']);
    }
}

/**
 * Minimal stripe-php HTTP client double: records each request and replies with
 * queued JSON bodies.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{body: array, status: int}> */
    private array $responses = [];

    /** @var array<int, array{method: string, url: string, params: array}> */
    private array $requests = [];

    public function queueResponse(array $body, int $status = 200): void
    {
        $this->responses[] = ['body' => $body, 'status' => $status];
    }

    public function lastRequest(): array
    {
        return end($this->requests);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        $response = array_shift($this->responses) ?? ['body' => ['error' => ['message' => 'No response queued']], 'status' => 500];

        return [json_encode($response['body']), $response['status'], []];
    }
}
