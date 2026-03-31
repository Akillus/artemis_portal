<?php

namespace App\Filament\Pages;

use App\Models\ImportedPortalResource;
use App\Services\PortalResourceImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

class LinkResources extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Link Resources';

    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Link Resources';

    protected string $view = 'filament.pages.link-resources';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'references' => '',
        ]);

        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $this->syncProjection(notify: false);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Linked resources could not be synchronized')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Portal Resources')
                    ->description('Paste one or more ARIADNE portal resource URLs or raw IDs, one per line.')
                    ->schema([
                        Textarea::make('references')
                            ->label('Resources to link')
                            ->rows(10)
                            ->required()
                            ->placeholder("https://portal.ariadne-infrastructure.eu/resource/...\nhttps://portal.ariadne-infrastructure.eu/resource/...\nraw-record-id"),
                    ]),
            ]);
    }

    public function linkResources(): void
    {
        $state = $this->form->getState();
        $references = $this->parseReferences((string) ($state['references'] ?? ''));

        if ($references === []) {
            Notification::make()
                ->title('No resources provided')
                ->body('Paste at least one ARIADNE portal resource URL or raw record ID.')
                ->danger()
                ->send();

            return;
        }

        $importer = app(PortalResourceImportService::class);
        $successes = 0;
        $failures = 0;
        $importedByOverrides = [];

        foreach ($references as $reference) {
            try {
                $result = $importer->import($reference);
                $recordId = $result['id'] ?? null;

                if (filled($recordId)) {
                    $importedByOverrides[$recordId] = auth()->id();
                }

                $successes++;
            } catch (Throwable $exception) {
                $failures++;
            }
        }

        if ($successes > 0) {
            $this->syncProjection($importedByOverrides, notify: false);
        }

        $this->form->fill([
            'references' => '',
        ]);
        $this->resetTable();

        Notification::make()
            ->title('Resource linking completed')
            ->body("Imported {$successes} resource(s), failed {$failures}.")
            ->color($failures > 0 ? 'warning' : 'success')
            ->send();
    }

    public function syncLinkedResources(): void
    {
        try {
            $count = $this->syncProjection(notify: false);

            $this->resetTable();

            Notification::make()
                ->title('Linked resources synchronized')
                ->body("The portal currently exposes {$count} linked resource(s) imported from ARIADNE.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Synchronization failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ImportedPortalResource::query()->with('user'))
            ->defaultSort('imported_at', 'desc')
            ->headerActions([
                Action::make('sync')
                    ->label('Sync from OpenSearch')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(fn () => $this->syncLinkedResources()),
            ])
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'linked' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('resource_type')
                    ->label('Type')
                    ->toggleable(),
                TextColumn::make('record_id')
                    ->label('Record ID')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('source_reference')
                    ->label('Portal URL')
                    ->url(fn (ImportedPortalResource $record): ?string => $record->source_reference)
                    ->openUrlInNewTab()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')
                    ->label('Admin')
                    ->toggleable(),
                TextColumn::make('imported_at')
                    ->label('Linked')
                    ->since()
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('removeLink')
                    ->label('Remove Link')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove linked resource')
                    ->modalDescription('This removes the selected resource from the local portal index.')
                    ->action(function (ImportedPortalResource $record): void {
                        $this->removeLinks(collect([$record]));
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('removeLinks')
                    ->label('Remove Link')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove selected linked resources')
                    ->modalDescription('This removes the selected resources from the local portal index.')
                    ->action(function (Collection $records): void {
                        $this->removeLinks($records);
                    }),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No linked resources yet')
            ->paginated([10, 25, 50]);
    }

    /**
     * @return list<string>
     */
    protected function parseReferences(string $references): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            preg_split('/[\r\n,;]+/', $references) ?: [],
        ))));
    }

    /**
     * @param  array<string, int|null>  $importedByOverrides
     */
    protected function syncProjection(array $importedByOverrides = [], bool $notify = true): int
    {
        $count = app(PortalResourceImportService::class)->syncProjection($importedByOverrides);

        if ($notify) {
            Notification::make()
                ->title('Linked resources synchronized')
                ->body("The portal currently exposes {$count} linked resource(s) imported from ARIADNE.")
                ->success()
                ->send();
        }

        return $count;
    }

    protected function removeLinks(Collection $records): void
    {
        $importer = app(PortalResourceImportService::class);
        $removed = 0;

        foreach ($records as $record) {
            if (! $record instanceof ImportedPortalResource) {
                continue;
            }

            $importer->remove($record->record_id);
            $removed++;
        }

        $remaining = $this->syncProjection(notify: false);
        $this->resetTable();

        Notification::make()
            ->title('Linked resources updated')
            ->body("Removed {$removed} resource(s). {$remaining} linked resource(s) remain in the portal.")
            ->success()
            ->send();
    }
}
