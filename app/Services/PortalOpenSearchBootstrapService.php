<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PortalOpenSearchBootstrapService
{
    /**
     * @return array{created: list<string>, skipped: list<string>, seeded: list<string>}
     */
    public function bootstrap(bool $fresh = false): array
    {
        $created = [];
        $skipped = [];

        foreach ($this->indices() as $index => $mapping) {
            if ($fresh) {
                $this->deleteIndex($index);
            }

            if ($this->indexExists($index)) {
                $skipped[] = $index;
                continue;
            }

            $this->request('PUT', '/'.$index, $mapping);
            $created[] = $index;
        }

        $seeded = [];

        if ($this->countDocuments($this->servicesIndex()) === 0) {
            $this->seedJsonIndex($this->servicesIndex(), resource_path('opensearch/default-services.json'));
            $seeded[] = $this->servicesIndex();
        }

        if ($this->countDocuments($this->publishersIndex()) === 0) {
            $this->seedJsonIndex($this->publishersIndex(), resource_path('opensearch/default-publishers.json'));
            $seeded[] = $this->publishersIndex();
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'seeded' => $seeded,
        ];
    }

    private function seedJsonIndex(string $index, string $path): void
    {
        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload)) {
            throw new RuntimeException("Invalid JSON seed file: {$path}");
        }

        foreach (array_values($payload) as $offset => $document) {
            if (!is_array($document)) {
                continue;
            }

            $document['id'] = $document['id'] ?? ($offset + 1);

            $this->request(
                'PUT',
                sprintf('/%s/_doc/%s?refresh=wait_for', $index, rawurlencode((string) $document['id'])),
                $document,
            );
        }
    }

    private function countDocuments(string $index): int
    {
        if (!$this->indexExists($index)) {
            return 0;
        }

        return (int) ($this->request('GET', '/'.$index.'/_count')['count'] ?? 0);
    }

    private function indexExists(string $index): bool
    {
        $response = $this->send('HEAD', '/'.$index);

        if ($response['status'] === 200) {
            return true;
        }

        if ($response['status'] === 404) {
            return false;
        }

        throw new RuntimeException("Unexpected OpenSearch response while checking {$index}: HTTP {$response['status']}");
    }

    private function deleteIndex(string $index): void
    {
        $response = $this->send('DELETE', '/'.$index);

        if (!in_array($response['status'], [200, 404], true)) {
            throw new RuntimeException("Unable to delete OpenSearch index {$index}: HTTP {$response['status']} {$response['body']}");
        }
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $response = $this->send($method, $path, $payload);

        if ($response['status'] >= 400) {
            throw new RuntimeException("OpenSearch request failed for {$path}: {$response['body']}");
        }

        return $response['json'];
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withBody($payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '', 'application/json')
                ->send($method, $this->opensearchBaseUrl().$path);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenSearch is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        return [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json() ?? [],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indices(): array
    {
        return [
            $this->recordsIndex() => $this->mainMapping(),
            $this->subjectsIndex() => $this->loadJson(resource_path('opensearch/aat-concepts-mapping.json')),
            $this->periodsIndex() => $this->periodMapping(),
            $this->aatTermDescendantsIndex() => $this->loadJson(resource_path('opensearch/aat-term-descendants-mapping.json')),
            $this->servicesIndex() => $this->loadJson(resource_path('opensearch/services-mapping.json')),
            $this->publishersIndex() => $this->loadJson(resource_path('opensearch/publishers-mapping.json')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON file: {$path}");
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function mainMapping(): array
    {
        return [
            'mappings' => [
                'dynamic' => 'false',
                'properties' => [
                    'issued' => ['type' => 'date'],
                    'identifier' => ['type' => 'keyword'],
                    'resourceType' => ['type' => 'keyword'],
                    'isPartOf' => ['type' => 'keyword'],
                    'title' => [
                        'properties' => [
                            'text' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                            'language' => ['type' => 'keyword'],
                        ],
                    ],
                    'description' => [
                        'properties' => [
                            'text' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                            'language' => ['type' => 'keyword'],
                        ],
                    ],
                    'publisher' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'contributor' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'creator' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'owner' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'responsible' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'country' => [
                        'properties' => [
                            'name' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'dataType' => [
                        'properties' => [
                            'label' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'ariadneSubject' => [
                        'properties' => [
                            'prefLabel' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'nativeSubject' => [
                        'properties' => [
                            'prefLabel' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'derivedSubject' => [
                        'properties' => [
                            'id' => ['type' => 'keyword'],
                            'prefLabel' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'is_about' => [
                        'properties' => [
                            'uri' => ['type' => 'keyword'],
                        ],
                    ],
                    'spatial' => [
                        'type' => 'nested',
                        'properties' => [
                            'placeName' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                            'geopoint' => ['type' => 'geo_point'],
                            'centroid' => ['type' => 'geo_point'],
                            'polygon' => ['type' => 'geo_shape'],
                            'boundingbox' => ['type' => 'geo_shape'],
                        ],
                    ],
                    'temporal' => [
                        'type' => 'nested',
                        'properties' => [
                            'periodName' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                            'uri' => ['type' => 'keyword'],
                            'from' => ['type' => 'integer'],
                            'until' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodMapping(): array
    {
        return [
            'mappings' => [
                'dynamic' => 'false',
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'label' => ['type' => 'text'],
                    'authority' => ['type' => 'text'],
                    'languageTag' => ['type' => 'keyword'],
                    'localizedLabels' => [
                        'type' => 'nested',
                        'properties' => [
                            'language' => ['type' => 'keyword'],
                            'label' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'spatialCoverage' => [
                        'properties' => [
                            'id' => ['type' => 'keyword'],
                            'label' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]],
                        ],
                    ],
                    'start' => [
                        'properties' => [
                            'year' => ['type' => 'integer'],
                            'label' => ['type' => 'text'],
                        ],
                    ],
                    'stop' => [
                        'properties' => [
                            'year' => ['type' => 'integer'],
                            'label' => ['type' => 'text'],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
                    'timestamp' => ['type' => 'long'],
                ],
            ],
        ];
    }

    private function opensearchBaseUrl(): string
    {
        return rtrim((string) env('OPENSEARCH_URL', 'http://127.0.0.1:9200'), '/');
    }

    private function recordsIndex(): string
    {
        return (string) env('OPENSEARCH_RECORDS_INDEX', 'ariadne_portal');
    }

    private function subjectsIndex(): string
    {
        return (string) env('OPENSEARCH_SUBJECTS_INDEX', 'ariadne_subjects');
    }

    private function periodsIndex(): string
    {
        return (string) env('OPENSEARCH_PERIODS_INDEX', 'ariadne_periods');
    }

    private function aatTermDescendantsIndex(): string
    {
        return (string) env('OPENSEARCH_AAT_DESCENDANTS_INDEX', 'ariadne_aat_term_descendants');
    }

    private function servicesIndex(): string
    {
        return (string) env('OPENSEARCH_SERVICES_INDEX', 'ariadne_services');
    }

    private function publishersIndex(): string
    {
        return (string) env('OPENSEARCH_PUBLISHERS_INDEX', 'ariadne_publishers');
    }
}
