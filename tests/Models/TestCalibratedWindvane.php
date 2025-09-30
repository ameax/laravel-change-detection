<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Test model that extends TestWindvane with BOTH own scope (calibrated only)
 * AND parent scope (weather station must be in scope).
 *
 * This tests the combined scope filtering feature.
 */
class TestCalibratedWindvane extends Model implements Hashable
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
        'direction' => 'float',
        'accuracy' => 'float',
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

    public function getHashParentRelations(): array
    {
        return ['weatherStation'];
    }

    public function getHashableScope(): ?\Closure
    {
        // Own scope: only calibrated windvanes (accuracy >= 90)
        return function (Builder $query) {
            $query->where('accuracy', '>=', 90.0);
        };
    }

    public function weatherStation(): BelongsTo
    {
        return $this->belongsTo(TestWeatherStation::class, 'weather_station_id');
    }
}
