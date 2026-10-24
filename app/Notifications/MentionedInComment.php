<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInComment extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Comment $comment) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->notificationChannelsFor('mentions') : [];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':name mentioned you on “:task”', ['name' => $this->comment->user->name, 'task' => $this->comment->task->title]))
            ->line(__(':name wrote:', ['name' => $this->comment->user->name]))
            ->line($this->comment->body)
            ->action(__('Reply'), route('tasks.show', $this->comment->task));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => __(':name mentioned you on “:task”', ['name' => $this->comment->user->name, 'task' => $this->comment->task->title]),
            'url' => route('tasks.show', $this->comment->task, absolute: false),
        ];
    }
}
