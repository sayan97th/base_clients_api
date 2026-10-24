<?php

namespace Tests\Unit\LinkBuilding\History;

use App\Models\LinkBuildingOrderPlacement;
use App\Services\LinkBuildingOrderHistoryRecorder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How the edit history compares and stores cell values. sameValue() decides both
 * what counts as a change worth recording and, on restore, whether a cell was
 * edited again since (a conflict), so it must treat the different ways the same
 * value comes back from the database as equal.
 */
class HistoryRecorderValuesTest extends TestCase
{
    public static function sameValueProvider(): array
    {
        return [
            'equal text'                    => ['seo', 'seo', true],
            'different text'                => ['seo', 'SEO', false],
            'number and numeric text'       => [40, '40', true],
            'boolean and its database int'  => [true, 1, true],
            'false and zero'                => [false, 0, true],
            'false and "0"'                 => [false, '0', true],
            'true and false'                => [true, false, false],
            'null and null'                 => [null, null, true],
            'null and empty text'           => [null, '', false],
            'empty formulas and none'       => [[], null, true],
            'same formulas'                 => [['a' => '=1'], ['a' => '=1'], true],
            'different formulas'            => [['a' => '=1'], ['a' => '=2'], false],
            'formulas and none'             => [['a' => '=1'], null, false],
        ];
    }

    #[DataProvider('sameValueProvider')]
    public function test_same_value(mixed $a, mixed $b, bool $expected): void
    {
        $this->assertSame($expected, LinkBuildingOrderHistoryRecorder::sameValue($a, $b));
        $this->assertSame($expected, LinkBuildingOrderHistoryRecorder::sameValue($b, $a), 'The comparison is symmetric.');
    }

    public function test_every_editable_column_is_tracked(): void
    {
        $tracked = LinkBuildingOrderHistoryRecorder::trackedColumns();

        foreach (['order_id', 'status', 'client', 'keyword', 'landing_page', 'exact_match', 'final_price', 'cell_formulas', 'user_id', 'assigned_admin_user_id'] as $column) {
            $this->assertContains($column, $tracked);
        }

        // Derived lookup keys are recalculated from Partnership and Landing Page, not edited.
        $this->assertNotContains('partnership_key', $tracked);
        $this->assertNotContains('landing_page_key', $tracked);
    }

    public function test_attributes_are_stored_in_a_json_safe_shape(): void
    {
        $placement = new LinkBuildingOrderPlacement([
            'exact_match'    => 1,
            'completed_date' => Carbon::parse('2026-10-15 13:45:00'),
            'cell_formulas'  => ['final_price' => '=[current_price:~0]*1.15'],
        ]);

        $attributes = LinkBuildingOrderHistoryRecorder::attributesOf($placement);

        $this->assertTrue($attributes['exact_match']);
        $this->assertSame('2026-10-15 13:45:00', $attributes['completed_date']);
        $this->assertSame(['final_price' => '=[current_price:~0]*1.15'], $attributes['cell_formulas']);
        $this->assertSame(array_values(LinkBuildingOrderHistoryRecorder::trackedColumns()), array_keys($attributes));
    }
}
