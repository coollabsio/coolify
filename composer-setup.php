<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

setupEnvironment();
process(is_array($argv) ? $argv : array());

/**
 * Initializes various values
 *
 * @throws RuntimeException If uopz extension prevents exit calls
 */
function setupEnvironment()
{
    ini_set('display_errors', 1);

    if (extension_loaded('uopz') && !(ini_get('uopz.disable') || ini_get('uopz.exit'))) {
        // uopz works at opcode level and disables exit calls
        if (function_exists('uopz_allow_exit')) {
            @uopz_allow_exit(true);
        } else {
            throw new RuntimeException('The uopz extension ignores exit calls and breaks this installer.');
        }
    }

    $installer = 'ComposerInstaller';

    if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
        if ($version = getenv('COMPOSERSETUP')) {
            $installer = sprintf('Composer-Setup.exe/%s', $version);
        }
    }

    define('COMPOSER_INSTALLER', $installer);
}

/**
 * Processes the installer
 */
function process($argv)
{
    // Determine ANSI output from --ansi and --no-ansi flags
    setUseAnsi($argv);

    $help = in_array('--help', $argv) || in_array('-h', $argv);
    if ($help) {
        displayHelp();
        exit(0);
    }

    $check      = in_array('--check', $argv);
    $force      = in_array('--force', $argv);
    $quiet      = in_array('--quiet', $argv);
    $channel    = 'stable';
    if (in_array('--snapshot', $argv)) {
        $channel = 'snapshot';
    } elseif (in_array('--preview', $argv)) {
        $channel = 'preview';
    } elseif (in_array('--1', $argv)) {
        $channel = '1';
    } elseif (in_array('--2', $argv)) {
        $channel = '2';
    } elseif (in_array('--2.2', $argv)) {
        $channel = '2.2';
    }
    $disableTls = in_array('--disable-tls', $argv);
    $installDir = getOptValue('--install-dir', $argv, false);
    $version    = getOptValue('--version', $argv, false);
    $filename   = getOptValue('--filename', $argv, 'composer.phar');
    $cafile     = getOptValue('--cafile', $argv, false);

    if (!checkParams($installDir, $version, $cafile)) {
        exit(1);
    }

    $ok = checkPlatform($warnings, $quiet, $disableTls, true);

    if ($check) {
        // Only show warnings if we haven't output any errors
        if ($ok) {
            showWarnings($warnings);
            showSecurityWarning($disableTls);
        }
        exit($ok ? 0 : 1);
    }

    if ($ok || $force) {
        if ($channel === '1' && !$quiet) {
            out('Warning: You forced the install of Composer 1.x via --1, but Composer 2.x is the latest stable version. Updating to it via composer self-update --stable is recommended.', 'error');
        }

        $installer = new Installer($quiet, $disableTls, $cafile);
        if ($installer->run($version, $installDir, $filename, $channel)) {
            showWarnings($warnings);
            showSecurityWarning($disableTls);
            exit(0);
        }
    }

    exit(1);
}

/**
 * Displays the help
 */
function displayHelp()
{
    echo <<<EOF
Composer Installer
------------------
Options
--help               this help
--check              for checking environment only
--force              forces the installation
--ansi               force ANSI color output
--no-ansi            disable ANSI color output
--quiet              do not output unimportant messages
--install-dir="..."  accepts a target installation directory
--preview            install the latest version from the preview (alpha/beta/rc) channel instead of stable
--snapshot           install the latest version from the snapshot (dev builds) channel instead of stable
--1                  install the latest stable Composer 1.x (EOL) version
--2                  install the latest stable Composer 2.x version
--2.2                install the latest stable Composer 2.2.x (LTS) version
--version="..."      accepts a specific version to install instead of the latest
--filename="..."     accepts a target filename (default: composer.phar)
--disable-tls        disable SSL/TLS security for file downloads
--cafile="..."       accepts a path to a Certificate Authority (CA) certificate file for SSL/TLS verification

EOF;
}

/**
 * Sets the USE_ANSI define for colorizing output
 *
 * @param array $argv Command-line arguments
 */
function setUseAnsi($argv)
{
    // --no-ansi wins over --ansi
    if (in_array('--no-ansi', $argv)) {
        define('USE_ANSI', false);
    } elseif (in_array('--ansi', $argv)) {
        define('USE_ANSI', true);
    } else {
        define('USE_ANSI', outputSupportsColor());
    }
}

/**
 * Returns whether color output is supported
 *
 * @return bool
 */
function outputSupportsColor()
{
    if (false !== getenv('NO_COLOR') || !defined('STDOUT')) {
        return false;
    }

    if ('Hyper' === getenv('TERM_PROGRAM')) {
        return true;
    }

    if (defined('PHP_WINDOWS_VERSION_BUILD')) {
        return (function_exists('sapi_windows_vt100_support')
            && sapi_windows_vt100_support(STDOUT))
            || false !== getenv('ANSICON')
            || 'ON' === getenv('ConEmuANSI')
            || 'xterm' === getenv('TERM');
    }

    if (function_exists('stream_isatty')) {
        return stream_isatty(STDOUT);
    }

    if (function_exists('posix_isatty')) {
        return posix_isatty(STDOUT);
    }

    $stat = fstat(STDOUT);
    // Check if formatted mode is S_IFCHR
    return $stat ? 0020000 === ($stat['mode'] & 0170000) : false;
}

/**
 * Returns the value of a command-line option
 *
 * @param string $opt The command-line option to check
 * @param array $argv Command-line arguments
 * @param mixed $default Default value to be returned
 *
 * @return mixed The command-line value or the default
 */
function getOptValue($opt, $argv, $default)
{
    $optLength = strlen($opt);

    foreach ($argv as $key => $value) {
        $next = $key + 1;
        if (0 === strpos($value, $opt)) {
            if ($optLength === strlen($value) && isset($argv[$next])) {
                return trim($argv[$next]);
            } else {
                return trim(substr($value, $optLength + 1));
            }
        }
    }

    return $default;
}

/**
 * Checks that user-supplied params are valid
 *
 * @param mixed $installDir The required istallation directory
 * @param mixed $version The required composer version to install
 * @param mixed $cafile Certificate Authority file
 *
 * @return bool True if the supplied params are okay
 */
function checkParams($installDir, $version, $cafile)
{
    $result = true;

    if (false !== $installDir && !is_dir($installDir)) {
        out("The defined install dir ({$installDir}) does not exist.", 'info');
        $result = false;
    }

    if (false !== $version && 1 !== preg_match('/^\d+\.\d+\.\d+(\-(alpha|beta|RC)\d*)*$/', $version)) {
        out("The defined install version ({$version}) does not match release pattern.", 'info');
        $result = false;
    }

    if (false !== $cafile && (!file_exists($cafile) || !is_readable($cafile))) {
        out("The defined Certificate Authority (CA) cert file ({$cafile}) does not exist or is not readable.", 'info');
        $result = false;
    }
    return $result;
}

