<?php
/**
 * V2raySocks 主模块 - 重构版本
 * 支持 VMESS (本地V2Ray) 和 VLESS-Reality (xrayR 后端)
 * 版本: 0.9.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once 'lib/functions.php';
require_once 'lib/config.php';
require_once 'lib/xrayR-api.php';
require_once 'lib/vless-reality.php';

V2raySocks_multi_language_support();

/**
 * ========== 配置选项 ==========
 */
function V2raySocks_ConfigOptions(){
    return array(
        // 原有选项 - 本地V2Ray用
        V2raySocks_get_lang('database') => array(
            'Type' => 'text',
            'Size' => '25',
            'Description' => '数据库名称 (本地 V2Ray 使用)'
        ),
        
        // xrayR 配置选项
        'xrayR API URL' => array(
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'xrayR 服务器地址 (例: http://127.0.0.1:7890)'
        ),
        
        'xrayR API Key' => array(
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'xrayR API 认证密钥'
        ),
        
        'xrayR 节点配置' => array(
            'Type' => 'textarea',
            'Rows' => '12',
            'Description' => 'JSON 格式节点配置'
        ),
        
        '协议选择' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'vmess' => 'VMESS (本地 V2Ray)',
                'vless-reality' => 'VLESS-Reality (xrayR)',
                'mixed' => 'VMESS + VLESS-Reality'
            ),
            'Description' => '选择此套餐使用的代理协议'
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
        
        V2raySocks_get_lang('bandwidth') => array(
            'Type' => 'text',
            'Size' => '25',
            'Description' => V2raySocks_get_lang('bandwidth_description')
        ),
        
        V2raySocks_get_lang('routelist') => array(
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '50',
            'Description' => V2raySocks_get_lang('routelist_description')
        ),
        
        V2raySocks_get_lang('announcements') => array(
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '50',
            'Description' => V2raySocks_get_lang('announcements_description')
        ),
        
        V2raySocks_get_lang('subscribe') => array(
            'Type'        => 'dropdown',
            'Options'     => array('1'=> V2raySocks_get_lang('enable'), '0' => V2raySocks_get_lang('disable')),
            'Description' => V2raySocks_get_lang('subscribe_description')
        )
    );
}

function V2raySocks_MetaData(){
    return array(
        'DisplayName' => 'V2raySocks',
        'APIVersion' => '1.0',
        'RequiresServer' => true
    );
}

function V2raySocks_TestConnection(array $params){
    try {
        $dbhost = $params['serverip'];
        $dbuser = $params['serverusername'];
        $dbpass = $params['serverpassword'];
        $db = new PDO('mysql:host=' . $dbhost, $dbuser, $dbpass);
        $success = true;
        $errorMsg = '';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_TestConnection', $params, $e->getMessage(), $e->getTraceAsString());
        $success = false;
        $errorMsg = $e->getMessage();
    }
    return array('success' => $success, 'error' => $errorMsg);
}

/**
 * ========== 创建账户 ==========
 */
function V2raySocks_CreateAccount(array $params){
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_CreateVlessRealityAccount($params);
        } else {
            return V2raySocks_CreateAccountLegacy($params);
        }
    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateAccount', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 创建 VLESS-Reality 账户
 */
function V2raySocks_CreateVlessRealityAccount($params) {
    try {
        $serviceId = $params['serviceid'];
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? '';
        $xrayRKey = $params['configoption2'] ?? '';
        $nodesJson = $params['configoption3'] ?? '[]';
        
        if (empty($xrayRUrl) || empty($xrayRKey)) {
            return "错误: xrayR 配置不完整";
        }
        
        $nodes = json_decode($nodesJson, true);
        if (empty($nodes)) {
            return "错误: 节点配置为空";
        }
        
        $uuid = V2raySocks_GenerateUuid();
        
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $trafficGB = floatval($package->configoption1 ?? 0);
        $trafficBytes = $trafficGB * 1024 * 1024 * 1024;
        
        $createdNodes = [];
        $errors = [];
        
        foreach ($nodes as $node) {
            try {
                $xrayRClient = new XrayRClient(
                    $xrayRUrl,
                    $xrayRKey,
                    $node['nodeId'] ?? 1
                );
                
                $xrayRClient->addUser(
                    $uuid,
                    $email,
                    intval($trafficBytes),
                    0
                );
                
                $createdNodes[] = $node['nodeId'] ?? 1;
                
            } catch (\Exception $e) {
                $errors[] = "节点错误: " . $e->getMessage();
                logModuleCall('V2raySocks', 'CreateVlessReality_Node', $node, $e->getMessage());
            }
        }
        
        if (empty($createdNodes)) {
            return "错误: 无法在任何节点上创建用户。" . implode("; ", $errors);
        }
        
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'username' => $uuid,
            'notes' => 'VLESS-Reality | 节点数: ' . count($createdNodes)
        ]);
        
        return "成功";
        
    } catch (\Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateVlessRealityAccount', $params, $e->getMessage());
        return "错误: " . $e->getMessage();
    }
}

