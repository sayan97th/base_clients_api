<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * GET /api/staff/invoices?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::with(['user', 'lineItems', 'billedTo'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data'         => $invoices->items(),
            'current_page' => $invoices->currentPage(),
            'last_page'    => $invoices->lastPage(),
            'total'        => $invoices->total(),
        ]);
    }
}