/**
 * Checks the platform for possible issues running Composer
 *
 * Errors are written to the output, warnings are saved for later display.
 *
 * @param array $warnings Populated by method, to be shown later
 * @param bool $quiet Quiet mode
 * @param bool $disableTls Bypass tls
 * @param bool $install If we are installing, rather than diagnosing
 *
 * @return bool True if there are no errors
 */
function checkPlatform(&$warnings, $quiet, $disableTls, $install)
{
    getPlatformIssues($errors, $warnings, $install);

    // Make openssl warning an error if tls has not been specifically disabled
    if (isset($warnings['openssl']) && !$disableTls) {
        $errors['openssl'] = $warnings['openssl'];
        unset($warnings['openssl']);
    }

    if (!empty($errors)) {
        // Composer-Setup.exe uses "Some settings" to flag platform errors
        out('Some settings on your machine make Composer unable to work properly.', 'error');
        out('Make sure that you fix the issues listed below and run this script again:', 'error');
        outputIssues($errors);
        return false;
    }

    if (empty($warnings) && !$quiet) {
        out('All settings correct for using Composer', 'success');
    }
    return true;
}

/**
 * Checks platform configuration for common incompatibility issues
 *
 * @param array $errors Populated by method
 * @param array $warnings Populated by method
 * @param bool $install If we are installing, rather than diagnosing
 *
 * @return bool If any errors or warnings have been found
 */
function getPlatformIssues(&$errors, &$warnings, $install)
{
    $errors = array();
    $warnings = array();

    $iniMessage = PHP_EOL.getIniMessage();
    $iniMessage .= PHP_EOL.'If you can not modify the ini file, you can also run `php -d option=value` to modify ini values on the fly. You can use -d multiple times.';

    if (ini_get('detect_unicode')) {
        $errors['unicode'] = array(
            'The detect_unicode setting must be disabled.',
            'Add the following to the end of your `php.ini`:',
            '    detect_unicode = Off',
            $iniMessage
        );
    }

    if (extension_loaded('suhosin')) {
        $suhosin = ini_get('suhosin.executor.include.whitelist');
        $suhosinBlacklist = ini_get('suhosin.executor.include.blacklist');
        if (false === stripos($suhosin, 'phar') && (!$suhosinBlacklist || false !== stripos($suhosinBlacklist, 'phar'))) {
            $errors['suhosin'] = array(
                'The suhosin.executor.include.whitelist setting is incorrect.',
                'Add the following to the end of your `php.ini` or suhosin.ini (Example path [for Debian]: /etc/php5/cli/conf.d/suhosin.ini):',
                '    suhosin.executor.include.whitelist = phar '.$suhosin,
                $iniMessage
            );
        }
    }

    if (!function_exists('json_decode')) {
        $errors['json'] = array(
            'The json extension is missing.',
            'Install it or recompile php without --disable-json'
        );
    }

    if (!extension_loaded('Phar')) {
        $errors['phar'] = array(
            'The phar extension is missing.',
            'Install it or recompile php without --disable-phar'
        );
    }

    if (!extension_loaded('filter')) {
        $errors['filter'] = array(
            'The filter extension is missing.',
            'Install it or recompile php without --disable-filter'
        );
    }

    if (!extension_loaded('hash')) {
        $errors['hash'] = array(
            'The hash extension is missing.',
            'Install it or recompile php without --disable-hash'
        );
    }

    if (!extension_loaded('iconv') && !extension_loaded('mbstring')) {
        $errors['iconv_mbstring'] = array(
            'The iconv OR mbstring extension is required and both are missing.',
            'Install either of them or recompile php without --disable-iconv'
        );
    }

    if (!ini_get('allow_url_fopen')) {
        $errors['allow_url_fopen'] = array(
            'The allow_url_fopen setting is incorrect.',
            'Add the following to the end of your `php.ini`:',
            '    allow_url_fopen = On',
            $iniMessage
        );
    }

    if (extension_loaded('ionCube Loader') && ioncube_loader_iversion() < 40009) {
        $ioncube = ioncube_loader_version();
        $errors['ioncube'] = array(
            'Your ionCube Loader extension ('.$ioncube.') is incompatible with Phar files.',
            'Upgrade to ionCube 4.0.9 or higher or remove this line (path may be different) from your `php.ini` to disable it:',
            '    zend_extension = /usr/lib/php5/20090626+lfs/ioncube_loader_lin_5.3.so',
            $iniMessage
        );
    }

    if (version_compare(PHP_VERSION, '5.3.2', '<')) {
        $errors['php'] = array(
            'Your PHP ('.PHP_VERSION.') is too old, you must upgrade to PHP 5.3.2 or higher.'
        );
    }

    if (version_compare(PHP_VERSION, '5.3.4', '<')) {
        $warnings['php'] = array(
            'Your PHP ('.PHP_VERSION.') is quite old, upgrading to PHP 5.3.4 or higher is recommended.',
            'Composer works with 5.3.2+ for most people, but there might be edge case issues.'
        );
    }

    if (!extension_loaded('openssl')) {
        $warnings['openssl'] = array(
            'The openssl extension is missing, which means that secure HTTPS transfers are impossible.',
            'If possible you should enable it or recompile php with --with-openssl'
        );
    }

    if (extension_loaded('openssl') && OPENSSL_VERSION_NUMBER < 0x1000100f) {
        // Attempt to parse version number out, fallback to whole string value.
        $opensslVersion = trim(strstr(OPENSSL_VERSION_TEXT, ' '));
        $opensslVersion = substr($opensslVersion, 0, strpos($opensslVersion, ' '));
        $opensslVersion = $opensslVersion ? $opensslVersion : OPENSSL_VERSION_TEXT;

        $warnings['openssl_version'] = array(
            'The OpenSSL library ('.$opensslVersion.') used by PHP does not support TLSv1.2 or TLSv1.1.',
            'If possible you should upgrade OpenSSL to version 1.0.1 or above.'
        );
    }

    if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && ini_get('apc.enable_cli')) {
        $warnings['apc_cli'] = array(
            'The apc.enable_cli setting is incorrect.',
            'Add the following to the end of your `php.ini`:',
            '    apc.enable_cli = Off',
            $iniMessage
        );
    }

    if (!$install && extension_loaded('xdebug')) {
        $warnings['xdebug_loaded'] = array(
            'The xdebug extension is loaded, this can slow down Composer a little.',
            'Disabling it when using Composer is recommended.'
        );

        if (ini_get('xdebug.profiler_enabled')) {
            $warnings['xdebug_profile'] = array(
                'The xdebug.profiler_enabled setting is enabled, this can slow down Composer a lot.',
                'Add the following to the end of your `php.ini` to disable it:',
                '    xdebug.profiler_enabled = 0',
                $iniMessage
            );
        }
    }

    if (!extension_loaded('zlib')) {
        $warnings['zlib'] = array(
            'The zlib extension is not loaded, this can slow down Composer a lot.',
            'If possible, install it or recompile php with --with-zlib',
            $iniMessage
        );
    }

    if (defined('PHP_WINDOWS_VERSION_BUILD')
        && (version_compare(PHP_VERSION, '7.2.23', '<')
        || (version_compare(PHP_VERSION, '7.3.0', '>=')
        && version_compare(PHP_VERSION, '7.3.10', '<')))) {
        $warnings['onedrive'] = array(
            'The Windows OneDrive folder is not supported on PHP versions below 7.2.23 and 7.3.10.',
            'Upgrade your PHP ('.PHP_VERSION.') to use this location with Composer.'
        );
    }

    if (extension_loaded('uopz') && !(ini_get('uopz.disable') || ini_get('uopz.exit'))) {
        $warnings['uopz'] = array(
            'The uopz extension ignores exit calls and may not work with all Composer commands.',
            'Disabling it when using Composer is recommended.'
        );
    }

    ob_start();
    phpinfo(INFO_GENERAL);
    $phpinfo = (string) ob_get_clean();
    if (preg_match('{Configure Command(?: *</td><td class="v">| *=> *)(.*?)(?:</td>|$)}m', $phpinfo, $match)) {
        $configure = $match[1];

        if (false !== strpos($configure, '--enable-sigchild')) {
            $warnings['sigchild'] = array(
                'PHP was compiled with --enable-sigchild which can cause issues on some platforms.',
                'Recompile it without this flag if possible, see also:',
                '    https://bugs.php.net/bug.php?id=22999'
            );
        }

        if (false !== strpos($configure, '--with-curlwrappers')) {
            $warnings['curlwrappers'] = array(
                'PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.',
                'Recompile it without this flag if possible'
            );
        }
    }

    // Stringify the message arrays
    foreach ($errors as $key => $value) {
        $errors[$key] = PHP_EOL.implode(PHP_EOL, $value);
    }

    foreach ($warnings as $key => $value) {
        $warnings[$key] = PHP_EOL.implode(PHP_EOL, $value);
    }

    return !empty($errors) || !empty($warnings);
}


