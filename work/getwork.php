<?php
if(extension_loaded('newrelic')) { 
    newrelic_add_custom_tracer('GetUpdate');
    newrelic_add_custom_tracer('GetVideoJob');
    newrelic_add_custom_tracer('GetJobFile');
}

chdir('..');
$debug = false;
include 'common.inc';
set_time_limit(600);

$is_json = isset($_GET['f']) && $_GET['f'] == 'json';
$location = $_GET['location'];
$key = @$_GET['key'];
$recover = @$_GET['recover'];
$pc = @$_GET['pc'];
$ec2 = @$_GET['ec2'];
$tester = null;
if (@strlen($ec2)) {
    $tester = $ec2;
} elseif (@strlen($pc)) {
    $tester = $pc . '-' . trim($_SERVER['REMOTE_ADDR']);
} else {
    $tester = trim($_SERVER['REMOTE_ADDR']);
}
$dnsServers = '';
if (array_key_exists('dns', $_REQUEST))
    $dnsServers = str_replace('-', ',', $_REQUEST['dns']);
$supports_sharding = false;
if (array_key_exists('shards', $_REQUEST) && $_REQUEST['shards'])
    $supports_sharding = true;

logMsg("getwork.php location:$location tester:$tester ex2:$ec2 recover:$recover");

$is_done = false;
if (!array_key_exists('freedisk', $_GET) || (float)$_GET['freedisk'] > 0.1) {
    // See if there is an update.
    if (!$is_done && $_GET['ver']) {
        $is_done = GetUpdate();
        if ($is_done)
          logMsg("getwork.php Update Available ($location:$tester)");
    }
    // see if there is a video  job
    if (!$is_done && @$_GET['video']) {
        $is_done = GetVideoJob();
        if ($is_done)
          logMsg("getwork.php Video Job ($location:$tester)");
    }
    if (!$is_done) {
        $is_done = GetJob();
        if ($is_done)
          logMsg("getwork.php Work returned ($location:$tester)");
        else
          logMsg("getwork.php No Work Available ($location:$tester)");
    }
} else {
  logMsg("getwork.php Not enough free disk space ($location:$tester)");
}

// kick off any cron work we need to do asynchronously
CheckCron();

// Send back a blank result if we didn't have anything.
if (!$is_done) {
    header('Content-type: text/plain');
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}


