<?php

use App\Http\Controllers\DownloadAttachmentController;
use App\Http\Controllers\SwitchTeamController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('teams/create', 'pages::teams.create')->name('teams.create');
    Route::post('teams/{team}/switch', SwitchTeamController::class)->name('teams.switch');
    Route::livewire('invitations/{invitation}', 'pages::invitations.show')->name('invitations.show');

    Route::middleware('team')->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('notifications', 'pages::notifications')->name('notifications');

        Route::livewire('teams/{team}/settings', 'pages::teams.settings')->name('teams.settings');

        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
        Route::livewire('tasks/{task}', 'pages::tasks.show')->name('tasks.show');

        Route::get('attachments/{attachment}', DownloadAttachmentController::class)->name('attachments.download');
    });
});

require __DIR__.'/settings.php';
