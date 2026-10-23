<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetLink extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly int $expiresInMinutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/reset-password?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject('Восстановление пароля — '.config('app.name'))
            ->greeting('Здравствуйте!')
            ->line('Мы получили запрос на смену пароля для вашей учётной записи.')
            ->action('Задать новый пароль', $url)
            ->line("Ссылка действует {$this->expiresInMinutes} мин. и может быть использована один раз.")
            ->line('Если вы не запрашивали смену пароля, просто проигнорируйте это письмо.')
            ->salutation(' ');
    }
}
