<?php

use App\Livewire\Runs\Create;
use App\Livewire\Runs\Index;
use App\Livewire\Runs\Show;
use Illuminate\Support\Facades\Route;

Route::get('/', Index::class)->name('runs.index');
Route::get('/runs/create', Create::class)->name('runs.create');
Route::get('/runs/{run}', Show::class)->name('runs.show');
