<?php

namespace App\Http\Controllers\Client\ContentBrief;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentBriefTierResource;
use App\Models\ContentBriefTier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentBriefTierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tiers = ContentBriefTier::where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy('sort_order', 'asc')
            ->get();

        return ContentBriefTierResource::collection($tiers);
    }
}
