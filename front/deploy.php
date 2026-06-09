<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

// ── AJAX 接口 ──────────────────────────────────────────────────────────────
// ?action=targets&task_id=N → 返回 JSON（任务终端明细）
if (isset($_GET['action']) && $_GET['action'] === 'targets' && isset($_GET['task_id'])) {
    header('Content-Type: application/json');
    echo json_encode(PluginAdmanagerDeploy::getTaskTargets((int)$_GET['task_id']));
    exit;
}

// ── POST 处理 ──────────────────────────────────────────────────────────────
$flash = null;
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['_action'] ?? '';
    // === DEBUG ===
    $dbgLine = date('[Y-m-d H:i:s] ') . 'deploy.php POST _action=' . $act
        . '  X-Requested-With=' . ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'none')
        . '  FILES=' . json_encode(array_map(fn($f)=>['name'=>$f['name'],'size'=>$f['size'],'error'=>$f['error']], $_FILES))
        . "\n";
    file_put_contents('/tmp/pkg_upload_debug.log', $dbgLine, FILE_APPEND | LOCK_EX);
    // === /DEBUG ===

    // 上传安装包需要较长时间（文件传输 + hash + FastAPI 注册），取消超时限制
    if ($act === 'upload_package') {
        set_time_limit(600);
    }

    // 上传安装包
    if ($act === 'upload_package') {
        if (!empty($_FILES['package']['tmp_name'])) {
            $result = PluginAdmanagerDeploy::uploadPackage(
                $_FILES['package'],
                trim($_POST['pkg_name']    ?? ''),
                trim($_POST['pkg_version'] ?? ''),
                trim($_POST['silent_args'] ?? ''),
                trim($_POST['pkg_desc']    ?? '')
            );
        } else {
            $result = ['ok' => false, 'message' => '请选择要上传的安装包文件'];
        }

        // AJAX 请求直接返回 JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 创建部署任务
    if ($act === 'create_task') {
        $interactive    = ($_POST['interactive']      ?? '0') === '1';
        $silentOverride = ($_POST['silent_override']  ?? '0') === '1';

        $extra = [];
        if ($interactive) {
            $extra['defer_minutes']  = $_POST['defer_minutes']  ?? null;
            $extra['defer_max_count']= $_POST['defer_max_count'] ?? null;
            $extra['dialog_title']   = $_POST['dialog_title']   ?? null;
            $extra['dialog_message'] = $_POST['dialog_message'] ?? null;
        }
        if ($silentOverride) {
            $extra['silent_override'] = true;
        }

        $result = PluginAdmanagerDeploy::createTask(
            trim($_POST['task_name']   ?? ''),
            (int)($_POST['package_id'] ?? 0),
            $_POST['target_type']      ?? 'all',
            !empty($_POST['target_id']) ? (int)$_POST['target_id'] : null,
            $interactive,
            ($_POST['need_reboot']     ?? '0') === '1',
            (int)($_POST['timeout']    ?? 600),
            $extra
        );
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 取消任务
    if ($act === 'cancel_task' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::cancelTask((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 删除任务
    if ($act === 'delete_task' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::deleteTask((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 重置失败任务
    if ($act === 'reset_failed' && !empty($_POST['task_id'])) {
        $result = PluginAdmanagerDeploy::resetFailed((int)$_POST['task_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 删除安装包
    if ($act === 'delete_package' && !empty($_POST['pkg_id'])) {
        $result = PluginAdmanagerDeploy::deletePackage((int)$_POST['pkg_id']);
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    // 编辑安装包元数据
    if ($act === 'update_package' && !empty($_POST['pkg_id'])) {
        $result = PluginAdmanagerDeploy::updatePackage(
            (int)$_POST['pkg_id'],
            trim($_POST['pkg_name']    ?? ''),
            trim($_POST['pkg_version'] ?? ''),
            trim($_POST['silent_args'] ?? ''),
            trim($_POST['pkg_desc']    ?? '')
        );
        Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }
}

// ── 数据准备 ───────────────────────────────────────────────────────────────
$packages      = PluginAdmanagerDeploy::getPackages();
$tasks         = PluginAdmanagerDeploy::getTasks(30);
$clients       = PluginAdmanagerDeploy::getClients();
$groups        = PluginAdmanagerDeploy::getGroups();
$deploy_config = PluginAdmanagerDeploy::getDeployConfig();
$can_write     = PluginAdmanagerProfile::canDo('admin', CREATE);

// AJAX 任务进度查询
if (isset($_GET['_ajax']) && $_GET['_ajax'] === 'task_progress') {
    $tasks = PluginAdmanagerDeploy::getTaskList();
    header('Content-Type: application/json');
    echo json_encode(array_values(array_filter($tasks, function($t) {
        $p = $t['progress'] ?? [];
        return ($p['pending'] ?? 0) + ($p['running'] ?? 0) > 0;
    })));
    exit;
}

Html::header('软件部署', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'deploy');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/deploy.html.twig', [
    'packages'      => $packages,
    'tasks'         => $tasks,
    'clients'       => $clients,
        'groups'        => $groups,
    'deploy_config' => $deploy_config,
    'can_write'     => $can_write,
    'csrf_token'    => Session::getNewCSRFToken(),
]);

Html::footer();
