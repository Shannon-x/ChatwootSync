#!/usr/bin/env bash
#
# 宿主机侧一键投递：Xboard 工单 → Captain 知识文档 → Captain 自动生成 FAQ
#
# 用法：
#   ./publish-kb.sh --dry-run                 # 全流程演练，不写 Chatwoot
#   ./publish-kb.sh --only=refund,invite      # 只投递指定分类（首次上线建议先投 1-2 类看质量）
#   ./publish-kb.sh                           # 全量投递（内容没变的文档会跳过，不烧 LLM）
#   ./publish-kb.sh --prune                   # 额外删除 JSON 里已不存在的旧知识文档
#
# 环境要求：在这台宿主机上执行，能 docker exec 到 Xboard 和 Chatwoot 两个容器。
set -euo pipefail

XBOARD_CONTAINER="${XBOARD_CONTAINER:-index-web-1}"
CHATWOOT_CONTAINER="${CHATWOOT_CONTAINER:-chatwoot-web}"
MONTHS="${KB_MONTHS:-3}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KB_JSON_IN_XBOARD="/www/plugins/ChatwootSync/storage/kb.json"
KB_JSON_ON_HOST="${SCRIPT_DIR}/../storage/kb.json"

PASSTHRU=()
for arg in "$@"; do
  case "$arg" in
    --months=*) MONTHS="${arg#*=}" ;;
    *) PASSTHRU+=("$arg") ;;
  esac
done

echo "==> [1/3] 在 Xboard 容器里编译工单知识库（最近 ${MONTHS} 个月）"
docker exec "$XBOARD_CONTAINER" php artisan chatwoot:ticket-kb --months="$MONTHS" --samples=0 --out="$KB_JSON_IN_XBOARD"

if [[ ! -f "$KB_JSON_ON_HOST" ]]; then
  echo "找不到 $KB_JSON_ON_HOST —— plugins 目录应当是 bind mount，请检查 compose 挂载" >&2
  exit 1
fi

echo
echo "==> [2/3] 把 JSON 和投递脚本送进 Chatwoot 容器"
docker cp "$KB_JSON_ON_HOST" "${CHATWOOT_CONTAINER}:/tmp/xboard-kb.json"
docker cp "${SCRIPT_DIR}/publish_kb.rb" "${CHATWOOT_CONTAINER}:/tmp/publish_kb.rb"

echo
echo "==> [3/3] 写入 Captain 文档（Captain 会自动生成 FAQ）"
docker exec "$CHATWOOT_CONTAINER" bundle exec rails runner /tmp/publish_kb.rb /tmp/xboard-kb.json ${PASSTHRU[@]+"${PASSTHRU[@]}"}