/**
 * 创建账户 - 本地 V2Ray
 */
function V2raySocks_CreateAccountLegacy(array $params){
    $query = V2raySocks_initialize($params);
    try {
        $db = V2raySocks_getDBFromParams($params);
        $already = $db->prepare($query['ALREADY_EXISTS']);
        $already->bindValue(':sid', $params['serviceid']);
        $already->execute();
        if ($already->fetchColumn()) {
            return V2raySocks_get_lang('User_already_exists');
        }
        
        $bandwidth = (!empty($params['configoption3']) ? V2raySocks_Convert($params['configoption3'], 'mb', 'bytes') : (!empty($params['configoptions']['traffic']) ? V2raySocks_Convert($params['configoptions']['traffic'], 'mb', 'bytes') : 0));
        
        $create = $db->prepare($query['CREATE_ACCOUNT']);
        $create->bindValue(':uuid', V2raySocks_GenerateUuid());
        $create->bindValue(':transfer_enable', $bandwidth);
        $create->bindValue(':need_reset', $params['configoption2']);
        $create->bindValue(':sid', $params['serviceid']);
        $create = $create->execute();
        
        if ($create) {
            return 'success';
        }else {
            $error = $db->errorInfo();
            return $error;
        }
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_CreateAccountLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return V2raySocks_get_lang('Model_error').$e->getMessage();
    }
}

/**
 * ========== 暂停账户 ==========
 */
