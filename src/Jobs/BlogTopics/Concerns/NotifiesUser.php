<?php

declare(strict_types=1);

namespace Core45\AiPosts\Jobs\BlogTopics\Concerns;

use Core45\AiCore\ModelRegistry;
use Filament\Notifications\Notification;
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

        $notification = Notification::make()
            ->title($title)
            ->body(Str::limit($body, 500));

        $notification = match ($status) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification->sendToDatabase($user);
    }
}