/**
 * Outputs an array of issues
 *
 * @param array $issues
 */
function outputIssues($issues)
{
    foreach ($issues as $issue) {
        out($issue, 'info');
    }
    out('');
}

/**
 * Outputs any warnings found
 *
 * @param array $warnings
 */
function showWarnings($warnings)
{
    if (!empty($warnings)) {
        out('Some settings on your machine may cause stability issues with Composer.', 'error');
        out('If you encounter issues, try to change the following:', 'error');
        outputIssues($warnings);
    }
}

/**
 * Outputs an end of process warning if tls has been bypassed
 *
 * @param bool $disableTls Bypass tls
 */
function showSecurityWarning($disableTls)
{
    if ($disableTls) {
        out('You have instructed the Installer not to enforce SSL/TLS security on remote HTTPS requests.', 'info');
        out('This will leave all downloads during installation vulnerable to Man-In-The-Middle (MITM) attacks', 'info');
    }
}

/**
 * colorize output
 */
function out($text, $color = null, $newLine = true)
{
    $styles = array(
        'success' => "\033[0;32m%s\033[0m",
        'error' => "\033[31;31m%s\033[0m",
        'info' => "\033[33;33m%s\033[0m"
    );

    $format = '%s';

    if (is_string($color) && isset($styles[$color]) && USE_ANSI) {
        $format = $styles[$color];
    }

    if ($newLine) {
        $format .= PHP_EOL;
    }

    printf($format, $text);
}

/**
 * Returns the system-dependent Composer home location, which may not exist
 *
 * @return string
 */
function getHomeDir()
{
    $home = getenv('COMPOSER_HOME');
    if ($home) {
        return $home;
    }

    $userDir = getUserDir();

    if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
        return $userDir.'/Composer';
    }

    $dirs = array();

    if (useXdg()) {
        // XDG Base Directory Specifications
        $xdgConfig = getenv('XDG_CONFIG_HOME');
        if (!$xdgConfig) {
            $xdgConfig = $userDir . '/.config';
        }

        $dirs[] = $xdgConfig . '/composer';
    }

    $dirs[] = $userDir . '/.composer';

    // select first dir which exists of: $XDG_CONFIG_HOME/composer or ~/.composer
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    // if none exists, we default to first defined one (XDG one if system uses it, or ~/.composer otherwise)
    return $dirs[0];
}

/**
 * Returns the location of the user directory from the environment
 * @throws RuntimeException If the environment value does not exists
 *
 * @return string
 */
function getUserDir()
{
    $userEnv = defined('PHP_WINDOWS_VERSION_MAJOR') ? 'APPDATA' : 'HOME';
    $userDir = getenv($userEnv);

    if (!$userDir) {
        throw new RuntimeException('The '.$userEnv.' or COMPOSER_HOME environment variable must be set for composer to run correctly');
    }

    return rtrim(strtr($userDir, '\\', '/'), '/');
}

/**
 * @return bool
 */
function useXdg()
{
    foreach (array_keys($_SERVER) as $key) {
        if (strpos((string) $key, 'XDG_') === 0) {
            return true;
        }
    }

    if (is_dir('/etc/xdg')) {
        return true;
    }

    return false;
}

function validateCaFile($contents)
{
    // assume the CA is valid if php is vulnerable to
    // https://www.sektioneins.de/advisories/advisory-012013-php-openssl_x509_parse-memory-corruption-vulnerability.html
    if (
        PHP_VERSION_ID <= 50327
        || (PHP_VERSION_ID >= 50400 && PHP_VERSION_ID < 50422)
        || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50506)
    ) {
        return !empty($contents);
    }

    return (bool) openssl_x509_parse($contents);
}

/**
 * Returns php.ini location information
 *
 * @return string
 */
function getIniMessage()
{
    $paths = array((string) php_ini_loaded_file());
    $scanned = php_ini_scanned_files();

    if ($scanned !== false) {
        $paths = array_merge($paths, array_map('trim', explode(',', $scanned)));
    }

    // We will have at least one value, which may be empty
    if ($paths[0] === '') {
        array_shift($paths);
    }

    $ini = array_shift($paths);

    if ($ini === null) {
        return 'A php.ini file does not exist. You will have to create one.';
    }

    if (count($paths) > 1) {
        return 'Your command-line PHP is using multiple ini files. Run `php --ini` to show them.';
    }

    return 'The php.ini used by your command-line PHP is: '.$ini;
}

