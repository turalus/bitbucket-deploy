<?php

// Set these dependant on your BB credentials    
$username = '';
$password = '';

// your Bitbucket repo name
$reponame = "";

// extract to
$dest = "./"; // leave ./ for relative destination

//Exclusion list
$exc = array(
    "files" => array("deploy.php", "lastcommit.hash"),
);

// set higher script timeout (for large repo's or slow servers)
$timeLimit = 5000;

///////////////////////////////////////////////////////////////////////////////////////
$mode = intval(isset($_POST['payload']));
$owner = $username; // if user is owner
$repo = $reponame;
$response = "";
if ($mode == 0) {
    function callback($url, $chunk) {
        global $response;
        $response .= $chunk;
        return strlen($chunk);
    }

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
} else {
    $json = stripslashes($_POST['payload']);
    $data = json_decode($json);
// Set some parameters to fetch the correct files
    $uri = $data->repository->absolute_url;
    $node = $data->commits[0]->node;
    $files = $data->commits[0]->files;
}
// Check last commit hash
if (file_exists('lastcommit.hash')) {
    $lastcommit = file_get_contents('lastcommit.hash');
    if ($lastcommit == $node)
        die('Project is already up to date');
}
file_put_contents('lastcommit.hash', $node);
set_time_limit($timeLimit);

// Grab the data from BB's POST service and decode
//Clear Root 
rmdirRecursively($dest);

// download the repo zip file
$fp = fopen("tip.zip", 'w');

$ch = curl_init("https://bitbucket.org/$username/$reponame/get/$node.zip");
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FILE, $fp);

$data = curl_exec($ch);

curl_close($ch);
fclose($fp);

// unzip
$zip = new ZipArchive;
$res = $zip->open('tip.zip');
if ($res !== TRUE) 
    die('ZIP not supported on this server!');
$zip->extractTo("$dest/");
$zip->close();

copy_recursively("$username-$reponame-$node", $dest);
rmdirRecursively("$username-$reponame-$node");
rmdir("$username-$reponame-$node");

// Delete the repo zip file
unlink("tip.zip");

// function to delete all files in a directory recursively
function rmdirRecursively($dir) {
    global $exc;
    $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir),
                    RecursiveIteratorIterator::CHILD_FIRST
    );

    $excludeDirsNames = array();
    $excludeFileNames = $exc["files"];

    foreach ($it as $entry) {
        // var_dump($entry->getPathname());
        if ($entry->isDir()) {
            if (!in_array($entry->getBasename(), $excludeDirsNames)) {
                try {
                    rmdir($entry->getPathname());
                } catch (Exception $ex) {
                    var_dump($ex);
                    rmdirRecursively($entry->getPathname());
                }
            }
        } elseif (!in_array($entry->getFileName(), $excludeFileNames)) {
            unlink($entry->getPathname());
        }
    }
}

function copy_recursively($src, $dest) {
    if (is_dir($src)) {
        // if ($dest != "./")
        //   rmdir_recursively($dest);
        @mkdir($dest);
        $files = scandir($src);
        foreach ($files as $file)
            if ($file != "." && $file != "..")
                copy_recursively("$src/$file", "$dest/$file");
    }
    else if (file_exists($src))
        copy($src, $dest);
    //  rmdir_recursively($src);
}

if ($mode == 0)
    echo 'Done';
?>