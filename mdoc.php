<?php
require_once(dirname(__FILE__) . '/lib/mymarkdown.php');
require_once(dirname(__FILE__) . '/lib/Spyc.php');

// support for hooks
$_action_callbacks = array();
$_filter_callbacks = array();
define('ACTION_ABORT', '___ACTION__ABORT___');
function action_hook($name, $data = NULL) {
    global $_action_callbacks;
    if (isset($_action_callbacks[$name])) {
        foreach($_action_callbacks[$name] as $cb) {
            $ret = $cb($data);
            if ($ret === ACTION_ABORT) return false;
        }
    }
    return true;
}

function filter_hook($name, $data) {
    global $_filter_callbacks;
    if (isset($_filter_callbacks[$name])) {
        foreach($_filter_callbacks[$name] as $cb) {
            $data = $cb($data);
        }
    }
    return $data;
}

function add_action($name, $cb) {
    global $_action_callbacks;
    if (!isset($_action_callbacks[$name])) {
        $_action_callbacks[$name] = array($cb);
    } else {
        $_action_callbacks[$name][] = $cb;
    }
}

function add_filter($name, $cb) {
    global $_filter_callbacks;
    if (!isset($_filter_callbacks[$name])) {
        $_filter_callbacks[$name] = array($cb);
    } else {
        $_filter_callbacks[$name][] = $cb;
    }
}
// end support for hooks

// support for special page
$_special_pages = array();
function invoke_special($method) {
    global $_special_pages;
    if (isset($_special_pages[$method])) {
        $cb = $_special_pages[$method][0];
        $cb();
    } else {
        header('Status: 404 Not found');
        exit();
    }
}

function register_special($method, $cb, $description = NULL) {
    global $_special_pages;
    $_special_pages[$method] = array($cb, $description);
}
// end support for special page

$mdoc_config = include(dirname(__FILE__) . '/config.php');
$default_config = array(
    'site_name' => 'Mdoc documentation',
    'short_name' => 'Mdoc',
    'main_url' => '/',
    'edit_link' => '?edit=1',
    'plugins' => array(),
);
$mdoc_config = array_merge($default_config, $mdoc_config);

function loadPlugins() {
    global $mdoc_config;
    foreach ($mdoc_config['plugins'] as $p) {
        require_once(dirname(__FILE__) . "/_plugins/$p.php");
    }
}

function parseMarkdown($md, $title = null) {
    $parser = new MyMarkdown();
    if (!empty($title)) {
        $parser->set_title($title);
    }
    $html = $parser->transform($md);
    $toc = $parser->getToc();
    return array('html' => $html, 'toc' => $toc);
}

function applyTemplate($template, $data) {
    require_once(dirname(__FILE__) . '/lib/smarty/Smarty.class.php');
    $smarty = new Smarty();
    $smarty->setTemplateDir(dirname(__FILE__).'/_template');
    $smarty->setCompileDir(dirname(__FILE__).'/_tmp/templates_c');
    $smarty->setCacheDir(dirname(__FILE__).'/_tmp/cache');
    $smarty->setConfigDir(dirname(__FILE__).'/_tmp/config');

    if ($template == NULL) {
        $template = 'default.html';
    } else {
        $template = "$template.html";
    }

    $smarty->assign($data);
    return $smarty->fetch($template);
}

function generate($contents, $data) {
    global $mdoc_config;
    $arr = explode("\n---\n", $contents, 2);
    if (count($arr) < 2) {
        return false;
    }
    $yaml = $arr[0];
    $md = $arr[1];
    $yaml = spyc_load($yaml);
    $result = parseMarkdown($md, $yaml['title']);

    $data = $yaml;

    $data['content'] = $result['html'];
    $data['toc'] = $result['toc'];

    $data = array_merge($mdoc_config, $data);

    $generated = applyTemplate($yaml['layout'], $data);
    return $generated;
}

function sort_cb($a, $b) {
    return $b['mtime'] - $a['mtime'];
}
function generateIndex($linkdir) {
    global $mdoc_config;
    var_dump($linkdir);
    $linkdir = trim($linkdir, '/');
    $dir = "_doc/$linkdir";
    $dirlist = scandir($dir);
    $files = array();
    foreach ($dirlist as $f) {
        if (!is_file("$dir/$f")) continue;
        if (strpos($f, '.md') !== strlen($f) - 3) continue;
        $stat = stat("$dir/$f");
        $contents = file_get_contents("$dir/$f");
        $arr = explode("\n---\n", $contents, 2);
        if (count($arr) < 2) {
            continue;
        }
        $yaml = $arr[0];
        $yaml = spyc_load($yaml);
        $name = substr($f, 0, strlen($f) - 3);
        $entry = array(
            'name' => $name,
            'author' => $yaml['author'],
            'title' => $yaml['title'],
            'mtime' => $stat['mtime'],
        );
        $files[] = $entry;
    }
    usort($files, 'sort_cb');

    //generate md from dirlist
    $md = "title: $linkdir 目录\n---\n## 文章列表\n";
    if (!empty($linkdir)) {
        $linkdir = '/' . $linkdir;
    }
    foreach ($files as $f) {
        $md .= "* [{$f['title']}]($linkdir/{$f['name']}) _last updated by **{$f['author']}** on ".strftime("%Y-%m-%d %H:%M",$f['mtime'])."_\n";
    }
    

    //generate page
    return generate($md, array('source_link' => '#'));
}

