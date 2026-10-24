<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitation $invitation) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have been invited to join :team on Crewline', ['team' => $this->invitation->team->name]))
            ->line(__(':name invited you to join :team as :role.', [
                'name' => $this->invitation->invitedBy->name ?? __('Someone'),
                'team' => $this->invitation->team->name,
                'role' => $this->invitation->role->label(),
            ]))
            ->action(__('View invitation'), route('invitations.show', $this->invitation))
            ->line(__('If you do not have an account yet, register with this email address first.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => __('You were invited to join :team', ['team' => $this->invitation->team->name]),
            'url' => route('invitations.show', $this->invitation, absolute: false),
        ];
    }
}
