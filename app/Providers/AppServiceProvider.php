<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Office;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();
        Relation::enforceMorphMap([
            'office' => Office::class,
            'user' => User::class,
        ]);
    }
}
