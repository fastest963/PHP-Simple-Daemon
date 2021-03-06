#!/usr/bin/php -q
<?php
error_reporting(E_ALL); //report all errors
set_time_limit(0); //run forever
declare(ticks = 1);

/* You may edit any values below this line */
// TRAILING SLASHES REQUIRED
$conf['piddir'] = $_SERVER['HOME'] . "/php-daemon"; //a folder that contains PIDs (must be 777)
$conf['logfile'] = $_SERVER['HOME'] . "/php-daemon/log.log"; //blank ("") for no logging
// you need super-user or root privleges to change the uid and gid
$conf['uid'] = ""; //the UID of the daemon to run (blank for no change)
$conf['gid'] = ""; //group ID of the daemon to run (blank for no change)
$conf['IP'] = "0.0.0.0"; // IP for the daemon to run on
$conf['port'] = 16644; //port to run daemon on
$conf['timeout'] = 300; //seconds to wait with NO activity until client is closed
$conf['welcome'] = "\nWelcome to my PHP Daemon.\nTo end your session, type 'end'.\n"; //welcome message
$conf['login'] = array('user' => 'pass', 'user2' => 'pass2');

// here is your function:
// it is called whenever someone enters text
function userfunc($sock, $sent) {
    //keep the function name the same!
    $msg = "You said '$sent'.\n";
    socket_write($sock, $msg, strlen($msg));
}

/* You should really be careful editting anything below this line! :P */

$masterPID = daemonize(); //store the master pid

//set the gid first
if (!empty($conf['gid'])) {
    if (!posix_setgid($conf['gid'])) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] Unable to setgid!\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] Unable to setgid!\n";
        exit(0);
    }
}
//set the uid
if (!empty($conf['uid'])) {
    if (!posix_setuid($conf['uid'])) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] Unable to setuid!\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] Unable to setuid!\n";
        exit(0);
    }
}

//make sure log is writable
if (!is_writable($conf['logfile']) && !is_writable(substr($conf['logfile'], 0, -1 * strlen(basename($conf['logfile']))))) {
    echo "[" . posix_getpid() . "] Log File and Directory are not writable\n";
    clearstatcache(); //cleanup
    exit(0);
}

//make sure pids is writable
if (!is_writable($conf['piddir'])) {
    echo "[" . posix_getpid() . "] PID Directory is not writable\n";
    clearstatcache(); //cleanup
    exit(0);
}

clearstatcache(); //cleanup

//handle system calls and send to signal()
pcntl_signal(SIGTERM, 'signal');
pcntl_signal(SIGINT, 'signal');
pcntl_signal(SIGCHLD, 'signal');
pcntl_signal(SIGHUP, "signal");

//start socket

file_put_contents($conf['logfile'], "\n****************\n* Daemon Start *\n****************\nTime: " . date("r") . "\n", FILE_APPEND);

$run = true;
$socket = begin($conf['IP'], $conf['port']);

function daemonize()
{
    global $conf;
    $pid = pcntl_fork();
    if ($pid == -1) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] initial fork failure!\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] initial fork failure!";
        exit();
    } elseif ($pid) { // we are in parent
        exit(0);
    } else {
        posix_setsid();
        chdir('/');
        umask(0);
        sdClients(false); // remove all current pids
        return posix_getpid();
    }
}

//starts socket and begins
function begin($ip, $p)
{
    global $run, $socket, $conf;

    //create socket
    if (($socket = socket_create(AF_INET, SOCK_STREAM, 0)) === false) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] failed to create socket: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] failed to create socket: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }

    // reuse socket if its already open (prevents errors on restart)
    if (socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1) === false) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] failed to set SO_REUSEADDR socket: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] failed to set SO_REUSEADDR socket: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }

    // bind socket to IP and port
    if (@socket_bind($socket, $ip, $p) === false) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] failed to bind socket: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] failed to bind socket: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }

    // start listening with no backlog
    if (socket_listen($socket, 0) === false) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] failed to listen to socket: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
        echo "[" . posix_getpid() . "] failed to listen to socket: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }
    //set the socket to non-blocking mode
    socket_set_nonblock($socket);

    file_put_contents($conf['logfile'], "[" . posix_getpid() . "] daemon connected and waiting...\n", FILE_APPEND);
    //echo "[".posix_getpid()."] daemon connected and waiting...\n";

    while ($run) {
        $conn = @socket_accept($socket);
        if ($conn === false)
            usleep(100);
        elseif ($conn > 0)
            handle_client($socket, $conn);
        else {
            file_put_contents($conf['logfile'], "[" . posix_getpid() . "] socket_accept() error: " . socket_strerror(socket_last_error()), FILE_APPEND);
            exit(0);
        }
    }
    return $socket;
}