class Installer
{
    private $quiet;
    private $disableTls;
    private $cafile;
    private $displayPath;
    private $target;
    private $tmpFile;
    private $tmpCafile;
    private $baseUrl;
    private $algo;
    private $errHandler;
    private $httpClient;
    private $pubKeys = array();
    private $installs = array();

    /**
     * Constructor - must not do anything that throws an exception
     *
     * @param bool $quiet Quiet mode
     * @param bool $disableTls Bypass tls
     * @param mixed $cafile Path to CA bundle, or false
     */
    public function __construct($quiet, $disableTls, $caFile)
    {
        if (($this->quiet = $quiet)) {
            ob_start();
        }
        $this->disableTls = $disableTls;
        $this->cafile = $caFile;
        $this->errHandler = new ErrorHandler();
    }

    /**
     * Runs the installer
     *
     * @param mixed $version Specific version to install, or false
     * @param mixed $installDir Specific installation directory, or false
     * @param string $filename Specific filename to save to, or composer.phar
     * @param string $channel Specific version channel to use
     * @throws Exception If anything other than a RuntimeException is caught
     *
     * @return bool If the installation succeeded
     */
    public function run($version, $installDir, $filename, $channel)
    {
        try {
            $this->initTargets($installDir, $filename);
            $this->initTls();
            $this->httpClient = new HttpClient($this->disableTls, $this->cafile);
            $result = $this->install($version, $channel);

            // in case --1 or --2 is passed, we leave the default channel for next self-update to stable
            if (1 === preg_match('{^\d+$}D', $channel)) {
                $channel = 'stable';
            }

            if ($result && $channel !== 'stable' && !$version && defined('PHP_BINARY')) {
                $null = (defined('PHP_WINDOWS_VERSION_MAJOR') ? 'NUL' : '/dev/null');
                @exec(escapeshellarg(PHP_BINARY) .' '.escapeshellarg($this->target).' self-update --'.$channel.' --set-channel-only -q > '.$null.' 2> '.$null, $output);
            }
        } catch (Exception $e) {
            $result = false;
        }

        // Always clean up
        $this->cleanUp($result);

        if (isset($e)) {
            // Rethrow anything that is not a RuntimeException
            if (!$e instanceof RuntimeException) {
                throw $e;
            }
            out($e->getMessage(), 'error');
        }
        return $result;
    }

    /**
     * Initialization methods to set the required filenames and composer url
     *
     * @param mixed $installDir Specific installation directory, or false
     * @param string $filename Specific filename to save to, or composer.phar
     * @throws RuntimeException If the installation directory is not writable
     */
    protected function initTargets($installDir, $filename)
    {
        $this->displayPath = ($installDir ? rtrim($installDir, '/').'/' : '').$filename;
        $installDir = $installDir ? realpath($installDir) : getcwd();

        if (!is_writeable($installDir)) {
            throw new RuntimeException('The installation directory "'.$installDir.'" is not writable');
        }

        $this->target = $installDir.DIRECTORY_SEPARATOR.$filename;
        $this->tmpFile = $installDir.DIRECTORY_SEPARATOR.basename($this->target, '.phar').'-temp.phar';

        $uriScheme = $this->disableTls ? 'http' : 'https';
        $this->baseUrl = $uriScheme.'://getcomposer.org';
    }

    /**
     * A wrapper around methods to check tls and write public keys
     * @throws RuntimeException If SHA384 is not supported
     */
    protected function initTls()
    {
        if ($this->disableTls) {
            return;
        }

        if (!in_array('sha384', array_map('strtolower', openssl_get_md_methods()))) {
            throw new RuntimeException('SHA384 is not supported by your openssl extension');
        }

        $this->algo = defined('OPENSSL_ALGO_SHA384') ? OPENSSL_ALGO_SHA384 : 'SHA384';
        $home = $this->getComposerHome();

        $this->pubKeys = array(
            'dev' => $this->installKey(self::getPKDev(), $home, 'keys.dev.pub'),
            'tags' => $this->installKey(self::getPKTags(), $home, 'keys.tags.pub')
        );

        if (empty($this->cafile) && !HttpClient::getSystemCaRootBundlePath()) {
            $this->cafile = $this->tmpCafile = $this->installKey(HttpClient::getPackagedCaFile(), $home, 'cacert-temp.pem');
        }
    }

    /**
     * Returns the Composer home directory, creating it if required
     * @throws RuntimeException If the directory cannot be created
     *
     * @return string
     */
    protected function getComposerHome()
    {
        $home = getHomeDir();

        if (!is_dir($home)) {
            $this->errHandler->start();

            if (!mkdir($home, 0777, true)) {
                throw new RuntimeException(sprintf(
                    'Unable to create Composer home directory "%s": %s',
                    $home,
                    $this->errHandler->message
                ));
            }
            $this->installs[] = $home;
            $this->errHandler->stop();
        }
        return $home;
    }

    /**
     * Writes public key data to disc
     *
     * @param string $data The public key(s) in pem format
     * @param string $path The directory to write to
     * @param string $filename The name of the file
     * @throws RuntimeException If the file cannot be written
     *
     * @return string The path to the saved data
     */
    protected function installKey($data, $path, $filename)
    {
        $this->errHandler->start();

        $target = $path.DIRECTORY_SEPARATOR.$filename;
        $installed = file_exists($target);
        $write = file_put_contents($target, $data, LOCK_EX);
        @chmod($target, 0644);

        $this->errHandler->stop();

        if (!$write) {
            throw new RuntimeException(sprintf('Unable to write %s to: %s', $filename, $path));
        }

        if (!$installed) {
            $this->installs[] = $target;
        }

        return $target;
    }

    /**
     * The main install function
     *
     * @param mixed $version Specific version to install, or false
     * @param string $channel Version channel to use
     *
     * @return bool If the installation succeeded
     */
    protected function install($version, $channel)
    {
        $retries = 3;
        $result = false;
        $infoMsg = 'Downloading...';
        $infoType = 'info';

        while ($retries--) {
            if (!$this->quiet) {
                out($infoMsg, $infoType);
                $infoMsg = 'Retrying...';
                $infoType = 'error';
            }

            if (!$this->getVersion($channel, $version, $url, $error)) {
                out($error, 'error');
                continue;
            }

            if (!$this->downloadToTmp($url, $signature, $error)) {
                out($error, 'error');
                continue;
            }

            if (!$this->verifyAndSave($version, $signature, $error)) {
                out($error, 'error');
                continue;
            }

            $result = true;
            break;
        }

        if (!$this->quiet) {
            if ($result) {
                out(PHP_EOL."Composer (version {$version}) successfully installed to: {$this->target}", 'success');
                out("Use it: php {$this->displayPath}", 'info');
                out('');
            } else {
                out('The download failed repeatedly, aborting.', 'error');
            }
        }
        return $result;
    }

