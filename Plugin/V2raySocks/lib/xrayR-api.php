<?php
/**
 * xrayR API Integration
 * Support for VLESS-Reality protocol management
 * 
 * xrayR 是一个功能强大的 Xray/V2Ray 管理后端
 * 支持多个节点、多个协议、用户管理、流量统计等
 */

class XrayRClient {
    private $baseUrl;
    private $apiKey;
    private $nodeId;
    private $timeout = 10;

    public function __construct($baseUrl, $apiKey, $nodeId) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->nodeId = $nodeId;
    }

    /**
     * 发送 HTTP 请求到 xrayR 服务器
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->baseUrl . '/api' . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("HTTP 请求错误: " . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("xrayR 返回错误 (HTTP $httpCode): " . $response);
        }

        return json_decode($response, true);
    }

    /**
     * 创建新用户 (VLESS-Reality)
     * @param string $uuid 用户 UUID
     * @param string $email 用户邮箱 (用作用户标识)
     * @param int $trafficLimit 流量限制 (字节)
     * @param int $expiryTime 过期时间 (unix timestamp)
     */
    public function addUser($uuid, $email, $trafficLimit = 0, $expiryTime = 0) {
        $payload = [
            'nodeId' => (int)$this->nodeId,
            'uuid' => $uuid,
            'email' => $email,
            'limitIp' => 0,
            'limitDevices' => 0,
            'traffic' => $trafficLimit,
            'expiryTime' => $expiryTime * 1000 // xrayR 使用毫秒时间戳
        ];

        return $this->request('POST', '/user', $payload);
    }

    /**
     * 更新用户信息
     */
    public function updateUser($uuid, $email, $trafficLimit = 0, $expiryTime = 0) {
        $payload = [
            'nodeId' => (int)$this->nodeId,
            'uuid' => $uuid,
            'email' => $email,
            'traffic' => $trafficLimit,
            'expiryTime' => $expiryTime * 1000
        ];

        return $this->request('PUT', '/user/' . urlencode($email), $payload);
    }

    /**
     * 删除用户
     */
    public function deleteUser($email) {
        return $this->request('DELETE', '/user/' . urlencode($email));
    }

    /**
     * 获取用户信息
     */
    public function getUser($email) {
        return $this->request('GET', '/user/' . urlencode($email));
    }

    /**
     * 获取用户流量使用情况
     */
    public function getUserStats($email) {
        $response = $this->getUser($email);
        if (isset($response['data'])) {
            return [
                'upload' => $response['data']['up'] ?? 0,
                'download' => $response['data']['down'] ?? 0,
                'traffic' => $response['data']['traffic'] ?? 0,
                'expiryTime' => ($response['data']['expiryTime'] ?? 0) / 1000
            ];
        }
        return null;
    }

    /**
     * 重置用户流量
     */
    public function resetUserTraffic($email) {
        $payload = [
            'email' => $email,
            'traffic' => 0,
            'up' => 0,
            'down' => 0
        ];

        return $this->request('POST', '/user/' . urlencode($email) . '/reset', $payload);
    }

    /**
     * 获取节点信息
     */
    public function getNodeInfo() {
        return $this->request('GET', '/node/' . $this->nodeId);
    }

    /**
     * 获取所有用户列表
     */
    public function listUsers($page = 1, $pageSize = 100) {
        $endpoint = '/node/' . $this->nodeId . '/users?page=' . $page . '&pageSize=' . $pageSize;
        return $this->request('GET', $endpoint);
    }

    /**
     * 批量添加用户
     */
    public function batchAddUsers($users) {
        $payload = [
            'nodeId' => (int)$this->nodeId,
            'users' => $users
        ];

        return $this->request('POST', '/users/batch', $payload);
    }

    /**
     * 获取协议支持列表
     */
    public function getSupportedProtocols() {
        return $this->request('GET', '/node/' . $this->nodeId . '/protocols');
    }

    /**
     * 检查节点健康状态
     */
    public function healthCheck() {
        try {
            $response = $this->request('GET', '/health');
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }
}

/**
 * 辅助函数：获取 xrayR 客户端实例
 */
function V2raySocks_getXrayRClient($nodeId) {
    $baseUrl = V2raySocks_getOption('xrayR_api_url');
    $apiKey = V2raySocks_getOption('xrayR_api_key');
    
    if (empty($baseUrl) || empty($apiKey)) {
        throw new \Exception("xrayR 配置未完成，请在模块设置中配置 API 地址和密钥");
    }

    return new XrayRClient($baseUrl, $apiKey, $nodeId);
}

/**
 * 辅助函数：获取模块选项
 */
function V2raySocks_getOption($key) {
    global $whmcs;
    
    // 从 WHMCS 配置中获取
    if (function_exists('get_query_params')) {
        $params = get_query_params('V2raySocks');
        return $params[$key] ?? null;
    }
    
    return null;
}
