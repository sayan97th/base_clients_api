<?php

namespace App\Http\Controllers\Client\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewContentTierResource;
use App\Models\NewContentTier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NewContentTierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tiers = NewContentTier::where('is_hidden', false)
            ->orderBy('sort_order', 'asc')
            ->get();

        return NewContentTierResource::collection($tiers);
    }
}