    /**
     * Sets the version url, downloading version data if required
     *
     * @param string $channel Version channel to use
     * @param false|string $version Version to install, or set by method
     * @param null|string $url The versioned url, set by method
     * @param null|string $error Set by method on failure
     *
     * @return bool If the operation succeeded
     */
    protected function getVersion($channel, &$version, &$url, &$error)
    {
        $error = '';

        if ($version) {
            if (empty($url)) {
                $url = $this->baseUrl."/download/{$version}/composer.phar";
            }
            return true;
        }

        $this->errHandler->start();

        if ($this->downloadVersionData($data, $error)) {
            $this->parseVersionData($data, $channel, $version, $url);
        }

        $this->errHandler->stop();
        return empty($error);
    }

    /**
     * Downloads and json-decodes version data
     *
     * @param null|array $data Downloaded version data, set by method
     * @param null|string $error Set by method on failure
     *
     * @return bool If the operation succeeded
     */
    protected function downloadVersionData(&$data, &$error)
    {
        $url = $this->baseUrl.'/versions';
        $errFmt = 'The "%s" file could not be %s: %s';

        if (!$json = $this->httpClient->get($url)) {
            $error = sprintf($errFmt, $url, 'downloaded', $this->errHandler->message);
            return false;
        }

        if (!$data = json_decode($json, true)) {
            $error = sprintf($errFmt, $url, 'json-decoded', $this->getJsonError());
            return false;
        }
        return true;
    }

    /**
     * A wrapper around the methods needed to download and save the phar
     *
     * @param string $url The versioned download url
     * @param null|string $signature Set by method on successful download
     * @param null|string $error Set by method on failure
     *
     * @return bool If the operation succeeded
     */
    protected function downloadToTmp($url, &$signature, &$error)
    {
        $error = '';
        $errFmt = 'The "%s" file could not be downloaded: %s';
        $sigUrl = $url.'.sig';
        $this->errHandler->start();

        if (!$fh = fopen($this->tmpFile, 'w')) {
            $error = sprintf('Could not create file "%s": %s', $this->tmpFile, $this->errHandler->message);

        } elseif (!$this->getSignature($sigUrl, $signature)) {
            $error = sprintf($errFmt, $sigUrl, $this->errHandler->message);

        } elseif (!fwrite($fh, $this->httpClient->get($url))) {
            $error = sprintf($errFmt, $url, $this->errHandler->message);
        }

        if (is_resource($fh)) {
            fclose($fh);
        }
        $this->errHandler->stop();
        return empty($error);
    }

    /**
     * Verifies the downloaded file and saves it to the target location
     *
     * @param string $version The composer version downloaded
     * @param string $signature The digital signature to check
     * @param null|string $error Set by method on failure
     *
     * @return bool If the operation succeeded
     */
    protected function verifyAndSave($version, $signature, &$error)
    {
        $error = '';

        if (!$this->validatePhar($this->tmpFile, $pharError)) {
            $error = 'The download is corrupt: '.$pharError;

        } elseif (!$this->verifySignature($version, $signature, $this->tmpFile)) {
            $error = 'Signature mismatch, could not verify the phar file integrity';

        } else {
            $this->errHandler->start();

            if (!rename($this->tmpFile, $this->target)) {
                $error = sprintf('Could not write to file "%s": %s', $this->target, $this->errHandler->message);
            }
            chmod($this->target, 0755);
            $this->errHandler->stop();
        }

        return empty($error);
    }

    /**
     * Parses an array of version data to match the required channel
     *
     * @param array $data Downloaded version data
     * @param mixed $channel Version channel to use
     * @param false|string $version Set by method
     * @param mixed $url The versioned url, set by method
     */
    protected function parseVersionData(array $data, $channel, &$version, &$url)
    {
        foreach ($data[$channel] as $candidate) {
            if ($candidate['min-php'] <= PHP_VERSION_ID) {
                $version = $candidate['version'];
                $url = $this->baseUrl.$candidate['path'];
                break;
            }
        }

        if (!$version) {
            $error = sprintf(
                'None of the %d %s version(s) of Composer matches your PHP version (%s / ID: %d)',
                count($data[$channel]),
                $channel,
                PHP_VERSION,
                PHP_VERSION_ID
            );
            throw new RuntimeException($error);
        }
    }

    /**
     * Downloads the digital signature of required phar file
     *
     * @param string $url The signature url
     * @param null|string $signature Set by method on success
     *
     * @return bool If the download succeeded
     */
    protected function getSignature($url, &$signature)
    {
        if (!$result = $this->disableTls) {
            $signature = $this->httpClient->get($url);

            if ($signature) {
                $signature = json_decode($signature, true);
                $signature = base64_decode($signature['sha384']);
                $result = true;
            }
        }

        return $result;
    }

    /**
     * Verifies the signature of the downloaded phar
     *
     * @param string $version The composer versione
     * @param string $signature The downloaded digital signature
     * @param string $file The temp phar file
     *
     * @return bool If the operation succeeded
     */
    protected function verifySignature($version, $signature, $file)
    {
        if (!$result = $this->disableTls) {
            $path = preg_match('{^[0-9a-f]{40}$}', $version) ? $this->pubKeys['dev'] : $this->pubKeys['tags'];
            $pubkeyid = openssl_pkey_get_public('file://'.$path);

            $result = 1 === openssl_verify(
                file_get_contents($file),
                $signature,
                $pubkeyid,
                $this->algo
            );

            // PHP 8 automatically frees the key instance and deprecates the function
            if (PHP_VERSION_ID < 80000) {
                openssl_free_key($pubkeyid);
            }
        }

        return $result;
    }

    /**
     * Validates the downloaded phar file
     *
     * @param string $pharFile The temp phar file
     * @param null|string $error Set by method on failure
     *
     * @return bool If the operation succeeded
     */
    protected function validatePhar($pharFile, &$error)
    {
        if (ini_get('phar.readonly')) {
            return true;
        }

        try {
            // Test the phar validity
            $phar = new Phar($pharFile);
            // Free the variable to unlock the file
            unset($phar);
            $result = true;

        } catch (Exception $e) {
            if (!$e instanceof UnexpectedValueException && !$e instanceof PharException) {
                throw $e;
            }
            $error = $e->getMessage();
            $result = false;
        }
        return $result;
    }

