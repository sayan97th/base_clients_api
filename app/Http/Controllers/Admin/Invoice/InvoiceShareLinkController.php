<?php

namespace App\Http\Controllers\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Invoice\UpdateShareLinksRequest;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class InvoiceShareLinkController extends Controller
{
    private const PORTAL_BASE_URL = 'https://clients.basesearchmarketing.com';

    /**
     * GET /api/admin/invoices/{invoice_id}/share-links
     */
    public function show(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $invoice_id)->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if (!$invoice->share_key) {
            $invoice->share_key = $this->generateUniqueShareKey();
            $invoice->save();
        }

        return response()->json($this->buildShareLinksResponse($invoice));
    }

    /**
     * PATCH /api/admin/invoices/{invoice_id}/share-links
     */
    public function update(UpdateShareLinksRequest $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $invoice_id)->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if (!$invoice->share_key) {
            $invoice->share_key = $this->generateUniqueShareKey();
        }

        $invoice->sharing_enabled = $request->boolean('sharing_enabled');
        $invoice->save();

        return response()->json($this->buildShareLinksResponse($invoice));
    }

    private function buildShareLinksResponse(Invoice $invoice): array
    {
        return [
            'sharing_enabled' => $invoice->sharing_enabled,
            'private_link'    => self::PORTAL_BASE_URL . '/invoices/' . $invoice->unique_id,
            'public_link'     => self::PORTAL_BASE_URL . '/share/invoices/' . $invoice->unique_id . '?key=' . $invoice->share_key,
        ];
    }

    private function generateUniqueShareKey(): string
    {
        do {
            $key = Str::random(48);
        } while (Invoice::where('share_key', $key)->exists());

        return $key;
    }
}
