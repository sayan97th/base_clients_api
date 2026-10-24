<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class LinkBuildingOrderPlacement extends Model
{
    use HasUuids;

    protected $fillable = [
        // Original placement-system fields
        'order_item_id',
        'row_index',
        'keyword',
        'landing_page',
        'exact_match',
        'live_link',
        'dr',
        'completed_date',
        // Dashboard fields (matching backlink_orders schema)
        'order_id',
        'status',
        'team_specific_link_id',
        'link_type',
        'client',
        'notes',
        'internal_notes',
        'request_date',
        'estimated_delivery_date',
        'estimated_turnaround_days',
        'link_builder_user_id',
        'link_builder',
        'pen_name',
        'partnership',
        'partnership_check',
        'article_title',
        'article',
        'live_link_date',
        'dr_lbs',
        'posting_fee_lbs',
        'current_traffic',
        'dr_formula',
        'current_poc',
        'current_price',
        'lb_tl_approval',
        'approval_date',
        'final_price',
        // Client assignment (admin can assign a standalone placement to a user)
        'user_id',
        // Admin team assignment
        'admin_team_id',
        // Admin user assigned to own this order
        'assigned_admin_user_id',
        // Currency for pricing fields
        'currency',
        // Spreadsheet formulas typed into cells, keyed by column (see FORMULA_COLUMNS)
        'cell_formulas',
    ];

    /**
     * Columns whose dashboard cells may hold a spreadsheet formula. The column keeps
     * the computed value, the formula is kept in cell_formulas. Status, Exact Match,
     * Currency, Order ID and the URL columns are left out on purpose: a recalculated
     * status would send client emails, and the others only accept fixed values.
     */
    public const FORMULA_COLUMNS = [
        'team_specific_link_id', 'client', 'keyword', 'notes', 'internal_notes',
        'request_date', 'estimated_delivery_date', 'article_title', 'live_link_date',
        'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula', 'current_poc',
        'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
    ];

    public const MAX_CELL_FORMULA_LENGTH = 2000;

    /** Pricing columns stored as money text, such as "$1,250.00", see formatPrice(). */
    public const PRICE_COLUMNS = ['posting_fee_lbs', 'current_price', 'final_price'];

    /** Symbol each dashboard currency is shown with. */
    public const CURRENCY_SYMBOLS = ['USD' => '$', 'EUR' => '€'];

    /**
     * Price text the way the BASE link sheet shows it: "150", "1250.5" or "$1,250.5"
     * become "$150.00" and "$1,250.50", using the symbol of the given currency. With
     * no currency, a symbol typed with the amount is kept and "$" is used otherwise.
     * Text that is not a plain amount ("TBD", "$50 to $100", "10%") is kept as typed.
     */
    public static function formatPrice(?string $value, ?string $currency = null): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return $value;
        }

        $is_negative = false;
        if ($text[0] === '-' || $text[0] === '+') {
            $is_negative = $text[0] === '-';
            $text        = ltrim(substr($text, 1));
        }

        $typed_symbol = null;
        if (preg_match('/^([$€£])\s*/u', $text, $match)) {
            $typed_symbol = $match[1];
            $text         = substr($text, strlen($match[0]));
        }

        if (! $is_negative && str_starts_with($text, '-')) {
            $is_negative = true;
            $text        = ltrim(substr($text, 1));
        }

        // Thousands separators only count when they group digits by three.
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d*)?$/', $text)) {
            $text = str_replace(',', '', $text);
        }

        if (! preg_match('/^(\d+\.?\d*|\.\d+)$/', $text)) {
            return $value;
        }

        $amount = round((float) $text, 2);
        $symbol = self::CURRENCY_SYMBOLS[strtoupper((string) $currency)] ?? $typed_symbol ?? '$';

        return ($is_negative && $amount > 0 ? '-' : '') . $symbol . number_format($amount, 2);
    }

    /**
     * Recent use of this row's domain for its landing page, set by
     * LinkBuildingDomainUsageService::annotate() before the row is sent to the
     * dashboard. Null when the domain was not used for that page recently, or
     * when the row was not annotated.
     *
     * @var array{count: int, last_order_id: string, last_request_date: string, window_months: int}|null
     */
    protected ?array $recent_domain_usage = null;

    /**
     * Domain of a Partnership value, so "https://www.Site.com/blog/" and "site.com"
     * count as the same site: lowercased, without scheme, "www.", path, query or
     * fragment. Null when there is no domain.
     */
    public static function partnershipKey(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $key);
        $key = preg_replace('#^www\.#', '', $key);
        $key = rtrim(preg_split('#[/?\#]#', $key, 2)[0], '.');

        return $key === '' ? null : mb_substr($key, 0, 255);
    }

    /**
     * Landing Page URL in a comparable form, so "https://www.site.com/page/?utm=x"
     * and "site.com/page" count as the same page: lowercased, without scheme,
     * "www.", query, fragment or trailing slash. Null when there is no URL.
     */
    public static function landingPageKey(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        $key = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $key);
        $key = preg_replace('#^www\.#', '', $key);
        $key = rtrim(preg_split('#[?\#]#', $key, 2)[0], '/');

        return $key === '' ? null : mb_substr($key, 0, 255);
    }

    /**
     * The partnership_key and landing_page_key columns for the given values. Bulk
     * writes that bypass the model (imports, migrations) use it to keep them in sync.
     *
     * @return array{partnership_key: ?string, landing_page_key: ?string}
     */
    public static function usageKeysFor(?string $partnership, ?string $landing_page): array
    {
        return [
            'partnership_key'  => self::partnershipKey($partnership),
            'landing_page_key' => self::landingPageKey($landing_page),
        ];
    }

    protected static function booted(): void
    {
        // The normalized keys follow the Partnership and Landing Page they are built
        // from, so the recent domain usage lookup always matches the current values.
        static::saving(function (self $placement) {
            if ($placement->isDirty('partnership')) {
                $placement->partnership_key = self::partnershipKey($placement->partnership);
            }

            if ($placement->isDirty('landing_page')) {
                $placement->landing_page_key = self::landingPageKey($placement->landing_page);
            }
        });

        // Prices are saved with the symbol of the row's currency, so a typed "150"
        // is stored as "$150.00", and switching the currency updates every price.
        static::saving(function (self $placement) {
            $columns = $placement->isDirty('currency')
                ? self::PRICE_COLUMNS
                : array_filter(self::PRICE_COLUMNS, fn (string $column) => $placement->isDirty($column));

            foreach ($columns as $column) {
                $placement->{$column} = self::formatPrice($placement->{$column}, $placement->currency ?? 'USD');
            }
        });
    }

    /** Bounds of the Estimated Turnaround Time (Days) an admin may enter on the dashboard. */
    public const MIN_TURNAROUND_DAYS = 1;
    public const MAX_TURNAROUND_DAYS = 365;

    /**
     * Estimated delivery date (MM/DD/YYYY) for a request date and a turnaround, the
     * same rule as the BASE link sheet: Delivery Date = Request Date + Turnaround Days.
     * Null when the request date is not a valid date or the turnaround is not a
     * positive whole number of days.
     */
    public static function deliveryDateFor(?string $request_date, mixed $turnaround_days): ?string
    {
        $turnaround_days = trim((string) $turnaround_days);

        if (blank($request_date) || ! ctype_digit($turnaround_days) || (int) $turnaround_days <= 0) {
            return null;
        }

        try {
            $base_date = Carbon::createFromFormat('!m/d/Y', trim($request_date));
        } catch (\Exception) {
            return null;
        }

        return $base_date->addDays((int) $turnaround_days)->format('m/d/Y');
    }

    /**
     * SQL that drops the formulas of the given columns from cell_formulas, for bulk
     * writes (imports) that bypass the model: a value written over a formula cell
     * replaces the formula, like pasting values in a spreadsheet. Null when none of
     * the columns can hold a formula. JSON_REMOVE works on MySQL and SQLite alike.
     */
    public static function removeCellFormulasExpression(array $columns): ?Expression
    {
        $formula_columns = array_values(array_intersect(self::FORMULA_COLUMNS, $columns));
        if (empty($formula_columns)) {
            return null;
        }

        // Safe to inline: the paths come from the FORMULA_COLUMNS constant, never from input.
        $paths = implode(', ', array_map(fn (string $column) => "'$.{$column}'", $formula_columns));

        return DB::raw("JSON_REMOVE(cell_formulas, {$paths})");
    }

    protected function casts(): array
    {
        return [
            'row_index'      => 'integer',
            'exact_match'    => 'boolean',
            'dr'             => 'integer',
            'completed_date' => 'datetime',
            'cell_formulas'  => 'array',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderItem::class, 'order_item_id');
    }

    public function reportRow(): HasOne
    {
        return $this->hasOne(OrderReportRow::class, 'order_placement_id');
    }

    public function linkBuilderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_builder_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adminTeam(): BelongsTo
    {
        return $this->belongsTo(AdminTeam::class, 'admin_team_id');
    }

    public function assignedAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_user_id');
    }

    /** Sets the recent domain usage sent with this row, see LinkBuildingDomainUsageService. */
    public function setRecentDomainUsage(?array $usage): static
    {
        $this->recent_domain_usage = $usage;

        return $this;
    }

    /**
     * Generates the next sequential BL-{n} order_id by querying the current maximum
     * across all placements. Used by dashboard creation and cart checkout controllers
     * so every placement receives a consistent human-readable identifier.
     */
    public static function generateNextOrderId(): string
    {
        $max_num = static::whereNotNull('order_id')
            ->whereRaw("order_id REGEXP '^BL-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(order_id, 4) AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($max_num === null ? 0 : (int) $max_num) + 1;

        return 'BL-' . $next;
    }

    /**
     * Returns the full API row shape for the admin dashboard, including computed
     * fields days_left and projected_health. The exact_match boolean is converted
     * to a "Yes"/"No" string to match the frontend's editable select field.
     *
     * For client-purchased placements (order_item_id set, no order_id):
     * - order_id is derived from the placement UUID as a BL- prefixed fallback
     * - client is derived from the purchase order's user name when not set directly
     */
    public function toApiArray(): array
    {
        [$days_left, $projected_health] = $this->computeDeliveryMetrics();

        // Derive display order_id for client-purchased placements that don't have one.
        // Uses placement UUID to guarantee uniqueness. Saved permanently on first admin edit.
        $order_id = $this->displayOrderId();

        // Derive company name from linked purchase order user when not set directly.
        // Company is the primary identifier; falls back to empty string if unset.
        $client = $this->client ?? '';
        if ($client === '' && $this->relationLoaded('orderItem')) {
            $purchase_user = $this->orderItem?->order?->user;
            if ($purchase_user) {
                $client = trim($purchase_user->company ?? '');
            }
        }
        if ($client === '' && $this->relationLoaded('user') && $this->user) {
            $client = trim($this->user->company ?? '');
        }

        return [
            'id'                        => $this->id,
            'order_id'                  => $order_id,
            'team_specific_link_id'     => $this->team_specific_link_id ?? '',
            'link_type'                 => $this->link_type ?? '',
            'client'                    => $client,
            'keyword'                   => $this->keyword ?? '',
            'landing_page'              => $this->landing_page ?? '',
            'exact_match'               => $this->exact_match ? 'Yes' : 'No',
            'notes'                     => $this->notes ?? '',
            'internal_notes'            => $this->internal_notes ?? '',
            'request_date'              => $this->request_date ?? '',
            'estimated_delivery_date'   => $this->estimated_delivery_date ?? '',
            'estimated_turnaround_days' => (string) ($this->estimated_turnaround_days ?? ''),
            'days_left'                 => $days_left,
            'projected_health'          => $projected_health,
            'link_builder'              => $this->link_builder ?? '',
            'pen_name'                  => $this->pen_name ?? '',
            'partnership'               => $this->partnership ?? '',
            'partnership_check'         => $this->partnership_check ?? '',
            'article_title'             => $this->article_title ?? '',
            'article'                   => $this->article ?? '',
            'status'                    => $this->status ?? 'New Request',
            'live_link'                 => $this->live_link ?? '',
            'live_link_date'            => $this->live_link_date ?? '',
            'dr_lbs'                    => $this->dr_lbs ?? '',
            'posting_fee_lbs'           => $this->posting_fee_lbs ?? '',
            'current_traffic'           => $this->current_traffic ?? '',
            'dr_formula'                => $this->dr_formula ?? '',
            'current_poc'               => $this->current_poc ?? '',
            'current_price'             => $this->current_price ?? '',
            'lb_tl_approval'            => $this->lb_tl_approval ?? '',
            'approval_date'             => $this->approval_date ?? '',
            'final_price'               => $this->final_price ?? '',
            'currency'                  => $this->currency ?? 'USD',
            'cell_formulas'             => empty($this->cell_formulas) ? null : $this->cell_formulas,
            'recent_domain_usage'       => $this->recent_domain_usage,
            'user_id'                       => $this->user_id,
            'admin_team_id'                 => $this->admin_team_id,
            'admin_team_name'               => $this->relationLoaded('adminTeam') ? ($this->adminTeam?->name ?? null) : null,
            'admin_team_color'              => $this->relationLoaded('adminTeam') ? ($this->adminTeam?->color ?? null) : null,
            'assigned_admin_user_id'        => $this->assigned_admin_user_id,
            'assigned_admin_user_name'      => $this->relationLoaded('assignedAdminUser')
                ? ($this->assignedAdminUser
                    ? trim(($this->assignedAdminUser->first_name ?? '') . ' ' . ($this->assignedAdminUser->last_name ?? ''))
                    : null)
                : null,
            'assigned_admin_user_avatar'    => $this->relationLoaded('assignedAdminUser')
                ? ($this->assignedAdminUser?->profile_photo_url ?? null)
                : null,
            'parent_order_status'           => $this->relationLoaded('orderItem') ? ($this->orderItem?->order?->status ?? null) : null,
            'created_at'                    => $this->created_at?->toIso8601String(),
            'updated_at'                    => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Order ID shown on the dashboard: the stored one, or the derived fallback below. */
    public function displayOrderId(): string
    {
        return $this->order_id ?? $this->derivedOrderId();
    }

    /**
     * Fallback display order_id for legacy placements that pre-date sequential assignment.
     * Format: BL-{first 10 hex chars of UUID uppercased}
     * New placements always receive a proper BL-{n} sequential id at creation time.
     */
    private function derivedOrderId(): string
    {
        return 'BL-' . strtoupper(substr(str_replace('-', '', $this->id), 0, 10));
    }

    /**
     * Computes days_left and projected_health from the stored date strings.
     *
     * @return array{string, string}  [$days_left, $projected_health]
     */
    private function computeDeliveryMetrics(): array
    {
        if (empty($this->estimated_delivery_date)) {
            return ['', ''];
        }

        try {
            $delivery_date = Carbon::createFromFormat('m/d/Y', $this->estimated_delivery_date)->startOfDay();
        } catch (\Exception) {
            return ['', ''];
        }

        $days_left  = (int) Carbon::today()->diffInDays($delivery_date, false);
        $turnaround = (int) ($this->estimated_turnaround_days ?? 0);

        if ($turnaround <= 0) {
            return [(string) $days_left, ''];
        }

        $projected_health = (int) round(($days_left / $turnaround) * 100);

        return [(string) $days_left, $projected_health . '%'];
    }
}
