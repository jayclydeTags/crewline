<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Task $task) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->notificationChannelsFor('due_dates') : [];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('“:task” is due :when', ['task' => $this->task->title, 'when' => $this->dueLabel()]))
            ->line(__('Your task “:task” in :project is due :when.', [
                'task' => $this->task->title,
                'project' => $this->task->project->name,
                'when' => $this->dueLabel(),
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
            'message' => __('“:task” is due :when', ['task' => $this->task->title, 'when' => $this->dueLabel()]),
            'url' => route('tasks.show', $this->task, absolute: false),
        ];
    }

    /**
     * Describe when the task is due relative to today.
     */
    private function dueLabel(): string
    {
        return $this->task->due_on?->isToday() ? __('today') : __('tomorrow');
    }
}
