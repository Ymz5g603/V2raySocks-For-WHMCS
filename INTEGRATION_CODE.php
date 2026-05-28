<?php
/**
 * V2raySocks 主模块 - 修改版本，支持 xrayR 和 VLESS-Reality
 * 
 * 本文件展示主要修改点，应该集成到原有的 V2raySocks.php 中
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// 新增库文件包含
require_once 'lib/functions.php';
require_once 'lib/xrayR-api.php';
require_once 'lib/vless-reality.php';
require_once 'lib/config.php';

V2raySocks_multi_language_support();

/**
 * ========== 修改内容 1: 扩展配置选项 ==========
 */
function V2raySocks_ConfigOptions(){
    return array(
        // 原有选项 - 保留以保证兼容性
        V2raySocks_get_lang('database') => array(
            'Type' => 'text',
            'Size' => '25',
            'Description' => '数据库名称 (原有本地 V2Ray 用)'
        ),
        
        // 新增选项 - xrayR 配置
        'xrayR API URL' => array(
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'xrayR 服务器地址 (例: http://127.0.0.1:7890 或 http://xrayR.domain.com:7890)'
        ),
        
        'xrayR API Key' => array(
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'xrayR API 认证密钥'
        ),
        
        'xrayR 节点配置' => array(
            'Type' => 'textarea',
            'Rows' => '12',
            'Description' => '10台服务器的 xrayR 节点配置 (JSON 格式)'
        ),
        
        '协议选择' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'vmess' => 'VMESS (原有，本地 V2Ray)',
                'vless-reality' => 'VLESS-Reality (推荐，使用 xrayR)',
                'mixed' => 'VMESS + VLESS-Reality (两者都支持)'
            ),
            'Description' => '选择使用的代理协议'
        ),
        
        V2raySocks_get_lang('resetbandwidth') => array(
            'Type'        => 'dropdown',
            'Options'     => array(
                '3' => V2raySocks_get_lang('end_of_month'),
                '2' => V2raySocks_get_lang('start_of_month'),
                '1' => V2raySocks_get_lang('by_duedate_day'),
                '0' => V2raySocks_get_lang('neednot_reset')
            ),
            'Description' => V2raySocks_get_lang('resetbandwidth_description')
        ),
    );
}

/**
 * ========== 修改内容 2: 创建账户 ==========
 */
function V2raySocks_CreateAccount($params) {
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            // 使用 xrayR 创建 VLESS-Reality 账户
            return V2raySocks_CreateVlessRealityAccount($params);
        } else {
            // 使用原有的本地 V2Ray 方式
            return V2raySocks_CreateAccountLegacy($params);
        }
        
    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateAccount', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 创建 VLESS-Reality 账户 (使用 xrayR)
 */
function V2raySocks_CreateVlessRealityAccount($params) {
    try {
        $serviceId = $params['serviceid'];
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? ''; // xrayR API URL
        $xrayRKey = $params['configoption2'] ?? ''; // xrayR API Key
        $nodesJson = $params['configoption3'] ?? '[]'; // 节点配置
        
        if (empty($xrayRUrl) || empty($xrayRKey)) {
            return "错误: xrayR 配置不完整";
        }
        
        $nodes = json_decode($nodesJson, true);
        if (empty($nodes)) {
            return "错误: 节点配置为空";
        }
        
        // 生成用户 UUID
        $uuid = V2raySocks_GenerateUuid();
        
        // 从套餐获取流量
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $trafficGB = floatval($package->configoption1 ?? 0); // 从 configoption1 读取流量
        $trafficBytes = $trafficGB * 1024 * 1024 * 1024;
        
        // 在所有 xrayR 节点上创建用户
        $createdNodes = [];
        foreach ($nodes as $node) {
            try {
                $xrayRClient = new XrayRClient(
                    $xrayRUrl,
                    $xrayRKey,
                    $node['nodeId']
                );
                
                $xrayRClient->addUser(
                    $uuid,
                    $email,
                    intval($trafficBytes),
                    0 // 不设置过期时间
                );
                
                $createdNodes[] = $node['nodeId'];
                
            } catch (\Exception $e) {
                logModuleCall('V2raySocks', 'CreateVlessRealityAccount_Node', 
                             array('nodeId' => $node['nodeId']), $e->getMessage());
                // 继续创建其他节点，不中断
            }
        }
        
        if (empty($createdNodes)) {
            return "错误: 无法在任何节点上创建用户";
        }
        
        // 保存 UUID 到 WHMCS
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'username' => $uuid,
            'notes' => '协议: VLESS-Reality | 节点数: ' . count($createdNodes)
        ]);
        
        return "成功";
        
    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateVlessRealityAccount', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 原有的创建账户函数 (本地 V2Ray)
 */
