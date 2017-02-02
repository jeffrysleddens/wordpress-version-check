#!/usr/bin/env php
<?php

define("VERSION_CACHE_FILE", "/etc/wordpress_version_check/versions.json");

$htaccess_contents  = "Require ip 145.24.0.0/16 145.51.0.0/16\n";
$htaccess_contents .= "ErrorDocument 403 /blocked_please_update_wordpress.html\n";
$htaccess_contents_md5 = md5($htaccess_contents);

function print_usage() {
  print("Usage: " . $_SERVER["SCRIPT_NAME"] . " [-v|--verbose] -d|--dir <directory to scan>\n");
}

$options = getopt("hvd:", array("help", "verbose", "dir:"));

if (isset($options["h"]) || isset($options["help"])) {
  print_usage();
  exit(0);
}

if (isset($options["v"]) || isset($options["verbose"])) {
  define("VERBOSE", true);
} else {
  define("VERBOSE", false);
}

$scandirs = array();
foreach (array("d", "dir") as $optionname) {
  if (isset($options[$optionname])) {
    if (is_array($options[$optionname])) {
      foreach ($options[$optionname] as $dir) {
        if (is_dir($dir)) $scandirs[] = $dir;
      }
    } elseif (is_dir($options[$optionname])) {
      $scandirs[] = $options[$optionname];
    }
  }
}
define("SCANDIR", $scandirs);

if (count(SCANDIR) == 0) {
  print_usage();
  exit(1);
}

$users = array();
$fh = fopen("/etc/passwd", "r");
while (($buffer = fgets($fh, 4096)) !== false) {
  $pwentry = explode(":", $buffer);
  $users[$pwentry[5]] = $pwentry[0];
}

function get_user_by_path($path) {
  global $users;
  if (strrpos($path, "/") == 0) return(false);
  if (array_key_exists($path, $users)) return($users[$path]);
  return(get_user_by_path(substr($path, 0, strrpos($path, "/"))));
}

function get_versions_from_json($json_version_string) {
  $wp_versions_json = json_decode($json_version_string);
  $wp_current_versions = array();
  if (isset($wp_versions_json->offers)) {
    foreach ($wp_versions_json->offers as $entry) {
      $wp_current_versions[] = $entry->current;
    }
  }
  return($wp_current_versions);
}

// Get the latest versions from wordpress.org
$json_version_string_current = file_get_contents("https://api.wordpress.org/core/version-check/1.7/");
$wp_current_versions = get_versions_from_json($json_version_string_current);

$version_hash_current = md5($json_version_string_current);
$version_hash_cache = "";

if (is_file(VERSION_CACHE_FILE)) {
  // Read versions from cache file
  $json_version_string_cache = file_get_contents(VERSION_CACHE_FILE);
  $version_hash_cache = md5($json_version_string_cache);
  $wp_current_versions = array_merge($wp_current_versions, get_versions_from_json($json_version_string_cache));
}

if (($version_hash_current != $version_hash_cache) && // New versions have been released AND
    ((is_file(VERSION_CACHE_FILE) && filemtime(VERSION_CACHE_FILE) < (time() - (7 * 24 * 60 * 60)) || // the cache file exists and is older than 7 days OR
     !is_file(VERSION_CACHE_FILE)))) { // there is no cache file
  // Then update the cache file with the current versions from wordpress.org
  if (!is_dir(dirname(VERSION_CACHE_FILE))) mkdir(dirname(VERSION_CACHE_FILE), 0755, true);
  file_put_contents(VERSION_CACHE_FILE, $json_version_string_current); 
}

$wp_current_versions = array_unique($wp_current_versions);

if (filemtime(VERSION_CACHE_FILE) > (time() - (24 * 60 * 60))) {
  mail("cmi-beheer@hr.nl", "Wordpress Version Check - Versions Updated", "The latest version file has been updated, you might want to check it:\n" . print_r($wp_current_versions, true));
  exit(0); // Give it one more day
}

if (VERBOSE) print("Outdated Wordpress sites:\n");
if (VERBOSE) print("------------------------------------------------------------------------------\n");

