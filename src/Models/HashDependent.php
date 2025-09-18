<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HashDependent extends Model
{
    protected $fillable = [
        'hash_id',
        'dependent_model_type',
        'dependent_model_id',
        'relation_name',
    ];

    protected $casts = [
        'dependent_model_id' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('change-detection.tables.hash_dependents', 'hash_dependents');

        $connection = config('change-detection.database_connection');
        if ($connection) {
            $this->connection = $connection;
        }
    }

    public function hash(): BelongsTo
    {
        return $this->belongsTo(Hash::class, 'hash_id');
    }

    public function dependent(): ?Model
    {
        if (! $this->getAttribute('dependent_model_type') || ! $this->getAttribute('dependent_model_id')) {
            return null;
        }

        $modelClass = $this->getAttribute('dependent_model_type');

        return $modelClass::find($this->getAttribute('dependent_model_id'));
    }
}
