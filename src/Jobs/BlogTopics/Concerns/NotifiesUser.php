<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics\Concerns;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Enums\NotificationSeverity;
use Core45\AiCore\ModelRegistry;
use Illuminate\Support\Str;

trait NotifiesUser
{
    private function notifyUser(string $title, string $body, string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($this->userId);
        if ($user === null) {
            return;
        }

        app(NotifiesOperator::class)->notifyOperator(
            $user,
            $title,
            Str::limit($body, 500),
            NotificationSeverity::fromStatus($status),
        );
    }
}
