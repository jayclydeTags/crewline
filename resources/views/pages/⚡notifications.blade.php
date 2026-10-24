<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    /**
     * Open a notification, marking it as read.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('dashboard'), navigate: true);
    }

    /**
     * Mark every notification as read.
     */
    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    /**
     * Provide the paginated notifications to the view.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'notifications' => Auth::user()->notifications()->paginate(20),
        ];
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="flex items-end justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
        @if (auth()->user()->unreadNotifications()->exists())
            <flux:button size="sm" variant="ghost" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-600">
            <flux:icon.bell class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">{{ __('You’re all caught up') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Assignments, mentions and due date reminders will show up here.') }}</flux:text>
        </div>
    @else
        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($notifications as $notification)
                <li wire:key="notification-{{ $notification->id }}">
                    <button type="button" wire:click="open('{{ $notification->id }}')"
                        class="flex w-full items-start gap-3 p-4 text-start hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent dark:hover:bg-zinc-900">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-blue-500' => ! $notification->read_at, 'bg-transparent' => $notification->read_at])></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-sm', 'font-medium' => ! $notification->read_at])>
                                {{ $notification->data['message'] ?? __('Notification') }}
                                @unless ($notification->read_at)<span class="sr-only">{{ __('(unread)') }}</span>@endunless
                            </span>
                            <time class="text-xs text-zinc-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>

        {{ $notifications->links() }}
    @endif
</div>
