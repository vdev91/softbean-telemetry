<?php

use Illuminate\Support\Facades\Route;
use Softbean\Telemetry\Http\HealthController;
use Softbean\Telemetry\Http\VerifyHubSignature;

Route::middleware(VerifyHubSignature::class)
    ->prefix(config('softbean-telemetry.saude.prefixo', '_softbean'))
    ->group(function () {
        Route::get('/health', HealthController::class)->name('softbean.health');
    });