/**
* Get an actual task to complete
* 
*/
function GetJob() {
    $is_done = false;

    global $location;
    global $key;
    global $pc;
    global $ec2;
    global $tester;
    global $recover;
    global $is_json;
    global $dnsServers;

    // load all of the locations
    $locations = parse_ini_file('./settings/locations.ini', true);
    BuildLocations($locations);

    $workDir = $locations[$location]['localDir'];
    $locKey = @$locations[$location]['key'];
    logMsg("getwork.php Key:$key ($locKey), dir: $workDir ($location:$tester)");
    if (strlen($workDir) && (!strlen($locKey) || !strcmp($key, $locKey))) {
        // see if the tester is marked as being offline
        $offline = false;
        if( strlen($ec2) && strlen($locations[$location]['ec2']) && is_file('./ec2/ec2.inc.php') )
        {
            logMsg("Checking $ec2 to see if it is offline");
            require_once('./ec2/ec2.inc.php');
            if( !EC2_CheckInstance($location, $locations[$location]['ec2'], $ec2) )
            {
                logMsg("$ec2 is offline");
                $offline = true;
            }
        }
        
        if( $lock = LockLocation($location) )
        {
            // make sure the work directory actually exists
            if( !is_dir($workDir) )
                mkdir($workDir, 0777, true);
            
            $now = time();
            $testers = GetTesters($location);

            // make sure the tester isn't marked as offline (usually when shutting down EC2 instances)                
            if(!@$testers[$tester]['offline']) {
                $fileName = GetJobFile($workDir, $priority);
                if( isset($fileName) && strlen($fileName) )
                {
                    $is_done = true;
                    $delete = true;
                    
                    if ($is_json)
                        header ("Content-type: application/json");
                    else
                        header('Content-type: text/plain');
                    header("Cache-Control: no-cache, must-revalidate");
                    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

                    // send the test info to the test agent
                    $testInfo = file_get_contents("$workDir/$fileName");

                    // extract the test ID from the job file
                    if( preg_match('/Test ID=([^\r\n]+)\r/i', $testInfo, $matches) )
                        $testId = trim($matches[1]);

                    if( isset($testId) ) {
                        // figure out the path to the results
                        $testPath = './' . GetTestPath($testId);

                        // flag the test with the start time
                        $ini = file_get_contents("$testPath/testinfo.ini");
                        if (stripos($ini, 'startTime=') === false) {
                            $time = time();
                            $start = "[test]\r\nstartTime=" . gmdate("m/d/y G:i:s", $time);
                            $out = str_replace('[test]', $start, $ini);
                            file_put_contents("$testPath/testinfo.ini", $out);
                        }
                        
                        if( gz_is_file("$testPath/testinfo.json") ) {
                            $testInfoJson = json_decode(gz_file_get_contents("$testPath/testinfo.json"), true);
                            if (!array_key_exists('tester', $testInfoJson) || !strlen($testInfoJson['tester']))
                                $testInfoJson['tester'] = $tester;
                            if (isset($dnsServers) && strlen($dnsServers))
                                $testInfoJson['testerDNS'] = $dnsServers;
                            if (!array_key_exists('started', $testInfoJson) || !strlen($testInfoJson['started']))
                                $testInfoJson['started'] = $time;
                            $testInfoJson['id'] = $testId;
                            ProcessTestShard($testInfoJson, $testInfo, $delete);
                            gz_file_put_contents("$testPath/testinfo.json", json_encode($testInfoJson));
                        }
                    }

                    if ($delete) {
                        unlink("$workDir/$fileName");
                    } else {
                        AddJobFileHead($workDir, $fileName, $priority, true);
                    }
                    
                    if ($is_json) {
                        $testJson = array();
                        $script = '';
                        $isScript = false;
                        $lines = explode("\r\n", $testInfo);
                        foreach($lines as $line) {
                            if( strlen(trim($line)) ) {
                                if( $isScript ) {
                                    if( strlen($script) )
                                        $script .= "\r\n";
                                    $script .= $line;
                                } elseif( !strcasecmp($line, '[Script]') )
                                    $isScript = true;
                                else {
                                    $pos = strpos($line, '=');
                                    if( $pos > -1 ) {
                                        $key = trim(substr($line, 0, $pos));
                                        $value = trim(substr($line, $pos + 1));
                                        if( strlen($key) && strlen($value) ) {
                                            if( is_numeric($value) )
                                                $testJson[$key] = (int)$value;
                                            else
                                                $testJson[$key] = $value;
                                        }
                                    }
                                }
                            }
                        }
                        if( strlen($script) )
                            $testJson['script'] = $script;
                        echo json_encode($testJson);
                    }
                    else
                        echo $testInfo;
                    $ok = true;
                }
                    
                // zero out the tracked page loads in case some got lost
                if( !$is_done ) {
                    $tests = json_decode(file_get_contents("./tmp/$location.tests"), true);
                    if( $tests ) {
                        $tests['tests'] = 0;
                        file_put_contents("./tmp/$location.tests", json_encode($tests));
                    }
                }
            }
            
            UnlockLocation($lock);

            // keep track of the last time this location reported in
            $testerInfo = array();
            $testerInfo['ip'] = $_SERVER['REMOTE_ADDR'];
            $testerInfo['pc'] = $pc;
            $testerInfo['ec2'] = $ec2;
            $testerInfo['ver'] = $_GET['ver'];
            $testerInfo['freedisk'] = @$_GET['freedisk'];
            $testerInfo['ie'] = @$_GET['ie'];
            $testerInfo['dns'] = $dnsServers;
            $testerInfo['video'] = @$_GET['video'];
            $testerInfo['test'] = '';
            if (isset($testId)) {
                $testerInfo['test'] = $testId;
            }
            UpdateTester($location, $tester, $testerInfo);
      } else
        logMsg("getwork.php Failed to lock location ($location:$tester)");
    } else
      logMsg("getwork.php Invalid location ($location:$tester)");
    
    return $is_done;
}