    /**
     * Returns a string representation of the last json error
     *
     * @return string The error string or code
     */
    protected function getJsonError()
    {
        if (function_exists('json_last_error_msg')) {
            return json_last_error_msg();
        } else {
            return 'json_last_error = '.json_last_error();
        }
    }

    /**
     * Cleans up resources at the end of the installation
     *
     * @param bool $result If the installation succeeded
     */
    protected function cleanUp($result)
    {
        if ($this->quiet) {
            // Ensure output buffers are emptied
            $errors = explode(PHP_EOL, (string) ob_get_clean());
        }

        if (!$result) {
            // Output buffered errors
            if ($this->quiet) {
                $this->outputErrors($errors);
            }
            // Clean up stuff we created
            $this->uninstall();
        } elseif ($this->tmpCafile !== null) {
            @unlink($this->tmpCafile);
        }
    }

    /**
     * Outputs unique errors when in quiet mode
     *
     */
    protected function outputErrors(array $errors)
    {
        $shown = array();

        foreach ($errors as $error) {
            if ($error && !in_array($error, $shown)) {
                out($error, 'error');
                $shown[] = $error;
            }
        }
    }

    /**
     * Uninstalls newly-created files and directories on failure
     *
     */
    protected function uninstall()
    {
        foreach (array_reverse($this->installs) as $target) {
            if (is_file($target)) {
                @unlink($target);
            } elseif (is_dir($target)) {
                @rmdir($target);
            }
        }

        if ($this->tmpFile !== null && file_exists($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    public static function getPKDev()
    {
        return <<<PKDEV
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAnBDHjZS6e0ZMoK3xTD7f
FNCzlXjX/Aie2dit8QXA03pSrOTbaMnxON3hUL47Lz3g1SC6YJEMVHr0zYq4elWi
i3ecFEgzLcj+pZM5X6qWu2Ozz4vWx3JYo1/a/HYdOuW9e3lwS8VtS0AVJA+U8X0A
hZnBmGpltHhO8hPKHgkJtkTUxCheTcbqn4wGHl8Z2SediDcPTLwqezWKUfrYzu1f
o/j3WFwFs6GtK4wdYtiXr+yspBZHO3y1udf8eFFGcb2V3EaLOrtfur6XQVizjOuk
8lw5zzse1Qp/klHqbDRsjSzJ6iL6F4aynBc6Euqt/8ccNAIz0rLjLhOraeyj4eNn
8iokwMKiXpcrQLTKH+RH1JCuOVxQ436bJwbSsp1VwiqftPQieN+tzqy+EiHJJmGf
TBAbWcncicCk9q2md+AmhNbvHO4PWbbz9TzC7HJb460jyWeuMEvw3gNIpEo2jYa9
pMV6cVqnSa+wOc0D7pC9a6bne0bvLcm3S+w6I5iDB3lZsb3A9UtRiSP7aGSo7D72
8tC8+cIgZcI7k9vjvOqH+d7sdOU2yPCnRY6wFh62/g8bDnUpr56nZN1G89GwM4d4
r/TU7BQQIzsZgAiqOGXvVklIgAMiV0iucgf3rNBLjjeNEwNSTTG9F0CtQ+7JLwaE
wSEuAuRm+pRqi8BRnQ/GKUcCAwEAAQ==
-----END PUBLIC KEY-----
PKDEV;
    }

    public static function getPKTags()
    {
        return <<<PKTAGS
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA0Vi/2K6apCVj76nCnCl2
MQUPdK+A9eqkYBacXo2wQBYmyVlXm2/n/ZsX6pCLYPQTHyr5jXbkQzBw8SKqPdlh
vA7NpbMeNCz7wP/AobvUXM8xQuXKbMDTY2uZ4O7sM+PfGbptKPBGLe8Z8d2sUnTO
bXtX6Lrj13wkRto7st/w/Yp33RHe9SlqkiiS4MsH1jBkcIkEHsRaveZzedUaxY0M
mba0uPhGUInpPzEHwrYqBBEtWvP97t2vtfx8I5qv28kh0Y6t+jnjL1Urid2iuQZf
noCMFIOu4vksK5HxJxxrN0GOmGmwVQjOOtxkwikNiotZGPR4KsVj8NnBrLX7oGuM
nQvGciiu+KoC2r3HDBrpDeBVdOWxDzT5R4iI0KoLzFh2pKqwbY+obNPS2bj+2dgJ
rV3V5Jjry42QOCBN3c88wU1PKftOLj2ECpewY6vnE478IipiEu7EAdK8Zwj2LmTr
RKQUSa9k7ggBkYZWAeO/2Ag0ey3g2bg7eqk+sHEq5ynIXd5lhv6tC5PBdHlWipDK
tl2IxiEnejnOmAzGVivE1YGduYBjN+mjxDVy8KGBrjnz1JPgAvgdwJ2dYw4Rsc/e
TzCFWGk/HM6a4f0IzBWbJ5ot0PIi4amk07IotBXDWwqDiQTwyuGCym5EqWQ2BD95
RGv89BPD+2DLnJysngsvVaUCAwEAAQ==
-----END PUBLIC KEY-----
PKTAGS;
    }
}

class ErrorHandler
{
    public $message;
    protected $active;

    /**
     * Handle php errors
     *
     * @param mixed $code The error code
     * @param mixed $msg The error message
     */
    public function handleError($code, $msg)
    {
        if ($this->message) {
            $this->message .= PHP_EOL;
        }
        $this->message .= preg_replace('{^file_get_contents\(.*?\): }', '', $msg);
    }

    /**
     * Starts error-handling if not already active
     *
     * Any message is cleared
     */
    public function start()
    {
        if (!$this->active) {
            set_error_handler(array($this, 'handleError'));
            $this->active = true;
        }
        $this->message = '';
    }

    /**
     * Stops error-handling if active
     *
     * Any message is preserved until the next call to start()
     */
    public function stop()
    {
        if ($this->active) {
            restore_error_handler();
            $this->active = false;
        }
    }
}

class NoProxyPattern
{
    private $composerInNoProxy = false;
    private $rulePorts = array();

    public function __construct($pattern)
    {
        $rules = preg_split('{[\s,]+}', $pattern, null, PREG_SPLIT_NO_EMPTY);

        if ($matches = preg_grep('{getcomposer\.org(?::\d+)?}i', $rules)) {
            $this->composerInNoProxy = true;

            foreach ($matches as $match) {
                if (strpos($match, ':') !== false) {
                    list(, $port) = explode(':', $match);
                    $this->rulePorts[] = (int) $port;
                }
            }
        }
    }

    /**
     * Returns true if NO_PROXY contains getcomposer.org
     *
     * @param string $url http(s)://getcomposer.org
     *
     * @return bool
     */
    public function test($url)
    {
        if (!$this->composerInNoProxy) {
            return false;
        }

        if (empty($this->rulePorts)) {
            return true;
        }

        if (strpos($url, 'http://') === 0) {
            $port = 80;
        } else {
            $port = 443;
        }

        return in_array($port, $this->rulePorts);
    }
}

class HttpClient {

    /** @var null|string */
    private static $caPath;

    private $options = array('http' => array());
    private $disableTls = false;

    public function __construct($disableTls = false, $cafile = false)
    {
        $this->disableTls = $disableTls;
        if ($this->disableTls === false) {
            if (!empty($cafile) && !is_dir($cafile)) {
                if (!is_readable($cafile) || !validateCaFile(file_get_contents($cafile))) {
                    throw new RuntimeException('The configured cafile (' .$cafile. ') was not valid or could not be read.');
                }
            }
            $options = $this->getTlsStreamContextDefaults($cafile);
            $this->options = array_replace_recursive($this->options, $options);
        }
    }

    public function get($url)
    {
        if (function_exists('http_clear_last_response_headers')) {
           $http_response_header = http_clear_last_response_headers();
        }

        $context = $this->getStreamContext($url);
        $result = file_get_contents($url, false, $context);

        if ($result && extension_loaded('zlib')) {
            if (function_exists('http_get_last_response_headers')) {
               $http_response_header = http_get_last_response_headers();
            }
            $headers = $http_response_header;
            $decode = false;
            foreach ($headers as $header) {
                if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                    $decode = true;
                    continue;
                } elseif (preg_match('{^HTTP/}i', $header)) {
                    $decode = false;
                }
            }

            if ($decode) {
                if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
                    $result = zlib_decode($result);
                } else {
                    // work around issue with gzuncompress & co that do not work with all gzip checksums
                    $result = file_get_contents('compress.zlib://data:application/octet-stream;base64,'.base64_encode($result));
                }

                if (!$result) {
                    throw new RuntimeException('Failed to decode zlib stream');
                }
            }
        }

        return $result;
    }

    protected function getStreamContext($url)
    {
        if ($this->disableTls === false) {
            if (PHP_VERSION_ID < 50600) {
                $this->options['ssl']['SNI_server_name'] = parse_url($url, PHP_URL_HOST);
            }
        }
        // Keeping the above mostly isolated from the code copied from Composer.
        return $this->getMergedStreamContext($url);
    }

    protected function getTlsStreamContextDefaults($cafile)
    {
        $ciphers = implode(':', array(
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256',
            'DHE-DSS-AES128-GCM-SHA256',
            'kEDH+AESGCM',
            'ECDHE-RSA-AES128-SHA256',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA',
            'ECDHE-ECDSA-AES128-SHA',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA',
            'ECDHE-ECDSA-AES256-SHA',
            'DHE-RSA-AES128-SHA256',
            'DHE-RSA-AES128-SHA',
            'DHE-DSS-AES128-SHA256',
            'DHE-RSA-AES256-SHA256',
            'DHE-DSS-AES256-SHA',
            'DHE-RSA-AES256-SHA',
            'AES128-GCM-SHA256',
            'AES256-GCM-SHA384',
            'AES128-SHA256',
            'AES256-SHA256',
            'AES128-SHA',
            'AES256-SHA',
            'AES',
            'CAMELLIA',
            'DES-CBC3-SHA',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!RC4',
            '!MD5',
            '!PSK',
            '!aECDH',
            '!EDH-DSS-DES-CBC3-SHA',
            '!EDH-RSA-DES-CBC3-SHA',
            '!KRB5-DES-CBC3-SHA',
        ));

        /**
         * CN_match and SNI_server_name are only known once a URL is passed.
         * They will be set in the getOptionsForUrl() method which receives a URL.
         *
         * cafile or capath can be overridden by passing in those options to constructor.
         */
        $options = array(
            'ssl' => array(
                'ciphers' => $ciphers,
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
            )
        );

        /**
         * Attempt to find a local cafile or throw an exception.
         * The user may go download one if this occurs.
         */
        if (!$cafile) {
            $cafile = self::getSystemCaRootBundlePath();
        }
        if (is_dir($cafile)) {
            $options['ssl']['capath'] = $cafile;
        } elseif ($cafile) {
            $options['ssl']['cafile'] = $cafile;
        } else {
            throw new RuntimeException('A valid cafile could not be located automatically.');
        }

        /**
         * Disable TLS compression to prevent CRIME attacks where supported.
         */
        if (version_compare(PHP_VERSION, '5.4.13') >= 0) {
            $options['ssl']['disable_compression'] = true;
        }

        return $options;
    }

    /**
     * function copied from Composer\Util\StreamContextFactory::initOptions
     *
     * Any changes should be applied there as well, or backported here.
     *
     * @param string $url URL the context is to be used for
     * @return resource Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    protected function getMergedStreamContext($url)
    {
        $options = $this->options;

        // Handle HTTP_PROXY/http_proxy on CLI only for security reasons
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy']))) {
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        // Prefer CGI_HTTP_PROXY if available
        if (!empty($_SERVER['CGI_HTTP_PROXY'])) {
            $proxy = parse_url($_SERVER['CGI_HTTP_PROXY']);
        }

        // Override with HTTPS proxy if present and URL is https
        if (preg_match('{^https://}i', $url) && (!empty($_SERVER['HTTPS_PROXY']) || !empty($_SERVER['https_proxy']))) {
            $proxy = parse_url(!empty($_SERVER['https_proxy']) ? $_SERVER['https_proxy'] : $_SERVER['HTTPS_PROXY']);
        }

        // Remove proxy if URL matches no_proxy directive
        if (!empty($_SERVER['NO_PROXY']) || !empty($_SERVER['no_proxy']) && parse_url($url, PHP_URL_HOST)) {
            $pattern = new NoProxyPattern(!empty($_SERVER['no_proxy']) ? $_SERVER['no_proxy'] : $_SERVER['NO_PROXY']);
            if ($pattern->test($url)) {
                unset($proxy);
            }
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'] . '://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ":" . $proxy['port'];
            } elseif (strpos($proxyURL, 'http://') === 0) {
                $proxyURL .= ":80";
            } elseif (strpos($proxyURL, 'https://') === 0) {
                $proxyURL .= ":443";
            }

            // check for a secure proxy
            if (strpos($proxyURL, 'https://') === 0) {
                if (!extension_loaded('openssl')) {
                    throw new RuntimeException('You must enable the openssl extension to use a secure proxy.');
                }
                if (strpos($url, 'https://') === 0) {
                    throw new RuntimeException('PHP does not support https requests through a secure proxy.');
                }
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            $options['http'] = array(
                'proxy' => $proxyURL,
            );

            // add request_fulluri for http requests
            if ('http' === parse_url($url, PHP_URL_SCHEME)) {
                $options['http']['request_fulluri'] = true;
            }

            // handle proxy auth if present
            if (isset($proxy['user'])) {
                $auth = rawurldecode($proxy['user']);
                if (isset($proxy['pass'])) {
                    $auth .= ':' . rawurldecode($proxy['pass']);
                }
                $auth = base64_encode($auth);

                $options['http']['header'] = "Proxy-Authorization: Basic {$auth}\r\n";
            }
        }

        if (isset($options['http']['header'])) {
            $options['http']['header'] .= "Connection: close\r\n";
        } else {
            $options['http']['header'] = "Connection: close\r\n";
        }
        if (extension_loaded('zlib')) {
            $options['http']['header'] .= "Accept-Encoding: gzip\r\n";
        }
        $options['http']['header'] .= "User-Agent: ".COMPOSER_INSTALLER."\r\n";
        $options['http']['protocol_version'] = 1.1;
        $options['http']['timeout'] = 600;

        return stream_context_create($options);
    }

    /**
    * This method was adapted from Sslurp.
    * https://github.com/EvanDotPro/Sslurp
    *
    * (c) Evan Coury <me@evancoury.com>
    *
    * For the full copyright and license information, please see below:
    *
    * Copyright (c) 2013, Evan Coury
    * All rights reserved.
    *
    * Redistribution and use in source and binary forms, with or without modification,
    * are permitted provided that the following conditions are met:
    *
    *     * Redistributions of source code must retain the above copyright notice,
    *       this list of conditions and the following disclaimer.
    *
    *     * Redistributions in binary form must reproduce the above copyright notice,
    *       this list of conditions and the following disclaimer in the documentation
    *       and/or other materials provided with the distribution.
    *
    * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
    * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
    * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
    * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
    * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
    */
    public static function getSystemCaRootBundlePath()
    {
        if (self::$caPath !== null) {
            return self::$caPath;
        }

        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertFile = getenv('SSL_CERT_FILE');
        if ($envCertFile && is_readable($envCertFile) && validateCaFile(file_get_contents($envCertFile))) {
            return self::$caPath = $envCertFile;
        }

        // If SSL_CERT_DIR env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertDir = getenv('SSL_CERT_DIR');
        if ($envCertDir && is_dir($envCertDir) && is_readable($envCertDir)) {
            return self::$caPath = $envCertDir;
        }

        $configured = ini_get('openssl.cafile');
        if ($configured && strlen($configured) > 0 && is_readable($configured) && validateCaFile(file_get_contents($configured))) {
            return self::$caPath = $configured;
        }

        $configured = ini_get('openssl.capath');
        if ($configured && is_dir($configured) && is_readable($configured)) {
            return self::$caPath = $configured;
        }

        $caBundlePaths = array(
            '/etc/pki/tls/certs/ca-bundle.crt', // Fedora, RHEL, CentOS (ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem', // SUSE, openSUSE (ca-certificates package)
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD (ca_root_nss_package)
            '/usr/ssl/certs/ca-bundle.crt', // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt', // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt', // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt', // Really old RedHat?
            '/etc/ssl/cert.pem', // OpenBSD
            '/usr/local/etc/ssl/cert.pem', // FreeBSD 10.x
            '/usr/local/etc/openssl/cert.pem', // OS X homebrew, openssl package
            '/usr/local/etc/openssl@1.1/cert.pem', // OS X homebrew, openssl@1.1 package
            '/opt/homebrew/etc/openssl@3/cert.pem', // macOS silicon homebrew, openssl@3 package
            '/opt/homebrew/etc/openssl@1.1/cert.pem', // macOS silicon homebrew, openssl@1.1 package
        );

        foreach ($caBundlePaths as $caBundle) {
            if (@is_readable($caBundle) && validateCaFile(file_get_contents($caBundle))) {
                return self::$caPath = $caBundle;
            }
        }

        foreach ($caBundlePaths as $caBundle) {
            $caBundle = dirname($caBundle);
            if (is_dir($caBundle) && glob($caBundle.'/*')) {
                return self::$caPath = $caBundle;
            }
        }

        return self::$caPath = false;
    }

    public static function getPackagedCaFile()
    {
        return <<<CACERT
##
## Bundle of CA Root Certificates for Let's Encrypt
##
## See https://letsencrypt.org/certificates/#root-certificates
##
## ISRG Root X1 (RSA 4096) expires Jun 04 11:04:38 2035 GMT
## ISRG Root X2 (ECDSA P-384) expires Sep 17 16:00:00 2040 GMT
##
## Both these are self-signed CA root certificates
##

ISRG Root X1
============
-----BEGIN CERTIFICATE-----
MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw
TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh
cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4
WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu
ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY
MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc
h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+
0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U
A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW
T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH
B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC
B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv
KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn
OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn
jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw
qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI
rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq
hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL
ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ
3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK
NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5
ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur
TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC
jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc
oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq
4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA
mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d
emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=
-----END CERTIFICATE-----

ISRG Root X2
============
-----BEGIN CERTIFICATE-----
MIICGzCCAaGgAwIBAgIQQdKd0XLq7qeAwSxs6S+HUjAKBggqhkjOPQQDAzBPMQsw
CQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJuZXQgU2VjdXJpdHkgUmVzZWFyY2gg
R3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBYMjAeFw0yMDA5MDQwMDAwMDBaFw00
MDA5MTcxNjAwMDBaME8xCzAJBgNVBAYTAlVTMSkwJwYDVQQKEyBJbnRlcm5ldCBT
ZWN1cml0eSBSZXNlYXJjaCBHcm91cDEVMBMGA1UEAxMMSVNSRyBSb290IFgyMHYw
EAYHKoZIzj0CAQYFK4EEACIDYgAEzZvVn4CDCuwJSvMWSj5cz3es3mcFDR0HttwW
+1qLFNvicWDEukWVEYmO6gbf9yoWHKS5xcUy4APgHoIYOIvXRdgKam7mAHf7AlF9
ItgKbppbd9/w+kHsOdx1ymgHDB/qo0IwQDAOBgNVHQ8BAf8EBAMCAQYwDwYDVR0T
AQH/BAUwAwEB/zAdBgNVHQ4EFgQUfEKWrt5LSDv6kviejM9ti6lyN5UwCgYIKoZI
zj0EAwMDaAAwZQIwe3lORlCEwkSHRhtFcP9Ymd70/aTSVaYgLXTWNLxBo1BfASdW
tL4ndQavEi51mI38AjEAi/V3bNTIZargCyzuFJ0nN6T5U6VR5CmD1/iQMVtCnwr1
/q4AaOeMSQ+2b1tbFfLn
-----END CERTIFICATE-----
CACERT;
    }
}