//shutdown clients
function sdClients($b = true)
{
    global $conf;
    $hand = opendir($conf['piddir']);
    while ($f = readdir($hand)) {
        if ($f != '.' && $f != '..' && substr($f, -4) == ".pid") {
            if ($b === true) file_put_contents($conf['logfile'], "[" . posix_getpid() . "]  killed client PID:" . basename($f, ".pid") . "\n", FILE_APPEND);
            unlink($conf['piddir'] . $f);
        }
    }
    closedir($hand);
    unset($f, $hand);
}

//handle system signals
function signal($sig)
{
    global $run, $socket, $masterPID, $conf;
    switch ($sig) {
        case SIGTERM: //shutdown
        case SIGINT:
            if (posix_getpid() == $masterPID) { // if we are the master
                file_put_contents($conf['logfile'], "[" . posix_getpid() . "] server shutdown...!\n", FILE_APPEND);
                sdClients();
                sleep(1); //hold on
                $run = false;
                socket_shutdown($socket, 2); //force end reading and writing
                socket_close($socket);
                file_put_contents($conf['logfile'], "[" . posix_getpid() . "] disconnected!\n", FILE_APPEND);
                unset($socket);
                exit(0);
            } else { // we are in a client
                //delete pid file
                unlink($conf['piddir'] . posix_getpid() . ".pid");
                exit(0);
            }
            break;
        case SIGHUP: //restart
            if (posix_getpid() == $masterPID) { // if we are the master
                file_put_contents($conf['logfile'], "[" . posix_getpid() . "] server restarting...!\n", FILE_APPEND);
                sdClients();
                sleep(1); //hold on
                $run = false;
                socket_shutdown($socket, 2); //force end reading and writing
                socket_close($socket);
                file_put_contents($conf['logfile'], "[" . posix_getpid() . "] disconnected and new process being started!\n", FILE_APPEND);
                unset($socket);
                exec('bash -c "exec nohup setsid ' . __FILE__ . ' > /dev/null 2>&1 &"'); //non-blocking exec (thanks to @miorel)
                //executes subprocess in null environment...
                //file_put_contents($conf['logfile'], "new process created, shutdown!\n", FILE_APPEND);
                exit(0);
            } else { //we are in client, just close, no restart possible
                exit(0);
            }
            break;
        case SIGCHLD: //child process terminated
            pcntl_waitpid(-1, $s); // wait for child to close and continue
            break;
    }
}

//handle a client connection, fork and continue
function handle_client($parentSocket, $childSocket)
{
    global $run, $conf;
    $pid = pcntl_fork(); // we could use the pid later?
    if ($pid == -1) {
        file_put_contents($conf['logfile'], "[" . posix_getpid() . "] handle_client() fork failure!\n", FILE_APPEND);
        exit(0);
    } elseif ($pid == 0) { // we are in the child
        $run = false;
        socket_close($parentSocket);
        file_put_contents($conf['piddir'] . posix_getpid() . ".pid", "true", LOCK_EX); //store pid file
        loop($childSocket); // start talking with server
        socket_shutdown($childSocket, 2);
        socket_close($childSocket);
        //delete pid file
        @unlink($conf['piddir'] . posix_getpid() . ".pid");
        exit(0);
    } else {
        socket_close($childSocket);
    }
}

