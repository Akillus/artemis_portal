<x-filament-panels::page>
    <form wire:submit="linkResources" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit">
                Link Resources
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="syncLinkedResources">
                Sync from OpenSearch
            </x-filament::button>
        </div>
    </form>

    <div class="mt-8">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
