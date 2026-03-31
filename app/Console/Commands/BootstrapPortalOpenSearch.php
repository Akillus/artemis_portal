<?php

namespace App\Console\Commands;

use App\Services\PortalOpenSearchBootstrapService;
use Illuminate\Console\Command;

class BootstrapPortalOpenSearch extends Command
{
    protected $signature = 'portal:bootstrap-opensearch
        {--fresh : Recreate the managed OpenSearch indices before seeding static data}';

    protected $description = 'Create the OpenSearch indices required by the portal and seed static reference data.';

    public function handle(PortalOpenSearchBootstrapService $service): int
    {
        $result = $service->bootstrap((bool) $this->option('fresh'));

        $this->components->info('OpenSearch bootstrap completed.');
        $this->line('Created indices: '.($result['created'] !== [] ? implode(', ', $result['created']) : 'none'));
        $this->line('Existing indices kept: '.($result['skipped'] !== [] ? implode(', ', $result['skipped']) : 'none'));
        $this->line('Static data seeded: '.($result['seeded'] !== [] ? implode(', ', $result['seeded']) : 'none'));

        return self::SUCCESS;
    }
}
