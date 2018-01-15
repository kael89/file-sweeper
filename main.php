<?php
/* Program */

global $config, $debug;

// Get configuration parameters
getConfig();

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

if (!$moveFiles || $debug) {
    printArr($dupls, 'Duplicate files:');
}
if (!$moveFiles) {
    exit();
}

// Move duplicate files
$source = $args['target'] ?: $args['source'];
$target = $args['target'] ?: $args['source'];
$moveFolder = $target . '/' . ltrim($config['move_to_folder'], '/');
$results = moveDuplicates($dupls, $source, $moveFolder);

if ($results['errors']) {
    printArr($results['errors']);
    die;
}

$count = count($results['moves']);
if ($debug) {
    echo "\n";
    printArr($results['moves'], "The following $count files will be moved:");
    echo "\n";
} else {
    echo "$count duplicate file(s) moved to $moveFolder\n";
}

// Clear target folder deleting empty subfolders
echo "Clearing up folders...\n";
$results = clearFolder($target);

if ($results['errors']) {
    printArr($results['errors']);
    die;
}

$count = count($results['deletes']);
if ($debug) {
    echo "\n";
    printArr($results['deletes'], "The following $count items will be deleted:");
    echo "\n";
}

echo "Done!\n";

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

        if ($target === false) {
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

function getConfig()
{
    global $config;
    $config = parse_ini_file('config.ini');

    if (!isset($config['ignore'])) {
        $config['ignore'] = [];
    }
    if (!isset($config['delete'])) {
        $config['delete'] = [];
    }
    if (!isset($config['move_to_folder'])) {
        $config['move_to_folder'] = '_duplicates';
    }
}

// Checks if a file is included in the ignore list
function isIgnored($file)
{
    global $config;

    static $ignores;
    if (!isset($ignores)) {
        $ignores = array_merge($config['ignore'], $config['delete'], ['.', '..', $config['move_to_folder']]);
    }    
    return in_array($file, $ignores);
}

// Check is a file or folder should be ignored when deleting
function isDeleteIgnored($file)
{
    global $config;
    return in_array($file, ['.', '..', $config['move_to_folder']]);
}

// Check if a file can be deleted when emptying folders
function isDeletable($file)
{
    global $config;

    static $deletables;
    if (!isset($deletables)) {
        $deletables = !empty($config['delete']) ? $config['delete'] : [];
    }
    return in_array($file, $deletables);
}

function scanDirectory($dir)
{
    return recursiveScan($dir);
}

// Scans for all files and folders in the provided directory recursively
function recursiveScan($dir, $root = true)
{
    global $config;

    static $id;
    static $files;
    static $folders;

    if ($root) {
        initScan($id, $files, $folders);
    }

    if (!is_dir($dir)) {
        return false;
    }

    // Recurse in directory contents
    $items = scandir($dir);
    $itemFiles = [];
    foreach ($items as $item) {
        $path = "$dir/$item";
        if (isIgnored($item)) {
            continue;
        } elseif (is_file($path)) {
            $itemFiles[] = $item;
            continue;
        }

        recursiveScan($path, false);
    }

    // Get directory files
    foreach ($itemFiles as $item) {
        $path = "$dir/$item";
        if (intval(filesize($path) / 1024) < $config['min_size']) {
            continue;
        } else {
            $files[] = [$item, $id];
        }
    }

    // Add current directory
    $folders[$id] = $dir;
    $id++;

    // When scanning ends, reset static variables and return results
    if ($root) {
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
    usort($files, function($a, $b) use ($sourceId, $targetId) {
        if ($a[0] > $b[0]) {
            return 1;
        }

        if ($a[0] == $b[0] && $b[2] == $sourceId && $a[2] == $targetId) {
            return 1;
        }

        return -1;
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

    $results = [
        'moves' => [],
        'errors' => []
    ];

    foreach ($files as $file) {
        $length = strlen($source);
        if (!substr($file, 0, $length) == $source) {
            $results['errors'][] = "Fatal error: wrong source folder specified when moving duplicates";
            return $results;
        }

        $newName = $target . substr($file, $length);
        $oldFolder = dirname($file);
        $parentFolder = dirname($oldFolder);
        $newFolder = dirname($newName);

        if (!$debug && !file_exists($newFolder) && !mkdir($newFolder, fileperms($file), true)) {
            $results['errors'][] = "Error: could not create $newFolder";
            continue;
        }

        if ($debug) {
            echo "$file => $newName\n";
        } elseif (!rename($file, $newName)) {
            $results['errors'][] = "Error: could not move $file";
            continue;
        }

        $results['moves'][] = "$file => $newName";
    }

    return $results;
}

// Clears a given folder by deleting all empty subfolders
function clearFolder($root)
{
    global $debug;

    $results = [
        'deletes' => [],
        'errors' => []
    ];

    // Recurse into subdirectories
    $items = scandir($root);
    if ($items === false) {
        die("Fatal error: could not find $root when deleting empty folders");
    }

    $deletes = [];
    foreach ($items as $item) {
        $path = "$root/$item";
        if (isDeleteIgnored($item)) {
            continue;
        }

        if (is_file($path)) {
            if (isDeletable($item)) {
                $deletes[] = $path;
            }
            continue;
        }
        
        $arr = clearFolder($path);
        $results['deletes'] = array_merge($results['deletes'], $arr['deletes']);
        $results['errors'] = array_merge($results['errors'], $arr['errors']);
    }

    foreach ($deletes as $item) {
        if (!unlink($item)) {
            $results['errors'][] = "Error: could not delete file $item\n";
            return $results;
        }
        $results['deletes'][] = "File $item will be deleted\n";
    }

    if (count(scandir($root)) == 2 &&  !rmdir($root)) {
        $results['errors'][] = "Error: could not delete folder $root";
        return $results;
    }

    $results['deletes'][] = "Folder $root will be deleted\n";
    return $results;
}
