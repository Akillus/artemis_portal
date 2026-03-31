<?php

use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/{path?}', [PortalController::class, 'spa'])
    ->where('path', '^(?!api|up).*$');
