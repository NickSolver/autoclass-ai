<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** Размер страницы списка: 1…100, по умолчанию 25. */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 25)));
    }

    /** Шаблон LIKE «содержит» с экранированием спецсимволов. */
    protected function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
