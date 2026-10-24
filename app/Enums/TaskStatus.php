<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Done = 'done';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Todo => __('To Do'),
            self::InProgress => __('In Progress'),
            self::InReview => __('In Review'),
            self::Done => __('Done'),
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Todo => 'zinc',
            self::InProgress => 'blue',
            self::InReview => 'amber',
            self::Done => 'green',
        };
    }

    /**
     * Get the icon that accompanies the status label.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Todo => 'stop-circle',
            self::InProgress => 'play-circle',
            self::InReview => 'eye',
            self::Done => 'check-circle',
        };
    }
}
