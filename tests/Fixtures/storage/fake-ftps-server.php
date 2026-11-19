<?php

/**
 * A minimal explicit-TLS FTP server for tests/Unit/FtpsFilesystemAdapterTest.php. It serves every file with 100
 * bytes, or stops answering at a given point.
 *
 * Usage: php fake-ftps-server.php <port> <certificate pem with key> <serve|stall-size|stall-data> [command log file]
 */
[, $port, $certificate, $scenario] = $argv;
$log = $argv[4] ?? null;

$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'verify_peer' => false]]);
$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

while ($control = stream_socket_accept($server, -1)) {
    $dataServer = null;
    fwrite($control, "220 Fake FTPS\r\n");

    while (($line = fgets($control)) !== false) {
        [$command] = explode(' ', trim($line), 2);
        if ($log !== null) {
            file_put_contents($log, trim($line)."\n", FILE_APPEND);
        }
        $reply = match (strtoupper($command)) {
            'AUTH' => '234 TLS',
            'USER' => '331 Password',
            'PASS' => '230 Logged in',
            'PBSZ', 'PROT', 'TYPE' => '200 OK',
            'PWD' => '257 "/"',
            'CWD' => '250 OK',
            'SIZE' => $scenario === 'stall-size' ? null : '213 100',
            'MDTM' => '213 20260101000000',
            'EPSV' => 'EPSV',
            'RETR' => '150 Sending',
            'QUIT' => '221 Bye',
            default => '502 Not implemented',
        };

        if ($reply === null) {
            sleep(60);

            break;
        }
        if ($reply === 'EPSV') {
            $dataServer = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            $dataPort = substr(strrchr(stream_socket_get_name($dataServer, false), ':'), 1);
            $reply = "229 Extended Passive Mode (|||{$dataPort}|)";
        }
        fwrite($control, $reply."\r\n");

        if ($command === 'AUTH') {
            stream_socket_enable_crypto($control, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        }
        if ($command === 'RETR') {
            $data = stream_socket_accept($dataServer, 10);
            stream_socket_enable_crypto($data, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($scenario === 'stall-data') {
                fwrite($data, 'abc');
                sleep(60);

                break;
            }
            fwrite($data, str_repeat('x', 100));
            fclose($data);
            fwrite($control, "226 Done\r\n");
        }
        if ($command === 'QUIT') {
            break;
        }
    }
    fclose($control);
}