/**
* Get the next job from the work queue
* 
* @param mixed $workDir
*/
function GetNextJobFile($workDir)
{
    $fileName = null;
    
    // get a list of all of the files in the directory and store them indexed by filetime
    $files = array();
    $f = scandir($workDir);
    foreach( $f as $file )
    {
        $fileTime = filemtime("$workDir/$file");
        if( $fileTime && !isset($files[$fileTime]) )
            $files[$fileTime] = $file;
        else
            $files[] = $file;
    }
    
    // sort it by time
    ksort($files);
    
    // loop through all of the possible extension types in priority order
    $priority = array( "url", "p1", "p2", "p3", "p4", "p5", "p6", "p7", "p8", "p9" );
    foreach( $priority as $ext )
    {
        foreach( $files as $file )
        {
            if(is_file("$workDir/$file"))
            {
                $parts = pathinfo($file);
                if( !strcasecmp( $parts['extension'], $ext) )
                {
                    $fileName = "$workDir/$file";
                    break 2;
                }
            }
        }
    }

    return $fileName;
}

/**
* See if there is a video rendering job that needs to be done
* 
*/
function GetVideoJob()
{
    global $debug;
    global $location;
    global $tester;
    $ret = false;
    
    $videoDir = './work/video';
    if( is_dir($videoDir) )
    {
        // lock the directory
        $lockFile = fopen( './tmp/video.lock', 'w',  false);
        if( $lockFile )
        {
            if( flock($lockFile, LOCK_EX) )
            {
                // look for the first zip file
                $dir = opendir($videoDir);
                if( $dir )
                {
                    $testFile = null;
                    while(!$testFile && $file = readdir($dir)) 
                    {
                        $path = $videoDir . "/$file";
                        if( is_file($path) && stripos($file, '.zip') )
                            $testFile = $path;
                    }
                    
                    if( $testFile )
                    {
                        header('Content-Type: application/zip');
                        header("Cache-Control: no-cache, must-revalidate");
                        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

                        logMsg("Video job $testFile sent to $tester from $location");
                        readfile_chunked($testFile);
                        $ret = true;
                        
                        // delete the test file
                        unlink($testFile);
                    }

                    closedir($dir);
                }
                flock($lockFile, LOCK_UN);
            }

            fclose($lockFile);
        }
    }
    
    return $ret;
}

/**
* See if there is a software update
* 
*/
function GetUpdate()
{
    global $location;
    $ret = false;
    
    // see if the client sent a version number
    if( $_GET['ver'] )
    {
        $fileBase = '';
        if( isset($_GET['software']) && strlen($_GET['software']) )
            $fileBase = trim($_GET['software']);
        
        $updateDir = './work/update';
        if( is_dir("$updateDir/$location") )
            $updateDir = "$updateDir/$location";
            
        // see if we have any software updates
        if( is_file("$updateDir/{$fileBase}update.ini") && is_file("$updateDir/{$fileBase}update.zip") )
        {
            $update = parse_ini_file("$updateDir/{$fileBase}update.ini");
            if( $update['ver'] && (int)$update['ver'] != (int)$_GET['ver'] )
            {
                header('Content-Type: application/zip');
                header("Cache-Control: no-cache, must-revalidate");
                header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

                readfile_chunked("$updateDir/{$fileBase}update.zip");
                $ret = true;
            }
        }
    }
    
    return $ret;
}

