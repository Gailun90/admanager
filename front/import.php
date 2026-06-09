<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

// AJAX：软件清单
if (isset($_GET['action']) && $_GET['action'] === 'software' && isset($_GET['client_id'])) {
    header('Content-Type: application/json');
    try {
        $data = PluginAdmanagerFastApiClient::getInstance()->getClientSoftware((int)$_GET['client_id']);
        echo json_encode($data);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX：创建卸载任务
if (isset($_POST['action']) && $_POST['action'] === 'uninstall'
    && isset($_POST['software_name']) && isset($_POST['client_id'])) {
    $result = PluginAdmanagerDeploy::createUninstallTask(
        trim($_POST['software_name']),
        (int)$_POST['client_id']
    );
    Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
    Html::redirect($_SERVER['PHP_SELF']);
}


// POST：删除客户端（需求4）
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'delete_client'
    && isset($_POST['del_client_id'])) {
    PluginAdmanagerProfile::checkRight('admin', DELETE);
    $client_id = (int)$_POST['del_client_id'];
    $hostname  = trim($_POST['del_hostname'] ?? '');
    $serial    = trim($_POST['del_serial']   ?? '');
    $ok = false;
    $msg = '';

    if ($client_id <= 0) {
        // FastAPI 中无对应记录（孤儿 syncstate）→ 只清本地同步状态
        global $DB;
        $DB->delete(PluginAdmanagerSyncState::$table, ['serial' => $serial]);
        $ok  = true;
        $msg = '已清除本地同步记录（FastAPI 中无对应客户端）';
    } else {
        try {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $resp = $api->deleteClient($client_id);
            $ok  = $resp['ok'] ?? false;
            $msg = $resp['message'] ?? '已删除';
        } catch (Exception $e) {
            $msg = 'FastAPI 错误：' . $e->getMessage();
        }
        // 同步删除 syncstate 记录
        if ($ok) {
            global $DB;
            $DB->delete(PluginAdmanagerSyncState::$table, ['serial' => $serial]);
        }
    }
    // 写入审计日志
    PluginAdmanagerAuditLog::write(
        'delete_client', 'Computer', $serial,
        $hostname,
        ['client_id' => $client_id, 'serial' => $serial],
        $ok,
        $ok ? '' : $msg
    );
    $level = $ok ? INFO : ERROR;
    $notice = $ok
        ? "已删除客户端 {$hostname}（ID: {$client_id}）"
        : "删除失败：{$msg}";
    Session::addMessageAfterRedirect($notice, true, $level);
    Html::redirect($_SERVER['PHP_SELF']);
}

// POST：手动导入 — 只传 serial，服务端查全量数据
$import_result = null;
$error         = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_serial'])) {
    PluginAdmanagerProfile::checkRight('admin', CREATE);
    $serial = trim($_POST['import_serial'] ?? '');
    if ($serial) {
        $client_data = null;
        try {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $result = $api->getClients(1, 200);
            foreach (($result['items'] ?? []) as $c) {
                if (($c['serial'] ?? '') === $serial) {
                    $client_data = $c;
                    break;
                }
            }
        } catch (Exception $e) {
            $error = 'FastAPI连接失败';
        }
        if ($client_data) {
            $import_result = PluginAdmanagerImportBridge::importComputer($client_data);
        } else {
            $error = '无效的终端数据：未找到序列号 ' . htmlspecialchars($serial);
        }
    } else {
        $error = '无效的终端数据：未获取到终端序列号';
    }
}

// 数据准备
$diff_only = isset($_GET['diff']) && $_GET['diff'] == '1';

$clients = [];
try {
    $api     = PluginAdmanagerFastApiClient::getInstance();
    $result  = $api->getClients(1, 200);
    $clients = $result['items'] ?? [];
} catch (Exception $e) {
    $error = $error ?: '无法连接 FastAPI：' . $e->getMessage();
}

// 从数据库获取差异终端列表
$db_diff_list = PluginAdmanagerSyncState::getDiffList(200);


// ── 预加载 IM 绑定映射（sam → 平台用户名列表）──
$im_bindings_all = [];
try {
    $all_bindings = PluginAdmanagerIMService::getBindings();
    foreach ($all_bindings as $b) {
        $sam_lower = strtolower($b['sam']);
        if (!isset($im_bindings_all[$sam_lower])) $im_bindings_all[$sam_lower] = [];
        $platform_label = ['wecom'=>'企微','dingtalk'=>'钉钉','feishu'=>'飞书'][$b['platform']] ?? $b['platform'];
        $im_bindings_all[$sam_lower][] = $platform_label . ':' . ($b['platform_name'] ?: $b['platform_uid']);
    }
} catch (\Throwable $e) {}

