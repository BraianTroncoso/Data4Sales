<?php

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    ApiRateLimitServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,
];
