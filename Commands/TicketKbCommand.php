<?php

namespace Plugin\ChatwootSync\Commands;

use App\Services\Plugin\PluginConfigService;
use Illuminate\Console\Command;
use Plugin\ChatwootSync\Services\TicketKnowledgeBuilder;

/**
 * 把 Xboard 工单编译成 Captain 知识文档（JSON）
 *
 * 这个命令只负责「产出」，不负责「投递」——投递需要写 Captain 文档的 content 字段，
 * 而 Chatwoot 的公开 API 不接受 content（只接受 external_link / pdf_file）。
 * 投递由宿主机脚本 scripts/publish-kb.sh 完成。
 *
 * Usage:
 *   php artisan chatwoot:ticket-kb --dry-run          # 只看统计和样本，不写文件
 *   php artisan chatwoot:ticket-kb --months=3         # 生成最近 3 个月的知识库 JSON
 *   php artisan chatwoot:ticket-kb --samples=10       # 多打印几条渲染样本人工检查
 */
class TicketKbCommand extends Command
{
    protected $signature = 'chatwoot:ticket-kb
                            {--months= : 取最近 N 个月的工单（默认读插件配置 kb_months）}
                            {--all-status : 连未关闭的工单也一起编译（默认只要已关闭的）}
                            {--max-chars= : 单个文档最大字符数（默认读插件配置 kb_max_chars）}
                            {--out= : 输出 JSON 路径（默认 plugins/ChatwootSync/storage/kb.json）}
                            {--samples=5 : 打印多少条渲染样本供人工检查}
                            {--dry-run : 只统计+打样本，不写文件}';

    protected $description = '把 Xboard 工单编译成 Chatwoot Captain 知识文档 JSON（脱敏 + 分类 + 切块）';

    public function handle(): int
    {
        $cfg = app(PluginConfigService::class)->getDbConfig('chatwoot_sync') ?: [];

        $months = (int) ($this->option('months') ?: ($cfg['kb_months'] ?? 3));
        $maxChars = (int) ($this->option('max-chars') ?: ($cfg['kb_max_chars'] ?? 6000));
        $assistantId = (int) ($cfg['kb_assistant_id'] ?? 1);

        $excludeRaw = trim((string) ($cfg['kb_exclude_keywords'] ?? ''));
        $exclude = $excludeRaw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $excludeRaw))))
            : null;

        $maskRaw = trim((string) ($cfg['kb_mask_domains'] ?? ''));
        $maskDomains = $maskRaw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $maskRaw))))
            : null;

        $keepRaw = trim((string) ($cfg['kb_keep_domains'] ?? ''));
        $keepDomains = $keepRaw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $keepRaw))))
            : null;

        $builder = new TicketKnowledgeBuilder(
            months: $months,
            closedOnly: !$this->option('all-status'),
            maxCharsPerDoc: $maxChars,
            excludeKeywords: $exclude,
            maskDomainSuffixes: $maskDomains,
            keepDomains: $keepDomains,
        );

        $this->info("编译最近 {$months} 个月的工单" . ($this->option('all-status') ? '（含未关闭）' : '（仅已关闭）') . " …");

        $result = $builder->build();
        $stats = $result['stats'];
        $docs = $result['docs'];

        $this->newLine();
        $this->table(['指标', '数量'], [
            ['扫描工单', $stats['scanned']],
            ['无客服回复（跳过）', $stats['no_answer']],
            ['问或答太短（跳过）', $stats['too_short']],
            ['答复无知识含量（跳过）', $stats['low_value_answer']],
            ['命中排除词（跳过）', $stats['excluded_keyword']],
            ['脱敏后仍有残留风险（跳过）', $stats['residual_risk']],
            ['最终采用', $stats['kept']],
            ['生成文档数', count($docs)],
            ['总字符数', array_sum(array_column($docs, 'chars'))],
        ]);

        $this->newLine();
        $this->line('<comment>文档清单：</comment>');
        foreach ($docs as $doc) {
            $this->line(sprintf('  %-46s %4d 单 / %5d 字', $doc['name'], $doc['ticket_count'], $doc['chars']));
        }

        $sampleCount = max(0, (int) $this->option('samples'));
        if ($sampleCount > 0 && $result['samples'] !== []) {
            $this->newLine();
            $this->line('<comment>渲染样本（脱敏后，确认没有个人信息再投递）：</comment>');
            foreach (array_slice($result['samples'], 0, $sampleCount) as $s) {
                $this->newLine();
                $this->line("  <info>[{$s['category']}] 工单 #{$s['ticket_id']}</info>");
                $this->line('  问：' . str_replace("\n", ' ', mb_substr($s['question'], 0, 180)));
                $this->line('  答：' . str_replace("\n", ' ', mb_substr($s['answer'], 0, 180)));
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('[DRY-RUN] 未写文件。去掉 --dry-run 即可生成 JSON。');
            return self::SUCCESS;
        }

        if ($docs === []) {
            $this->error('没有任何可用工单，不生成文件。');
            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: base_path('plugins/ChatwootSync/storage/kb.json'));
        $dir = dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error("无法创建目录：{$dir}");
            return self::FAILURE;
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'assistant_id' => $assistantId,
            'source' => 'xboard-tickets',
            'window_months' => $months,
            // 给 publish_kb.rb --lint 用：检查模型有没有把这些域名逐字符拆开（客户照抄打不开）
            'keep_domains' => $keepDomains ?? [],
            'stats' => $stats,
            'docs' => $docs,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($out, $json) === false) {
            $this->error("写入失败：{$out}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("已写入 {$out}（" . count($docs) . ' 个文档）');
        $this->line('下一步在宿主机执行投递：<comment>plugins/ChatwootSync/scripts/publish-kb.sh</comment>');

        return self::SUCCESS;
    }
}