// 按 serial 索引 FastAPI 客户端
$client_index = [];
foreach ($clients as $c) {
    if (!empty($c['serial'])) {
        $client_index[$c['serial']] = $c;
    }
}

// 从 syncstates 获取所有终端的 glpi_items_id（不限 has_diff）
global $DB;
$ss_index = [];
$all_ss = $DB->request(['SELECT' => ['serial','glpi_items_id'], 'FROM' => PluginAdmanagerSyncState::$table]);
foreach ($all_ss as $d) {
    if (!empty($d['serial'])) {
        $ss_index[$d['serial']] = $d['glpi_items_id'];
    }
}
// 合并到 clients（全部终端视图需要显示 GLPI 状态 + 当前用户 + IM绑定）
foreach ($clients as $k => $c) {
    if (isset($ss_index[$c['serial']])) {
        $clients[$k]['glpi_items_id'] = $ss_index[$c['serial']];
    }
    // 解析当前用户 → 查 IM 绑定
    $raw_user = $c['current_user'] ?? '';
    $clients[$k]['current_user_raw'] = $raw_user;
    if ($raw_user) {
        // Windows 格式：DOMAIN\username → username, username@domain → username
        $sam = strtolower(preg_replace('/^.+\\\|@.+$/', '', $raw_user));
        $sam = trim($sam);
        $clients[$k]['current_user_sam'] = $sam;
        $clients[$k]['im_bindings'] = $im_bindings_all[$sam] ?? [];
    }
}

// 合并：diff_list 记录用 FastAPI 数据补全
$enriched_diff = [];
foreach ($db_diff_list as $row) {
    $api = $client_index[$row['serial']] ?? [];
    $raw_user = $api['current_user'] ?? '';
    $sam = '';
    $im_bindings = [];
    if ($raw_user) {
        $sam = strtolower(preg_replace('/^.+\\\|@.+$/', '', $raw_user));
        $sam = trim($sam);
        $im_bindings = $im_bindings_all[$sam] ?? [];
    }
    $enriched_diff[] = array_merge($row, $api, [
        'has_diff' => $row['has_diff'] ?? ($api['has_diff'] ?? false),
        'client_id' => $api['client_id'] ?? 0,
        'os_name'   => $api['os_name']   ?? ($row['os_name'] ?? ''),
        'cpu'       => $api['cpu']       ?? '',
        'memory_gb' => $api['memory_gb'] ?? 0,
        'last_seen' => $api['last_seen'] ?? ($row['last_seen_api'] ?? ''),
        'glpi_items_id' => $row['glpi_items_id'],
        'current_user_raw' => $raw_user,
        'current_user_sam' => $sam,
        'im_bindings'      => $im_bindings,
    ]);
}

$can_import = PluginAdmanagerProfile::canDo('admin', CREATE);


// 获取 FastAPI 对外 SERVER_URL（前端 WebSocket 用，非内部 fastapi_url）
$fastapi_server_url = $fastapi_url;
try {
    $info = PluginAdmanagerFastApiClient::getInstance()->get('/api/server/info');
    $fastapi_server_url = $info['server_url'] ?? $fastapi_url;
} catch (Exception $e) {
    // 降级
}

// 获取在线终端 serial 列表
$online_serials = [];
try {
    $dash = PluginAdmanagerFastApiClient::getInstance()->getDashboard();
    $online_serials = array_flip($dash['online_serials'] ?? []);
} catch (Exception $e) {}

Html::header('手动导入', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'import');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/import.html.twig', [
    'clients'      => $clients,
    'diff_list'    => $enriched_diff,
    'diff_only'    => $diff_only,
    'can_import'   => $can_import,
    'error'        => $error,
    'import_result'=> $import_result,
    'csrf_token'    => Session::getNewCSRFToken(),
    'fastapi_token' => PluginAdmanagerConfig::getFastApiConfig()['token'] ?? '',
    'fastapi_url'   => PluginAdmanagerConfig::getFastApiConfig()['url']   ?? '',
    'fastapi_server_url' => $fastapi_server_url,
    'online_serials'    => $online_serials,
]);

Html::footer();