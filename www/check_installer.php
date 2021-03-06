<?php
if(extension_loaded('newrelic')) {
    newrelic_add_custom_tracer('ApcCheckIp');
    newrelic_add_custom_tracer('CheckIp');
}
include 'common_lib.inc';
error_reporting(E_ERROR | E_PARSE);

$has_apc = function_exists('apc_fetch') && function_exists('apc_store');

$ok = false;
$ip = $_SERVER["REMOTE_ADDR"];
if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
  $forwarded = explode(',',$_SERVER["HTTP_X_FORWARDED_FOR"]);
  if (isset($forwarded) && is_array($forwarded) && count($forwarded)) {
    $forwarded_ip = trim(end($forwarded));
    if (strlen($forwarded_ip) && $forwarded_ip != "127.0.0.1")
        $ip = $forwarded_ip;
  }
}
if (isset($_REQUEST['installer']) && isset($ip)) {
  $installer = $_REQUEST['installer'];
  $installer_postfix = GetSetting('installerPostfix');
  if ($installer_postfix) {
    $installer .= $installer_postfix;
    $ok = true;
  } elseif ($ip == '72.66.115.14' ||  // Public WebPageTest
            $ip == '149.20.63.13') {  // HTTP Archive
    $ok = true;
  } elseif (preg_match('/^(software|browsers\/[-_a-zA-Z0-9]+)\.dat$/', $installer)) {
    $ok = IsValidIp($ip, $installer);
  }
}

if ($ok) {
  $file = __DIR__ . '/installers/' . $installer;
  $data = $has_apc ? apc_fetch("installer-$installer") : null;
  if (!$data && is_file($file)) {
    $data = file_get_contents($file);
    ModifyInstaller($data);
    if ($has_apc)
      apc_store("installer-$installer", $data, 600);
  }
  if (isset($data) && strlen($data)) {
    header("Content-type: text/plain");
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    echo $data;
  } else {
    header('HTTP/1.0 404 Not Found');
  }
} else {
  header('HTTP/1.0 403 Forbidden');
}

function IsValidIp($ip, $installer) {
  global $has_apc;
  $ok = true;
  
  // Make sure it isn't on our banned IP list
  $filename = __DIR__ . '/settings/block_installer_ip.txt';
  if (is_file($filename)) {
    $blocked_addresses = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (in_array($ip, $blocked_addresses)) {
      $ok = false;
    }
  }

  if ($ok ) {  
    $ok = $has_apc ? ApcCheckIp($ip, $installer) : CheckIp($ip, $installer);
    if (!$ok) {
      logMsg("BLOCKED - $ip : {$_REQUEST['installer']}", "log/software.log", true);
    }
  }
  return $ok;
}

function ApcCheckIp($ip, $installer) {
  $ok = true;
  if (isset($ip) && strlen($ip)) {
    $now = time();
    $key = "inst-ip-$ip-$installer";
    $history = apc_fetch($key);
    if (!$history) {
      $history = array();
    } elseif (!is_array($history)) {
      $history = json_decode($history, true);
      if (!$history) {
        $history = array();
      }
    }
    $history[] = $now;
    // Use 1KB blocks to prevent fragmentation
    apc_store($key, $history, 604800);
    if (count($history) > 10)
      array_shift($history);
    $count = 0;
    foreach ($history as $time) {
      if ($now - $time < 3600)
        $count++;
    }
    if ($count > 4) {
      $ok = false;
    }
  }
  return $ok;
}

/**
* For each IP/Installer pair, keep track of the last 4 checks and if they
* were within the last hour fail the request.
* 
* @param mixed $installer
*/
function CheckIp($ip, $installer) {
  $ok = true;
  if (isset($ip) && strlen($ip)) {
    $lock = Lock("Installers", true, 5);
    if ($lock) {
      $now = time();
      $file = "./tmp/installers.dat";
      if (gz_is_file($file))
        $history = json_decode(gz_file_get_contents($file), true);
      if (!isset($history) || !is_array($history))
        $history = array();
      
      if (isset($history[$ip])) {
        if (isset($history[$ip][$installer])) {
          $history[$ip][$installer][] = $now;
          if (count($history[$ip][$installer]) > 10)
            array_shift($history[$ip][$installer]);
          if (isset($history[$ip]["last-$installer"]) &&
              $now - $history[$ip]["last-$installer"] < 3600) {
            $count = 0;
            foreach ($history[$ip][$installer] as $time) {
              if ($now - $time < 3600)
                $count++;
            }
            if ($count > 4) {
              $ok = false;
            }
          }
        } else {
          $history[$ip][$installer] = array($now);
        }
      } else {
        $history[$ip] = array($installer => array($now));
      }
      $history[$ip]['last'] = $now;
      if ($ok) {
        $history[$ip]["last-$installer"] = $now;
      }
      
      // prune any agents that haven't connected in 7 days
      foreach ($history as $agent => $info) {
        if ($now - $info['last'] > 604800) {
          unset($history[$agent]);
        }
      }
      
      gz_file_put_contents($file, json_encode($history));
      Unlock($lock);
    }
  }
  return $ok;
}

/**
* Override installer options from settings
* 
* @param mixed $data
*/
function ModifyInstaller(&$data) {
  $always_update = GetSetting('installer-always-update');
  if ($always_update)
    $data = str_replace('update=0', 'update=1', $data);
  $base_url = GetSetting('installer-base-url');
  if ($base_url && strlen($base_url))
    $data = str_replace('http://cdn.webpagetest.org/', $base_url, $data);
}
?>
