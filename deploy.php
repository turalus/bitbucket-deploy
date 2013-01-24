<?php

require_once 'deploy.conf';

// set higher script timeout (for large repo's or slow servers)
$timeLimit = 5000;

///////////////////////////////////////////////////////////////////////////////////////
$mode = intval(isset($_POST['payload']));

if (isset($_GET['commit']))
    $mode = 2;

$force = isset($_GET['force']);
$owner = (isset($owner)) ? $owner : $username; // if user is owner
$repo = $reponame;
$response = "";

if ($mode == 0) { // manual deploy

    function callback($url, $chunk) {
        global $response;
        $response .= $chunk;
        return strlen($chunk);
    }

    ;

    $ch = curl_init("https://api.bitbucket.org/1.0/repositories/$owner/$repo/changesets?limit=1");

    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent:Mozilla/5.0'));
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'callback');
    curl_exec($ch);
    curl_close($ch);

    $changesets = json_decode($response, true);
    $node = $changesets['changesets'][0]['node'];
    $raw_node = $changesets['changesets'][0]['raw_node'];
} else if ($mode == 1) { // auto deploy
    $json = stripslashes($_POST['payload']);
    $data = json_decode($json);
    // Set some parameters to fetch the correct files
    $uri = $data->repository->absolute_url;
    $node = $data->commits[0]->node;
    echo $node;
    $files = $data->commits[0]->files;
} else if ($mode == 2) { // deploy with hash code
    $node = $_GET['commit'];
    $node = substr($node, 0, 12);
    echo 'commit: ' . $node . "\n";
}
// Check last commit hash

if (isset($_GET['updated'])) {
    echo "\n<br>Bitbucket Deploy Updated<br>\n";
}

set_time_limit($timeLimit);

// Grab the data from BB's POST service and decode
// Clear Root
// download the repo zip file

if (!$force && file_exists('lastcommit.hash')) {
    $lastcommit = file_get_contents('lastcommit.hash');
    if ($lastcommit == $node)
        die('Project is already up to date');
}

file_put_contents('lastcommit.hash', $node);


$fp = fopen("tip.zip", 'w');

$ch = curl_init("https://bitbucket.org/$owner/$reponame/get/$node.zip");
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FILE, $fp);

$data = curl_exec($ch);

curl_close($ch);
fclose($fp);

$exc["files"][] = realpath("tip.zip");

$tipsize = filesize("tip.zip");

if ($tipsize < 50) {
    die("Commit not found");
}

if ($autoUpdate)
    updateDeploy();

//var_dump($exc);
//die();


RemoveDir(realpath($dest), true, $exc);


// unzip
$zip = new ZipArchive;
$res = $zip->open('tip.zip');
if ($res !== TRUE) {
    die('ZIP not supported on this server!');
}

$zip->extractTo("$dest/");
$zip->close();

copy_recursively("$owner-$reponame-$node", $dest);

RemoveDir(realpath("$owner-$reponame-$node"), false);
@rmdir("$owner-$reponame-$node");
// Delete the repo zip file
unlink("tip.zip");

// function to delete all files in a directory recursively

function updateDeploy() {
    global $force;
    global $dest;
    global $mode;
    $updated = isset($_GET['updated']);
    //var_dump($_GET);
    if ($updated)
        return true;

    $response = "";

    $response = file_get_contents("https://api.bitbucket.org/1.0/repositories/codearts/bitbucket-deploy/changesets?limit=1");

    $changesets = json_decode($response, true);
    $node = $changesets['changesets'][0]['node'];
    $raw_node = $changesets['changesets'][0]['raw_node'];

    $lastcommit = file_get_contents('data.hash');
    if (file_exists('data.hash')) {    //   if (!$force && file_exists('data.hash')) {
        $lastcommit = file_get_contents('data.hash');
        if ($lastcommit == $node)
            return;
    }
    file_put_contents('data.hash', $node);
    $deployLink = "https://bitbucket.org/codearts/bitbucket-deploy/get/$node.zip";
    $deploy = file_get_contents($deployLink);

    $f = fopen("deploy.zip", "w");
    fwrite($f, $deploy);
    fclose($f);
    $zip = new ZipArchive;
    $res = $zip->open('deploy.zip');
    if ($res !== TRUE) {
        die('ZIP not supported on this server!');
    }
    $zip->extractTo("$dest/");
    $zip->close();
    unlink('deploy.php');
    copy("codearts-bitbucket-deploy-$node/deploy.php", 'deploy.php');
    //unlink("codearts-bitbucket-deploy-$node/deploy.php");
    RemoveDir(realpath("codearts-bitbucket-deploy-$node"), false);
    @rmdir(realpath("codearts-bitbucket-deploy-$node"));

    $url = "http://" . $_SERVER['HTTP_HOST'] . "/deploy.php?updated" . (($force) ? '&force' : '');

    header("Location:" . $url);
    die();

//    if($mode != 1) echo "\n<br>Bitbucket Deploy Updated<br>\n";
}

// Deleting with exclude list


function checkExcluding($path, $excludinglist) {
    if (!isset($excludinglist["files"]))
        return false;
    if (!is_dir($path)) {
        return in_array($path, $excludinglist["files"]);
    }
    else
        return in_array($path, $excludinglist["dirs"]);
}

function RemoveDir($dir, $exclude = false, $excludelist = array()) {
    $it = new RecursiveDirectoryIterator($dir);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    //  var_dump($files);
    foreach ($files as $file) {
        if ($exclude && checkExcluding($file->getRealPath(), $excludelist)) {
            //  echo 'Excluding: ' . $file->getRealPath() . '<br>';
            continue;
        }

        if ($file->isDir()) {
            @rmdir($file->getRealPath());
            //echo 'DIR: ' . $file->getRealPath() . '<br>';
        } else {
            @unlink($file->getRealPath());
            //echo 'FILE: ' . $file->getRealPath() . '<br>';
        }
    }
    if (file_exists($dir))
        @rmdir($dir);
}

function copy_recursively($src, $dest) {
    //var_dump($src);
    global $exc;
    $excludeDirsNames = array();
    $excludeFileNames = $exc["files"];
    //     var_dump(  $excludeFileNames  );

    if (is_dir('' . $src)) {
        //  var_dump($src);
        // if ($dest != "./")
        //   rmdir_recursively($dest);
        @mkdir($dest);
        $files = scandir($src);



        // var_dump( $excludeFileNames );

        foreach ($files as $file) {
            if (!in_array($file, $excludeDirsNames)) {

                if ($file != "." && $file != "..")
                    copy_recursively("$src/$file", "$dest/$file");
            }
        }
    }
    else if (file_exists($src)) {

        $filename = $src;
        $filename = end(explode("/", $src));
        //$filename = $filename[count( $filename)-2];
        if (!in_array($filename, $excludeFileNames)) {
            //var_dump($filename);
            // var_dump(in_array( $filename, $excludeDirsNames));
            copy($src, $dest);
        }
    }
    //  rmdir_recursively($src);
}

if ($mode != 1)
    echo '<br>Done';
?>