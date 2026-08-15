#!/usr/bin/env bash
#
# 每周自动跑一轮：编译工单 → 投递 → 等生成 → 补空文档 → 体检
#
# crontab 用法（宿主机 root）：
#   0 4 * * 1 /opt/1panel/www/sites/xboard/index/plugins/ChatwootSync/scripts/weekly-kb.sh >> /var/log/xboard-kb.log 2>&1
#
# 环境变量：
#   KB_WAIT_SECONDS  投递后等 Sidekiq 生成的秒数（默认 300；冒烟测试时可设小）
#   KB_MONTHS        时间窗，默认读插件配置
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CHATWOOT_CONTAINER="${CHATWOOT_CONTAINER:-chatwoot-web}"
WAIT="${KB_WAIT_SECONDS:-300}"

log() { echo "[$(date '+%F %T')] $*"; }

runner() {
  docker exec "$CHATWOOT_CONTAINER" bundle exec rails runner /tmp/publish_kb.rb /tmp/xboard-kb.json "$@" 2>&1 \
    | grep -vE 'RubyLLM|migration guide|ip_lookup:setup|^W, |^I, |^$' || true
}

log "===== 工单知识库周更开始 ====="

# publish-kb.sh 内部会把 kb.json 和 publish_kb.rb 送进 Chatwoot 容器，
# 后面的 --retry-empty / --lint 直接复用那两个文件
"$SCRIPT_DIR/publish-kb.sh"

log "等待 ${WAIT}s 让 Sidekiq 把 FAQ 生成完"
sleep "$WAIT"

log "--- 检查空文档（LLM 瞬时失败会静默生成 0 条）---"
runner --retry-empty

log "--- 体检生成结果 ---"
runner --lint

log "===== 周更结束 ====="
