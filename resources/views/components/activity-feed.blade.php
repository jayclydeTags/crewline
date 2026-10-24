@props(['activities'])

@if ($activities->isEmpty())
    <flux:text class="text-sm">{{ __('No activity yet.') }}</flux:text>
@else
    <ol class="space-y-4">
        @foreach ($activities as $activity)
            <li class="flex gap-3 text-sm" wire:key="activity-{{ $activity->id }}">
                <flux:avatar size="xs" circle :name="$activity->causerName()" :initials="$activity->user?->initials() ?? '?'" />
                <div class="min-w-0">
                    <p><span class="font-medium">{{ $activity->causerName() }}</span> {{ $activity->description }}</p>
                    <time datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->toDayDateTimeString() }}" class="text-xs text-zinc-500">
                        {{ $activity->created_at->diffForHumans() }}
                    </time>
                </div>
            </li>
        @endforeach
    </ol>
@endif
