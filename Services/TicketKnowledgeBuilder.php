<?php

namespace Plugin\ChatwootSync\Services;

use App\Models\Ticket;

/**
 * 把 Xboard 工单编译成「可以直接喂给 Chatwoot Captain 的知识文档」
 *
 * 产物不是 FAQ —— FAQ 由 Captain 自己的 FaqGeneratorService 生成。
 * 这里只负责：挑出值得学的工单 → 脱敏 → 归类 → 切成大小合适的文档。
 *
 * 为什么要切分：Captain 对每个文档做一次 LLM 调用把整篇内容变成 FAQ。
 * 一个 18 万字的巨型文档会超上下文、生成质量崩掉；实测 1-3 千字的文档能稳定产出 6-10 条 FAQ。
 */
class TicketKnowledgeBuilder
{
    /**
     * 分类规则：命中即归类，顺序从具体到宽泛，最后兜底 other。
     * 分类的意义是让每个文档主题集中，Captain 生成的 FAQ 才不会东一句西一句。
     */
    private const CATEGORIES = [
        'plan-change' => ['name' => '套餐变更与补差价', 'keywords' => ['换套餐', '更换套餐', '升级套餐', '降级', '补差价', '折抵', '换个套餐']],
        'traffic' => ['name' => '流量与流量重置', 'keywords' => ['流量', '重置', '倍率', '上传', '下载', '超量', '用超', 'GB', '偷跑']],
        'expiry-renew' => ['name' => '续费与到期', 'keywords' => ['续费', '到期', '过期', '延期', '剩余时间', '自动续']],
        'payment' => ['name' => '支付与订单', 'keywords' => ['支付', '付款', '支付宝', '微信', 'usdt', 'USDT', '订单', '充值', '没到账', '未到账', '汇率', '钱包', '收款']],
        'refund' => ['name' => '退款', 'keywords' => ['退款', '退钱', '退单', '退回']],
        'invite' => ['name' => '邀请与佣金', 'keywords' => ['邀请', '佣金', '提现', '返利', '推广', '下线']],
        'subscribe' => ['name' => '订阅与导入', 'keywords' => ['订阅', '导入', '更新订阅', '二维码', '订阅地址', '链接失效', '拉不到']],
        'client' => ['name' => '客户端与使用教程', 'keywords' => ['clash', 'Clash', 'v2ray', 'V2Ray', 'singbox', 'sing-box', 'surge', 'shadowrocket', '小火箭', '客户端', '教程', '安装', '配置', '规则模式', 'tun', 'TUN', 'verge', '软件']],
        'node' => ['name' => '节点与线路问题', 'keywords' => ['节点', '线路', '延迟', '掉线', '连不上', '超时', '丢包', '全红', '解锁', '流媒体', 'netflix', 'Netflix', 'chatgpt', 'ChatGPT', 'youtube', '看不了', '打不开', '速度', '卡']],
        'account' => ['name' => '账号与登录', 'keywords' => ['登录', '密码', '注册', '验证码', '封禁', '设备', '被封', '登不上', '账号']],
    ];

    /**
     * 默认排除关键词：命中的工单整单不进知识库。
     *
     * 三类必须挡掉的东西（都是实测从生成结果里反推出来的）：
     *   1. 给某个客户的一次性特例（补偿/延期/手动处理）——会被学成对所有人的承诺
     *   2. 针对个别客户的惩罚性处置（"因为你 XXX，现在终止你的套餐"）——会变成对正常客户的答复
     *   3. 对内部同事说的话（"若后续再有人反馈请通知我"）——会直接泄漏进对客答案
     */
    private const DEFAULT_EXCLUDE = [
        '补偿', '特殊处理', '单独给你', '已为你', '给你延', '手动给', '内部', '测试账号',
        '恶意', '投诉', '立刻结束', '已对你', '通知我', '封号', '拉黑',
        // 「我已经替你把某件事做了」——这是对某一个客户的动作播报，不是规则。
        // 实测线上出现过「提现处理状态如何？→ 已完成提现处理」这种通用 FAQ。
        '已经给你', '已给你', '已帮你', '已经帮你', '已完成提现', '补发',
        // 敬语变体必须一起写。只写「你」会漏掉「已经为您补单」「已经为您提现 999.84元」
        // 这类同样是个案播报的句子（实测漏了 13 条）。
        '已经给您', '已给您', '已帮您', '已经帮您', '已经为您', '已经为你',
        '为您补单', '为你补单', '给你补上', '为您提现', '为你提现',
    ];

    /** 纯应答，没有任何知识含量，学进去只会稀释知识库 */
    private const ACK_ANSWERS = ['已处理', '已解决', '好的', '收到', '嗯', '是的', '可以', '已完成', '已重置', '已恢复', '请稍等', '稍等', '在的', '已查看'];

    private TicketSanitizer $sanitizer;

