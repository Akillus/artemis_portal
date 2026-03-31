<?php

namespace Tests\Feature\Services;

use App\Models\ImportedPortalResource;
use App\Models\User;
use App\Services\PortalResourceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalResourceImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_projection_reflects_current_opensearch_state(): void
    {
        $user = User::factory()->create();
        $existingImportedAt = Carbon::parse('2026-03-01 10:00:00');
        $existingCreatedAt = Carbon::parse('2026-03-01 09:00:00');

        ImportedPortalResource::query()->create([
            'source_reference' => 'https://portal.ariadne-infrastructure.eu/resource/existing-record',
            'record_id' => 'existing-record',
            'identifier' => 'https://example.test/existing-record',
            'status' => 'linked',
            'resource_type' => 'dataset',
            'title' => 'Existing record',
            'imported_by' => $user->id,
            'imported_at' => $existingImportedAt,
        ])->forceFill([
            'created_at' => $existingCreatedAt,
            'updated_at' => $existingCreatedAt,
        ])->save();

        ImportedPortalResource::query()->create([
            'source_reference' => 'https://portal.ariadne-infrastructure.eu/resource/stale-record',
            'record_id' => 'stale-record',
            'status' => 'linked',
        ]);

        Http::fake([
            'http://127.0.0.1:9200/ariadne_portal/_search' => Http::sequence()
                ->push([
                    'hits' => [
                        'hits' => [
                            [
                                '_id' => 'existing-record',
                                'sort' => ['existing-record'],
                                '_source' => [
                                    'identifier' => 'https://example.test/existing-record',
                                    'resourceType' => 'dataset',
                                    'title' => ['text' => 'Existing record'],
                                    'importSource' => 'ariadne-portal-api',
                                    'sourcePortal' => 'https://portal.ariadne-infrastructure.eu',
                                ],
                            ],
                            [
                                '_id' => 'new-record',
                                'sort' => ['new-record'],
                                '_source' => [
                                    'identifier' => 'https://example.test/new-record',
                                    'resourceType' => 'collection',
                                    'title' => ['text' => 'New record'],
                                    'importSource' => 'ariadne-portal-api',
                                    'sourcePortal' => 'https://portal.ariadne-infrastructure.eu',
                                ],
                            ],
                        ],
                    ],
                ])
                ->push([
                    'hits' => [
                        'hits' => [],
                    ],
                ]),
        ]);

        $count = app(PortalResourceImportService::class)->syncProjection([
            'new-record' => $user->id,
        ]);

        $this->assertSame(2, $count);
        $this->assertDatabaseMissing('imported_portal_resources', [
            'record_id' => 'stale-record',
        ]);
        $this->assertDatabaseHas('imported_portal_resources', [
            'record_id' => 'new-record',
            'status' => 'linked',
            'resource_type' => 'collection',
            'imported_by' => $user->id,
        ]);

        $syncedExisting = ImportedPortalResource::query()->where('record_id', 'existing-record')->firstOrFail();

        $this->assertSame($user->id, $syncedExisting->imported_by);
        $this->assertTrue($existingImportedAt->equalTo($syncedExisting->imported_at));
        $this->assertTrue($existingCreatedAt->equalTo($syncedExisting->created_at));
    }
}
