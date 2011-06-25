<?php

namespace DrSlump\Protobuf\Compiler;

require_once 'Console/CommandLine.php';

use DrSlump\Protobuf;

class Cli
{
    public static function run($pluginExecutable)
    {
        // Open STDIN in non-blocking mode
        $fp = fopen('php://stdin', 'rb');
        stream_set_blocking($fp, FALSE);

        // Loop until STDIN is closed or we've waited too long for data
        $cnt = 0;
        $stdin = '';
        while (!feof($fp) && $cnt++ < 10) {
            // give protoc some time to feed the data
            usleep(10000);
            // read the bytes
            $bytes = fread($fp, 1024);
            if (strlen($bytes)) {
                $cnt = 0;
                $stdin .= $bytes;
            }
        }

        // If no input was given we launch protoc from here
        if (0 === strlen($stdin)) {
            self::runProtoc($pluginExecutable);
            exit(0);
        }

        // We have data from stdin so compile it
        try {
            // Create a compiler interface
            $comp = new Protobuf\Compiler();
            echo $comp->compile($stdin);
            exit(0);
        } catch(\Exception $e) {
            fputs(STDERR, 'ERROR: ' . $e->getMessage());
            fputs(STDERR, $e->getTraceAsString());
            exit(255);
        }
    }

    public static function runProtoc($pluginExecutable)
    {
        $result = self::parseArguments();

        $protocBin = $result->options['protoc'];

        // Check if protoc is available
        exec("$protocBin --version", $output, $return);

        if (0 !== $return && 1 !== $return) {
            fputs(STDERR, "ERROR: Unable to find the protoc command.". PHP_EOL);
            fputs(STDERR, "       Please make sure it's installed and available in the path." . PHP_EOL);
            exit(1);
        }

        if (!preg_match('/[0-9\.]+/', $output[0], $m)) {
            fputs(STDERR, "ERROR: Unable to get protoc command version.". PHP_EOL);
            fputs(STDERR, "       Please make sure it's installed and available in the path." . PHP_EOL);
            exit(1);
        }

        if (version_compare($m[0], '2.3.0') < 0) {
            fputs(STDERR, "ERROR: The protoc command in your system is too old." . PHP_EOL);
            fputs(STDERR, "       Minimum version required is 2.3.0 but found {$m[0]}." . PHP_EOL);
            exit(1);
        }

        $cmd[] = $protocBin;
        $cmd[] = '--plugin=protoc-gen-php=' . escapeshellarg($pluginExecutable);

        // Include paths
        $cmd[] = '--proto_path=' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'protos');
        if (!empty($result->options['include'])) {
            foreach($result->options['include'] as $include) {
                $include = realpath($include);
                $cmd[] = '--proto_path=' . escapeshellarg($include);
            }
        }


        // Protoc will pass custom arguments to the plugin if they are given
        // before a colon character. ie: --php_out="foo=bar:/path/to/plugin"
        // We make use of it to pass arguments encoded as an URI query string

        $args = array();
        if ($result->options['verbose']) {
            $args['verbose'] = 1;
        }
        if ($result->options['json']) {
            $args['json'] = 1;
        }

        $cmd[] = '--php_out=' .
                 escapeshellarg(
                     http_build_query($args, '', '&') .
                     ':' .
                     $result->options['out']
                 );


        // Add the chosen proto files to generate
        foreach ($result->args['protos'] as $arg) {
            $cmd[] = escapeshellarg(realpath($arg));
        }

        $cmdStr = implode(' ', $cmd);

        // Run command with stderr redirected to stdout
        passthru($cmdStr . ' 2>&1', $return);

        if ($return !== 0) {
            fputs(STDERR, PHP_EOL);
            fputs(STDERR, 'ERROR: protoc exited with an error (' . $return . ') when executed with: ' . PHP_EOL);
            fputs(STDERR, '  ' . implode(" \\\n    ", $cmd) . PHP_EOL);
            exit($return);
        }
    }


    public static function parseArguments()
    {
        $main = new \Console_CommandLine();

        $main->addOption('out', array(
            'short_name'    => '-o',
            'long_name'     => '--out',
            'action'        => 'StoreString',
            'description'   => 'destination directory for generated files',
            'default'       => './',
        ));

        $main->addOption('include', array(
            'short_name'    => '-i',
            'long_name'     => '--include',
            'action'        => 'StoreArray',
            'description'   => 'define an include path (can be repeated)',
            'multiple'      => true,
        ));


        $main->addOption('json', array(
            'short_name'    => '-j',
            'long_name'     => '--json',
            'action'        => 'StoreTrue',
            'description'   => 'turn on ProtoJson Javascript file generation',
        ));

        $main->addOption('protoc', array(
            'long_name'     => '--protoc',
            'action'        => 'StoreString',
            'default'       => 'protoc',
            'description'   => 'protoc compiler executable path',
        ));

        $main->addOption('verbose', array(
            'short_name'    => '-v',
            'long_name'     => '--verbose',
            'action'        => 'StoreTrue',
            'description'   => 'turn on verbose output',
        ));

        $main->addArgument('protos', array(
            'multiple'      => true,
            'description'   => 'proto files',
        ));

        try {
            echo 'Protobuf-PHP ' . Protobuf::VERSION . ' by Ivan -DrSlump- Montes' . PHP_EOL . PHP_EOL;
            $result = $main->parse();
            return $result;
        } catch (\Exception $e) {
            $main->displayError($e->getMessage());
            exit(1);
        }
    }

}
