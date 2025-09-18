<?php

namespace Ameax\LaravelChangeDetection\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ameax\LaravelChangeDetection\LaravelChangeDetection
 */
class LaravelChangeDetection extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ameax\LaravelChangeDetection\LaravelChangeDetection::class;
    }
}
