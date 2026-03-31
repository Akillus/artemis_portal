<?php

namespace App\Console\Commands;

use App\Services\PortalResourceImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportPortalResource extends Command
{
    protected $signature = 'portal:import-resource
        {reference* : One or more ARIADNE portal resource URLs or raw IDs}';

    protected $description = 'Import one or more ARIADNE portal resources into the local OpenSearch index.';

    public function handle(PortalResourceImportService $importer): int
    {
        $rows = [];
        $failures = 0;

        foreach (array_values(array_unique((array) $this->argument('reference'))) as $reference) {
            try {
                $result = $importer->import((string) $reference);
                $rows[] = [
                    'reference' => (string) $reference,
                    'id' => $result['id'] ?? '',
                    'result' => $result['result'] ?? 'unknown',
                    'type' => $result['resourceType'] ?? '',
                    'title' => $result['title'] ?? '',
                ];
            } catch (Throwable $exception) {
                $failures++;
                $rows[] = [
                    'reference' => (string) $reference,
                    'id' => '',
                    'result' => 'failed',
                    'type' => '',
                    'title' => $exception->getMessage(),
                ];
            }
        }

        $this->table(['reference', 'id', 'result', 'type', 'title'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
