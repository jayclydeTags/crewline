<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public bool $assignments = true;

    public bool $mentions = true;

    public bool $due_dates = true;

    public bool $email = true;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        foreach (array_keys(User::NOTIFICATION_DEFAULTS) as $type) {
            $this->{$type} = Auth::user()->wantsNotification($type);
        }
    }

    /**
     * Save the user's notification preferences.
     */
    public function savePreferences(): void
    {
        $validated = $this->validate([
            'assignments' => ['boolean'],
            'mentions' => ['boolean'],
            'due_dates' => ['boolean'],
            'email' => ['boolean'],
        ]);

        Auth::user()->forceFill(['notification_preferences' => $validated])->save();

        Flux::toast(variant: 'success', text: __('Notification preferences saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose what Crewline tells you about')">
        <form wire:submit="savePreferences" class="my-6 w-full space-y-6">
            <flux:switch wire:model="assignments" :label="__('Task assignments')" :description="__('When someone assigns a task to you.')" />
            <flux:switch wire:model="mentions" :label="__('Mentions')" :description="__('When someone @mentions you in a comment.')" />
            <flux:switch wire:model="due_dates" :label="__('Due date reminders')" :description="__('A daily reminder for your tasks due today or tomorrow.')" />
            <flux:separator />
            <flux:switch wire:model="email" :label="__('Also send by email')" :description="__('In-app notifications are always on for the types above.')" />

            <flux:button variant="primary" type="submit" data-test="save-notification-preferences">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
