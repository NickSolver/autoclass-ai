<?php

use Illuminate\Support\Facades\Route;

// SPA: все не-API адреса отдают одну страницу; маршрутизация — во Vue Router.
// Доступ к данным проверяется сервером в API, а не этим маршрутом.
Route::view('/{any?}', 'spa')
    ->where('any', '^(?!api/|docs/api|horizon|up$).*$')
    ->name('spa');
