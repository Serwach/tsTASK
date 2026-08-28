<?php

declare(strict_types=1);

namespace App\Notification;

final class InMemoryNotifier implements NotifierPort
{
    private int $calls = 0;

    /** @var array<int, array{userId:int, message:string}> */
    public array $sent = [];

    public function __construct(private readonly ?int $failOnCallNumber = null)
    {
    }

    public function notify(int $userId, string $message): void
    {
        $this->calls++;

        if ($this->failOnCallNumber === $this->calls) {
            throw new \RuntimeException("Simulated notification channel outage (call #{$this->calls})");
        }

        $this->sent[] = ['userId' => $userId, 'message' => $message];
    }
}
