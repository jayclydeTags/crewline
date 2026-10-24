<?php

use App\Models\TeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team invitation')] class extends Component {
    public TeamInvitation $invitation;

    /**
     * Only the invited email address may view the invitation.
     */
    public function mount(): void
    {
        abort_unless($this->invitation->isFor(Auth::user()), 403, __('This invitation was sent to a different email address.'));
    }

    /**
     * Join the team with the invited role.
     */
    public function accept(): void
    {
        abort_unless($this->invitation->isFor(Auth::user()), 403);

        $team = $this->invitation->team;

        $this->invitation->accept(Auth::user());

        Flux::toast(variant: 'success', text: __('Welcome to :team!', ['team' => $team->name]));

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Decline and discard the invitation.
     */
    public function decline(): void
    {
        abort_unless($this->invitation->isFor(Auth::user()), 403);

        $this->invitation->delete();

        Flux::toast(text: __('Invitation declined.'));

        $this->redirectRoute(Auth::user()->teams()->exists() ? 'dashboard' : 'teams.create', navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-lg py-6">
    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg" level="1">{{ __('Join :team', ['team' => $invitation->team->name]) }}</flux:heading>
            <flux:text class="mt-2">
                {{ __(':name invited you to join as :role.', ['name' => $invitation->invitedBy->name ?? __('Someone'), 'role' => $invitation->role->label()]) }}
            </flux:text>
            <flux:text class="mt-1 text-sm">{{ $invitation->role->description() }}</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button variant="primary" wire:click="accept" data-test="accept-invitation">{{ __('Accept') }}</flux:button>
            <flux:button variant="ghost" wire:click="decline" data-test="decline-invitation">{{ __('Decline') }}</flux:button>
        </div>
    </flux:card>
</div>