    /** @var array<string,int> */
    private array $stats = [];

    /** @var array<int,string> */
    private array $excludeKeywords;

    public function __construct(
        private int $months = 3,
        private bool $closedOnly = true,
        private int $maxCharsPerDoc = 6000,
        private int $minQuestionChars = 8,
        private int $minAnswerChars = 8,
        ?array $excludeKeywords = null,
        ?array $maskDomainSuffixes = null,
        ?array $keepDomains = null,
    ) {
        $this->sanitizer = new TicketSanitizer($maskDomainSuffixes, $keepDomains);
        $this->excludeKeywords = $excludeKeywords ?? self::DEFAULT_EXCLUDE;
    }

    /**
     * @return array{docs: array<int, array{slug: string, name: string, external_link: string, content: string, ticket_count: int, chars: int}>, stats: array<string,int>, samples: array<int, array<string,string>>}
     */
    public function build(): array
    {
        $this->stats = [
            'scanned' => 0,
            'no_answer' => 0,
            'too_short' => 0,
            'low_value_answer' => 0,
            'excluded_keyword' => 0,
            'kept' => 0,
            'residual_risk' => 0,
        ];

        $entries = [];
        $samples = [];

        foreach ($this->fetchTickets() as $ticket) {
            $this->stats['scanned']++;

            $entry = $this->compileTicket($ticket);
            if ($entry === null) {
                continue;
            }

            $this->stats['kept']++;
            $entries[$entry['category']][] = $entry;

            if (count($samples) < 20) {
                $samples[] = [
                    'ticket_id' => (string) $ticket->id,
                    'category' => $entry['category'],
                    'question' => $entry['question'],
                    'answer' => $entry['answer'],
                ];
            }
        }

        return [
            'docs' => $this->renderDocuments($entries),
            'stats' => $this->stats,
            'samples' => $samples,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    private function fetchTickets()
    {
        $cutoff = now()->subMonths(max(1, $this->months))->timestamp;

        return Ticket::query()
            ->where('created_at', '>=', $cutoff)
            ->when($this->closedOnly, fn($q) => $q->where('status', Ticket::STATUS_CLOSED))
            ->with(['messages' => fn($q) => $q->orderBy('id')])
            ->orderBy('id')
            ->get();
    }

    /**
     * 单个工单 → 一条「问/答」。判定客服回复的口径：发信人不是工单发起人。
     *
     * @return array{category: string, subject: string, question: string, answer: string}|null
     */
    private function compileTicket(Ticket $ticket): ?array
    {
        $questionParts = [];
        $answerParts = [];

        foreach ($ticket->messages as $msg) {
            $text = $this->sanitizer->clean((string) $msg->message);
            if ($text === '') {
                continue;
            }
            if ((int) $msg->user_id === (int) $ticket->user_id) {
                $questionParts[] = $text;
            } else {
                $answerParts[] = $text;
            }
        }

        if (empty($answerParts)) {
            $this->stats['no_answer']++;
            return null;
        }

        $subject = $this->sanitizer->clean((string) $ticket->subject);

        // 很多工单的首条消息本身就是标题的展开，重复一遍只会浪费上下文
        $firstMessage = $questionParts[0] ?? '';
        $question = ($subject !== '' && !str_starts_with($firstMessage, $subject))
            ? trim($subject . "\n" . implode("\n", $questionParts))
            : trim(implode("\n", $questionParts));

        $answer = implode("\n", $answerParts);

        if (mb_strlen($question) < $this->minQuestionChars || mb_strlen($answer) < $this->minAnswerChars) {
            $this->stats['too_short']++;
            return null;
        }

        if ($this->isLowValueAnswer($answer)) {
            $this->stats['low_value_answer']++;
            return null;
        }

        $haystack = $question . "\n" . $answer;

        foreach ($this->excludeKeywords as $kw) {
            if ($kw !== '' && mb_stripos($haystack, $kw) !== false) {
                $this->stats['excluded_keyword']++;
                return null;
            }
        }

        if ($this->sanitizer->residualRisks($haystack) !== []) {
            $this->stats['residual_risk']++;
            return null;
        }

        return [
            // 按「客户问的是什么」归类，而不是按客服答了什么——
            // 否则客服回复里随口提到的「订阅」「流量」会把工单拽进错误的分类
            'category' => $this->categorize($question, $answer),
            'subject' => $subject,
            'question' => $question,
            'answer' => $answer,
        ];
    }

    /**
     * 客服的反问（"你用的什么客户端？"）不是知识，是对话中间态，学进去就是噪音
     */
    private function isLowValueAnswer(string $answer): bool
    {
        $stripped = trim(preg_replace('/[\s\p{P}]+/u', '', $answer) ?? $answer);

        if ($stripped === '' || in_array($stripped, self::ACK_ANSWERS, true)) {
            return true;
        }

        if (mb_strlen($answer) < 40 && preg_match('/[?？]\s*$/u', $answer) === 1) {
            return true;
        }

        // 中文里反问经常不带问号（"你用的什么客户端"），靠疑问词 + 长度兜一层
        if (mb_strlen($answer) < 30) {
            foreach (['什么', '哪个', '哪些', '是否', '多少'] as $marker) {
                if (mb_strpos($answer, $marker) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 计分归类：统计每个分类命中了多少个不同关键词，取最高分。
     * 主判据是问题文本，只有问题里完全没有信号时才看客服回复。
     */
    private function categorize(string $question, string $answer): string
    {
        $best = $this->scoreCategories($question);
        if ($best !== null) {
            return $best;
        }

        return $this->scoreCategories($answer) ?? 'other';
    }

    private function scoreCategories(string $text): ?string
    {
        $scores = [];

        foreach (self::CATEGORIES as $slug => $def) {
            $hits = 0;
            foreach ($def['keywords'] as $kw) {
                if (mb_stripos($text, $kw) !== false) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $scores[$slug] = $hits;
            }
        }

        if ($scores === []) {
            return null;
        }

        // 同分时按 CATEGORIES 的声明顺序取先出现的（从具体到宽泛）
        arsort($scores);
        $top = max($scores);
        foreach (array_keys(self::CATEGORIES) as $slug) {
            if (($scores[$slug] ?? 0) === $top) {
                return $slug;
            }
        }

        return array_key_first($scores);
    }

    /**
     * 按分类聚合并切块。每块一个 Captain 文档。
     *
     * external_link 用自定义 scheme：Captain 对非 PDF 文档只要求 external_link 非空且在同一助手下唯一，
     * 不做 URL 校验；用 xboard-ticket-kb:// 前缀可以让投递脚本安全地识别"哪些文档归我管"。
     *
     * @param array<string, array<int, array<string,string>>> $entries
     */
    private function renderDocuments(array $entries): array
    {
        $docs = [];

        foreach ($entries as $category => $items) {
            $categoryName = self::CATEGORIES[$category]['name'] ?? '其他问题';
            $chunks = $this->chunk($items);
            $total = count($chunks);

            foreach ($chunks as $i => $chunk) {
                $part = $i + 1;
                $slug = $total > 1 ? "{$category}-{$part}" : $category;
                $title = $total > 1
                    ? "Xboard 工单知识库 · {$categoryName}（{$part}/{$total}）"
                    : "Xboard 工单知识库 · {$categoryName}";

                // 措辞很关键：Captain 的 FAQ 生成提示词要求「严格基于原文」，
                // 如果文档读起来像聊天记录，生成的答案就会是「客服表示…」这种转述体。
                // 把它写成「官方口径汇编」并明确要求第一人称，产出的 FAQ 才能直接当答案用。
                $body = "# {$title}\n\n"
                    . "> 本文档整理自真实客服工单并已脱敏，记录本站对外的标准答复。\n"
                    . "> 「客户问题」是客户原话，可能带情绪或口语；「标准答复」是本站的正式说明。\n"
                    . "> 生成 FAQ 时请以第一人称直接给出结论，不要写成「客服表示…」「客服强调…」的转述，\n"
                    . "> 不要复述情绪化措辞，也不要把针对单个客户的一次性处理写成通用规则。\n"
                    // 不加这句，模型会把占位符原样抄进答案，产出「套餐到期时间为“[具体时间]”」
                    // 这种读起来像 bug 的 FAQ（实测泄漏 15 条）。
                    . "> 文中形如 [邮箱]、[订单号]、[具体时间]、[TG账号] 的方括号内容是已脱敏的个人信息，\n"
                    . "> 不要在问题或答案里引用这些占位符，也不要围绕它们提问；只写对所有客户都成立的通用内容。\n\n";

                foreach ($chunk as $item) {
                    $body .= "## {$item['subject']}\n\n"
                        . "**客户问题**：{$item['question']}\n\n"
                        . "**标准答复**：{$item['answer']}\n\n";
                }

                $docs[] = [
                    'slug' => $slug,
                    'name' => $title,
                    'external_link' => "xboard-ticket-kb://{$slug}",
                    'content' => trim($body),
                    'ticket_count' => count($chunk),
                    'chars' => mb_strlen($body),
                ];
            }
        }

        return $docs;
    }

    /**
     * @param array<int, array<string,string>> $items
     * @return array<int, array<int, array<string,string>>>
     */
    private function chunk(array $items): array
    {
        $chunks = [];
        $current = [];
        $size = 0;

        foreach ($items as $item) {
            $len = mb_strlen($item['subject']) + mb_strlen($item['question']) + mb_strlen($item['answer']) + 16;

            if ($current !== [] && $size + $len > $this->maxCharsPerDoc) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $item;
            $size += $len;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
