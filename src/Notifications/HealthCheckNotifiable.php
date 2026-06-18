<?php

namespace Tiacx\Health\Notifications;

use Spatie\Health\Notifications\Notifiable;

class HealthCheckNotifiable extends Notifiable
{
    public function routeNotificationForWebhook(): string
    {
        return config('health.notifications.webhook.url');
    }
}
