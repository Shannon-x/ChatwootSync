<?php

namespace Plugin\ChatwootSync\Services;

/**
 * 工单文本脱敏器
 *
 * 工单内容最终会被送进 Captain 的 LLM 生成 FAQ，生成结果对所有客户可见。
 * 所以任何「只属于某一个客户」的信息都必须在这里抹掉，否则会被学进机器人的答案里。
 *
 * 设计原则：宁可多抹。抹掉的东西对 FAQ 生成没有价值（订单号/订阅链接/邮箱都不是知识），
 * 但泄漏出去就是事故。
 */
class TicketSanitizer
{
    /**
     * 通用的、与站点无关的白名单。你自己的官网域名请在插件配置 kb_keep_domains 里填，
     * 不要写死在代码里——这个仓库是公开的。
     */
    private const DEFAULT_KEEP_DOMAINS = [
        'github.com',
        'apps.apple.com',
        'play.google.com',
        'telegram.org',
        't.me',
    ];

    /** 单条消息保留的最大长度（防止有人把整份客户端日志/base64 配置贴进来） */
    private const MAX_MESSAGE_CHARS = 800;

    /**
     * 轮换入口域名的后缀（插件配置 kb_mask_domains）。这类域名被墙就换一个，
     * 写进 FAQ 等于埋定时炸弹：实测生成过「把订阅链接里的 alpha1234.example.net
     * 改成 beta5678.example.net」，域名一轮换这条 FAQ 就开始骗客户。
     *
     * 默认为空：入口域名属于站点机密，必须由部署方自己在后台填。
     */
    private array $maskDomainSuffixes;

    /** 允许原样保留的域名（官方站点、下载页等），来自插件配置 kb_keep_domains */
    private array $keepDomains;

    public function __construct(?array $maskDomainSuffixes = null, ?array $keepDomains = null)
    {
        $this->maskDomainSuffixes = $maskDomainSuffixes ?? [];
        $this->keepDomains = array_merge(self::DEFAULT_KEEP_DOMAINS, $keepDomains ?? []);
    }

    public function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $text = $this->maskUrls($text);
        $text = $this->maskRotatingDomains($text);

        // 邮箱
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[邮箱]', $text);

        // 区块链交易哈希（USDT 工单里客户会贴自己的 txid，属于可关联到个人的链上标识）
        $text = preg_replace('/\b(?:0x)?[0-9a-fA-F]{40,}\b/', '[交易哈希]', $text);

        // 钱包地址：提现工单里客户会贴自己的收款地址，是财务敏感信息
        // TRON(T 开头 34 位) / BTC(1 或 3 开头) / bech32(bc1)，都用 base58 字符集约束避免误伤普通英文
        $text = preg_replace(
            '/\b(?:T[1-9A-HJ-NP-Za-km-z]{33}|[13][1-9A-HJ-NP-Za-km-z]{25,39}|bc1[02-9ac-hj-np-z]{20,60})\b/',
            '[钱包地址]',
            $text
        );

        // UUID（节点/用户 uuid）
        $text = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[UUID]', $text);

        // 订阅 token（32 位 hex）
        $text = preg_replace('/\b[0-9a-f]{32}\b/i', '[令牌]', $text);

        // 订单号（Xboard 订单号是 25 位纯数字，这里放宽到 13 位以上）
        $text = preg_replace('/\b\d{13,}\b/', '[订单号]', $text);

        // 手机号
        $text = preg_replace('/\b1[3-9]\d{9}\b/', '[手机号]', $text);

        // 绝对时间/日期：工单里的「2026-08-15 12:18 才会重置流量」「到期时间 2026-06-28 20:15」
        // 都是某个客户当下的状态，被学成通用 FAQ 就是错答（实测线上出现过 11 条）。
        // 分隔符要含点号：实测源头里有 2026.06.28 这种写法，只写 [-/] 会漏。
        // 注意「6.21」「6/18」这种裸月日没法安全地抹（和价格 41.20、版本号 8.8 撞脸），
        // 只能靠 publish_kb.rb --lint 在生成后兜底。
        $text = preg_replace('/20\d{2}[-\/.]\d{1,2}[-\/.]\d{1,2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?/u', '[具体时间]', $text);

        // IPv4（含端口）
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b/', '[IP]', $text);

        // Telegram / 社交账号
        $text = preg_replace('/(?<![\w\/])@[A-Za-z0-9_]{5,32}\b/', '[TG账号]', $text);

