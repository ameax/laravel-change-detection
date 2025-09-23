<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TestWindvane extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_windvanes';

    protected $fillable = [
        'weather_station_id',
        'direction',
        'accuracy',
        'calibration_date',
    ];

    protected $casts = [
        'direction' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'calibration_date' => 'date',
    ];

    public function getHashableAttributes(): array
    {
        return ['direction', 'accuracy', 'calibration_date'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }

    public function weatherStation(): BelongsTo
    {
        return $this->belongsTo(TestWeatherStation::class, 'weather_station_id');
    }

    public function scopeCalibrated(Builder $query): Builder
    {
        return $query->where('accuracy', '>=', 90.0);
    }

    public function isAccurate(): bool
    {
        return $this->accuracy >= 90.0;
    }

    public function getCardinalDirection(): string
    {
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $index = round($this->direction / 45) % 8;
        return $directions[$index];
    }
}