<?php

namespace Plugin\ChatwootSync\Commands;

use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Console\Command;
use Plugin\ChatwootSync\Services\AttributeMapper;
use Plugin\ChatwootSync\Services\ChatwootClient;

/**
 * 把 Chatwoot 中现有的 contact 全部回填 Xboard 信息
 *
 * Usage:
 *   php artisan chatwoot:backfill
 *   php artisan chatwoot:backfill --dry-run
 *   php artisan chatwoot:backfill --only-with-email
 *   php artisan chatwoot:backfill --rate=300 --max-pages=20
 */
class BackfillCommand extends Command
{
    protected $signature = 'chatwoot:backfill
                            {--dry-run : 仅模拟，不实际写入 Chatwoot}
                            {--only-with-email : 跳过没有 email 的 contact}
                            {--rate=200 : 每次 PATCH 后限速 ms（避免触发 Chatwoot 限流）}
                            {--max-pages=0 : 最多处理多少页（0 = 不限制）}
                            {--start-page=1 : 从第几页开始}';

    protected $description = '把 Chatwoot 现有 contact 的 Xboard 信息一次性回填到 custom_attributes';

    public function handle(): int
    {
        $cfg = app(PluginConfigService::class)->getDbConfig('chatwoot_sync');

        $baseUrl = (string) ($cfg['chatwoot_base_url'] ?? '');
        $apiToken = (string) ($cfg['chatwoot_api_token'] ?? '');
        $accountId = (string) ($cfg['chatwoot_account_id'] ?? '');

        if ($baseUrl === '' || $apiToken === '' || $accountId === '') {
            $this->error('Chatwoot 配置不完整，请到 Xboard 后台 → 插件 → ChatwootSync 填写：');
            $this->error('  - chatwoot_base_url');
            $this->error('  - chatwoot_api_token');
            $this->error('  - chatwoot_account_id');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $onlyWithEmail = (bool) $this->option('only-with-email');
        $rateMs = max(0, (int) $this->option('rate'));
        $maxPages = max(0, (int) $this->option('max-pages'));
        $page = max(1, (int) $this->option('start-page'));

        $client = new ChatwootClient($baseUrl, $accountId, $apiToken);
        $xboardBaseUrl = (string) (admin_setting('app_url') ?: url('/'));

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "开始回填 Chatwoot contacts (起始页: {$page})");

        $stats = [
            'total' => 0,
            'matched' => 0,
            'no_xboard' => 0,
            'no_email_skipped' => 0,
            'failed' => 0,
        ];

        while (true) {
            $result = $client->listContacts($page);
            $contacts = $result['payload'];

            if (empty($contacts)) {
                $this->line("page {$page}: 0 contact，结束");
                break;
            }

            $this->line("page {$page}: " . count($contacts) . " contact");

            foreach ($contacts as $contact) {
                $stats['total']++;
                $contactId = (int) ($contact['id'] ?? 0);
                $email = strtolower(trim((string) ($contact['email'] ?? '')));

                if ($email === '') {
                    if ($onlyWithEmail) {
                        $stats['no_email_skipped']++;
                        continue;
                    }
                }

                $user = $email !== ''
                    ? User::with(['plan', 'invite_user'])->byEmail($email)->first()
                    : null;

                if ($user) {
                    $stats['matched']++;
                } else {
                    $stats['no_xboard']++;
                }

                $attrs = AttributeMapper::map($user, $xboardBaseUrl);

                if ($dryRun) {
                    $this->line(sprintf(
                        '  contact#%d <%s> -> %s (plan=%s, balance=%s)',
                        $contactId,
                        $email ?: '(no-email)',
                        $attrs['xboard_status'] ?? '?',
                        $attrs['xboard_plan'] ?? '-',
                        $attrs['xboard_balance'] ?? '-',
                    ));
                } else {
                    $ok = $client->updateContact($contactId, $attrs);
                    if (!$ok) {
                        $stats['failed']++;
                        $this->warn("  contact#{$contactId} 更新失败");
                    }
                    if ($rateMs > 0) {
                        usleep($rateMs * 1000);
                    }
                }
            }

            $page++;
            if ($maxPages > 0 && ($page - (int) $this->option('start-page')) >= $maxPages) {
                $this->line("达到 max-pages={$maxPages}，停止");
                break;
            }
        }

        $this->newLine();
        $this->info('=== 回填完成 ===');
        $this->table(
            ['指标', '数量'],
            [
                ['总处理', $stats['total']],
                ['匹配 Xboard 用户', $stats['matched']],
                ['Xboard 无此用户', $stats['no_xboard']],
                ['因无 email 跳过', $stats['no_email_skipped']],
                ['更新失败', $stats['failed']],
            ]
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
