<?php

use Illuminate\Support\Facades\Route;
use Innertia\Files\Http\FileController;

// File serving — permission check is done inside the controller.
// 'public' files need no auth, 'auth'/'restricted' do → handled internally.
Route::get('/files/{id}',          [FileController::class, 'view'])->name('innertia.files.view');
Route::get('/files/{id}/download', [FileController::class, 'download'])->name('innertia.files.download');
