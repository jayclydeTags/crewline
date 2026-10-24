@props(['task', 'canUpdate' => false])

<div {{ $attributes->class('group relative rounded-lg border border-zinc-200 bg-white p-3 shadow-xs transition-colors hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500') }}>
    <div class="flex items-start justify-between gap-2">
        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-medium after:absolute after:inset-0 after:rounded-lg focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-accent">
            {{ $task->title }}
        </a>

        @if ($canUpdate)
            <div wire:sort:ignore class="relative z-10 -me-1 -mt-1">
                <flux:dropdown align="end">
                    <flux:button size="xs" variant="subtle" icon="ellipsis-horizontal" aria-label="{{ __('Move :task', ['task' => $task->title]) }}" />
                    <flux:menu>
                        <flux:menu.heading>{{ __('Move to') }}</flux:menu.heading>
                        @foreach (\App\Enums\TaskStatus::cases() as $status)
                            @continue($status === $task->status)
                            <flux:menu.item :icon="$status->icon()" wire:click="moveTask({{ $task->id }}, 0, '{{ $status->value }}')">{{ $status->label() }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
    </div>

    @if ($task->labels->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach ($task->labels as $label)
                <flux:badge size="sm" :color="$label->color" wire:key="card-label-{{ $task->id }}-{{ $label->id }}">{{ $label->name }}</flux:badge>
            @endforeach
        </div>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 tabular-nums">
        <flux:badge size="sm" :color="$task->priority->color()" inset="top bottom">{{ $task->priority->label() }}</flux:badge>

        @if ($task->due_on)
            <span @class(['flex items-center gap-1', 'font-medium text-red-600 dark:text-red-400' => $task->isOverdue()])>
                <flux:icon.calendar variant="micro" />
                {{ $task->due_on->format('M j') }}
                @if ($task->isOverdue())
                    <span class="sr-only">{{ __('(overdue)') }}</span>
                @endif
            </span>
        @endif

        @if ($task->checklist_items_count)
            <span class="flex items-center gap-1" title="{{ __('Checklist') }}">
                <flux:icon.check-circle variant="micro" />
                {{ $task->completed_checklist_items_count }}/{{ $task->checklist_items_count }}
            </span>
        @endif

        @if ($task->comments_count)
            <span class="flex items-center gap-1">
                <flux:icon.chat-bubble-left variant="micro" />
                {{ $task->comments_count }}
                <span class="sr-only">{{ __('comments') }}</span>
            </span>
        @endif

        @if ($task->assignee)
            <flux:avatar size="xs" circle class="ms-auto" :name="$task->assignee->name" :initials="$task->assignee->initials()" :tooltip="$task->assignee->name" />
        @endif
    </div>
</div>