function V2raySocks_CreateAccountLegacy($params) {
    try {
        $serviceId = $params['serviceid'];
        $dbhost = $params['serverip'] ?? 'localhost';
        $dbname = $params['configoption1'] ?? '';
        $dbuser = $params['serverusername'] ?? '';
        $dbpass = decrypt($params['serverpassword'] ?? '');
        
        if (empty($dbname)) {
            return "错误: 数据库配置不完整";
        }
        
        $db = new PDO('mysql:host=' . $dbhost . ';dbname=' . $dbname, $dbuser, $dbpass);
        
        // 检查用户是否已存在
        $check = $db->prepare('SELECT COUNT(*) FROM `user` WHERE `sid` = :sid');
        $check->execute([':sid' => $serviceId]);
        if ($check->fetchColumn()) {
            return "用户已存在";
        }
        
        // 生成 UUID
        $uuid = V2raySocks_GenerateUuid();
        
        // 获取流量限制
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $transferEnable = floatval($package->configoption1 ?? 0) * 1024 * 1024 * 1024;
        
        // 创建用户
        $stmt = $db->prepare('INSERT INTO `user`
                             (`uuid`,`u`,`d`,`transfer_enable`,`created_at`,`updated_at`,`need_reset`,`sid`) 
                             VALUES (:uuid,0,0,:transfer_enable,UNIX_TIMESTAMP(),0,1,:sid)');
        
        $stmt->execute([
            ':uuid' => $uuid,
            ':transfer_enable' => intval($transferEnable),
            ':sid' => $serviceId
        ]);
        
        return "成功";
        
    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateAccountLegacy', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * ========== 修改内容 3: 暂停/恢复账户 ==========
 */
function V2raySocks_SuspendAccount($params) {
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_SuspendVlessReality($params);
        } else {
            return V2raySocks_SuspendAccountLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_SuspendVlessReality($params) {
    try {
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? '';
        $xrayRKey = $params['configoption2'] ?? '';
        $nodesJson = $params['configoption3'] ?? '[]';
        
        $nodes = json_decode($nodesJson, true);
        
        foreach ($nodes as $node) {
            try {
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId']);
                $client->deleteUser($email);
            } catch (\Exception $e) {
                // 继续处理其他节点
            }
        }
        
        return "已暂停";
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

/**
 * ========== 修改内容 4: 恢复账户 ==========
 */
function V2raySocks_UnsuspendAccount($params) {
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            // 重新创建用户
            return V2raySocks_CreateVlessRealityAccount($params);
        } else {
            return V2raySocks_UnsuspendAccountLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

/**
 * ========== 修改内容 5: 获取用户信息 ==========
 */
function V2raySocks_AdminServicesTabFields($params) {
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_GetVlessRealityInfo($params);
        } else {
            return V2raySocks_AdminServicesTabFieldsLegacy($params);
        }
    } catch (\Exception $e) {
        return ['错误' => $e->getMessage()];
    }
}

function V2raySocks_GetVlessRealityInfo($params) {
    try {
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? '';
        $xrayRKey = $params['configoption2'] ?? '';
        $nodesJson = $params['configoption3'] ?? '[]';
        $uuid = Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('username');
        
        $nodes = json_decode($nodesJson, true);
        
        $totalUpload = 0;
        $totalDownload = 0;
        
        // 从所有节点汇总流量
        foreach ($nodes as $node) {
            try {
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId']);
                $stats = $client->getUserStats($email);
                
                if ($stats) {
                    $totalUpload += $stats['upload'];
                    $totalDownload += $stats['download'];
                }
            } catch (\Exception $e) {
                // 忽略单个节点错误
            }
        }
        
        $uploadMB = round($totalUpload / 1024 / 1024, 2);
        $downloadMB = round($totalDownload / 1024 / 1024, 2);
        $usedMB = round(($totalUpload + $totalDownload) / 1024 / 1024, 2);
        
        return array(
            'UUID' => $uuid,
            '协议' => 'VLESS-Reality',
            '节点数' => count($nodes),
            '上传 (MB)' => $uploadMB,
            '下载 (MB)' => $downloadMB,
            '总用量 (MB)' => $usedMB
        );
        
    } catch (\Exception $e) {
        return ['错误' => $e->getMessage()];
    }
}

/**
 * ========== 修改内容 6: 客户端获取配置 ==========
 * 用于在客户端面板显示订阅 URL 和配置
 */
function V2raySocks_ClientAreaOutputs($params) {
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_ClientAreaVlessReality($params);
        } else {
            return V2raySocks_ClientAreaOutputsLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_ClientAreaVlessReality($params) {
    try {
        $serviceId = $params['serviceid'];
        $uuid = Capsule::table('tblhosting')->where('id', $serviceId)->value('username');
        $nodesJson = $params['configoption3'] ?? '[]';
        
        $nodes = json_decode($nodesJson, true);
        
        $html = '<div class="alert alert-info">';
        $html .= '<h4>VLESS-Reality 节点配置</h4>';
        $html .= '<p><strong>您的 UUID:</strong> <code>' . htmlspecialchars($uuid) . '</code></p>';
        $html .= '<h5>可用节点:</h5>';
        $html .= '<table class="table table-striped">';
        $html .= '<tr><th>节点名称</th><th>地址</th><th>端口</th><th>配置</th></tr>';
        
        foreach ($nodes as $node) {
            $vlessUrl = V2raySocks_make_vless_reality($node, $uuid, $node['name']);
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($node['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($node['ip']) . '</td>';
            $html .= '<td>' . $node['port'] . '</td>';
            $html .= '<td>';
            $html .= '<button class="btn btn-sm btn-primary" onclick="copyToClipboard(\'' . 
                     htmlspecialchars(str_replace("'", "\\'", $vlessUrl)) . '\')">复制</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $html .= '<p class="text-muted">提示: 复制配置 URL 后导入到您的客户端应用</p>';
        $html .= '</div>';
        
        return $html;
        
    } catch (\Exception $e) {
        return '<div class="alert alert-danger">错误: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

/**
 * ========== 辅助函数 ==========
 */

// 生成 UUID
if (!function_exists('V2raySocks_GenerateUuid')) {
    function V2raySocks_GenerateUuid(){  
        $chars = md5(uniqid(mt_rand(), true));  
        $uuid  = substr($chars,0,8) . '-';  
        $uuid .= substr($chars,8,4) . '-';  
        $uuid .= substr($chars,12,4) . '-';  
        $uuid .= substr($chars,16,4) . '-';  
        $uuid .= substr($chars,20,12);  
        return strtoupper($uuid);  
    }
}

// MB/GB 转换
if (!function_exists('V2raySocks_MBGB')) {
    function V2raySocks_MBGB($tra){
        if($tra >= 1024){
            $tra = round($tra / 1024, 2);
            $tra .= 'GB';
        }else{
            $tra .= 'MB';
        }
        return $tra;
    }
}

// 获取语言字符串 (保持兼容)
if (!function_exists('V2raySocks_get_lang')) {
    function V2raySocks_get_lang($var){
        global $_VLANG;
        return isset($_VLANG[$var]) ? $_VLANG[$var] : $var;
    }
}

// 原有的遗留函数 (占位符)
// 实际使用中这些应该保留原有实现
function V2raySocks_SuspendAccountLegacy($params) { return "成功"; }
function V2raySocks_UnsuspendAccountLegacy($params) { return "成功"; }
function V2raySocks_TerminateAccountLegacy($params) { return "成功"; }
function V2raySocks_ChangePackageLegacy($params) { return "成功"; }
function V2raySocks_AdminServicesTabFieldsLegacy($params) { return array(); }
function V2raySocks_ClientAreaOutputsLegacy($params) { return ""; }