//take client and interact
function loop($sock)
{
    global $masterPID, $conf;

    $msg = $conf['welcome'];
    if (!empty($msg))
        socket_write($sock, $msg, strlen($msg));
    unset($msg);

    $user = false; //var for user login
    $pass = false; //var for password
    $login = false; //login mode
    $admin = false; //if user is admin
    $shutdown = false;
    $idle = false;
    $time = time(); //get inital time

    do {
        $buf = "";
        $chg = socket_select($read = array($sock), $write = null, $except = null, 0, 250); //wait 250 ms
        if ($chg > 0) {
            if (false === ($buf = socket_read($sock, 2048, PHP_NORMAL_READ))) {
                file_put_contents($conf['logfile'], "[" . posix_getpid() . "] socket_read() error: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
                break;
            }
        } elseif ($chg === false) { //something went wrong
            file_put_contents($conf['logfile'], "[" . posix_getpid() . "] socket_select() error: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
            break;
        }
        unset($chg);

        $buf = trim($buf);
        $sbuf = strtolower($buf);
        if (!empty($buf)) {
            $time = time(); //reset time
            switch ($sbuf) {
                case 'login':
                    $login = true;
                    $msg = "User: ";
                    $user = true;
                    socket_write($sock, $msg, strlen($msg));
                    break;
                case 'quit':
                case 'end':
                    break 2; // break out were done
                case 'shutdown':
                    if ($admin === true) {
                        $msg = "Shutting down master server!\nMaster PID: $masterPID\n";
                        socket_write($sock, $msg, strlen($msg));
                        posix_kill($masterPID, SIGTERM); //send shutdown signal
                        $shutdown = true;
                        break 2;
                    } else {
                        $msg = "Woah! Who said you could do that?\n";
                        socket_write($sock, $msg, strlen($msg));
                        break 2;
                    }
                case 'process list':
                    if ($admin === true) {
                        $msg = "List Of Running PIDs\nMaster PID: $masterPID\n";
                        $hand = opendir($conf['piddir']);
                        while ($f = readdir($hand)) {
                            if ($f != '.' && $f != '..' && substr($f, -4) == ".pid")
                                $msg .= basename($f, ".pid") . "\n";
                        }
                        closedir($hand);
                        socket_write($sock, $msg, strlen($msg));
                        unset($f, $msg, $hand);
                    } else {
                        $msg = "Woah! Who said you could do that?\n";
                        socket_write($sock, $msg, strlen($msg));
                    }
                    break;
                case 'restart':
                    if ($admin === true) {
                        $msg = "Restarting master server!\nMaster PID: $masterPID\n";
                        socket_write($sock, $msg, strlen($msg));
                        posix_kill($masterPID, SIGHUP); //send restart signal
                        break;
                    } else {
                        $msg = "Woah! Who said you could do that?\n";
                        socket_write($sock, $msg, strlen($msg));
                        break;
                    }
                default:
                    if ($login === true) {
                        $login = false; //prevent looping
                        if ($user === true) {
                            $user = false; //prevent looping
                            if (empty($buf)) {
                                $msg = "\nInvalid User\n";
                                socket_write($sock, $msg, strlen($msg));
                                break;
                            } else {
                                $user = $buf;
                                $msg = "Pass: ";
                                $pass = true;
                                $login = true;
                                socket_write($sock, $msg, strlen($msg));
                                break;
                            }
                        }
                        if ($pass === true) {
                            if (isset($conf['login'][$user]) && $buf == $conf['login'][$user]) {
                                $msg = "\n**************\nWelcome $user!\n**************\n";
                                socket_write($sock, $msg, strlen($msg));
                                //we are done with login so make all false
                                $user = false;
                                $pass = false;
                                $login = false;
                                $admin = true;
                                break;
                            }
                            //we are done with login so make all false
                            $user = false;
                            $pass = false;
                            $login = false;
                            $msg = "**Invalid User/Pass**\n\n";
                            socket_write($sock, $msg, strlen($msg));
                            break;
                        }
                    } else {
                        // this is where the user can define functions, etc
                        userfunc($sock, $buf);
                    }
                    break;
            }
        } else { // buf is empty
            if (time() - $time > $conf['timeout']) { //uh oh user has timed out
                $idle = true;
                break;
            }
        }
        unset($msg);
    } while (file_exists($conf['piddir'] . posix_getpid() . ".pid"));

    if (!file_exists($conf['piddir'] . posix_getpid() . ".pid") && isset($shutdown) && $shutdown === false) { //we were shutdown!
        $msg = "Remote server shutdown...!\n";
        socket_write($sock, $msg, strlen($msg));
        return;
    }
    if ($idle) { //idle process
        $msg = "**Idle Process**\n";
        socket_write($sock, $msg, strlen($msg));
    }
}

?>