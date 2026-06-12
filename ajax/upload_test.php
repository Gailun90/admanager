<?php
echo '<pre>';
echo 'upload_max=' . ini_get('upload_max_filesize') . PHP_EOL;
echo 'post_max=' . ini_get('post_max_size') . PHP_EOL;
echo PHP_EOL;
if (!empty($_FILES)) {
    foreach ($_FILES as $key => $info) {
        echo "FILES[$key]:" . PHP_EOL;
        echo "  name=" . ($info['name'] ?? 'N/A') . PHP_EOL;
        echo "  tmp_name=" . ($info['tmp_name'] ?? 'N/A') . PHP_EOL;
        echo "  size=" . ($info['size'] ?? 'N/A') . PHP_EOL;
        echo "  error=" . ($info['error'] ?? 'N/A') . ' (';
        $errors = [0=>'OK',1=>'INI_SIZE',2=>'FORM_SIZE',3=>'PARTIAL',4=>'NO_FILE',6=>'NO_TMP_DIR',7=>'CANT_WRITE',8=>'EXTENSION'];
        echo ($errors[$info['error'] ?? -1] ?? 'UNKNOWN') . ')' . PHP_EOL;
    }
} else {
    echo 'No files uploaded' . PHP_EOL;
}
