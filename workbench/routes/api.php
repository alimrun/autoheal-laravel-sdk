<?php

use Illuminate\Support\Facades\Route;

Route::get('/autoheal/sample-exception', function () {
    throw new RuntimeException('AutoHeal sample exception');
})->name('autoheal.sample-exception');
