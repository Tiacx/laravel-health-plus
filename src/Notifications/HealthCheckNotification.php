<?php

namespace Tiacx\Health\Notifications;

use NotificationChannels\Webhook\WebhookChannel;
use NotificationChannels\Webhook\WebhookMessage;
use Spatie\Health\Enums\Status;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;

class HealthCheckNotification extends CheckFailedNotification
{
    public function via(): array
    {
        return [WebhookChannel::class];
    }

    public function shouldSend(Notifiable $notifiable, string $channel): bool
    {
        if (!config('health.notifications.enabled')) {
            return false;
        }

        // 判断是否需要强制发送通知（默认60分钟内发送一次）
        foreach ($this->results as $result) {
            if (property_exists($result->check, 'mustNotifyOnFailure')) {
                return $result->check->mustNotifyOnFailure && $result->status === Status::failed();
            }
        }

        return parent::shouldSend($notifiable, $channel);
    }

    public function toWebhook($notifiable): WebhookMessage
    {
        $content = '**異常告警**  ' . PHP_EOL;
        $content .= '項目名稱：' . config('app.name') . '  ' . PHP_EOL;
        $content .= '運行環境：' . strtoupper(config('app.env')) . '  ' . PHP_EOL;
        $content .= '告警內容：  ' . PHP_EOL;

        $queryUrls = [];

        foreach ($this->results as $result) {
            $status = $result->status === Status::failed() ? '嚴重' : '警告';
            $content .= "- [{$status}]`{$result->getNotificationMessage()}`  " . PHP_EOL;
            if (isset($result->meta['query_url'])) {
                $queryUrls[] = $result->meta['query_url'];
            }
        }

        if (!empty($queryUrls)) {
            $content .= PHP_EOL . '查看詳情：  ' . PHP_EOL;
            foreach ($queryUrls as $url) {
                $content .= "- {$url}  " . PHP_EOL;
            }
        }

        return WebhookMessage::create()
            ->data([
                'msgtype' => 'markdown_v2',
                'markdown_v2' => [
                    'content' => $content,
                ],
            ])->header('Content-Type', 'application/json');
    }
}
