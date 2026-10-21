<?php

namespace Tests\Unit\LinkBuilding;

use App\Models\LinkBuildingPartnerMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The domain key decides which Link Table rows a reference domain updates, so
 * every way a Partnership is typed or stored has to land on the same key.
 */
class LinkBuildingPartnerMetricTest extends TestCase
{
    public static function equivalentDomainProvider(): array
    {
        return [
            'bare domain'                      => ['techkaya.com'],
            'https, as saved by the dashboard' => ['https://techkaya.com'],
            'http'                             => ['http://techkaya.com'],
            'www prefix'                       => ['www.techkaya.com'],
            'protocol and www'                 => ['https://www.techkaya.com'],
            'trailing slash'                   => ['techkaya.com/'],
            'several trailing slashes'         => ['https://techkaya.com///'],
            'uppercase'                        => ['TechKaya.COM'],
            'surrounding spaces'               => ['  https://www.TechKaya.com/  '],
        ];
    }

    #[DataProvider('equivalentDomainProvider')]
    public function test_equivalent_partnership_values_share_one_key(string $value): void
    {
        $this->assertSame('techkaya.com', LinkBuildingPartnerMetric::normalizeDomainKey($value));
    }

    public function test_subdomains_other_than_www_are_kept(): void
    {
        $this->assertSame('blog.storymirror.com', LinkBuildingPartnerMetric::normalizeDomainKey('https://blog.storymirror.com'));
    }

    public function test_the_path_is_kept_so_site_sections_stay_distinct(): void
    {
        $this->assertSame('medium.com/@writer', LinkBuildingPartnerMetric::normalizeDomainKey('https://medium.com/@writer/'));
    }

    public function test_www_is_only_removed_as_a_prefix(): void
    {
        $this->assertSame('mywww.example.com', LinkBuildingPartnerMetric::normalizeDomainKey('mywww.example.com'));
    }

    public static function emptyValueProvider(): array
    {
        return [
            'null'          => [null],
            'empty string'  => [''],
            'only spaces'   => ['   '],
            'only protocol' => ['https://'],
            'only slashes'  => ['//'],
        ];
    }

    #[DataProvider('emptyValueProvider')]
    public function test_values_without_a_domain_have_no_key(?string $value): void
    {
        $this->assertNull(LinkBuildingPartnerMetric::normalizeDomainKey($value));
    }

    public function test_key_is_capped_to_the_column_length(): void
    {
        $key = LinkBuildingPartnerMetric::normalizeDomainKey(str_repeat('a', 300) . '.com');

        $this->assertSame(255, mb_strlen($key));
    }

    public function test_reference_state_helpers_follow_the_sync_timestamps(): void
    {
        $reference = new LinkBuildingPartnerMetric();
        $this->assertFalse($reference->hasMetrics());
        $this->assertFalse($reference->isListedPartner());

        $reference->metrics_synced_at = '2026-10-01 10:00:00';
        $reference->partner_synced_at = '2026-10-01 10:00:00';
        $this->assertTrue($reference->hasMetrics());
        $this->assertTrue($reference->isListedPartner());
    }
}
