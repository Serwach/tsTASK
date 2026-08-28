<?php

declare(strict_types=1);

namespace App\Notification;

interface NotifierPort
{
    public function notify(int $userId, string $message): void;
}