function sendfile($file, $type = "http") {
    if ($type == 'text') {
        header("Content-Type: text/plain; charset=utf-8");
    }
    header("X-Sendfile: ".dirname(__FILE__)."/$file");
}

function returnCachedFile($file) {
    $cache = "_cache/" . str_replace("/", ",.,.", trim($file, '/'));
    $ori = "_doc/$file";
    $need_build = true;
    if (file_exists($cache)) {
        $oristat = stat($ori);
        $cachestat = stat($cache);
        if ($oristat['mtime'] < $cachestat['mtime']) {
            $need_build = false;
        }
    }
    if ($need_build) {
        $rand = rand();
        if (!is_dir(dirname($cache))) {
            mkdir(dirname($cache), 0755, true);
        }
        $data = array('source_link' => $file);
        file_put_contents("$cache.$rand", generate(file_get_contents($ori), $data));
        rename("$cache.$rand", "$cache");
    }
    sendfile($cache);
}

function returnCachedIndex($dir) {
    $cache = "_cache/" . str_replace("/", ",.,.", trim(trim($dir, '/') . '/index.md', '/'));
    $ori = "_doc/$dir";
    $need_build = true;
    if (file_exists($cache)) {
        $oristat = stat($ori);
        $cachestat = stat($cache);
        if ($oristat['mtime'] < $cachestat['mtime']) {
            $need_build = false;
            foreach (scandir($ori) as $f) {
                if (!is_file("$ori/$f")) continue;
                if (strpos($f, '.md') !== strlen($f) - 3) continue;
                $stat = stat("$ori/$f");
                if ($stat['mtime'] > $cachestat['mtime']) {
                    $need_build = true;
                    break;
                }
            }
        }
    }
    if ($need_build) {
        $rand = rand();
        if (!is_dir(dirname($cache))) {
            mkdir(dirname($cache), 0755, true);
        }
        $data = array('source_link' => $file);
        file_put_contents("$cache.$rand", generateIndex($dir));
        rename("$cache.$rand", "$cache");
    }
    sendfile($cache);
}

loadPlugins();

$file = $_GET['path'];
if (strpos($file, "special:") === 0) {
    // use special page
    $method = substr($file, strlen("special:"));
    invoke_special($method);
    exit();
}
if ($_GET['post'] == 1) {
    $mode = 'post';
} else if ($_GET['edit'] == 1) {
    $mode = 'edit';
} else {
    $mode = 'view';
}

if ($mode == 'view') {
    if (!action_hook('before view', $file)) {
        exit(1);
    }
    if (is_file("_doc/$file.md")) {
        returnCachedFile("$file.md");
    } else if (is_file("_doc/$file")) {
        sendfile("_doc/$file", 'text');
    } else if (is_dir("_doc/$file")) {
        if (is_file("_doc/$file/index.md")) {
            returnCachedFile("$file/index.md");
        } else {
            returnCachedIndex($file);
        }
    } else {
        header("Status: 404 Not found");
        exit;
    }
} else if ($mode == 'edit') {
    //edit mode
    if (!action_hook('before_edit', $file)) {
        header('Status: 403 Forbidden');
        exit(1);
    }
    sendfile("_template/_edit.html");
} else if ($mode == 'post') {
    //verify contents
    if (!action_hook('before_commit')) {
        header('Status: 403 Forbidden');
        exit(1);
    }
    $content = $_POST['content'];
    $content = filter_hook('contents_before_save_edit', $content);
    if (empty($content)) {
        echo 'content empty!';
        exit();
    }
    $filename = "_doc/$file.md";
    $dirname = dirname($filename);
    if (!is_dir($dirname)) {
        mkdir($dirname, 0755, true);
    }
    if (!action_hook('before_save_edit', $filename)) {
        echo "hook failed";
        exit();
    }
    $ret = file_put_contents($filename, $content);
    if ($ret === false) {
        echo "write file $file error";
        exit();
    }
    if (!action_hook('after_save_edit', $filename)) {
        echo "hook failed, but file might have been modified\n";
        echo "backup your data and try editting again";
    } else {
        echo 1;
    }
}
