/* admanager 导入页专属脚本 */
document.addEventListener('DOMContentLoaded', function() {
    var selectAllCb = document.getElementById('select-all-clients');
    if (selectAllCb) {
        selectAllCb.addEventListener('change', function() {
            document.querySelectorAll('.client-checkbox').forEach(function(cb) {
                cb.checked = selectAllCb.checked;
            });
        });
    }
});
