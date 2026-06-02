<?php

namespace Plugin\ChatwootSync;

use App\Models\Order;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\ChatwootSync\Jobs\PushToChatwootJob;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        if (!$this->getConfig('enable_push_sync', true)) {
            return;
        }

        // 用户生命周期事件
        $this->listen('user.register.after', [$this, 'syncByUser'], 20);
        $this->listen('user.password.reset.after', [$this, 'syncByUser'], 20);

        // 登录事件高频，加节流
        $this->listen('user.login.after', [$this, 'syncByUserThrottled'], 20);

        // 订单 / 支付事件
        $this->listen('order.create.after', [$this, 'syncByOrder'], 20);
        $this->listen('payment.notify.success', [$this, 'syncByOrder'], 20);

        // 流量重置
        $this->listen('traffic.reset.after', [$this, 'syncByUser'], 20);
    }

    /**
     * Disable 时清理本插件注册的 hook 回调
     *
     * ⚠️ 必须传入精确的 callback；AbstractPlugin::removeListener($hook) 不带 callback
     *    会调用 HookManager::remove($hook, null)，那会把该 hook 上所有插件的
     *    回调（包括别人的）全部清掉。
     */
    public function cleanup(): void
    {
        $bindings = [
            'user.register.after'       => [$this, 'syncByUser'],
            'user.password.reset.after' => [$this, 'syncByUser'],
            'user.login.after'          => [$this, 'syncByUserThrottled'],
            'order.create.after'        => [$this, 'syncByOrder'],
            'payment.notify.success'    => [$this, 'syncByOrder'],
            'traffic.reset.after'       => [$this, 'syncByUser'],
        ];

        foreach ($bindings as $hook => $callback) {
            HookManager::remove($hook, $callback);
        }
    }

    /**
     * 普通同步：直接 dispatch Job
     */
    public function syncByUser($user): void
    {
        if (!$user instanceof User || empty($user->email)) {
            return;
        }
        $this->dispatchSync($user->id);
    }

    /**
     * 节流同步：5 分钟内同一用户只同步 1 次（登录事件用）
     */
    public function syncByUserThrottled($user): void
    {
        if (!$user instanceof User || empty($user->email)) {
            return;
        }
        $minutes = (int) ($this->getConfig('login_throttle_minutes', 5) ?: 5);
        $key = "chatwoot_sync:throttle:{$user->id}";
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, 1, now()->addMinutes($minutes));
        $this->dispatchSync($user->id);
    }

    /**
     * 订单事件 → 同步该订单关联的用户
     */
    public function syncByOrder($order): void
    {
        if (!$order instanceof Order) {
            return;
        }
        $user = User::find($order->user_id);
        if ($user) {
            $this->syncByUser($user);
        }
    }

    /**
     * 注册定时任务：每日 03:00 兜底同步活跃 contact
     *
     * 同样受 enable_push_sync 总开关控制——关掉推送时也不跑 cron
     */
    public function schedule(Schedule $schedule): void
    {
        if (!$this->getConfig('enable_push_sync', true)) {
            return;
        }

        $schedule->command('chatwoot:backfill --only-with-email')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    private function dispatchSync(int $userId): void
    {
        try {
            PushToChatwootJob::dispatch($userId);
        } catch (\Throwable $e) {
            Log::warning('[ChatwootSync] dispatch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
