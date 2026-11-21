# Run inside Chatwoot. Read the dedicated XBoard service credential from standard input.
require 'uri'
require_relative 'website-support-routing'

check = ARGV.delete('--check')
stage = ARGV.delete('--stage')
routing_only = ARGV.delete('--routing-only')
abort 'Choose --stage alone' if stage && (check || routing_only)
abort 'Unexpected arguments' unless ARGV.empty?

account = Account.find(Integer(ENV.fetch('CW_ACCOUNT_ID')))
inbox = account.inboxes.find(Integer(ENV.fetch('CW_WEBSITE_INBOX_ID')))
assistant = account.captain_assistants.find(Integer(ENV.fetch('CW_ASSISTANT_ID')))
abort 'Website inbox is not connected to the specified assistant' unless inbox.web_widget? && inbox.captain_assistant == assistant
abort 'Use a website-only assistant for website tools' if assistant.inboxes.where.not(id: inbox.id).exists?
abort 'Captain custom tools are not enabled for this account' unless account.feature_enabled?('custom_tools')

endpoint = URI.parse(ENV.fetch('XBOARD_SUPPORT_BASE'))
abort 'Expected an HTTPS support endpoint' unless endpoint.is_a?(URI::HTTPS) && endpoint.host.present? && endpoint.userinfo.nil? &&
                                                endpoint.query.nil? && endpoint.fragment.nil? && endpoint.path == '/api/v1/plugin/chatwoot/support'
unless routing_only
  secret = $stdin.read.strip
  abort 'Missing service credential' unless secret.bytesize >= 32
end

def parameter(name, description, required: false, type: 'string')
  { 'name' => name, 'type' => type, 'description' => description, 'required' => required }
end

tools = [
  ['plans', 'XBoard Plans', '查询面板当前在售套餐、周期价格及公开权益。用于售前或比较套餐。若结果含 has_more，可缩小关键词或继续翻页。',
   [parameter('keyword', '可选的套餐名称关键词。'), parameter('page', '目录页码，从 1 开始。', type: 'integer')]],
  ['connection', 'XBoard Connection', '核实本人套餐、到期和节点上报。流量消耗、钱包余额、倍率、赠送或保底疑问请传 detail=usage；涉及购买到账时同轮再查本人订单。节点心跳只反映最近上报。',
   [parameter('node', '仅排查线路时传节点显示名或地区关键词。'),
    parameter('detail', '核实流量、钱包、计费倍率、入账和保底时填 usage；排查线路、套餐到期或节点状态时省略。')]],
  ['orders', 'XBoard Orders', '核对本人近期套餐和充值订单的实付、状态、赠送和流量入账。客户问购买后流量去了哪里，配合用量明细核对，先查再索取凭证。仍需财务核查时引导提交面板服务工单。',
   [parameter('trade_no', '客户明确提供的完整面板订单号。未提供时查询近期订单。')]],
  ['offers', 'XBoard Notices and Offers', '查询已登录客户当前可见的最新公告与公告披露的有效优惠。用户询问活动、维护公告或优惠码时查询。', []],
  ['quote', 'XBoard Coupon Quote', '试算本人所选套餐、周期和可选优惠码。先查询套餐目录取得有效 ID。试算不锁定库存或优惠，不下单。',
   [parameter('plan_id', '在售套餐 ID。', required: true, type: 'integer'),
    parameter('period', '目录返回的付款周期。', required: true),
    parameter('coupon_code', '客户提供或当前公告披露的优惠码。'),
    parameter('traffic_gb', '客户明确选择的流量档位 GB。', type: 'integer'),
    parameter('addon_group_ids', '选择的增值线路组 ID，以英文逗号分隔。')]]
]

changed_scenarios = []
ActiveRecord::Base.transaction do
  assistant.lock! unless check
  tools.each do |path, title, description, parameters|
    slug = "custom_#{title.parameterize(separator: '_')}"
    tool = assistant.custom_tools.lock(!check).find_or_initialize_by(slug: slug)
    url = "#{endpoint}/#{path}"
    abort "Existing unrelated tool uses #{slug}" if tool.persisted? && tool.endpoint_url.split('?').first != url

    abort "Cannot stage an already enabled tool #{slug}" if stage && tool.persisted? && tool.enabled?

    query = parameters.map { |p| "#{p['name']}={{ #{p['name']} | url_encode }}" }.join('&')
    description += ' 个人资料仅来自当前面板登录。若未能获取，请客户登录面板并从该页面打开客服，勿索取邮箱或验证码。' unless path == 'plans'
    target_url = query.empty? ? url : "#{url}?#{query}"
    if routing_only
      abort "Expected an enabled website GET tool #{slug}" unless tool.persisted? && tool.enabled? && tool.http_method == 'GET'
      next unless %w[orders connection].include?(path)

      tool.assign_attributes(description: description, endpoint_url: target_url, param_schema: parameters)
    else
      tool.assign_attributes(account: account, title: title, description: description, http_method: 'GET',
                             endpoint_url: target_url, auth_type: 'bearer',
                             auth_config: { token: secret }, param_schema: parameters, enabled: !stage,
                             request_template: nil, response_template: nil, headers: { 'Accept' => 'application/json' },
                             settings: { 'requires_email_verification' => false })
    end
    tool.validate!
    tool.tool(assistant)
    tool.save! if !check && tool.changed?
  end
  unless stage
    guides = [WebsiteSupportRouting::MAIN_GUIDE, WebsiteSupportRouting::TECH_REMINDERS]
    assistant.response_guidelines = WebsiteSupportRouting.master_rules(assistant.response_guidelines, guides)
    assistant.guardrails = WebsiteSupportRouting.master_rules(assistant.guardrails, [WebsiteSupportRouting::MAIN_GUARD])
    # Only a full install configures the contact snapshot preference. A routing update preserves installation settings.
    assistant.config = assistant.config.merge('feature_contact_attributes' => false) unless routing_only
    assistant.validate!
    assistant.save! if !check && assistant.changed?

    assistant.scenarios.enabled.where(title: WebsiteSupportRouting::SCENARIOS.keys).lock(!check).each do |scenario|
      kind = WebsiteSupportRouting::SCENARIOS.fetch(scenario.title)
      scenario.instruction = WebsiteSupportRouting.instructions(kind, scenario.instruction)
      scenario.description = WebsiteSupportRouting.description(kind, scenario.description)
      next unless scenario.changed?

      changed_scenarios << scenario.id
      # On a fresh --check the tools have only been built in memory. An apply validates them
      # against the newly persisted tools, and scenario.save! derives its bindings from the links.
      if check
        scenario.validate! if routing_only
      else
        scenario.save!
      end
    end
  end
end

action = check ? 'Validated' : (stage ? 'Staged' : 'Enabled')
puts "#{action} #{tools.size} website read-only tools for assistant #{assistant.id} / inbox #{inbox.id}; changed scenarios: #{changed_scenarios.join(', ')}"
