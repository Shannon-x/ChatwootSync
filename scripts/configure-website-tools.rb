# Run inside Chatwoot. Read the dedicated XBoard service credential from standard input.
require 'uri'

account = Account.find(Integer(ENV.fetch('CW_ACCOUNT_ID')))
inbox = account.inboxes.find(Integer(ENV.fetch('CW_WEBSITE_INBOX_ID')))
assistant = account.captain_assistants.find(Integer(ENV.fetch('CW_ASSISTANT_ID')))
abort 'Website inbox is not connected to the specified assistant' unless inbox.web_widget? && inbox.captain_assistant == assistant
abort 'Use a website-only assistant for website tools' if assistant.inboxes.where.not(id: inbox.id).exists?
abort 'Captain custom tools are not enabled for this account' unless account.feature_enabled?('custom_tools')

endpoint = URI.parse(ENV.fetch('XBOARD_SUPPORT_BASE'))
abort 'Expected an HTTPS support endpoint' unless endpoint.is_a?(URI::HTTPS) && endpoint.host.present? && endpoint.userinfo.nil? &&
                                                endpoint.query.nil? && endpoint.fragment.nil? && endpoint.path == '/api/v1/plugin/chatwoot/support'
secret = $stdin.read.strip
abort 'Missing service credential' unless secret.bytesize >= 32

def parameter(name, description, required: false, type: 'string')
  { 'name' => name, 'type' => type, 'description' => description, 'required' => required }
end

tools = [
  ['plans', 'XBoard Plans', '查询面板当前在售套餐、周期价格及公开权益。用于售前或比较套餐。若结果含 has_more，可缩小关键词或继续翻页。',
   [parameter('keyword', '可选的套餐名称关键词。'), parameter('page', '目录页码，从 1 开始。', type: 'integer')]],
  ['connection', 'XBoard Connection', '查询本人套餐和流量、线路权益、节点最近上报。遇到无法连接、节点缺失或套餐到期先查此项。心跳不证明客户线路可达。',
   [parameter('node', '可选的节点显示名或地区关键词。')]],
  ['orders', 'XBoard Orders', '核对本人套餐或充值订单及开通状态，区分待付、处理中与已完成。如客户称已扣款但状态不符请转人工。',
   [parameter('trade_no', '客户明确提供的完整面板订单号。未提供时查询近期订单。')]],
  ['offers', 'XBoard Notices and Offers', '查询已登录客户当前可见的最新公告与公告披露的有效优惠。用户询问活动、维护公告或优惠码时查询。', []],
  ['quote', 'XBoard Coupon Quote', '试算本人所选套餐、周期和可选优惠码。先查询套餐目录取得有效 ID。试算不锁定库存或优惠，不下单。',
   [parameter('plan_id', '在售套餐 ID。', required: true, type: 'integer'),
    parameter('period', '目录返回的付款周期。', required: true),
    parameter('coupon_code', '客户提供或当前公告披露的优惠码。'),
    parameter('traffic_gb', '客户明确选择的流量档位 GB。', type: 'integer'),
    parameter('addon_group_ids', '选择的增值线路组 ID，以英文逗号分隔。')]]
]

check = ARGV.delete('--check')
stage = ARGV.delete('--stage')
abort 'Choose either --stage or --check' if stage && check
abort 'Unexpected arguments' unless ARGV.empty?

assistant.with_lock do
  tools.each do |path, title, description, parameters|
    slug = "custom_#{title.parameterize(separator: '_')}"
    tool = assistant.custom_tools.find_or_initialize_by(slug: slug)
    url = "#{endpoint}/#{path}"
    abort "Existing unrelated tool uses #{slug}" if tool.persisted? && tool.endpoint_url.split('?').first != url

    abort "Cannot stage an already enabled tool #{slug}" if stage && tool.persisted? && tool.enabled?

    query = parameters.map { |p| "#{p['name']}={{ #{p['name']} | url_encode }}" }.join('&')
    description += ' 个人资料仅来自当前面板登录。若未能获取，请客户登录面板并从该页面打开客服，勿索取邮箱或验证码。' unless path == 'plans'
    tool.assign_attributes(account: account, title: title, description: description, http_method: 'GET',
                           endpoint_url: query.empty? ? url : "#{url}?#{query}", auth_type: 'bearer',
                           auth_config: { token: secret }, param_schema: parameters, enabled: !stage,
                           request_template: nil, response_template: nil, headers: { 'Accept' => 'application/json' },
                           settings: { 'requires_email_verification' => false })
    tool.validate!
    tool.tool(assistant)
    tool.save! unless check
  end
  # The contact attributes were populated from multiple channels, including unverified contacts.
  assistant.update!(config: assistant.config.merge('feature_contact_attributes' => false)) unless check || stage
end

action = check ? 'Validated' : (stage ? 'Staged' : 'Enabled')
puts "#{action} #{tools.size} website read-only tools for assistant #{assistant.id} / inbox #{inbox.id}"
