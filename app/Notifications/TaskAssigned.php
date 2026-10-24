<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Task $task, public User $assigner) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->notificationChannelsFor('assignments') : [];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You were assigned “:task”', ['task' => $this->task->title]))
            ->line(__(':name assigned you “:task” in :project.', [
                'name' => $this->assigner->name,
                'task' => $this->task->title,
                'project' => $this->task->project->name,
            ]))
            ->action(__('View task'), route('tasks.show', $this->task));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => __(':name assigned you “:task”', ['name' => $this->assigner->name, 'task' => $this->task->title]),
            'url' => route('tasks.show', $this->task, absolute: false),
        ];
    }
}