        // 超长无空格串（base64 配置、客户端日志），会污染 FAQ 且浪费 token
        $text = preg_replace('/\S{200,}/u', '[长文本已省略]', $text);

        // 压缩空白
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);

        return $this->truncate($text, self::MAX_MESSAGE_CHARS);
    }

    /**
     * URL 处理：
     *   - 带 token= 或指向订阅接口的 → [订阅链接]（这是最危险的一类，等于账号密码）
     *   - 白名单官方域名 → 保留（对 FAQ 有用）
     *   - 其余 → [链接]
     */
    private function maskUrls(string $text): string
    {
        return preg_replace_callback('/https?:\/\/\S+/u', function (array $m): string {
            $url = $m[0];

            if (stripos($url, 'token=') !== false || stripos($url, '/client/subscribe') !== false) {
                return '[订阅链接]';
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                return '[链接]';
            }

            foreach ($this->keepDomains as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    return $url;
                }
            }

            return '[链接]';
        }, $text) ?? $text;
    }

    /**
     * 抹掉裸写的轮换入口域名，换成「订阅域名」这种不会过期的说法。
     *
     * 两条规则：
     *   1. 配置的后缀名单（kb_mask_domains）下的任何子域
     *   2. 形如 alpha1234.xxx / beta5678.xxx 的「字母+数字结尾」子域——
     *      这是自动生成的轮换域名的典型长相，将来换了服务商也能兜住。
     *      不会误伤 ip2location.com / github.com 这类（它们的最左标签不以数字结尾）。
     */
    private function maskRotatingDomains(string $text): string
    {
        foreach ($this->maskDomainSuffixes as $suffix) {
            $suffix = trim($suffix);
            if ($suffix === '') {
                continue;
            }
            // 注意：不能用 \b。在 /u 模式下中文也是 word char，
            // "订阅链接里面的alpha1234.example.net修改为…" 两侧都不存在词边界，\b 会整个匹配不上。
            // 这里改用「前后不是 ASCII 域名字符」来界定，中文紧贴也能正确抹掉。
            $text = preg_replace(
                '/(?<![A-Za-z0-9_.\-])[\w-]+\.' . preg_quote($suffix, '/') . '(?![A-Za-z0-9_\-])/iu',
                '[订阅域名]',
                $text
            ) ?? $text;
        }

        $text = preg_replace(
            '/(?<![A-Za-z0-9_.\-])[a-z]{3,}\d{2,}\.[a-z0-9-]+\.[a-z]{2,}(?![A-Za-z0-9_\-])/iu',
            '[订阅域名]',
            $text
        ) ?? $text;

        // 光把域名换成占位符还不够：「把 A 改成 B」会变成「把订阅域名改成订阅域名」，
        // 模型再一压缩就成了「用"订阅域名修订阅域名即可"」这种鬼话（实测生成过）。
        // 一句话里出现两次占位符 = 这是一条「换域名」指令，整句收掉换成不会过期的说法。
        // 占位符必须带方括号：客服原话里「订阅域名被墙了」本身就含这四个字，
        // 用裸词当占位符会和原文撞车，收句时把「现在已经更换，请刷新网站」也一起吃掉。
        // 边界必须收到逗号级：用句号级边界时，前面的 {0,24} 会贪婪地把
        // 「现在已经更换，请刷新网站，获取最新的订阅链接」这些有用内容一起吃掉。
        return preg_replace(
            '/[^。；;，,\n]{0,20}\[订阅域名\][^。；;，,\n]{0,14}\[订阅域名\][^。；;，,\n]{0,8}/u',
            '请以官网最新的订阅链接为准',
            $text
        ) ?? $text;
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '…';
    }

    /**
     * 脱敏后自检：是否仍残留明显的个人信息。
     * 用于命令行 --audit，出现残留说明上面的规则漏了，需要补。
     */
    public function residualRisks(string $text): array
    {
        $risks = [];
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $text)) {
            $risks[] = 'email';
        }
        if (preg_match('/\b\d{13,}\b/', $text)) {
            $risks[] = 'long_number';
        }
        if (preg_match('/\b[0-9a-f]{32}\b/i', $text)) {
            $risks[] = 'token';
        }
        if (preg_match('/token=/i', $text)) {
            $risks[] = 'token_param';
        }
        return $risks;
    }
}
