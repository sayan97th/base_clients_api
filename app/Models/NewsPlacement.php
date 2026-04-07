<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NewsPlacement extends Model
{
    use HasUuids;

    protected $table = 'news_placements';

    protected $fillable = [
        'domain',
        'dr',
        'traffic',
        'category',
        'price',
        'types_of_content',
        'do_follow_no_follow',
        'indexable',
        'well_known_site',
        'links_allowed',
        'additional_notes',
        'price_1',
        'poc_1',
        'price_2',
        'poc_2',
        'tier',
        'pbn_check',
        'used_domain',
        'within_budget',
        'ref_domains',
    ];
}
