<?php

namespace Tests\Unit\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prices on the admin "Link Building Orders" table are saved the way the BASE
 * link sheet shows them, with the currency symbol and two decimals.
 */
class LinkBuildingOrderPlacementPriceTest extends TestCase
{
    public static function amountProvider(): array
    {
        return [
            'whole number'            => ['150', 'USD', '$150.00'],
            'one decimal'             => ['1250.5', 'USD', '$1,250.50'],
            'already formatted'       => ['$1,250.00', 'USD', '$1,250.00'],
            'symbol without decimals' => ['$50', 'USD', '$50.00'],
            'rounded to cents'        => ['12.345', 'USD', '$12.35'],
            'leading decimal point'   => ['.5', 'USD', '$0.50'],
            'surrounding spaces'      => ['  75  ', 'USD', '$75.00'],
            'negative before symbol'  => ['-$20', 'USD', '-$20.00'],
            'negative after symbol'   => ['$-20', 'USD', '-$20.00'],
            'euro row'                => ['99', 'EUR', '€99.00'],
            'currency in lowercase'   => ['99', 'eur', '€99.00'],
            'currency replaces typed' => ['$99', 'EUR', '€99.00'],
            'zero'                    => ['0', 'USD', '$0.00'],
            'a million'               => ['1000000', 'USD', '$1,000,000.00'],
            'grouped thousands'       => ['1,250', 'USD', '$1,250.00'],
            'many decimals'           => ['1250.567', 'USD', '$1,250.57'],
            'plus sign'               => ['+50', 'USD', '$50.00'],
            'space after the symbol'  => ['€ 40', 'USD', '$40.00'],
            'pound on a euro row'     => ['£40', 'EUR', '€40.00'],
            'unknown currency'        => ['40', 'GBP', '$40.00'],
            'negative zero'           => ['-0', 'USD', '$0.00'],
            'rounds to zero'          => ['-0.001', 'USD', '$0.00'],
        ];
    }

    #[DataProvider('amountProvider')]
    public function test_amounts_get_the_currency_symbol_and_two_decimals(string $value, string $currency, string $expected): void
    {
        $this->assertSame($expected, LinkBuildingOrderPlacement::formatPrice($value, $currency));
    }

    public function test_without_a_currency_the_typed_symbol_is_kept(): void
    {
        $this->assertSame('€40.00', LinkBuildingOrderPlacement::formatPrice('€40'));
        $this->assertSame('$40.00', LinkBuildingOrderPlacement::formatPrice('40'));
    }

    public function test_an_unknown_currency_keeps_the_typed_symbol(): void
    {
        $this->assertSame('€40.00', LinkBuildingOrderPlacement::formatPrice('€40', 'GBP'));
    }

    public function test_the_price_columns_match_the_dashboard(): void
    {
        // Kept in sync with PRICE_COLUMN_KEYS in base_portal/src/lib/link-building-prices.ts.
        $this->assertSame(['posting_fee_lbs', 'current_price', 'final_price'], LinkBuildingOrderPlacement::PRICE_COLUMNS);
        $this->assertSame(['USD' => '$', 'EUR' => '€'], LinkBuildingOrderPlacement::CURRENCY_SYMBOLS);
    }

    public static function textProvider(): array
    {
        return [
            'null'                => [null],
            'empty'               => [''],
            'only spaces'         => ['   '],
            'words'               => ['TBD'],
            'range'               => ['$50 to $100'],
            'percent'             => ['10%'],
            'bad thousands group' => ['1,25'],
            'two decimal points'  => ['1.2.3'],
            'exponent'            => ['1e3'],
            'only a symbol'       => ['$'],
            'only a sign'         => ['-'],
            'currency code'       => ['50 USD'],
            'mixed grouping'      => ['12,34.5'],
        ];
    }

    #[DataProvider('textProvider')]
    public function test_text_that_is_not_an_amount_is_kept_as_typed(?string $value): void
    {
        $this->assertSame($value, LinkBuildingOrderPlacement::formatPrice($value, 'USD'));
    }
}
