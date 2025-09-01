<?php
// 关闭错误报告，避免干扰输出
error_reporting(0);

// ------------------- 配置区 -------------------
// 自定义 User-Agent，确保能获取到 sing-box 格式的 JSON
define('CUSTOM_USER_AGENT', 'sing-box/1.9.0');
// ---------------------------------------------

// 设置响应头为纯文本
header('Content-Type: text/plain; charset=utf-8');

// 从 URL 参数获取原始订阅链接（经过 Base64 编码）
$base64_url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($base64_url)) {
    die('错误：未提供订阅链接参数 (url)。');
}

// 解码获取原始订阅链接
$original_url = base64_decode($base64_url);

if ($original_url === false || !filter_var($original_url, FILTER_VALIDATE_URL)) {
    die('错误：url 参数不是有效的 Base64 编码或解码后不是一个有效的 URL。');
}

// 初始化 cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $original_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_USERAGENT, CUSTOM_USER_AGENT);

// 执行 cURL 请求
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 检查请求是否成功
if ($http_code != 200 || $response === false) {
    die('错误：无法从原始订阅地址获取内容。HTTP 状态码: ' . $http_code);
}

// 解码获取到的 JSON 配置文件
$profile = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($profile['outbounds'])) {
    // 如果返回的不是 JSON, 直接 Base64 后输出
    echo base64_encode($response);
    exit;
}

// --- 核心转换逻辑 ---
$share_links = [];
foreach ($profile['outbounds'] as $outbound) {
    if (!isset($outbound['server'])) continue;
    $link = '';
    switch ($outbound['type']) {
        case 'vless': $link = generate_vless_link($outbound); break;
        case 'hysteria2': $link = generate_hysteria2_link($outbound); break;
        case 'tuic': $link = generate_tuic_link($outbound); break;
        case 'anytls': $link = generate_anytls_link($outbound); break;
    }
    if (!empty($link)) $share_links[] = $link;
}

if (!empty($share_links)) {
    echo base64_encode(implode("\n", $share_links));
} else {
    echo '';
}

// --- 各种协议分享链接的生成函数 ---
function generate_vless_link($out) {
    $uuid = $out['uuid']; $address = $out['server']; $port = $out['server_port']; $tag = rawurlencode($out['tag']);
    $params = ['encryption' => 'none', 'type' => 'tcp'];
    if (isset($out['flow'])) $params['flow'] = $out['flow'];
    if (isset($out['tls']['enabled']) && $out['tls']['enabled']) {
        $params['security'] = 'tls';
        if (isset($out['tls']['server_name'])) $params['sni'] = $out['tls']['server_name'];
        if (isset($out['tls']['utls']['enabled']) && $out['tls']['utls']['enabled']) $params['fp'] = $out['tls']['utls']['fingerprint'];
        if (isset($out['tls']['reality']['enabled']) && $out['tls']['reality']['enabled']) {
            $params['security'] = 'reality'; $params['pbk'] = $out['tls']['reality']['public_key'];
            if(isset($out['tls']['reality']['short_id'])) $params['sid'] = $out['tls']['reality']['short_id'];
        }
    }
    $params['spx'] = '/';
    return "vless://{$uuid}@{$address}:{$port}?" . http_build_query($params) . "#{$tag}";
}
function generate_hysteria2_link($out) {
    $auth = $out['password']; $address = $out['server']; $port = $out['server_port']; $tag = rawurlencode($out['tag']);
    $params = [];
    if (isset($out['tls']['enabled']) && $out['tls']['enabled']) {
        if (isset($out['tls']['server_name'])) $params['sni'] = $out['tls']['server_name'];
        if (isset($out['tls']['insecure']) && $out['tls']['insecure']) $params['insecure'] = '1';
    }
    if (isset($out['obfs']['type'])) {
        $params['obfs'] = $out['obfs']['type'];
        if (isset($out['obfs']['password'])) $params['obfs-password'] = $out['obfs']['password'];
    }
    return "hysteria2://{$auth}@{$address}:{$port}?" . http_build_query($params) . "#{$tag}";
}
function generate_tuic_link($out) {
    $uuid = $out['uuid']; $password = $out['password']; $address = $out['server']; $port = $out['server_port']; $tag = rawurlencode($out['tag']);
    $params = [];
    if (isset($out['tls']['server_name'])) $params['sni'] = $out['tls']['server_name'];
    if (isset($out['congestion_control'])) $params['congestion_control'] = $out['congestion_control'];
    if (isset($out['udp_relay_mode'])) $params['udp_relay_mode'] = $out['udp_relay_mode'];
    if (isset($out['tls']['alpn'][0])) $params['alpn'] = $out['tls']['alpn'][0];
    return "tuic://{$uuid}:{$password}@{$address}:{$port}?" . http_build_query($params) . "#{$tag}";
}
function generate_anytls_link($out) {
    $password = $out['password']; $address = $out['server']; $port = $out['server_port']; $tag = rawurlencode($out['tag']);
    $params = [];
    if (isset($out['tls']['server_name'])) $params['sni'] = $out['tls']['server_name'];
    if (isset($out['tls']['alpn'][0])) $params['alpn'] = $out['tls']['alpn'][0];
    return "anytls://{$password}@{$address}:{$port}?" . http_build_query($params) . "#{$tag}";
}
