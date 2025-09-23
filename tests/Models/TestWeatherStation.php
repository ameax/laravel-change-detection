<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Closure;

class TestWeatherStation extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_weather_stations';

    protected $fillable = [
        'name',
        'location',
        'latitude',
        'longitude',
        'status',
        'is_operational',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_operational' => 'boolean',
    ];

    public function getHashableAttributes(): array
    {
        return ['name', 'location', 'latitude', 'longitude', 'status', 'is_operational'];
    }

    public function getHashCompositeDependencies(): array
    {
        return ['windvanes', 'anemometers'];
    }

    public function getHashableScope(): ?Closure
    {
        return function ($query) {
            $query->where('is_operational', true)
                  ->where('status', 'active');
        };
    }

    public function windvanes(): HasMany
    {
        return $this->hasMany(TestWindvane::class, 'weather_station_id');
    }

    public function anemometers(): HasMany
    {
        return $this->hasMany(TestAnemometer::class, 'weather_station_id');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('is_operational', true)
                    ->where('status', 'active');
    }

    public function hasCompleteSensorSetup(): bool
    {
        return $this->windvanes()->exists() && $this->anemometers()->exists();
    }

    public function getAverageWindData(): array
    {
        return [
            'avg_direction' => $this->windvanes()->avg('direction'),
            'avg_speed' => $this->anemometers()->avg('wind_speed'),
            'max_speed' => $this->anemometers()->max('max_speed'),
        ];
    }
}
