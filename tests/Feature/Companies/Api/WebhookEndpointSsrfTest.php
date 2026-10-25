<?php

namespace Tests\Feature\Companies\Api;

use App\Models\Company;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression test for GHSA-x9rj-mmg2-wcfv. Before the fix the Company
 * API's $company->fill($request->all()) mass-assigned webhook_endpoint
 * without running the ExternalUrl SSRF rule, so any user with
 * companies.create or companies.edit could point the per-company
 * webhook at loopback / RFC1918 / cloud metadata and the server would
 * POST to that URL on every subsequent asset checkout / checkin for
 * the company. Fix layers webhook_* rules onto Company::$rules so
 * ValidatingTrait fires the ExternalUrl check on every save surface.
 */
class WebhookEndpointSsrfTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function internalTargetProvider(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/attacker'],
            'loopback v6' => ['http://[::1]/attacker'],
            'aws imds' => ['http://169.254.169.254/latest/meta-data/'],
            'gcp metadata' => ['http://metadata.google.internal/computeMetadata/v1/'],
            'rfc1918 10' => ['http://10.0.0.5/attacker'],
            'rfc1918 172' => ['http://172.16.0.1/attacker'],
            'rfc1918 192' => ['http://192.168.1.1/attacker'],
            'non-http scheme ftp' => ['ftp://example.com/attacker'],
            'non-http scheme gopher' => ['gopher://example.com/attacker'],
        ];
    }

    #[DataProvider('internalTargetProvider')]
    public function test_api_store_rejects_internal_or_non_http_webhook_endpoint(string $endpoint): void
    {
        $caller = User::factory()->createCompanies()->create();

        $this->actingAsForApi($caller)
            ->postJson(route('api.companies.store'), [
                'name' => 'SSRF test '.bin2hex(random_bytes(4)),
                'webhook_selected' => 'slack',
                'webhook_endpoint' => $endpoint,
            ])
            ->assertStatusMessageIs('error');

        $this->assertDatabaseMissing('companies', ['webhook_endpoint' => $endpoint]);
    }

    #[DataProvider('internalTargetProvider')]
    public function test_api_update_rejects_internal_or_non_http_webhook_endpoint(string $endpoint): void
    {
        $company = Company::factory()->create(['webhook_endpoint' => null]);
        $caller = User::factory()->editCompanies()->create();

        $this->actingAsForApi($caller)
            ->patchJson(route('api.companies.update', $company), [
                'name' => $company->name,
                'webhook_selected' => 'slack',
                'webhook_endpoint' => $endpoint,
            ])
            ->assertStatusMessageIs('error');

        $this->assertSame(null, $company->refresh()->webhook_endpoint);
    }

    public function test_api_accepts_a_public_https_webhook_endpoint(): void
    {
        $caller = User::factory()->createCompanies()->create();

        $this->actingAsForApi($caller)
            ->postJson(route('api.companies.store'), [
                'name' => 'legit webhook',
                'webhook_selected' => 'slack',
                'webhook_endpoint' => 'https://hooks.slack.com/services/T000/B000/XXXXX',
            ])
            ->assertStatusMessageIs('success');

        $this->assertDatabaseHas('companies', [
            'name' => 'legit webhook',
            'webhook_endpoint' => 'https://hooks.slack.com/services/T000/B000/XXXXX',
        ]);
    }

    public function test_api_rejects_unknown_webhook_selected_value(): void
    {
        $caller = User::factory()->createCompanies()->create();

        $this->actingAsForApi($caller)
            ->postJson(route('api.companies.store'), [
                'name' => 'bogus webhook_selected',
                'webhook_selected' => 'definitely-not-a-real-webhook-type',
                'webhook_endpoint' => 'https://hooks.slack.com/services/T000/B000/XXXXX',
            ])
            ->assertStatusMessageIs('error');

        $this->assertDatabaseMissing('companies', ['name' => 'bogus webhook_selected']);
    }

    public function test_api_accepts_null_webhook_endpoint_so_companies_without_webhooks_still_save(): void
    {
        // Null webhook_endpoint is the common case (most companies do not
        // run a webhook). The rule must not force a non-null value on
        // every company write.
        $caller = User::factory()->createCompanies()->create();

        $this->actingAsForApi($caller)
            ->postJson(route('api.companies.store'), [
                'name' => 'no webhook',
                'webhook_endpoint' => null,
            ])
            ->assertStatusMessageIs('success');
    }
}
