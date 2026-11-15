<?php
/**
 * Minimal SMTP sink for WireTest: listens on 127.0.0.1 on a free port, prints that port on stdout,
 * accepts one session, writes every command (and the headers of the message) as JSON lines to the log
 * file given as first argument, and exits after QUIT. It accepts everything and delivers nothing.
 */
$log    = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
  fwrite(STDERR, "cannot listen: $errstr\n");
  exit(1);
}
echo explode(':', stream_socket_get_name($server, FALSE))[1], "\n";
fflush(STDOUT);

$conn = stream_socket_accept($server, 20);
if (!$conn) {
  exit(1);
}
$write = function (string $line) use ($conn) {
  fwrite($conn, $line . "\r\n");
};
$note = function (array $entry) use ($log) {
  file_put_contents($log, json_encode($entry) . "\n", FILE_APPEND);
};

$write('220 sink ESMTP');
while (($line = fgets($conn)) !== FALSE) {
  $cmd = rtrim($line, "\r\n");
  $up  = strtoupper($cmd);
  $note(['cmd' => $cmd]);
  if (str_starts_with($up, 'EHLO') || str_starts_with($up, 'HELO')) {
    $write('250-sink');
    $write('250 8BITMIME');
  }
  elseif ($up === 'DATA') {
    $write('354 go ahead');
    $headers = [];
    $inHeaders = TRUE;
    while (($d = fgets($conn)) !== FALSE) {
      $d = rtrim($d, "\r\n");
      if ($d === '.') {
        break;
      }
      if ($inHeaders) {
        if ($d === '') {
          $inHeaders = FALSE;
        }
        elseif (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $d, $m)) {
          $headers[$m[1]] = $m[2];
        }
      }
    }
    $note(['headers' => $headers]);
    $write('250 queued');
  }
  elseif ($up === 'QUIT') {
    $write('221 bye');
    break;
  }
  else {
    $write('250 ok');
  }
}
fclose($conn);
