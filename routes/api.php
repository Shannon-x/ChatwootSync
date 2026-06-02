<?php

/**
 * ChatwootSync 插件路由
 *
 * 注意：Xboard PluginManager::loadRoutes() 只应用 'api' middleware group，
 *      不会自动加 /api 前缀，所以这里必须写完整路径。
 *      使用 FQCN array-callable 形式，不依赖 ->namespace() 包裹。
 */

use Illuminate\Support\Facades\Route;
use Plugin\ChatwootSync\Controllers\SyncController;
use Plugin\ChatwootSync\Controllers\WidgetController;

// Chatwoot 推送过来的 webhook（contact_created / contact_updated）
Route::post('/api/v1/plugin/chatwoot/sync', [SyncController::class, 'handle']);

// Xboard 用户前端注入的 widget.js（动态生成 + 强制 no-store）
Route::get('/api/v1/plugin/chatwoot/widget.js', [WidgetController::class, 'widgetJs']);

// widget.js 客户端 fetch 取当前登录用户身份（要求 Authorization: Bearer ...）
// 跨域场景浏览器会先发 OPTIONS preflight，identityPreflight 处理 CORS 协商
Route::get('/api/v1/plugin/chatwoot/widget-identity', [WidgetController::class, 'identity']);
Route::options('/api/v1/plugin/chatwoot/widget-identity', [WidgetController::class, 'identityPreflight']);

// 健康检查（不暴露敏感值，仅返回配置完整度）
Route::get('/api/v1/plugin/chatwoot/health', [SyncController::class, 'health']);
