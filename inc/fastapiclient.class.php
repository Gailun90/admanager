<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerFastApiClient
{
    private static ?self $instance = null;
    private array $cfg = [];

    private function __construct() {
        $cfg = PluginAdmanagerConfig::getFastApiConfig();
        // fastapi_token 在 GLPI 中以 sodium 加密存储，需解密后使用
        if (!empty($cfg['token'])) {
            $key = new GLPIKey();
            $decrypted = $key->decrypt($cfg['token']);
            $cfg['token'] = !empty($decrypted) ? $decrypted : $cfg['token'];
        }
        $this->cfg = $cfg;
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function get(string $path, array $query = []): array {
        return $this->request('GET', $path, $query);
    }
    /** POST 请求走 query params（而非 JSON body） */
    public function postQuery(string $path, array $query = []): array {
        return $this->request('POST', $path, $query, []);
    }


    public function post(string $path, array $body = []): array {
        return $this->request('POST', $path, [], $body);
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array {
        $url = $this->cfg['url'] . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->cfg['token'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("cURL 错误：{$err}");
        if ($code >= 400) throw new \RuntimeException("FastAPI 返回 HTTP {$code}：{$raw}");

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            throw new \RuntimeException('FastAPI 返回非 JSON 响应');
        return $data;
    }

    // ── 封装常用接口 ──────────────────────────────────────────────────────────


    /** 删除指定客户端（及关联数据） */
    public function deleteClient(int $client_id): array {
        return $this->delete("/api/clients/{$client_id}");
    }

    /** 获取终端列表（用于手动导入选择） */

    public function patch(string $path, array $query = []): array {
        return $this->request('PATCH', $path, $query);
    }

    public function delete(string $path, array $query = []): array {
        return $this->request('DELETE', $path, $query);
    }

    public function getClients(int $page = 1, int $limit = 50): array {
        return $this->get('/api/export/clients', ['page' => $page, 'limit' => $limit]);
    }

    /** 获取指定终端软件清单 */
    public function getClientSoftware(int $client_id): array {
        return $this->get("/api/export/software/{$client_id}");
    }

    /** 获取差异报告统计 */
    public function getDiffStats(): array {
        return $this->get('/api/dashboard/diff');
    }

    /** 获取仪表盘概览 */
    public function getDashboard(): array {
        return $this->get('/api/dashboard');
    }
}
