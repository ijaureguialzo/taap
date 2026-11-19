<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastChannelsProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    BroadcastChannelsProvider::class,
];
