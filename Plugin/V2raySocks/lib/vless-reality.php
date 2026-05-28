<?php
/**
 * V2raySocks VLESS-Reality Support Module
 * 支持 VLESS-Reality 协议和 xrayR 后端对接
 */

require_once 'lib/xrayR-api.php';

/**
 * 生成 VLESS-Reality 连接字符串
 * 格式: vless://uuid@ip:port?encryption=none&flow=xtls-rprx-vision&security=reality&sni=domain&fp=chrome&pbk=publickey&sid=shortid#remarks
 */
function V2raySocks_make_vless_reality($nodeInfo, $uuid, $remarks = '') {
    $config = [
        'encryption' => 'none',
        'flow' => 'xtls-rprx-vision', // 或 xtls-rprx-vision-udp443
        'security' => 'reality',
        'sni' => $nodeInfo['sni'] ?? 'www.microsoft.com',
        'fp' => $nodeInfo['fingerprint'] ?? 'chrome',
        'pbk' => $nodeInfo['publicKey'] ?? '',
        'sid' => $nodeInfo['shortId'] ?? '',
        'spx' => $nodeInfo['spiderX'] ?? '/'
    ];

    $query = http_build_query($config);
    $url = "vless://{$uuid}@{$nodeInfo['ip']}:{$nodeInfo['port']}?{$query}";
    
    if (!empty($remarks)) {
        $url .= '#' . urlencode($remarks);
    }

    return $url;
}

/**
 * 生成 VLESS-Reality JSON 配置 (用于客户端)
 */
function V2raySocks_generate_vless_reality_json($nodeInfo, $uuid, $remarks = '') {
    return json_encode([
        'v' => '2',
        'ps' => $remarks,
        'add' => $nodeInfo['ip'],
        'port' => $nodeInfo['port'],
        'id' => $uuid,
        'aid' => 0,
        'net' => 'tcp',
        'type' => 'none',
        'tls' => 'reality',
        'sni' => $nodeInfo['sni'] ?? 'www.microsoft.com',
        'flow' => 'xtls-rprx-vision',
        'fp' => $nodeInfo['fingerprint'] ?? 'chrome',
        'pbk' => $nodeInfo['publicKey'] ?? '',
        'sid' => $nodeInfo['shortId'] ?? '',
        'spx' => $nodeInfo['spiderX'] ?? '/'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 在 xrayR 上创建用户
 */
function V2raySocks_createAccountOnXrayR($params) {
    try {
        $serviceId = $params['serviceid'];
        $clientId = $params['clientsdetails']['userid'];
        
        // 获取套餐配置
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        if (!$package) {
            return "错误: 无法找到套餐信息";
        }

        // 获取服务器信息
        $server = Capsule::table('tblservers')->where('id', $params['serverid'])->first();
        if (!$server) {
            return "错误: 无法找到服务器信息";
        }

        // 获取 xrayR 配置
        $xrayRServers = json_decode($package->configoption2 ?? '[]', true);
        if (empty($xrayRServers)) {
            return "错误: 未配置 xrayR 服务器";
        }

        // 生成 UUID
        $uuid = V2raySocks_GenerateUuid();
        
        // 生成流量 (字节)
        $trafficLimit = floatval($package->configoption3 ?? 0) * 1024 * 1024 * 1024; // GB to Bytes

        // 在每个 xrayR 节点上创建用户
        $results = [];
        foreach ($xrayRServers as $xrayRServer) {
            try {
                $client = new XrayRClient(
                    $xrayRServer['baseUrl'],
                    $xrayRServer['apiKey'],
                    $xrayRServer['nodeId']
                );

                $result = $client->addUser(
                    $uuid,
                    $params['clientsdetails']['email'],
                    intval($trafficLimit),
                    0 // 不设置过期时间，由 WHMCS 管理
                );

                $results[] = [
                    'server' => $xrayRServer['name'],
                    'status' => 'success',
                    'uuid' => $uuid
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'server' => $xrayRServer['name'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                logModuleCall('V2raySocks', 'createAccountOnXrayR', $xrayRServer, $e->getMessage());
            }
        }

        // 保存用户信息到本地数据库 (用于记录和统计)
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'username' => $uuid, // 存储 UUID
            'password' => encrypt(json_encode($results)), // 存储创建结果
        ]);

        return "账户创建成功";

    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_createAccountOnXrayR', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 在 xrayR 上删除用户
 */
function V2raySocks_terminateAccountOnXrayR($params) {
    try {
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $xrayRServers = json_decode($package->configoption2 ?? '[]', true);
        
        $email = $params['clientsdetails']['email'];
        $results = [];

        foreach ($xrayRServers as $xrayRServer) {
            try {
                $client = new XrayRClient(
                    $xrayRServer['baseUrl'],
                    $xrayRServer['apiKey'],
                    $xrayRServer['nodeId']
                );

                $client->deleteUser($email);
                
                $results[] = [
                    'server' => $xrayRServer['name'],
                    'status' => 'deleted'
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'server' => $xrayRServer['name'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                logModuleCall('V2raySocks', 'terminateAccountOnXrayR', $xrayRServer, $e->getMessage());
            }
        }

        return "账户已删除";

    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_terminateAccountOnXrayR', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 从 xrayR 获取用户使用统计
 */
function V2raySocks_getUserStatsFromXrayR($email, $xrayRServer) {
    try {
        $client = new XrayRClient(
            $xrayRServer['baseUrl'],
            $xrayRServer['apiKey'],
            $xrayRServer['nodeId']
        );

        return $client->getUserStats($email);

    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'getUserStatsFromXrayR', $xrayRServer, $e->getMessage());
        return null;
    }
}

/**
 * 同步用户流量统计
 */
function V2raySocks_syncTrafficFromXrayR($params) {
    try {
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $xrayRServers = json_decode($package->configoption2 ?? '[]', true);
        $email = $params['clientsdetails']['email'];

        $totalUpload = 0;
        $totalDownload = 0;

        foreach ($xrayRServers as $xrayRServer) {
            $stats = V2raySocks_getUserStatsFromXrayR($email, $xrayRServer);
            if ($stats) {
                $totalUpload += $stats['upload'];
                $totalDownload += $stats['download'];
            }
        }

        // 保存到本地数据库用于显示
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'notes' => 'Upload: ' . $totalUpload . ', Download: ' . $totalDownload
        ]);

        return true;

    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'syncTrafficFromXrayR', $params, $e->getMessage());
        return false;
    }
}

/**
 * 获取用户配置字符串 (含 VLESS-Reality)
 */
function V2raySocks_getUserConfigVlessReality($params) {
    try {
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $xrayRServers = json_decode($package->configoption4 ?? '[]', true); // 节点配置
        $uuid = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('username');

        $configs = [];
        foreach ($xrayRServers as $node) {
            $vlessUrl = V2raySocks_make_vless_reality($node, $uuid, $node['name'] ?? 'Node');
            $configs[] = $vlessUrl;
        }

        return implode("\n", $configs);

    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'getUserConfigVlessReality', $params, $e->getMessage());
        return '';
    }
}
