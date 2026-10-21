<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One partnership domain of the reference list the Link Table looks up by its
 * Partnership column, with its latest DR, traffic, publisher (POC) and posting
 * fee. Uploaded in bulk from the metrics and partner database spreadsheets.
 */
class LinkBuildingPartnerMetric extends Model
{
    /** Value written to Current POC and Current Price when the partnership is no longer in the partner list. */
    public const SITE_REMOVED = 'SITE REMOVED';

    protected $fillable = [
        'domain_key',
        'domain',
        'dr',
        'traffic',
        'current_poc',
        'current_price',
        'metrics_synced_at',
        'partner_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics_synced_at' => 'datetime',
            'partner_synced_at' => 'datetime',
        ];
    }

    /**
     * Normalizes a partnership or domain so "https://www.Example.com/" and
     * "example.com" match: trims, lowercases, drops the protocol, a leading
     * "www." and trailing slashes. The path is kept, since some partnerships
     * are a section of a bigger site. Null when nothing is left.
     */
    public static function normalizeDomainKey(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $key);
        $key = preg_replace('#^www\.#', '', $key);
        $key = rtrim($key, '/');

        return $key === '' ? null : mb_substr($key, 0, 255);
    }

    public function hasMetrics(): bool
    {
        return $this->metrics_synced_at !== null;
    }

    public function isListedPartner(): bool
    {
        return $this->partner_synced_at !== null;
    }
}
