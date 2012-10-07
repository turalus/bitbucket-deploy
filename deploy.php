<?php

// Set these dependant on your BB credentials    
$username = '';
$password = '';

// your Bitbucket repo name
$reponame = "";

// extract to
$dest = "./"; // leave ./ for relative destination


// Grab the data from BB's POST service and decode
$json = stripslashes($_POST['payload']);
$data = json_decode($json);

// set higher script timeout (for large repo's or slow servers)
set_time_limit(5000);


// Set some parameters to fetch the correct files
$uri = $data->repository->absolute_url;
$node = $data->commits[0]->node;
$files = $data->commits[0]->files;

// download the repo zip file
$fp = fopen("tip.zip", 'w');

$ch = curl_init("https://bitbucket.org/$username/$reponame/get/$node.zip");
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FILE, $fp);

$data = curl_exec($ch);

curl_close($ch);
fclose($fp);

// unzip
$zip = new ZipArchive;
$res = $zip->open('tip.zip');
if ($res === TRUE) {
    $zip->extractTo('./');
    $zip->close();
} else {
    die('ZIP not supported on this server!');
}

// function to delete all files in a directory recursively
function rmdir_recursively($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir . "/" . $object) == "dir")
                    rmdir_recursively($dir . "/" . $object); else
                    unlink($dir . "/" . $object);
            }
        }
        reset($objects);
        rmdir($dir);
    }
}

// function to recursively copy the files
function copy_recursively($src, $dest) {
    if (is_dir($src)) {
        if ($dest != "./")
            rmdir_recursively($dest);
        @mkdir($dest);
        $files = scandir($src);
        foreach ($files as $file)
            if ($file != "." && $file != "..")
                copy_recursively("$src/$file", "$dest/$file");
    }
    else if (file_exists($src))
        copy($src, $dest);
    rmdir_recursively($src);
}

// start copying the files from extracted repo and delete the old directory recursively
copy_recursively("$username-$reponame-$node", $dest);

// delete the repo zip file
unlink("tip.zip");
?>