<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeUser extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to '.config('app.name'))
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('An administrator has created your account on '.config('app.name').'.')
            ->line('You will receive separate emails to set your password and verify your email address.')
            ->line('Set your password first, then sign in to verify your email address.')
            ->action('Sign in', route('login'));
    }
}
