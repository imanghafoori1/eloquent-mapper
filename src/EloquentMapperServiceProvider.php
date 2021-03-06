<?php

namespace Railken\EloquentMapper;

use Illuminate\Support\ServiceProvider;
use Railken\EloquentMapper\Console\Commands\Mapper;
use Illuminate\Support\Facades\Event;

class EloquentMapperServiceProvider extends ServiceProvider
{
    /**
     * @inherit
     */
    public function register()
    {
        $this->app->singleton('eloquent.mapper', function ($app) {
            return new \Railken\EloquentMapper\Helper();
        });
    }

    public function boot()
    {
        $this->commands([Mapper::class]);

        Event::listen(\Railken\EloquentMapper\Events\EloquentMapUpdate::class, function () {
            $this->app->get('eloquent.mapper')->boot();
        });
    }
}
