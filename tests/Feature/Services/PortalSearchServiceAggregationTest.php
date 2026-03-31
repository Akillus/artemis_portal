<?php

namespace Tests\Feature\Services;

use App\Services\PortalSearchService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalSearchServiceAggregationTest extends TestCase
{
    public function test_aggregation_query_keeps_active_filters_combined(): void
    {
        $capturedPayload = null;

        Http::fake(function (ClientRequest $request) use (&$capturedPayload) {
            $this->assertStringEndsWith('/ariadne_portal/_search', $request->url());

            $capturedPayload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response([
                'hits' => [
                    'total' => ['value' => 1, 'relation' => 'eq'],
                    'hits' => [],
                ],
                'aggregations' => [
                    'resourceType' => ['buckets' => []],
                    'publisher' => ['buckets' => []],
                ],
            ]);
        });

        $result = app(PortalSearchService::class)->getSearchAggregationData(
            Request::create('/api/getSearchAggregationData', 'GET', [
                'q' => '',
                'resourceType' => 'dataset',
                'publisher' => 'Hungarian National Museum (HNM)',
            ])
        );

        $this->assertSame(['value' => 1, 'relation' => 'eq'], $result['total']);
        $this->assertIsArray($capturedPayload);
        $this->assertSame(0, $capturedPayload['size'] ?? null);
        $this->assertArrayHasKey('query', $capturedPayload);
        $this->assertArrayHasKey('bool', $capturedPayload['query']);
        $this->assertArrayHasKey('filter', $capturedPayload['query']['bool']);

        $filterGroups = $capturedPayload['query']['bool']['filter']['bool']['must'] ?? [];

        $this->assertCount(2, $filterGroups);
        $this->assertContainsEquals([
            'bool' => [
                'should' => [
                    ['term' => ['resourceType' => 'dataset']],
                ],
            ],
        ], $filterGroups);
        $this->assertContainsEquals([
            'bool' => [
                'should' => [
                    ['term' => ['publisher.name.raw' => 'Hungarian National Museum (HNM)']],
                ],
            ],
        ], $filterGroups);
    }
}
