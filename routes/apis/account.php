<?php

use App\Http\Controllers\Api\MailboxController;
use Illuminate\Support\Facades\Route;

// The signed-in accountant's own mailbox: the clients' emails go from it, their replies are read from it.
Route::get('/mail', [MailboxController::class, 'show'])->middleware('can:clients.manage')->name('api.account.mail');
Route::put('/mail', [MailboxController::class, 'update'])->middleware('can:clients.manage')->name('api.account.mail.update');
Route::post('/mail/test', [MailboxController::class, 'test'])->middleware('can:clients.manage')->name('api.account.mail.test');
Route::post('/mail/check', [MailboxController::class, 'check'])->middleware('can:clients.manage')->name('api.account.mail.check');
Route::delete('/mail', [MailboxController::class, 'destroy'])->middleware('can:clients.manage')->name('api.account.mail.destroy');
