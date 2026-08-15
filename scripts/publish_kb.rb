# frozen_string_literal: true
#
# 在 Chatwoot 容器里跑：把 Xboard 工单知识库 JSON 投递成 Captain 文档
#
#   docker exec chatwoot-web bundle exec rails runner /tmp/publish_kb.rb /tmp/kb.json [--dry-run] [--only=slug1,slug2] [--prune]
#
# 为什么要用 rails runner 而不是 Captain 的 HTTP API：
#   Captain 的 POST /captain/documents 只接受 name / external_link / pdf_file，
#   不接受 content —— 内容必须由爬虫（Firecrawl，云端，要求页面公网可达）或 PDF 解析填充。
#   直接写 content 可以完全绕开公网暴露和第三方抓取，工单内容不出这台机器
#   （最终仍会发给 CAPTAIN_OPEN_AI_ENDPOINT 生成 FAQ，这一步在任何方案里都免不了）。
#
# 触发链（Captain 原生，不做任何改动）：
#   document 创建时 status=available + content 非空
#     → after_commit :enqueue_response_builder_job
#     → Captain::Documents::ResponseBuilderJob
#     → Captain::Llm::FaqGeneratorService（账号配置的模型）
#     → captain_assistant_responses（正式 FAQ，带 embedding，机器人立即可检索）
#   创建时就给 available 会跳过 enqueue_crawl_job（它只在 in_progress 时排队），
#   所以既不会调 Firecrawl，也不会去抓那个假的 external_link。

require 'json'

path = ARGV.find { |a| !a.start_with?('--') }
abort 'usage: rails runner publish_kb.rb <kb.json> [--dry-run] [--only=slug1,slug2] [--prune]' if path.nil?
abort "file not found: #{path}" unless File.exist?(path)

dry_run = ARGV.include?('--dry-run')
prune = ARGV.include?('--prune')
only_arg = ARGV.find { |a| a.start_with?('--only=') }
only = only_arg ? only_arg.split('=', 2).last.split(',').map(&:strip).reject(&:empty?) : nil

payload = JSON.parse(File.read(path))
assistant = Captain::Assistant.find(payload.fetch('assistant_id'))

# --status / --retry-empty：投递后过几分钟跑一次。
#
# 为什么必须有这个：FaqGeneratorService 在 LLM 调用失败时 `rescue RubyLLM::Error` → 记日志 → 返回 []，
# 既不重试也不标记失败。表现就是「这个文档静默生成了 0 条 FAQ」，你在后台完全看不出来。
# 实测 27 个文档里就有 1 个这样翻车（手工重跑立刻成功，纯瞬时错误）。
if ARGV.include?('--status') || ARGV.include?('--retry-empty')
  retry_empty = ARGV.include?('--retry-empty')
  scope = assistant.documents.where("external_link LIKE 'xboard-ticket-kb://%'").order(:name)
  empty = []

  scope.each do |d|
    count = d.responses.count
    empty << d if count.zero?
    puts format('  %-52s %4d 条 FAQ%s', d.name, count, count.zero? ? '   <<< 空' : '')
  end

  puts
  puts "共 #{scope.count} 个文档，#{scope.sum { |d| d.responses.count }} 条 FAQ，其中 #{empty.size} 个文档为空"

  if empty.any? && retry_empty
    puts "\n重跑 #{empty.size} 个空文档的生成任务…"
    empty.each do |d|
      Captain::Documents::ResponseBuilderJob.perform_later(d)
      puts "  已入队: #{d.name}"
    end
  elsif empty.any?
    puts '加 --retry-empty 可以把这些空文档重新排队生成。'
  end

  exit 0
end

