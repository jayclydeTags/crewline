<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationSent;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Team settings')] class extends Component {
    use WithFileUploads;

    public Team $team;

    public string $name = '';

    public $logo = null;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public string $newOwnerId = '';

    public string $deleteConfirmation = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view', $this->team);

        $this->name = $this->team->name;
    }

    /**
     * Get the team's members, owners first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function members()
    {
        return $this->team->members()
            ->orderByRaw("case team_user.role when 'owner' then 0 when 'admin' then 1 when 'member' then 2 else 3 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TeamInvitation>
     */
    #[Computed]
    public function invitations()
    {
        return $this->team->invitations()->latest()->get();
    }

    /**
     * Update the team's name and logo.
     */
    public function updateTeam(): void
    {
        $this->authorize('update', $this->team);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'logo' => ['nullable', 'image', 'max:1024'],
        ]);

        $this->team->name = $validated['name'];

        if ($this->logo) {
            if ($this->team->logo_path) {
                Storage::disk('public')->delete($this->team->logo_path);
            }

            $this->team->logo_path = $this->logo->store('team-logos', 'public');
            $this->logo = null;
        }

        $this->team->save();

        Flux::toast(variant: 'success', text: __('Team updated.'));
    }

    /**
     * Remove the team's logo.
     */
    public function removeLogo(): void
    {
        $this->authorize('update', $this->team);

        if ($this->team->logo_path) {
            Storage::disk('public')->delete($this->team->logo_path);
            $this->team->update(['logo_path' => null]);
        }
    }

    /**
     * Invite someone to the team by email.
     */
    public function invite(): void
    {
        $this->authorize('manageMembers', $this->team);

        $validated = $this->validate([
            'inviteEmail' => [
                'required', 'email', 'max:255',
                function (string $attribute, string $value, Closure $fail) {
                    if ($this->team->members()->where('email', $value)->exists()) {
                        $fail(__('This person is already on the team.'));
                    }
                },
            ],
            'inviteRole' => ['required', Rule::in(array_map(fn (TeamRole $role) => $role->value, TeamRole::assignable()))],
        ], attributes: ['inviteEmail' => 'email', 'inviteRole' => 'role']);

        $invitation = $this->team->invitations()->updateOrCreate(
            ['email' => strtolower($validated['inviteEmail'])],
            ['role' => $validated['inviteRole'], 'invited_by_id' => Auth::id()],
        );

        $existingUser = User::where('email', $invitation->email)->first();

        $existingUser
            ? $existingUser->notify(new TeamInvitationSent($invitation))
            : Notification::route('mail', $invitation->email)->notify(new TeamInvitationSent($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    /**
     * Revoke a pending invitation.
     */
    public function revokeInvitation(int $invitationId): void
    {
        $this->authorize('manageMembers', $this->team);

        $this->team->invitations()->whereKey($invitationId)->delete();

        unset($this->invitations);
    }

    /**
     * Change a member's role. The owner's role only changes through a transfer.
     */
    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('manageMembers', $this->team);

        $member = $this->team->members()->whereKey($userId)->firstOrFail();
        $newRole = TeamRole::tryFrom($role);

        abort_if($member->id === Auth::id(), 403, __('You cannot change your own role.'));
        abort_if($member->membership->role === TeamRole::Owner, 403);
        abort_unless($newRole && in_array($newRole, TeamRole::assignable(), true), 422);

        $this->team->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);

        unset($this->members);

        Flux::toast(variant: 'success', text: __(':name is now :role.', ['name' => $member->name, 'role' => $newRole->label()]));
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(int $userId): void
    {
        $this->authorize('manageMembers', $this->team);

        $member = $this->team->members()->whereKey($userId)->firstOrFail();

        abort_if($member->id === Auth::id() || $member->membership->role === TeamRole::Owner, 403);

        $this->team->removeMember($member);

        unset($this->members);

        Flux::toast(text: __(':name was removed from the team.', ['name' => $member->name]));
    }

    /**
     * Hand ownership to another member. The previous owner becomes an admin.
     */
    public function transferOwnership(): void
    {
        $this->authorize('transferOwnership', $this->team);

        $this->validate(['newOwnerId' => ['required', 'integer']], attributes: ['newOwnerId' => 'new owner']);

        $newOwner = $this->team->members()->whereKey($this->newOwnerId)->whereKeyNot(Auth::id())->firstOrFail();

        DB::transaction(function () use ($newOwner) {
            $this->team->members()->updateExistingPivot($newOwner->id, ['role' => TeamRole::Owner->value]);
            $this->team->members()->updateExistingPivot(Auth::id(), ['role' => TeamRole::Admin->value]);
        });

        $this->reset('newOwnerId');
        unset($this->members);

        Flux::modal('transfer-ownership')->close();
        Flux::toast(variant: 'success', text: __(':name now owns this team.', ['name' => $newOwner->name]));
    }

    /**
     * Leave the team.
     */
    public function leaveTeam(): void
    {
        $this->authorize('leave', $this->team);

        $this->team->removeMember(Auth::user());

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Permanently delete the team and everything in it.
     */
    public function deleteTeam(): void
    {
        $this->authorize('delete', $this->team);

        $this->validate([
            'deleteConfirmation' => ['required', Rule::in([$this->team->name])],
        ], ['deleteConfirmation.in' => __('Type the team name exactly to confirm.')], ['deleteConfirmation' => 'confirmation']);

        if ($this->team->logo_path) {
            Storage::disk('public')->delete($this->team->logo_path);
        }

        $this->team->delete();

        $this->redirectRoute('dashboard', navigate: true);
    }

}; ?>

<div class="mx-auto w-full max-w-3xl space-y-10">
    <div>
        <flux:heading size="xl" level="1">{{ __('Team settings') }}</flux:heading>
        <flux:subheading>{{ __('Manage :team’s profile, members and invitations.', ['team' => $team->name]) }}</flux:subheading>
    </div>

    @can('update', $team)
        <section class="space-y-4" aria-labelledby="general-heading">
            <flux:heading size="lg" level="2" id="general-heading">{{ __('General') }}</flux:heading>

            <form wire:submit="updateTeam" class="space-y-6">
                <flux:input wire:model="name" :label="__('Team name')" required autocomplete="organization" />

                <flux:field>
                    <flux:label>{{ __('Logo') }}</flux:label>
                    <div class="flex items-center gap-4">
                        @if ($logo && method_exists($logo, 'isPreviewable') && $logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('New logo preview') }}" width="48" height="48" class="size-12 rounded-lg object-cover">
                        @elseif ($team->logoUrl())
                            <img src="{{ $team->logoUrl() }}" alt="{{ __('Current logo') }}" width="48" height="48" class="size-12 rounded-lg object-cover">
                        @else
                            <span class="flex size-12 items-center justify-center rounded-lg bg-zinc-200 font-semibold dark:bg-zinc-700" aria-hidden="true">{{ $team->initials() }}</span>
                        @endif

                        <input type="file" wire:model="logo" accept="image/*" aria-label="{{ __('Upload a logo') }}"
                            class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600">

                        @if ($team->logo_path)
                            <flux:button size="sm" variant="ghost" wire:click="removeLogo">{{ __('Remove') }}</flux:button>
                        @endif
                    </div>
                    <flux:error name="logo" />
                </flux:field>

                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </form>
        </section>

        <flux:separator />
    @endcan

    <section class="space-y-4" aria-labelledby="members-heading">
        <flux:heading size="lg" level="2" id="members-heading">{{ __('Members') }}</flux:heading>

        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->members as $member)
                <li class="flex flex-wrap items-center gap-3 p-3" wire:key="member-{{ $member->id }}">
                    <flux:avatar size="sm" :name="$member->name" :initials="$member->initials()" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {{ $member->name }}
                            @if ($member->id === auth()->id())
                                <span class="text-zinc-500">({{ __('you') }})</span>
                            @endif
                        </div>
                        <div class="truncate text-xs text-zinc-500">{{ $member->email }}</div>
                    </div>

                    @if (auth()->user()->can('manageMembers', $team) && $member->membership->role !== \App\Enums\TeamRole::Owner && $member->id !== auth()->id())
                        <div class="w-36">
                            <flux:select size="sm" aria-label="{{ __('Role for :name', ['name' => $member->name]) }}" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                @foreach (\App\Enums\TeamRole::assignable() as $role)
                                    <flux:select.option value="{{ $role->value }}" :selected="$member->membership->role === $role">{{ $role->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})"
                            wire:confirm="{{ __('Remove :name from the team?', ['name' => $member->name]) }}"
                            aria-label="{{ __('Remove :name', ['name' => $member->name]) }}" />
                    @else
                        <flux:badge size="sm" :color="$member->membership->role === \App\Enums\TeamRole::Owner ? 'amber' : 'zinc'">
                            {{ $member->membership->role->label() }}
                        </flux:badge>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @can('manageMembers', $team)
        <section class="space-y-4" aria-labelledby="invite-heading">
            <flux:heading size="lg" level="2" id="invite-heading">{{ __('Invite people') }}</flux:heading>

            <form wire:submit="invite" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex-1">
                    <flux:input wire:model="inviteEmail" type="email" :label="__('Email')" autocomplete="off" inputmode="email" placeholder="name@company.com" />
                </div>
                <div class="sm:w-40">
                    <flux:select wire:model="inviteRole" :label="__('Role')">
                        @foreach (\App\Enums\TeamRole::assignable() as $role)
                            <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button variant="primary" type="submit" class="sm:mt-6" data-test="send-invite">{{ __('Send invite') }}</flux:button>
            </form>

            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                @foreach (\App\Enums\TeamRole::cases() as $role)
                    <div class="rounded-md bg-zinc-50 p-3 dark:bg-zinc-900">
                        <dt class="font-medium">{{ $role->label() }}</dt>
                        <dd class="text-zinc-500">{{ $role->description() }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($this->invitations->isNotEmpty())
                <flux:heading level="3" class="pt-2">{{ __('Pending invitations') }}</flux:heading>
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->invitations as $invitation)
                        <li class="flex items-center gap-3 p-3 text-sm" wire:key="invitation-{{ $invitation->id }}">
                            <flux:icon.envelope variant="micro" class="text-zinc-400" />
                            <span class="min-w-0 flex-1 truncate">{{ $invitation->email }}</span>
                            <flux:badge size="sm">{{ $invitation->role->label() }}</flux:badge>
                            <flux:button size="sm" variant="ghost" wire:click="revokeInvitation({{ $invitation->id }})">{{ __('Revoke') }}</flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endcan

    <flux:separator />

    <section class="space-y-4" aria-labelledby="danger-heading">
        <flux:heading size="lg" level="2" id="danger-heading">{{ __('Danger zone') }}</flux:heading>

        <div class="flex flex-wrap gap-2">
            @can('transferOwnership', $team)
                <flux:modal.trigger name="transfer-ownership">
                    <flux:button icon="arrows-right-left">{{ __('Transfer ownership') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="delete-team">
                    <flux:button variant="danger" icon="trash">{{ __('Delete team') }}</flux:button>
                </flux:modal.trigger>
            @endcan

            @can('leave', $team)
                <flux:button variant="danger" icon="arrow-right-start-on-rectangle" wire:click="leaveTeam"
                    wire:confirm="{{ __('Leave :team? You will lose access to its projects.', ['team' => $team->name]) }}">
                    {{ __('Leave team') }}
                </flux:button>
            @endcan
        </div>
    </section>

    @can('transferOwnership', $team)
        <flux:modal name="transfer-ownership" class="md:w-96">
            <form wire:submit="transferOwnership" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Transfer ownership') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The new owner gets full control. You will become an admin.') }}</flux:text>
                </div>
                <flux:select wire:model="newOwnerId" :label="__('New owner')" :placeholder="__('Choose a member…')">
                    @foreach ($this->members->where('id', '!=', auth()->id()) as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Transfer') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="delete-team" class="md:w-96">
            <form wire:submit="deleteTeam" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete team?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('All projects, tasks, comments and files in :team will be permanently deleted.', ['team' => $team->name]) }}</flux:text>
                </div>
                <flux:input wire:model="deleteConfirmation" :label="__('Type “:name” to confirm', ['name' => $team->name])" autocomplete="off" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Delete team') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