foreach (SCANDIR as $scandir) {
  $di = new RecursiveDirectoryIterator($scandir, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);
  foreach (new RecursiveIteratorIterator($di, RecursiveIteratorIterator::SELF_FIRST) as $direntry) {
    if ($direntry->getFileName() == "version.php") {
      if (preg_match("/wp-includes\/?/", $direntry->getPath())) {
        $wp_version = "";
        $wp_basedir = $direntry->getPathname();
        $fh = @fopen($wp_basedir, "r");
        if ($fh) {
          while (($buffer = fgets($fh, 4096)) !== false) {
            $matches = array();
            if (preg_match('/^\s*\$wp_version\s*=\s*["\'](.*?)["\']\s*;/', $buffer, $matches)) {
              $wp_version = $matches[1];
              $wp_basedir = preg_replace("/wp-includes\/version.php$/", "", $wp_basedir);
              $htaccess_file = $wp_basedir . "/blocked_please_update_wordpress";
              if (in_array($wp_version, $wp_current_versions) === false) {
                // Old version detected
                if (VERBOSE) print("  Basedir: $wp_basedir\n");
                if (VERBOSE) print("  Version: $wp_version\n");
                $wp_owner = get_user_by_path($wp_basedir);
                if (VERBOSE) print("  Owner:   $wp_owner\n");
                if (!is_file($htaccess_file) || md5_file($htaccess_file) != $htaccess_contents_md5) {
                  $mail_to = "";
                  if (substr($wp_owner, 0, 4) == "prj_" || substr($wp_owner, 0, 5) == "vgrp_") {
                    $group = posix_getgrnam($wp_owner);
                    foreach ($group["members"] as $member) $mail_to .= ", " . $member . "@hr.nl";
                  } else $mail_to = $wp_owner . "@hr.nl";
                  $mail_to = preg_replace("/^, /", "", $mail_to);
                  file_put_contents($htaccess_file, $htaccess_contents);
                  if ($mail_to != "") {
                    $headers = "From: \"CMI Serverbeheer\" <cmi-beheer@hr.nl>\r\n";
                    $headers.= "Cc: \"CMI Serverbeheer\" <cmi-beheer@hr.nl>\r\n";
                    $message = "Wij hebben een verouderde versie van Wordpress op je CMI webspace gevonden. Deze oude versies zijn erg onveilig voor het netwerk en kunnen misbruikt worden door hackers; dit kan grote consequenties hebben voor de Hogeschool Rotterdam.\r\n\r\nBij deze wordt je dan ook verzocht de Wordpress site te updaten naar de laatste versie. De nieuwste versies van Wordpress bieden ook een \"auto-update\" optie, waardoor je hierna altijd de laatste en veilige versie van Wordpress zal draaien.\r\n\r\nDe Wordpress site is vanaf nu van buiten het netwerk van Hogeschool Rotterdam niet meer te bereiken doordat we een bestand genaamd \"blocked_please_update_wordpress\" in je webspace hebben gezet. Als je de Wordpress site wilt updaten moet je eerst dat bestand via SFTP of SSH toegang verwijderen en daarna kun je Wordpress normaal updaten.\r\n\r\n\r\n\r\nWe have found an old version of Wordpress in your CMI webspace. These old versions are very unsecure and pose a threat to the network of Rotterdam University as they can be abused by hackers; this can have major consequences for Rotterdam University.\r\n\r\nWe urge you to update your Wordpress site as soon as possible to the latest version. The latest versions of Wordpress offer an \"auto-update\" option, this option makes sure that you will always run the latest and secure version of Wordpress.\r\n\r\Your outdated Wordpress site is now blocked from outsite the University network because we have placed a \"blocked_please_update_wordpress\" file in your webspace. If you want to update your Wordpress site you first have to delete that file through SFTP or SSH access and after that you can update Wordpress normally.\r\n\r\n\r\n\r\nUsername: $wp_owner\r\nWordpress directory: $wp_basedir\r\nInstalled version: $wp_version\r\n\r\nThe latest available version of Wordpress can be downloaded from http://wordpress.org";
                    mail($mail_to, "Oude Wordpress site / Old Wordpress site", $message, $headers);
                    if (VERBOSE) print("  Action:  blocked and mail sent to $mail_to\n");
                  } else {
                    if (VERBOSE) print("  Action:  blocked, but no mail sent\n");
                  }
                } else {
                  if (VERBOSE) print("  Action:  none, was already blocked\n");
                }
                if (VERBOSE) print("------------------------------------------------------------------------------\n");
              } else {
                // Acceptable version detected
                if (is_file($htaccess_file)) {
                  // Unblock the site, apparantly it has been updated
                  unlink($htaccess_file);
                  if (VERBOSE) print("Unblocked: $wp_basedir ($wp_version)\n");
                  if (VERBOSE) print("------------------------------------------------------------------------------\n");
                }
              }
            }
          }
          fclose($fh);
        }
      }
    }
  }
}
