<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class SetupPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your password')
            ->line('Your account has been created. Choose your own password using the link below.')
            ->action('Set password', $url)
            ->line('This link expires in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.')
            ->line('If the link expires, request a new one from the forgot password page.');
    }
}
