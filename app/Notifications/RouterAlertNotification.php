<?php

namespace App\Notifications;

use App\Models\Router;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RouterAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE_OFFLINE = 'offline';

    public const TYPE_ONLINE = 'online';

    public const TYPE_HIGH_CPU = 'high_cpu';

    public const TYPE_HIGH_RAM = 'high_ram';

    private const LABELS = [
        self::TYPE_OFFLINE => 'Router unreachable',
        self::TYPE_ONLINE => 'Router back online',
        self::TYPE_HIGH_CPU => 'High CPU usage',
        self::TYPE_HIGH_RAM => 'High RAM usage',
    ];

    public function __construct(
        public readonly Router $router,
        public readonly string $type,
        public readonly string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->notify_by_email ?? true) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->router->loadMissing('shop.tenant');

        return (new MailMessage)
            ->subject('MMS Radius alert: '.$this->label().' -- '.$this->router->name)
            ->greeting($this->label())
            ->line($this->message)
            ->line('Router: '.$this->router->name.' ('.$this->router->shop?->name.')')
            ->action('View router', route('admin.routers.show', $this->router));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'router_id' => $this->router->id,
            'router_name' => $this->router->name,
            'type' => $this->type,
            'label' => $this->label(),
            'message' => $this->message,
            'url' => route('admin.routers.show', $this->router),
        ];
    }

    private function label(): string
    {
        return self::LABELS[$this->type] ?? 'Router alert';
    }
}
