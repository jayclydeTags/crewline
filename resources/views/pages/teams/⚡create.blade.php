<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Create team')] class extends Component {
    #[Validate('required|string|max:80')]
    public string $name = '';

    /**
     * Create a new team owned by the current user and switch to it.
     */
    public function createTeam(): void
    {
        $this->authorize('create', Team::class);

        $this->validate();

        $team = DB::transaction(function () {
            $team = Team::create(['name' => $this->name]);
            $team->addMember(Auth::user(), TeamRole::Owner);

            return $team;
        });

        Auth::user()->switchTeam($team);

        $this->redirectRoute('projects.index', navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-lg py-6">
    <flux:heading size="xl" level="1">{{ __('Create a team') }}</flux:heading>
    <flux:subheading class="mb-6">
        {{ __('A team is a shared workspace for your crew’s projects and tasks. You’ll be its owner.') }}
    </flux:subheading>

    <form wire:submit="createTeam" class="space-y-6">
        <flux:input wire:model="name" :label="__('Team name')" placeholder="{{ __('e.g. Acme Design') }}" required autofocus autocomplete="organization" />

        <flux:button variant="primary" type="submit" data-test="create-team-button">
            {{ __('Create team') }}
        </flux:button>
    </form>
</div>
