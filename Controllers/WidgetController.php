<?php

namespace Plugin\ChatwootSync\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WidgetController extends Controller
{
    /**
     * 返回 widget.js（注入到 Xboard 主题 custom_html）
     *
     * URL: GET /api/v1/plugin/chatwoot/widget.js
     *
     * ⚠️ 重要：这个端点是浏览器以 <script src=...> 方式请求的子资源，
     *    Xboard 前端的 auth token 存在 localStorage（不在 cookie），
     *    所以这个请求**永远不会带身份**。
     *
     * 因此 widget.js 自身**只负责加载 Chatwoot SDK + 启动浮窗**；
     * 拿身份信息这一步交给客户端 JS：从 localStorage 取出 token，
     * 主动 fetch 一次 /widget-identity 接口获取 {email, identifier_hash}，
     * 再调 setUser()。
     */
    public function widgetJs(Request $request): Response
    {
        $config = app(PluginConfigService::class)->getDbConfig('chatwoot_sync');

        if (empty($config['enable_widget'])) {
            return $this->jsResponse("// ChatwootSync: widget disabled\n");
        }

        $baseUrl = rtrim((string) ($config['chatwoot_base_url'] ?? ''), '/');
        $widgetToken = (string) ($config['widget_token'] ?? '');
        $hmacSecret = (string) ($config['hmac_secret'] ?? '');

        if ($baseUrl === '' || $widgetToken === '') {
            return $this->jsResponse("// ChatwootSync: missing chatwoot_base_url or widget_token\n");
        }

        return response()
            ->view('ChatwootSync::widget', [
                'base_url' => $baseUrl,
                'widget_token' => $widgetToken,
                'identity_endpoint' => url('/api/v1/plugin/chatwoot/widget-identity'),
                'has_hmac_secret' => $hmacSecret !== '',
            ])
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * 返回当前登录用户的 Chatwoot 身份载荷（email + identifier_hash）
     *
     * URL: GET /api/v1/plugin/chatwoot/widget-identity
     *      Header: Authorization: Bearer <sanctum-token>
     *
     * 这是 widget.js 第二阶段主动调的端点。客户端从 localStorage 读出
     * auth_data，作为 Authorization Header 发过来，由 sanctum guard 解析。
     *
     * 响应：
     *   200 { authenticated: true,  identifier, email, name, identifier_hash }
     *   200 { authenticated: false } 未登录或 token 失效
     *   503 { error: "..." } 配置缺失
     */
    public function identity(Request $request): JsonResponse
    {
        $config = app(PluginConfigService::class)->getDbConfig('chatwoot_sync');

        if (empty($config['enable_widget'])) {
            return response()->json(['error' => 'widget_disabled'], 503);
        }

        $hmacSecret = (string) ($config['hmac_secret'] ?? '');
        if ($hmacSecret === '') {
            Log::warning('[ChatwootSync] identity skipped: hmac_secret missing');
            return response()->json(['error' => 'hmac_secret_not_configured'], 503);
        }

        $user = $this->resolveCurrentUser($request);
        if (!$user || empty($user->email)) {
            return response()->json(['authenticated' => false])
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'authenticated' => true,
            'identifier' => $user->email,
            'email' => $user->email,
            'name' => $user->email,
            'identifier_hash' => hash_hmac('sha256', $user->email, $hmacSecret),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if ($user instanceof User) {
                return $user;
            }
        } catch (\Throwable $e) {
            // ignore, fallthrough
        }

        // 兜底：从 Authorization header 手动解析（保险）
        try {
            $bearer = $request->bearerToken();
            if ($bearer && class_exists(\Laravel\Sanctum\PersonalAccessToken::class)) {
                $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
                if ($pat && $pat->tokenable instanceof User) {
                    return $pat->tokenable;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    private function jsResponse(string $body, int $status = 200): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}
