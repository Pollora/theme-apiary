<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use %theme_namespace%\Http\Controllers\Api\ProductSearchController;

/*
|--------------------------------------------------------------------------
| Theme API Routes
|--------------------------------------------------------------------------
|
| Lightweight routes served by Pollora's Laravel router.
| Prefix: /api
|
*/

Route::get('/products/search', ProductSearchController::class);
