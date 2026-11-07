<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::view('/{path?}', 'app')->where('path', '^(?!api|docs|openapi|auth|login).*$');
});
