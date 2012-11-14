<?php

require_once 'deploy.conf';

// set higher script timeout (for large repo's or slow servers)
$timeLimit = 5000;

///////////////////////////////////////////////////////////////////////////////////////
$mode = intval(isset($_POST['payload']));

if(isset($_GET['commit'])) $mode = 2;

$force = isset($_GET['force']);
$owner = $username; // if user is owner
$repo = $reponame;
$response = "";
if($mode == 0) // manual deploy
{
    function callback ($url, $chunk){
        global $response;
        $response .= $chunk;
        return strlen($chunk);
    };

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
}
else if($mode == 1) // auto deploy
{
    $json = stripslashes($_POST['payload']);
    $data = json_decode($json);
    // Set some parameters to fetch the correct files
    $uri = $data->repository->absolute_url;
    $node = $data->commits[0]->node;
    echo $node;
    $files = $data->commits[0]->files;
}
else if($mode == 2) // deploy with hash code
{
    $node = $_GET['commit'];
    $node = substr($node,0,12);
    echo 'commit: '.$node."\n";
}
// Check last commit hash

if(isset($_GET['updated']))
{
    echo "\n<br>Bitbucket Deploy Updated<br>\n";
}

set_time_limit($timeLimit);

// Grab the data from BB's POST service and decode

//Clear Root


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


$tipsize = filesize("tip.zip");

if($tipsize < 50)
{
    die("Commit not found");
}

if(!$force && file_exists('lastcommit.hash'))
{
    $lastcommit = file_get_contents('lastcommit.hash');
    if($lastcommit == $node) die('Project is already up to date');
}

file_put_contents('lastcommit.hash', $node);


if($autoUpdate)
    updateDeploy();

rmdirRecursively($dest);


// unzip
$zip = new ZipArchive;
$res = $zip->open('tip.zip');
if ($res !== TRUE) {
    die('ZIP not supported on this server!');
}

$zip->extractTo("$dest/");
$zip->close();

copy_recursively("$username-$reponame-$node", $dest);
rmdirRecursively("$username-$reponame-$node",true);
rmdir("$username-$reponame-$node");
// Delete the repo zip file
unlink("tip.zip");

// function to delete all files in a directory recursively

function updateDeploy()
{
    global $force;
    global $dest;
    global $mode;
    $response = "";

    $response = file_get_contents("https://api.bitbucket.org/1.0/repositories/codearts/bitbucket-deploy/changesets?limit=1");

    $changesets = json_decode($response, true);
    $node = $changesets['changesets'][0]['node'];
    $raw_node = $changesets['changesets'][0]['raw_node'];

    if(!$force && file_exists('data.hash'))
    {
        $lastcommit = file_get_contents('data.hash');
        if($lastcommit == $node) return;
    }
    file_put_contents('data.hash', $node);
    $deployLink = "https://bitbucket.org/codearts/bitbucket-deploy/get/$node.zip";
    $deploy = file_get_contents($deployLink);

    $f = fopen("deploy.zip","w");
    fwrite($f,$deploy);
    fclose($f);
    $zip = new ZipArchive;
    $res = $zip->open('deploy.zip');
    if ($res !== TRUE) {
        die('ZIP not supported on this server!');
    }
    $zip->extractTo("$dest/");
    $zip->close();
    unlink('deploy.php');
    copy("codearts-bitbucket-deploy-$node/deploy.php",'deploy.php');
    unlink("codearts-bitbucket-deploy-$node/deploy.php");
    unlink("codearts-bitbucket-deploy-$node/README");
    rmdirRecursively("codearts-bitbucket-deploy-$node");
    $uri = $_SERVER['REQUEST_URI'];
    if(strpos($uri,"?")>0)
    {
        $uri.="&updated";
    }
    else
        $uri.="?updated";
    header("Location:/deploy.php".$uri);die();

//    if($mode != 1) echo "\n<br>Bitbucket Deploy Updated<br>\n";
}

function rmdirRecursively($dir,$noExclude=false) {
    global $exc;
    $noExclude |= ( preg_match('/\w{0,}-\w{0,}-[0-9|a|b|c|d|e|f]{12}/',$dir) > 0);
    //  echo $dir;
    //   var_dump($noExclude);

    // echo '\n'.$dir.'\n';
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $excludeDirsNames = array();
    $excludeFileNames = $exc["files"];

    foreach ($it as $entry) {
        // var_dump($entry->getPathname());
        if ($entry->isDir()) {
            if ($noExclude || !in_array($entry->getBasename(), $excludeDirsNames)) {
                //	echo "\n\n erasing dir ".$entry->getBasename()." \n\n";
                rmdirRecursively($entry->getPathname());
                rmdir($entry->getPathname());
            }
        } elseif ($noExclude ||!in_array($entry->getFileName(), $excludeFileNames)) {
            unlink($entry->getPathname());
        }
    }
}

function copy_recursively($src, $dest) {
    //var_dump($src);
    global $exc;
    $excludeDirsNames = array();
    $excludeFileNames = $exc["files"];
    //     var_dump(  $excludeFileNames  );

    if (is_dir(''.$src)) {
        //  var_dump($src);
        // if ($dest != "./")
        //   rmdir_recursively($dest);
        @mkdir($dest);
        $files = scandir($src);



        // var_dump( $excludeFileNames );

        foreach ($files as $file)
        {
            if (!in_array($file, $excludeDirsNames))
            {

                if ($file != "." && $file != "..")
                    copy_recursively("$src/$file", "$dest/$file");
            }
        }
    }
    else if (file_exists($src))
    {

        $filename = $src;
        $filename = end(explode("/",$src));
        //$filename = $filename[count( $filename)-2];
        if (!in_array( $filename, $excludeFileNames))
        {
            //var_dump($filename);
            // var_dump(in_array( $filename, $excludeDirsNames));
            copy($src, $dest);

        }
    }
    //  rmdir_recursively($src);
}

if($mode != 1) echo '<br>Done';

?>