function V2raySocks_SuspendAccount(array $params){
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
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId'] ?? 1);
                $client->deleteUser($email);
            } catch (\Exception $e) {
                logModuleCall('V2raySocks', 'SuspendVlessReality_Node', $node, $e->getMessage());
            }
        }
        
        return "已暂停";
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_SuspendAccountLegacy(array $params){
    $query = V2raySocks_initialize($params);
    try {
        $db = V2raySocks_getDBFromParams($params);
        $enable = $db->prepare($query['ENABLE']);
        $enable->bindValue(':enable', '0');
        $enable->bindValue(':sid', $params['serviceid']);
        
        $todo = $enable->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_SuspendAccountLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

/**
 * ========== 恢复账户 ==========
 */
function V2raySocks_UnsuspendAccount(array $params){
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_CreateVlessRealityAccount($params);
        } else {
            return V2raySocks_UnsuspendAccountLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_UnsuspendAccountLegacy(array $params){
    $query = V2raySocks_initialize($params,time());
    try {
        $db = V2raySocks_getDBFromParams($params);
        $enable = $db->prepare($query['ENABLE']);
        $enable->bindValue(':enable', '1');
        $enable->bindValue(':sid', $params['serviceid']);
    
        $todo = $enable->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        $enable = $db->prepare($query['RESET']);
        $enable->bindValue(':sid', $params['serviceid']);
        $todo = $enable->execute();
        $resetchart = $db->prepare($query['RESETUSERCHART']);
        $resetchart->bindValue(':sid', $params['serviceid']);
        $resetchart->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_UnsuspendAccountLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

/**
 * ========== 终止账户 ==========
 */
function V2raySocks_TerminateAccount(array $params){
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_TerminateVlessReality($params);
        } else {
            return V2raySocks_TerminateAccountLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_TerminateVlessReality($params) {
    try {
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? '';
        $xrayRKey = $params['configoption2'] ?? '';
        $nodesJson = $params['configoption3'] ?? '[]';
        
        $nodes = json_decode($nodesJson, true);
        
        foreach ($nodes as $node) {
            try {
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId'] ?? 1);
                $client->deleteUser($email);
            } catch (\Exception $e) {
                logModuleCall('V2raySocks', 'TerminateVlessReality_Node', $node, $e->getMessage());
            }
        }
        
        return 'success';
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_TerminateAccountLegacy(array $params){
    $query = V2raySocks_initialize($params);
    try {
        $db = V2raySocks_getDBFromParams($params);
        $enable = $db->prepare($query['DELETE_ACCOUNT']);
        $enable->bindValue(':sid', $params['serviceid']);
        
        $todo = $enable->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_TerminateAccountLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

/**
 * ========== 更改套餐 ==========
 */
function V2raySocks_ChangePackage(array $params){
    try {
        $protocol = $params['configoption4'] ?? 'vmess';
        
        if ($protocol === 'vless-reality' || $protocol === 'mixed') {
            return V2raySocks_ChangePackageVlessReality($params);
        } else {
            return V2raySocks_ChangePackageLegacy($params);
        }
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_ChangePackageVlessReality($params) {
    try {
        $email = $params['clientsdetails']['email'];
        $xrayRUrl = $params['configoption1'] ?? '';
        $xrayRKey = $params['configoption2'] ?? '';
        $nodesJson = $params['configoption3'] ?? '[]';
        
        $package = Capsule::table('tblproducts')->where('id', $params['packageid'])->first();
        $trafficGB = floatval($package->configoption1 ?? 0);
        $trafficBytes = $trafficGB * 1024 * 1024 * 1024;
        
        $nodes = json_decode($nodesJson, true);
        
        foreach ($nodes as $node) {
            try {
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId'] ?? 1);
                $client->updateUser($email, $email, intval($trafficBytes), 0);
            } catch (\Exception $e) {
                logModuleCall('V2raySocks', 'ChangePackageVlessReality_Node', $node, $e->getMessage());
            }
        }
        
        return 'success';
    } catch (\Exception $e) {
        return "错误: " . $e->getMessage();
    }
}

function V2raySocks_ChangePackageLegacy(array $params){
    $query = V2raySocks_initialize($params);
    try {
        $db = V2raySocks_getDBFromParams($params);
        $bandwidth = (!empty($params['configoption3']) ? V2raySocks_Convert($params['configoption3'], 'mb', 'bytes') : (!empty($params['configoptions']['traffic']) ? V2raySocks_Convert($params['configoptions']['traffic'], 'mb', 'bytes') : 0));
        $enable = $db->prepare($query['CHANGE_PACKAGE']);
        $enable->bindValue(':transfer_enable', $bandwidth);
        $enable->bindValue(':sid', $params['serviceid']);
        $todo = $enable->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_ChangePackageLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function V2raySocks_AdminCustomButtonArray(){
    return array(V2raySocks_get_lang('resetbandwidth') => 'ResetBandwidth',
                 V2raySocks_get_lang('resetUUID') => 'ResetUUID');
}

function V2raySocks_ResetBandwidth(array $params){
    $query = V2raySocks_initialize($params,time());
    try {
        $db = V2raySocks_getDBFromParams($params);
        $enable = $db->prepare($query['RESET']);
        $enable->bindValue(':sid', $params['serviceid']);
        $todo = $enable->execute();
        $resetchart = $db->prepare($query['RESETUSERCHART']);
        $resetchart->bindValue(':sid', $params['serviceid']);
        $resetchart->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_ResetBandwidth', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function V2raySocks_ResetUUID(array $params){
    $query = V2raySocks_initialize($params,time());
    try {
        $db = V2raySocks_getDBFromParams($params);
        $enable = $db->prepare($query['RESETUUID']);
        $enable->bindValue(':sid', $params['serviceid']);
        $enable->bindValue(':uuid', V2raySocks_GenerateUuid());
        $todo = $enable->execute();
        if (!$todo) {
            $error = $db->errorInfo();
            return $error;
        }
        return 'success';
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_ResetUUID', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }
}

function V2raySocks_AdminServicesTabFields(array $params){
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
        
        foreach ($nodes as $node) {
            try {
                $client = new XrayRClient($xrayRUrl, $xrayRKey, $node['nodeId'] ?? 1);
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

function V2raySocks_AdminServicesTabFieldsLegacy(array $params){
    $query = V2raySocks_initialize($params);
    try {
        $db = V2raySocks_getDBFromParams($params);
        $userinfo = $db->prepare($query['USERINFO']);
        $userinfo->bindValue(':sid', $params['serviceid']);
        $userinfo->execute();
        $userinfo = $userinfo->fetch();
        if ($userinfo) {
            return array(V2raySocks_get_lang('uuid') => $userinfo['uuid'], V2raySocks_get_lang('bandwidth') => V2raySocks_convert($userinfo['transfer_enable'], 'bytes', 'mb') . 'MB');
        }
    }
    catch (Exception $e) {
        logModuleCall('V2raySocks', 'V2raySocks_AdminServicesTabFieldsLegacy', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getTraceAsString();
    }
}

// 辅助函数
function V2raySocks_initialize(array $params , $date = false){
    $query['CREATE_ACCOUNT'] = 'INSERT INTO `user`(`uuid`,`u`,`d`,`transfer_enable`,`created_at`,`updated_at`,`need_reset`,`sid`) VALUES (:uuid,0,0,:transfer_enable,UNIX_TIMESTAMP(),0,:need_reset,:sid)';
    $query['ALREADY_EXISTS'] = 'SELECT `uuid` FROM `user` WHERE `sid` = :sid';
    $query['ENABLE'] = 'UPDATE `user` SET `enable` = :enable WHERE `sid` = :sid';
    $query['USERINFO'] = 'SELECT `id`,`uuid`,`t`,`u`,`d`,`transfer_enable`,`enable`,`created_at`,`updated_at`,`need_reset`,`sid` FROM `user` WHERE `sid` = :sid';
    $query['DELETE_ACCOUNT'] = 'DELETE FROM `user` WHERE `sid` = :sid';
    $query['CHANGE_PACKAGE'] = 'UPDATE `user` SET `transfer_enable` = :transfer_enable WHERE `sid` = :sid';
    $query['RESETUSERCHART'] = 'delete from `user_usage` where `sid` = :sid';
    $query['UPDATEBALANCE'] = 'UPDATE `user` SET `transfer_enable` = `transfer_enable` + :transfer WHERE `sid` = :sid';
    $query['RESETUUID'] = 'UPDATE `user` SET `uuid` = :uuid WHERE `sid` = :sid';
    if($date){
        $query['RESET'] = 'UPDATE `user` SET `u`=0,`d`=0,`updated_at`='.$date.'  WHERE `sid` = :sid';
        $query['CHARTINFO'] = 'SELECT * FROM `user_usage` WHERE `sid` = :sid AND `date` >= '.$date.' ORDER BY `date` DESC';
    }else{
        $query['RESET'] = 'UPDATE `user` SET `u`=0,`d`=0 WHERE `sid` = :sid';
        $query['CHARTINFO'] = 'SELECT * FROM `user_usage` WHERE `sid` = :sid ORDER BY `date` DESC';
    }
    return $query;
}

function V2raySocks_getDBFromParams($params){
    $dbhost = $params['serverip'];
    $dbname = $params['configoption1'];
    $dbuser = $params['serverusername'];
    $dbpass = decrypt($params['serverpassword'] ?? '');
    $db = new PDO('mysql:host=' . $dbhost . ';dbname=' . $dbname, $dbuser, $dbpass);
    return $db;
}

function V2raySocks_GenerateUuid(){  
    $chars = md5(uniqid(mt_rand(), true));  
    $uuid  = substr($chars,0,8) . '-';  
    $uuid .= substr($chars,8,4) . '-';  
    $uuid .= substr($chars,12,4) . '-';  
    $uuid .= substr($chars,16,4) . '-';  
    $uuid .= substr($chars,20,12);  
    return strtoupper($uuid);  
}

function V2raySocks_Convert($number, $from, $to){
    $to = strtolower($to);
    $from = strtolower($from);
    switch ($from) {
    case 'gb':
        switch ($to) {
        case 'mb':
            return $number * 1024;
        case 'bytes':
            return $number * 1073741824;
        default:
        }
        return $number;
        break;
    case 'mb':
        switch ($to) {
        case 'gb':
            return $number / 1024;
        case 'bytes':
            return $number * 1048576;
        default:
        }
        return $number;
        break;
    case 'bytes':
        switch ($to) {
        case 'gb':
            return $number / 1073741824;
        case 'mb':
            return $number / 1048576;
        default:
        }
        return $number;
        break;
    default:
    }
    return $number;
}

function V2raySocks_convert($number, $from, $to){
    return V2raySocks_Convert($number, $from, $to);
}

function V2raySocks_MBGB($tra){
    if($tra >= 1024){
        $tra = round($tra / 1024,2);
        $tra .= 'GB';
    }else{
        $tra .= 'MB';
    }
    return $tra;
}

function V2raySocks_P_QueryToArray($query){
    $products = array();
    foreach ($query as $product) {
        $producta = array();
        foreach($product as $k => $produc){
            $producta[$k] = $produc;
        }
        $products[] = $producta;
    }
    return $products;
}
