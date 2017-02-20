#!/usr/bin/php
<?php

/**
 * Migrate PHP sessions from file storage to redis storage using Mass Insertion
 *
 * PHP script written 2017 by Andrew Gillard <andrew@lorddeath.net>;
 *   heavily inspired by Bash script 2013 by renasboy <renasboy@gmail.com>
 * @licence GPL v3
 *
 * Reads sessions stored in $sessionDir and transfers the contents to redis
 *  database using redis-cli pipe mode and redis mass insertion protocol.
 * Sessions are stored with $sessionPrefix as a prefix
 */

// Where are the existing PHP sessions stored?
// Essentially your session.save_path php.ini value
$sessionDir = "/var/lib/php5/sessions";

// The PHP session prefix inside redis.
// The default is "PHPHREDIS_SESSION"
$sessionPrefix = "PHPREDIS_SESSION";

// Your redis password, if set. Leave blank if not needed.
$redisAuth = "";

// Host where redis is being run. Leave blank for localhost.
$redisHost = "";

// Port on which redis is running. Leave blank for the default.
$redisPort = "";

// ------ End of Config ------ //

// Create a temporary file for storing the redis protocol messages
$tmpFile = tempnam(sys_get_temp_dir(), "prms");
$fp = fopen($tmpFile, 'wb');
echo "Opened temporary file '$tmpFile'...\r\n";

// And delete the temporary file when we're done
register_shutdown_function(function () use ($tmpFile) {
    unlink($tmpFile);
});

// Process every file in the sessions directory...
echo "Processing each file...\r\n";
foreach (new DirectoryIterator($sessionDir) as $file) {
    // Skip the "dot" "files" and any empty sessions
    if ($file->isDot()) {
        continue;
    }
    if ($file->getSize() === 0) {
        continue;
    }

    // Prepare the session data for appending to the protocol file
    $sessionId = $sessionPrefix . ":" . preg_replace('/^sess_/i', '', $file->getFilename());
    $sessionData = file_get_contents($file->getPathname());

    // Incorporate the session data into the redis protocol
    $protocol = sprintf(
        "*3\r\n$3\r\nSET\r\n$%d\r\n%s\r\n$%d\r\n%s\r\n",
        strlen($sessionId),
        $sessionId,
        strlen($sessionData),
        $sessionData
    );

    // And write the protocol data out to our temp file
    fwrite($fp, $protocol);
}

// We've finished writing to the temporary file, so close it...
echo "Finished preparing protocol data...\r\n";
fclose($fp);

// ...and pass it on to redis-cli for transfer to the server
echo "Passing data to redis-cli...\r\n";
$redisCmd = "cat $tmpFile | redis-cli --pipe";
// Redis command line tool options
if ($redisHost != '') {
    $redisCmd .= " -h " . escapeshellarg($redisHost);
}
if ($redisPort != '') {
    $redisCmd .= " -p " . (int) $redisPort;
}
if ($redisAuth != '') {
    $redisCmd .= " -a " . escapeshellarg($redisAuth);
}
echo "Executing `$redisCmd`...\r\n";
passthru($redisCmd);
