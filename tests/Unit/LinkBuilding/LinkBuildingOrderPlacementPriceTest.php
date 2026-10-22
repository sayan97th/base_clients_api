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
        ];
    }

    #[DataProvider('textProvider')]
    public function test_text_that_is_not_an_amount_is_kept_as_typed(?string $value): void
    {
        $this->assertSame($value, LinkBuildingOrderPlacement::formatPrice($value, 'USD'));
    }
}
