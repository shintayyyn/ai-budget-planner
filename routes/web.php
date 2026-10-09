<?php

use Illuminate\Support\Facades\Route;

// Every non-API path is handled by the Vue single-page app.
Route::view('/{any?}', 'app')->where('any', '^(?!api|up|storage).*$');
