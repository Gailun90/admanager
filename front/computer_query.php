<?php
/**
 * front/computer_query.php — 计算机综合查询
 * 搜索 AD计算机 + FastAPI终端 + GLPI资产，三源比对
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$keyword   = $_GET['keyword']   ?? '';
$source    = $_GET['source']    ?? 'all'; // all|ad|fastapi|glpi
$results   = ['ad'=>[], 'fastapi'=>[], 'glpi'=>[], 'glpi_computers'=>[]];
$error     = null;
$bitlocker_links = [];

try {
    if ($keyword) {
        // 1. AD 计算机搜索
        if (in_array($source, ['all','ad'])) {
            $ad_computers = PluginAdmanagerAdCache::searchComputers($keyword);
            $results['ad'] = $ad_computers;
        }

        // 2. FastAPI 终端搜索
        if (in_array($source, ['all','fastapi'])) {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $fastapi_results = $api->get('/api/export/clients', ['keyword'=>$keyword, 'limit'=>50]);
            $results['fastapi'] = $fastapi_results['items'] ?? [];
        }

        // 3. GLPI 本地 Computer 搜索
        if (in_array($source, ['all','glpi'])) {
            global $DB;
            $esc = $DB->escape($keyword);
            $sql = "SELECT c.id,c.name,c.serial,c.contact,c.comment,c.date_mod,
                           m.name as manufacturer, cm.name as model, os.name as os_name
                    FROM glpi_computers c
                    LEFT JOIN glpi_manufacturers m ON c.manufacturers_id=m.id
                    LEFT JOIN glpi_computermodels cm ON c.computermodels_id=cm.id
                    LEFT JOIN glpi_items_operatingsystems ios ON ios.items_id=c.id AND ios.itemtype='Computer'
                    LEFT JOIN glpi_operatingsystems os ON ios.operatingsystems_id=os.id
                    WHERE (c.name LIKE '%{$esc}%' OR c.serial LIKE '%{$esc}%' OR c.contact LIKE '%{$esc}%')
                      AND c.is_deleted=0 AND c.is_template=0
                    ORDER BY c.date_mod DESC LIMIT 50";
            $results['glpi_computers'] = iterator_to_array($DB->request($sql));
        }
    }
} catch (\Throwable $e) {
    $error = '查询失败：' . $e->getMessage();
}

Html::header('计算机查询', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'computer_query');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/computer_query.html.twig', [
    'keyword'   => $keyword,
    'source'    => $source,
    'results'   => $results,
    'error'     => $error,
    'csrf_token'=> Session::getNewCSRFToken(),
]);

Html::footer();
