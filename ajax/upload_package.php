<?php
/**
 * ajax/upload_package.php
 * 上传安装包 AJAX 接口
 * 走 /ajax/ 路径：GLPI 自动用 X-Glpi-Csrf-Token header 验证，不消耗 token，无 session lock 问题
 */
include '../../../inc/includes.php';

header('Content-Type: application/json; charset=utf-8');

if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired']);
    exit;
}

PluginAdmanagerProfile::checkRight('admin', CREATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

// session_write_close 让 session 锁尽早释放，上传大文件时不阻塞其他请求
session_write_close();

// v2: Validate file extension
$allowed_ext = ['exe','msi','zip','7z','rar','bat','cmd','ps1','vbs','msu','msp'];
$ext = strtolower(pathinfo($_FILES['package']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => "Unsupported file type: .{$ext}"]);
    exit;
}

if (empty($_FILES['package']['tmp_name'])) {
    echo json_encode(['ok' => false, 'message' => '请选择要上传的安装包文件，或文件超过服务器 upload_max_filesize 限制']);
    exit;
}

$result = PluginAdmanagerDeploy::uploadPackage(
    $_FILES['package'],
    trim($_POST['pkg_name']    ?? ''),
    trim($_POST['pkg_version'] ?? ''),
    trim($_POST['silent_args'] ?? ''),
    trim($_POST['pkg_desc']    ?? '')
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
