<?php

namespace App\Services;

use App\Models\ImportedPortalResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PortalResourceImportService
{
    public function import(string $reference): array
    {
        $recordId = $this->extractRecordId($reference);
        $document = $this->fetchPortalRecord($recordId);
        $document = $this->sanitizeDocument($document);

        $result = $this->putRecord($recordId, $document);

        return [
            'id' => $recordId,
            'identifier' => $document['identifier'] ?? null,
            'resourceType' => $document['resourceType'] ?? null,
            'title' => $document['title']['text'] ?? null,
            'result' => $result['result'] ?? 'unknown',
        ];
    }

    public function remove(string $reference): void
    {
        $recordId = $this->extractRecordId($reference);

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->send('DELETE', $this->opensearchBaseUrl().'/'.$this->recordsIndex().'/_doc/'.rawurlencode($recordId).'?refresh=wait_for');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenSearch is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException("OpenSearch delete failed for {$recordId}: ".$response->body());
        }
    }

    /**
     * @param  array<string, int|null>  $importedByOverrides
     */
    public function syncProjection(array $importedByOverrides = []): int
    {
        $now = Carbon::now();
        $existingRecords = ImportedPortalResource::query()
            ->get(['record_id', 'imported_by', 'imported_at', 'created_at'])
            ->filter(fn (ImportedPortalResource $record): bool => filled($record->record_id))
            ->keyBy('record_id');

        $records = [];

        foreach ($this->fetchLinkedDocuments() as $document) {
            $recordId = (string) ($document['id'] ?? '');

            if ($recordId === '') {
                continue;
            }

            $sourcePortal = (string) ($document['sourcePortal'] ?? $this->portalBaseUrl());
            $existingRecord = $existingRecords->get($recordId);
            $hasImportOverride = array_key_exists($recordId, $importedByOverrides);

            $records[] = [
                'source_reference' => $sourcePortal.'/resource/'.$recordId,
                'record_id' => $recordId,
                'identifier' => $document['identifier'] ?? null,
                'status' => 'linked',
                'resource_type' => $document['resourceType'] ?? null,
                'title' => $document['title']['text'] ?? null,
                'error_message' => null,
                'imported_by' => $hasImportOverride
                    ? $importedByOverrides[$recordId]
                    : $existingRecord?->imported_by,
                'imported_at' => $hasImportOverride
                    ? $now
                    : ($existingRecord?->imported_at ?? $now),
                'created_at' => $existingRecord?->created_at ?? $now,
                'updated_at' => $now,
            ];
        }

        ImportedPortalResource::query()->delete();

        if ($records !== []) {
            ImportedPortalResource::query()->insert($records);
        }

        return count($records);
    }

    public function extractRecordId(string $reference): string
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new RuntimeException('Empty resource reference.');
        }

        if (preg_match('~/resource/([^/?#]+)~', $reference, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~/api/getRecord/([^/?#]+)~', $reference, $matches) === 1) {
            return $matches[1];
        }

        return $reference;
    }

    private function fetchPortalRecord(string $recordId): array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->get($this->portalBaseUrl().'/api/getRecord/'.$recordId);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Portal API is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException("Portal API request failed for {$recordId}: ".$response->body());
        }

        return $response->json() ?? [];
    }

    private function putRecord(string $recordId, array $document): array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withBody(json_encode($document, JSON_UNESCAPED_SLASHES), 'application/json')
                ->send('PUT', $this->opensearchBaseUrl().'/'.$this->recordsIndex().'/_doc/'.rawurlencode($recordId).'?refresh=wait_for');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenSearch is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException("OpenSearch write failed for {$recordId}: ".$response->body());
        }

        return $response->json() ?? [];
    }

    private function sanitizeDocument(array $document): array
    {
        foreach (['id', 'similar', 'nearby', 'collection', 'partOf', 'isAboutResource', 'periodo'] as $transientField) {
            unset($document[$transientField]);
        }

        $document['importSource'] = 'ariadne-portal-api';
        $document['sourcePortal'] = $this->portalBaseUrl();

        return $document;
    }

    private function portalBaseUrl(): string
    {
        return rtrim((string) env('ARIADNE_PORTAL_SOURCE_URL', 'https://portal.ariadne-infrastructure.eu'), '/');
    }

    private function opensearchBaseUrl(): string
    {
        return rtrim((string) env('OPENSEARCH_URL', 'http://127.0.0.1:9200'), '/');
    }

    private function recordsIndex(): string
    {
        return (string) env('OPENSEARCH_RECORDS_INDEX', 'ariadne_portal');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLinkedDocuments(): array
    {
        $documents = [];
        $searchAfter = null;

        do {
            $payload = [
                'size' => 250,
                '_source' => ['identifier', 'resourceType', 'title', 'importSource', 'sourcePortal'],
                'sort' => [['_id' => 'asc']],
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['importSource' => 'ariadne-portal-api']],
                        ],
                    ],
                ],
            ];

            if ($searchAfter !== null) {
                $payload['search_after'] = [$searchAfter];
            }

            try {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES), 'application/json')
                    ->send('POST', $this->opensearchBaseUrl().'/'.$this->recordsIndex().'/_search');
            } catch (ConnectionException $exception) {
                throw new RuntimeException('OpenSearch is unreachable: '.$exception->getMessage(), previous: $exception);
            }

            if ($response->failed()) {
                throw new RuntimeException('OpenSearch projection sync failed: '.$response->body());
            }

            $hits = $response->json('hits.hits') ?? [];

            foreach ($hits as $hit) {
                $source = $hit['_source'] ?? [];
                $source['id'] = $hit['_id'] ?? null;
                $documents[] = $source;
            }

            $lastHit = end($hits);
            $searchAfter = $lastHit['sort'][0] ?? null;
        } while (! empty($hits));

        return $documents;
    }
}
