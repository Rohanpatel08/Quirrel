<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MySQLQueryController;

Route::get('/', function () {
    return redirect('/mysql-query');
});

Route::prefix('mysql-query')->group(function () {
    Route::get('/', [MySQLQueryController::class, 'index'])->name('mysql-query.index');
    Route::post('/execute', [MySQLQueryController::class, 'executeQuery'])->name('mysql-query.execute');
    Route::get('/schema', [MySQLQueryController::class, 'getSchema'])->name('mysql-query.schema');
    Route::get('/status', [MySQLQueryController::class, 'checkStatus'])->name('mysql-query.status');
    Route::post('/cleanup', [MySQLQueryController::class, 'cleanupDatabases'])->name('mysql-query.cleanup');
});