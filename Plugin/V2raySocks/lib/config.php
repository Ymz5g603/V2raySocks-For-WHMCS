<?php
/**
 * V2raySocks 配置文件
 * 支持 VLESS-Reality 和 xrayR 后端
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// 协议类型常量
define('V2RAYSOCKS_PROTOCOL_VMESS', 'vmess');
define('V2RAYSOCKS_PROTOCOL_VLESS_REALITY', 'vless-reality');
define('V2RAYSOCKS_PROTOCOL_MIXED', 'mixed');

// 默认配置
$V2RAYSOCKS_CONFIG = array(
    'protocol' => V2RAYSOCKS_PROTOCOL_VMESS,
    'use_xrayR' => false,
    'xrayR_timeout' => 10,
    'traffic_unit' => 'GB', // GB 或 MB
    'default_sni' => 'www.microsoft.com',
    'default_fingerprint' => 'chrome',
    'default_flow' => 'xtls-rprx-vision'
);

/**
 * 获取模块配置
 */
function V2raySocks_getConfig($key = null) {
    global $V2RAYSOCKS_CONFIG;
    
    if ($key === null) {
        return $V2RAYSOCKS_CONFIG;
    }
    
    return isset($V2RAYSOCKS_CONFIG[$key]) ? $V2RAYSOCKS_CONFIG[$key] : null;
}

/**
 * 设置模块配置
 */
function V2raySocks_setConfig($key, $value) {
    global $V2RAYSOCKS_CONFIG;
    $V2RAYSOCKS_CONFIG[$key] = $value;
}
