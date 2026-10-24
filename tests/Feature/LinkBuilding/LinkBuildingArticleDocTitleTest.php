<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkBuildingArticleDocTitleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/admin/link-building-orders/article-doc-title';

    private const DOC_ID = '1Q0BbC9es8daEQBWL2Qvkll19lUhIoZ8e';

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function docPage(string $title): string
    {
        return '<html><head><title>' . $title . ' - Google Docs</title>'
            . '<meta property="og:title" content="' . $title . '"></head><body></body></html>';
    }

    private function lookup(string $url)
    {
        return $this->actingAs($this->admin)->getJson(self::BASE_URL . '?url=' . urlencode($url));
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_returns_the_title_of_a_public_google_doc(): void
    {
        Http::fake([
            'docs.google.com/document/d/' . self::DOC_ID . '/edit' => Http::response($this->docPage('6 Ways To Take Care of Yourself &amp; Others.docx')),
        ]);

        $this->lookup('https://docs.google.com/document/d/' . self::DOC_ID . '/edit#heading=h.j03i5j3bs68n')
            ->assertOk()
            ->assertJsonPath('data.document_id', self::DOC_ID)
            ->assertJsonPath('data.kind', 'document')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.title', '6 Ways To Take Care of Yourself & Others.docx');
    }

    public function test_caches_the_title_per_document(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::response($this->docPage('Cached title')),
        ]);

        $this->lookup('https://docs.google.com/document/d/' . self::DOC_ID . '/edit')->assertJsonPath('data.title', 'Cached title');
        // Same file, different link shape: answered from the cache.
        $this->lookup('https://docs.google.com/document/u/0/d/' . self::DOC_ID . '/edit?usp=sharing')->assertJsonPath('data.title', 'Cached title');

        Http::assertSentCount(1);
    }

    public function test_reports_a_private_doc_without_following_the_sign_in_redirect(): void
    {
        Http::fake([
            'docs.google.com/*' => Http::response('', 302, ['Location' => 'https://accounts.google.com/ServiceLogin']),
        ]);

        $this->lookup('https://docs.google.com/document/d/' . self::DOC_ID . '/edit')
            ->assertOk()
            ->assertJsonPath('data.status', 'private')
            ->assertJsonPath('data.title', null);
    }

    public function test_reads_drive_file_links(): void
    {
        Http::fake([
            'drive.google.com/file/d/' . self::DOC_ID . '/view' => Http::response($this->docPage('Drive article.docx')),
        ]);

        $this->lookup('https://drive.google.com/file/d/' . self::DOC_ID . '/view?usp=sharing')
            ->assertOk()
            ->assertJsonPath('data.kind', 'file')
            ->assertJsonPath('data.title', 'Drive article.docx');
    }

    public function test_does_not_request_links_outside_google(): void
    {
        Http::fake();

        $this->lookup('https://example.com/document/d/' . self::DOC_ID . '/edit')
            ->assertOk()
            ->assertJsonPath('data.status', 'unsupported')
            ->assertJsonPath('data.document_id', null);

        $this->lookup('https://docs.google.com.evil.test/document/d/' . self::DOC_ID . '/edit')
            ->assertJsonPath('data.status', 'unsupported');

        Http::assertNothingSent();
    }

    public function test_requires_a_url(): void
    {
        $this->actingAs($this->admin)->getJson(self::BASE_URL)->assertStatus(422);
    }

    public function test_clients_cannot_look_up_titles(): void
    {
        $this->actingAs($this->client)
            ->getJson(self::BASE_URL . '?url=' . urlencode('https://docs.google.com/document/d/' . self::DOC_ID . '/edit'))
            ->assertStatus(403);
    }
}
