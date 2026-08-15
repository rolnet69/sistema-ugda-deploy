<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;

class UserActivityLogger
{
    public function log(
        User $user,
        ?User $actor,
        string $eventType,
        string $title,
        ?string $description = null,
        ?Request $request = null
    ): void {
        UserActivityLog::create([
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