# --lint：体检已生成的 FAQ，挑出四类「生成侧」问题。
#
# 这些问题源头是干净的，是模型在生成时自己产出的，所以只能在产出后查：
#   1. 域名被打散（模型会把域名逐字符拆开，客户照抄打不开）
#   2. 绝对时间戳被写成通用答案（"2026-08-15 12:18 才会重置流量"）
#   3. 个案动作播报（"已完成提现处理"）
#   4. 脱敏占位符被原样抄进 FAQ（"[订单号]"），读起来像 bug
if ARGV.include?('--lint')
  # 域名清单来自 kb.json（插件配置 kb_keep_domains），不写死站点信息
  known_domains = Array(payload['keep_domains']).map(&:to_s).reject(&:empty?)
  checks = {
    '域名被打散' => ->(t) { known_domains.any? { |d| t.gsub(/\s+/, '').downcase.include?(d) && !t.downcase.include?(d) } },
    '绝对时间戳' => ->(t) { t.match?(%r{20\d\d[-/]\d{1,2}[-/]\d{1,2}}) },
    '个案动作播报' => ->(t) { t.match?(/已经?(?:给|帮|为)\s*[你您]/) || t.include?('已完成提现') },
    '占位符泄漏' => ->(t) { t.match?(/\[(?:邮箱|订单号|具体时间|令牌|UUID|手机号|IP|TG账号|订阅链接)\]/) }
  }

  rows = assistant.documents
                  .where("external_link LIKE 'xboard-ticket-kb://%'")
                  .flat_map { |d| d.responses.to_a }
  flagged = Hash.new { |h, k| h[k] = [] }

  rows.each do |r|
    text = "#{r.question}\n#{r.answer}"
    checks.each { |name, probe| flagged[name] << r if probe.call(text) }
  end

  puts "体检 #{rows.size} 条 FAQ"
  checks.each_key do |name|
    hits = flagged[name]
    puts "\n== #{name}: #{hits.size} 条"
    hits.first(10).each do |r|
      puts "  ##{r.id} [doc #{r.documentable_id}]#{r.edited ? ' (人工改过)' : ''} #{r.question.to_s[0, 46]}"
      puts "        #{r.answer.to_s.gsub(/\s+/, ' ')[0, 100]}"
    end
    puts "  …还有 #{hits.size - 10} 条" if hits.size > 10
  end

  total = flagged.values.flatten.uniq.size
  puts "\n合计 #{total} 条需要人工看（后台 Captain → Knowledge 可直接改或删）"
  exit 0
end

# --review：把已生成的工单 FAQ 全量导出成纯文本，方便一次性通读复核
# （Captain 后台一条条点太慢；发现不合适的记下 id，用后台 Knowledge 页删除或改写）
if ARGV.include?('--review')
  docs_scope = assistant.documents.where("external_link LIKE 'xboard-ticket-kb://%'").order(:name)
  puts "共 #{docs_scope.count} 个工单知识文档"
  docs_scope.each do |d|
    puts "\n=== [doc ##{d.id}] #{d.name} — #{d.responses.count} 条 FAQ ==="
    d.responses.order(:id).each do |r|
      puts "  ##{r.id} 问：#{r.question}"
      puts "        答：#{r.answer.to_s.gsub(/\s+/, ' ')}"
    end
  end
  exit 0
end
docs = payload.fetch('docs')
docs = docs.select { |d| only.any? { |o| d['slug'] == o || d['slug'].start_with?("#{o}-") } } if only

puts "assistant: ##{assistant.id} #{assistant.name}"
puts "payload:   #{payload['generated_at']} / #{payload.fetch('docs').size} docs (selected: #{docs.size})"
puts "mode:      #{dry_run ? 'DRY-RUN' : 'APPLY'}#{prune ? ' +prune' : ''}"
puts

stats = Hash.new(0)

docs.each do |doc|
  link = doc.fetch('external_link')
  content = doc.fetch('content')
  existing = assistant.documents.find_by(external_link: link)

  if existing.nil?
    stats[:created] += 1
    puts format('  + %-52s %5d chars  (new)', doc['name'], content.length)
    next if dry_run

    assistant.documents.create!(
      name: doc.fetch('name'),
      external_link: link,
      status: :available,
      content: content
    )
  elsif existing.content == content
    stats[:unchanged] += 1
    puts format('  = %-52s %5d chars  (unchanged, no LLM call)', doc['name'], content.length)
  else
    stats[:updated] += 1
    puts format('  ~ %-52s %5d chars  (content changed -> FAQ 重新生成)', doc['name'], content.length)
    next if dry_run

    # content 变化会重新触发 ResponseBuilderJob；它会删掉本文档下未被人工编辑过的旧 FAQ，
    # 人工编辑过的（edited=true）保留
    existing.update!(name: doc.fetch('name'), status: :available, content: content)
  end
end

if prune
  keep = docs.map { |d| d['external_link'] }
  orphans = assistant.documents
                     .where("external_link LIKE 'xboard-ticket-kb://%'")
                     .where.not(external_link: keep)
  orphans.each do |o|
    stats[:pruned] += 1
    puts format('  - %-52s (removed, %d FAQ 一并删除)', o.name, o.responses.count)
    o.destroy unless dry_run
  end
end

puts
puts "created=#{stats[:created]} updated=#{stats[:updated]} unchanged=#{stats[:unchanged]} pruned=#{stats[:pruned]}"

unless dry_run
  total = assistant.documents.where("external_link LIKE 'xboard-ticket-kb://%'").count
  puts "工单知识库文档总数: #{total}"
  puts 'FAQ 由 Sidekiq(low) 队列异步生成，稍等片刻后在 Captain → Knowledge 里查看。'
end
