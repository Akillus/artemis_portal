<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportedPortalResource extends Model
{
    protected $fillable = [
        'source_reference',
        'record_id',
        'identifier',
        'status',
        'resource_type',
        'title',
        'error_message',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
