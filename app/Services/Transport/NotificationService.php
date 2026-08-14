<?php

namespace App\Services\Transport;

use App\Jobs\SendSmsJob;

class NotificationService
{
    public function sendSmsNotifications(?int $schoolId, array $phones, string $message): void
    {
        $phones = array_filter(array_unique(array_map('trim', $phones)));

        foreach ($phones as $phone) {
            if (empty($phone)) {
                continue;
            }

            SendSmsJob::dispatch($schoolId, null, $phone, $message);
        }
    }
}
