<?php
/* Program */

global $config, $debug;
$config = parse_ini_file('config.ini');

// Parse arguments
$args = parseArgs($argv);
if ($args['errors']) {
    printArr($args['errors']);
    die;
}
$debug = array_key_exists('d', $args['options']);
$moveFiles = array_key_exists('m', $args['options']);

// Find duplicates
$dir1 = scanDirectory($args['source']);
$dir2 = scanDirectory($args['target']);
if ($dir2 === false) {
    $dir2 = [];
}
$dupls = findDuplicates($dir1, $dir2);

if ($debug) {
    printArr($dupls, 'Duplicate files:');
}
if (!$moveFiles || $debug) {
    exit();
}

// (Optional) move duplicate files
$source = $args['target'] ?: $args['source'];
$target = $args['target'] ?: $args['source'];
$target .= '/' . ltrim($config['move_to_folder'], '/');

$errors = moveDuplicates($dupls, $source, $target);
if ($errors) {
    printArr($errors);
    die;
}
foreach ($errors as $error) {
    echo "$error\n";
}
if (!$errors) {
    echo count($dupls) . " duplicate file(s) moved to $target\n";
}

/* Functions */
// Parses arguments from the command line
function parseArgs($argv)
{
    $results = [
        'errors' => ''
    ];
    if (count($argv) < 2) {
        $results['errors'][] = "Usage: file_sweeper.php [-d] [-m] source [target]";
        return $results;
    }

    // Get options
    $results['options'] = getopt('dm');
    $off = count($results['options']);

    // Get directories
    $dir = $argv[$off + 1];
    $source = realpath($dir);
    if ($source === false) {
        $results['errors'][] = "Error: Specified path $dir is not a directory";
    }

    if (count($argv) > $off + 2) {
        $dir = $argv[$off + 2];
        $target = realpath($dir);

        if ($source === false) {
            $results['errors'][] = "Error: Specified path $dir is not a directory";
        }
    } else {
        $target = '';
    }

    if ($results['errors']) {
        return $results;
    }

    if ($source == $target) {
        $results['errors'][] = "Error: Target and source directories are the same";
        return $results;
    }

    $results['source'] = $source;
    $results['target'] = $target;
    return $results;
}

// Checks if a file is included in the ignore list
function isIgnored($file)
{
    global $config;

    static $ignores;
    if (!isset($ignores)) {
        $ignores = array_merge($config['ignore'], ['.', '..', $config['move_to_folder']]);
    }    
    return in_array($file, $ignores);
}

// Scans for all files and folders in the provided directory recursively
function scanDirectory($dir)
{
    global $config;

    static $id;
    static $files;
    static $folders;

    if (!isset($id)) {
        initScan($id, $files, $folders);
    }
    $currentId = $id;

    // Add current directory
    if (!is_dir($dir)) {
        return false;
    } else {
        $folders[$currentId] = $dir;
        $id++;
    }

    // Get directory contents
    $items = scandir($dir);
    foreach ($items as $item) {
        if (isIgnored($item)) {
            continue;
        }

        $path = "$dir/$item";

        if (is_dir($path)) {
            scanDirectory($path);
        } elseif (intval(filesize($path) / 1024) < $config['min_size']) {
            continue;
        } else {
            $files[] = [$item, $currentId];
        }
    }

    // When scanning ends, reset static variables and return results
    if ($currentId == 1) {
        $results = [
            'files' => $files,
            'folders' => $folders
        ];

        initScan($id, $files, $folders);
        return $results;
    }
}

// Initializes the static variables of scandDirectory()
function initScan(&$id, &$files, &$folders)
{
    global $config;

    $id = 1;
    $files = [];
    $folders = [];
}

// Returns an array of duplicate files' paths
function findDuplicates($filedata1, $filedata2 = [])
{
    $results = [];
    $sourceId = 'A';
    $targetId = 'B';

    $files = addRootId($filedata1['files'], $sourceId);
    $folders = [
        $sourceId => $filedata1['folders']
    ];
    
    if ($filedata2) {
        $files = array_merge($files, addRootId($filedata2['files'], $targetId));
        $folders[$targetId] = $filedata2['folders'];
    }

    if (!$files) {
        return $results;
    }

    // Sort files by name
    usort($files, function($a, $b) {
        return $a[0] > $b[0];
    });
    // Sort files by root folder
    usort($files, function($a, $b) use ($sourceId, $targetId) {
        if ($a[0] == $b[0]) {
            return ($b[2] == $sourceId && $a[2] == $targetId);
        }
    });

    $current = $files[0];
    unset($files[0]);
    foreach ($files as $file) {
        if ($file[0] != $current[0]) {
            if (!$filedata2 || $file[2] == $sourceId) {
                $current = $file;
            }
            continue;
        } elseif ($filedata2 && $file[2] == $sourceId) {
            continue;
        }

        $filepath1 = $folders[$current[2]][$current[1]] . "/$current[0]";
        $filepath2 = $folders[$file[2]][$file[1]] . "/$file[0]";
        if (!compareFiles($filepath1, $filepath2)) {
            continue;
        }
        
        $results[] = $filepath2;
    }

    return $results;
}

// Adds an id element in the array
function addRootId($arr, $id)
{
    foreach ($arr as &$item) {
        $item[2] = $id;
    }
    return $arr;
}

// Returns true if two files are identical based on the given criteria
function compareFiles($a, $b)
{
    global $config;

    $params = $config['params'];

    if (in_array('size', $params) && filesize($a) != filesize($b)) {
        return false;
    }
    if (in_array('last_modified', $params) && filemtime($a) != filemtime($b)) {
        return false;
    }
    if (in_array('hash', $params) && md5_file($a) != md5_file($b)) {
        return false;
    }

    return true;
}

// Prints an array with an optional descriptive message
function printArr($arr, $msg = '')
{
    if ($msg) {
        echo "$msg\n";
    }

    foreach ($arr as $item) {
        echo "$item\n";
    }
}

// Moves duplicates in the specified location
function moveDuplicates($files, $source, $target)
{
    global $debug;

    $oldFolders = [];
    $moved = [];
    $deleted = [];
    $errors = [];

    foreach ($files as $file) {
        $length = strlen($source);
        if (!substr($file, 0, $length) == $source) {
            $errors[] = "Fatal error: wrong source folder specified when moving duplicates";
            return $errors;
        }

        $newName = $target . substr($file, $length);
        $oldFolder = dirname($file);
        $newFolder = dirname($newName);

        if (!file_exists($newFolder) && !mkdir($newFolder, fileperms($file), true)) {
            $errors[] = "Error: could not create $newFolder";
            continue;
        }

        if (!rename($file, $newName)) {
            $errors[] = "Error: could not move $file";
            continue;
        }

        $oldFolders[] = $oldFolder;
    }

    if (!$debug) {
        deleteEmptyFolders(array_unique($oldFolders));
    }
    return $errors;
}

// Deletes all folders that the program emptied
function deleteEmptyFolders($folders)
{
    $errors = [];
    foreach ($folders as $folder) {
        $items = scandir($folder);

        if ($items === false) {
            $errors[] = "Fatal error: could not find $folder when deleting empty folders";
            return $errors;
        }

        foreach ($items as $item) {
            if (!isIgnored($item)) {
                continue 2;
            }
        }

        if (!rmdir($folder)) {
            $errors[] = "Error: could not delete $folder";
        }
    }

    return $errors;
}