/**
* Send a quick http request locally if we need to process cron events (to each of the cron entry points)
* 
* This only runs events on 15-minute intervals and tries to keep it close to the clock increments (00, 15, 30, 45)
* 
*/
function CheckCron() {
    // open and lock the cron job file - abandon quickly if we can't get a lock
    $should_run = false;
    $cron_lock = fopen('./tmp/wpt_cron.lock', 'w+');
    if ($cron_lock !== false) {
        if (flock($cron_lock, LOCK_EX | LOCK_NB)) {
            $last_run = 0;
            if (is_file('./tmp/wpt_cron.dat'))
                $last_run = file_get_contents('./tmp/wpt_cron.dat');
            $now = time();
            $elapsed = $now - $last_run;
            if (!$last_run || $elapsed > 120) {
                if ($elapsed > 1200) {
                    // if it has been over 20 minutes, run regardless of the wall-clock time
                    $should_run = true;
                } else {
                    $minute = gmdate('i', $now) % 5;
                    if ($minute < 2)
                        $should_run = true;
                }
            }
            if ($should_run) {
                file_put_contents('./tmp/wpt_cron.dat', $now);
            }
            flock($cron_lock, LOCK_UN);
        }
        fclose($cron_lock);
    }
    
    // send the cron requests
    if ($should_run) {
        if (is_file('./settings/benchmarks/benchmarks.txt') && 
            is_file('./benchmarks/cron.php')) {
            SendCronRequest('/benchmarks/cron.php');
        }
        if (is_file('./jpeginfo/cleanup.php')) {
            SendCronRequest('/jpeginfo/cleanup.php');
        }
    }
}

/**
* Send a request with a really short timeout to fire an async cron event
* 
* @param mixed $relative_url
*/
function SendCronRequest($relative_url) {
    $url = "http://{$_SERVER['HTTP_HOST']}$relative_url";
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($c, CURLOPT_TIMEOUT, 1);
    curl_exec($c);
    curl_close($c);
}

/**
* Process a sharded test
* 
* @param mixed $testInfo
*/
function ProcessTestShard(&$testInfo, &$test, &$delete) {
    global $supports_sharding;
    global $tester;
    if (isset($testInfo) && array_key_exists('shard_test', $testInfo) && $testInfo['shard_test']) {
        if ($supports_sharding) {
            if( $testLock = fopen( "$testPath/test.lock", 'w',  false) )
                flock($testLock, LOCK_EX);
            $done = true;
            $assigned_run = 0;
            if (!array_key_exists('test_runs', $testInfo)) {
                $testInfo['test_runs'] = array();
                for ($run = 1; $run <= $testInfo['runs']; $run++) {
                    $testInfo['test_runs'][$run] = array();
                }
            }
            
            // find a run to assign to a tester
            for ($run = 1; $run <= $testInfo['runs']; $run++) {
                if (!array_key_exists('tester', $testInfo['test_runs'][$run])) {
                    $testInfo['test_runs'][$run]['tester'] = $tester;
                    $testInfo['test_runs'][$run]['started'] = time();
                    $testInfo['test_runs'][$run]['done'] = false;
                    $assigned_run = $run;
                    break;
                }
            }
            
            // go through again and see if all tests have been assigned
            for ($run = 1; $run <= $testInfo['runs']; $run++) {
                if (!array_key_exists('tester', $testInfo['test_runs'][$run])) {
                    $done = false;
                    break;
                }
            }
            
            if ($assigned_run) {
                $append = "run=$assigned_run\r\n";

                // Figure out if this test needs to be discarded
                $index = $assigned_run;
                if (array_key_exists('discard', $testInfo)) {
                    if ($index <= $testInfo['discard']) {
                        $append .= "discardTest=1\r\n";
                        $index = 1;
                        $done = true;
                        $testInfo['test_runs'][$assigned_run]['discarded'] = true;
                    } else {
                        $index -= $testInfo['discard'];
                    }
                }
                $append .= "index=$index\r\n";
                
                $insert = strpos($test, "\nurl");
                if ($insert !== false) {
                    $test = substr($test, 0, $insert + 1) . 
                            $append . 
                            substr($test, $insert + 1);
                } else {
                    $test = "run=$assigned_run\r\n" + $test;
                }
            }

            if (!$done)
                $delete = false;
                
            if (isset($testLock) && $testLock) {
                flock($testLock, LOCK_UN);
                fclose($testLock);
            }
        } else {
            $testInfo['shard_test'] = 0;
        }
    }
}
?>
