<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;

final class LogNotifier implements NotifierPort
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function notify(int $userId, string $message): void
    {
        $this->logger->info('Notifying user {userId}: {message}', [
            'userId' => $userId,
            'message' => $message,
        ]);
    }
}
