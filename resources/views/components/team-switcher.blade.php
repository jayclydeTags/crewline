@php
    $user = auth()->user();
    $currentTeam = $user->currentTeam;
    $teams = $user->teams;
@endphp

<flux:dropdown position="bottom" align="start" {{ $attributes }}>
    <flux:sidebar.item icon:trailing="chevrons-up-down" data-test="team-switcher">
        <span class="flex min-w-0 items-center gap-2">
            @if ($currentTeam?->logoUrl())
                <img src="{{ $currentTeam->logoUrl() }}" alt="" width="20" height="20" class="size-5 shrink-0 rounded object-cover">
            @else
                <span class="flex size-5 shrink-0 items-center justify-center rounded bg-zinc-200 text-[10px] font-semibold dark:bg-zinc-700" aria-hidden="true">
                    {{ $currentTeam?->initials() }}
                </span>
            @endif
            <span class="truncate">{{ $currentTeam?->name ?? __('Choose a team') }}</span>
        </span>
    </flux:sidebar.item>

    <flux:menu class="min-w-60">
        <flux:menu.heading>{{ __('Switch team') }}</flux:menu.heading>

        @foreach ($teams as $team)
            <form method="POST" action="{{ route('teams.switch', $team) }}" wire:key="team-{{ $team->id }}">
                @csrf
                <flux:menu.item as="button" type="submit" class="w-full" :icon="$team->id === $currentTeam?->id ? 'check' : null">
                    <span class="flex w-full items-center justify-between gap-3">
                        <span class="truncate">{{ $team->name }}</span>
                        <span class="text-xs text-zinc-500">{{ $team->membership->role->label() }}</span>
                    </span>
                </flux:menu.item>
            </form>
        @endforeach

        <flux:menu.separator />

        @if ($currentTeam)
            <flux:menu.item icon="cog-6-tooth" :href="route('teams.settings', $currentTeam)" wire:navigate>
                {{ __('Team settings') }}
            </flux:menu.item>
        @endif

        <flux:menu.item icon="plus" :href="route('teams.create')" wire:navigate>
            {{ __('Create team') }}
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
