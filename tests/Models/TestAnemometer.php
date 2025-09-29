<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestAnemometer extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_anemometers';

    protected $fillable = [
        'weather_station_id',
        'wind_speed',
        'max_speed',
        'sensor_type',
    ];

    protected $casts = [
        'wind_speed' => 'decimal:2',
        'max_speed' => 'decimal:2',
    ];

    public function getHashableAttributes(): array
    {
        return ['wind_speed', 'max_speed', 'sensor_type'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }

    public function getHashParentRelations(): array
    {
        return ['weatherStation'];
    }

    public function weatherStation(): BelongsTo
    {
        return $this->belongsTo(TestWeatherStation::class, 'weather_station_id');
    }

    public function getHashableScope(): ?\Closure
    {
        return null;
    }

    public function scopeHighWind(Builder $query): Builder
    {
        return $query->where('wind_speed', '>', 10.0);
    }

    public function isHighWind(): bool
    {
        return $this->wind_speed > 10.0;
    }

    public function getBeaufortScale(): int
    {
        if ($this->wind_speed < 0.3) {
            return 0;
        }
        if ($this->wind_speed < 1.6) {
            return 1;
        }
        if ($this->wind_speed < 3.4) {
            return 2;
        }
        if ($this->wind_speed < 5.5) {
            return 3;
        }
        if ($this->wind_speed < 8.0) {
            return 4;
        }
        if ($this->wind_speed < 10.8) {
            return 5;
        }
        if ($this->wind_speed < 13.9) {
            return 6;
        }
        if ($this->wind_speed < 17.2) {
            return 7;
        }
        if ($this->wind_speed < 20.8) {
            return 8;
        }
        if ($this->wind_speed < 24.5) {
            return 9;
        }
        if ($this->wind_speed < 28.5) {
            return 10;
        }
        if ($this->wind_speed < 32.7) {
            return 11;
        }

        return 12;
    }
}
