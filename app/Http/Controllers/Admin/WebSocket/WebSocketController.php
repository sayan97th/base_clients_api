<?php

namespace App\Http\Controllers\Admin\WebSocket;

use App\Events\GenericTestEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebSocketController extends Controller
{
    private function getReverbConfig(): array
    {
        return [
            'host'   => env('REVERB_HOST', 'localhost'),
            'port'   => (int) env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'app_id' => env('REVERB_APP_ID'),
            'key'    => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
        ];
    }

    private function buildPusherSignedUrl(string $method, string $path, array $extra_params = []): string
    {
        $cfg = $this->getReverbConfig();

        $params = array_merge([
            'auth_key'       => $cfg['key'],
            'auth_timestamp' => time(),
            'auth_version'   => '1.0',
        ], $extra_params);

        ksort($params);

        $query_string    = http_build_query($params);
        $signature_string = strtoupper($method) . "\n" . $path . "\n" . $query_string;
        $signature        = hash_hmac('sha256', $signature_string, $cfg['secret']);

        $params['auth_signature'] = $signature;

        return "{$cfg['scheme']}://{$cfg['host']}:{$cfg['port']}{$path}?" . http_build_query($params);
    }

    /**
     * GET /api/admin/websocket/status
     * Check if the Reverb server is reachable and return server stats.
     */
    public function status(): JsonResponse
    {
        $cfg         = $this->getReverbConfig();
        $server_time = now()->toIso8601String();

        $reverb_info = [
            'host'      => $cfg['host'],
            'port'      => $cfg['port'],
            'scheme'    => $cfg['scheme'],
            'reachable' => false,
        ];

        try {
            $path = "/apps/{$cfg['app_id']}/channels";
            $url  = $this->buildPusherSignedUrl('GET', $path);

            $response = Http::timeout(5)->get($url);

            if ($response->successful()) {
                $reverb_info['reachable'] = true;

                $stats = [
                    'active_connections' => 0,
                    'peak_connections'   => 0,
                    'uptime_seconds'     => 0,
                    'memory_usage_mb'    => 0,
                ];

                $channels = $response->json('channels', []);
                $stats['active_connections'] = count($channels);

                return response()->json([
                    'status'      => 'ok',
                    'reverb'      => $reverb_info,
                    'stats'       => $stats,
                    'server_time' => $server_time,
                ]);
            }
        } catch (Throwable) {
            // fall through to error response
        }

        return response()->json([
            'status'      => 'error',
            'message'     => 'Could not reach the Reverb server',
            'reverb'      => $reverb_info,
            'server_time' => $server_time,
        ], 503);
    }

    /**
     * POST /api/admin/websocket/broadcast
     * Trigger a test broadcast to any channel.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel'      => ['required', 'string'],
            'event'        => ['required', 'string'],
            'payload'      => ['sometimes', 'array'],
            'channel_type' => ['sometimes', 'string', 'in:public,private'],
        ]);

        $channel      = $validated['channel'];
        $event        = $validated['event'];
        $payload      = $validated['payload'] ?? [];
        $channel_type = $validated['channel_type'] ?? 'public';

        try {
            broadcast(new GenericTestEvent($channel, $event, $payload, $channel_type));

            return response()->json([
                'ok'             => true,
                'channel'        => $channel,
                'event'          => $event,
                'channel_type'   => $channel_type,
                'broadcasted_at' => now()->toIso8601ZuluString('millisecond'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Broadcast failed: ' . $e->getMessage(),
                'channel' => $channel,
                'event'   => $event,
            ], 500);
        }
    }

    /**
     * GET /api/admin/websocket/channels
     * List channels with active subscribers from the Reverb HTTP API.
     */
    public function channels(Request $request): JsonResponse
    {
        $filter     = $request->query('filter');
        $fetched_at = now()->toIso8601String();

        try {
            $cfg         = $this->getReverbConfig();
            $path        = "/apps/{$cfg['app_id']}/channels";
            $extra       = [];

            if ($filter) {
                $extra['filter_by_prefix'] = $filter;
            }

            $url      = $this->buildPusherSignedUrl('GET', $path, $extra);
            $response = Http::timeout(5)->get($url);

            if (!$response->successful()) {
                return response()->json([
                    'message'    => 'Could not retrieve channels from the Reverb server',
                    'channels'   => [],
                    'total'      => 0,
                ], 503);
            }

            $raw_channels = $response->json('channels', []);

            $channels = collect($raw_channels)
                ->map(function (array $data, string $name) {
                    $type               = str_starts_with($name, 'private-') ? 'private' : 'public';
                    $subscription_count = $data['subscription_count'] ?? 0;

                    return [
                        'name'               => $name,
                        'type'               => $type,
                        'subscription_count' => $subscription_count,
                        'occupied'           => $subscription_count > 0,
                    ];
                })
                ->values()
                ->all();

            return response()->json([
                'channels'   => $channels,
                'total'      => count($channels),
                'fetched_at' => $fetched_at,
            ]);
        } catch (Throwable) {
            return response()->json([
                'message'  => 'Could not retrieve channels from the Reverb server',
                'channels' => [],
                'total'    => 0,
            ], 503);
        }
    }
}
