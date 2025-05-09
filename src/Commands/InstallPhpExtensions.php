<?php

namespace Amohamed\NativePhpCustomPhp\Commands;

use Illuminate\Console\Command;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Progress;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

class InstallPhpExtensions extends Command
{
    protected $signature = 'php-ext:install 
                         {--path= : Path to static-php-cli installation}
                         {--sapi=cli : SAPI to build (cli, micro)}
                         {--php-version=8.4 : PHP version to build}
                         {--upx : Enable UPX compression}';

    protected $description = 'Install PHP extensions for NativePHP using static-php-cli';

    protected $availableExtensions = [
        'bcmath', 'bz2', 'ctype', 'curl', 'dom', 'fileinfo', 'filter',
        'gd', 'iconv', 'mbstring', 'opcache', 'openssl', 'pdo',
        'pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'phar', 'session',
        'simplexml', 'sockets', 'sqlite3', 'tokenizer', 'xml',
        'zip', 'zlib', 'sqlsrv', 'pdo_sqlsrv'
    ];

    protected $requiredLibraries = [
        'bzip2', 'zlib', 'openssl', 'libssh2', 'libiconv-win',
        'libxml2', 'nghttp2', 'curl', 'libpng', 'sqlite', 'xz', 'libzip'
    ];

    protected $defaultPath;

    public function __construct()
    {
        parent::__construct();
        $this->defaultPath = base_path('nativephp-php-custom');
    }

    protected function detectOS(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'Windows',
            'Darwin'  => 'macOS',
            'Linux'   => 'Linux',
            default   => throw new RuntimeException("Unsupported OS: " . PHP_OS_FAMILY),
        };
    }

    public function handle(): int
    {
        $this->validateEnvironment();

        $spcPath = $this->option('path') ?: $this->defaultPath;

        if (! file_exists($spcPath)) {
            $this->info("nativephp-php-custom not found. Cloning into {$spcPath}...");
            $result = Process::run("git clone https://github.com/Abdallah-Tah/nativephp-php-custom.git \"{$spcPath}\"");
            if (! $result->successful()) {
                throw new RuntimeException("Failed to clone nativephp-php-custom: " . $result->errorOutput());
            }
        }

        $os = $this->detectOS();
        $this->info("Detected operating system: {$os}");

        if ($os === 'Windows') {
            $this->checkWindowsRequirements();
        }

        $this->downloadRequiredLibraries($spcPath);

        $this->info('Available PHP extensions:');
        foreach ($this->availableExtensions as $i => $ext) {
            $this->line("[{$i}] {$ext}");
        }

        $indexes = $this->ask('Enter the numbers of the extensions to install, separated by commas');
        if (! $indexes) {
            $this->warn('No extensions selected.');
            return self::SUCCESS;
        }

        $selected = array_intersect_key($this->availableExtensions, array_flip(array_map('trim', explode(',', $indexes))));
        if (! $selected) {
            $this->warn('No valid extensions selected.');
            return self::SUCCESS;
        }

        return $this->buildPhp($selected, $os, $spcPath);
    }

    protected function downloadRequiredLibraries(string $spcPath): void
    {
        $this->info('Checking and downloading required libraries...');
        foreach ($this->requiredLibraries as $lib) {
            $this->info("Checking library: {$lib}");
            $p = Process::start("{$spcPath}/bin/spc build-library {$lib}");
            while ($p->running()) {
                $out = $p->latestOutput();
                if ($out && str_contains($out, 'not downloaded or not locked')) {
                    $this->warn("Library {$lib} not found, downloading...");
                    $d = Process::start("{$spcPath}/bin/spc download {$lib}");
                    while ($d->running()) {
                        if ($o = $d->latestOutput()) {
                            $this->line($o);
                        }
                        sleep(1);
                    }
                    if ($d->successful()) {
                        $this->info("Successfully downloaded {$lib}");
                    } else {
                        $this->error("Failed to download {$lib}: " . $d->errorOutput());
                        if (! $this->confirm("Continue without {$lib}?")) {
                            throw new RuntimeException("Cannot continue without {$lib}");
                        }
                    }
                    break;
                }
                sleep(1);
            }
        }
    }

    protected function buildPhp(array $exts, string $os, string $spcPath): int
    {
        $list = implode(',', $exts);
        $sapi = $this->option('sapi');
        $ver  = $this->option('php-version');

        $this->info("Building PHP {$ver} with: {$list}...");
        $cmd = ($os === 'Windows' ? "{$spcPath}\\bin\\spc" : "{$spcPath}/bin/spc")
             . " build \"{$list}\" --build-{$sapi}"
             . ($this->option('upx') ? ' --with-upx' : '')
             . ($sapi === 'micro'        ? ' --with-micro' : '');

        $this->comment("Running: {$cmd}");
        $p = Process::start($cmd);
        $current = null;
        while ($p->running()) {
            if ($o = $p->latestOutput()) {
                $this->line($o);
                if (preg_match('/Building required lib \[(\w+)\]/', $o, $m)) {
                    $current = $m[1];
                } elseif ($current && str_contains($o, 'not downloaded or not locked')) {
                    $this->warn("Missing {$current}, downloading...");
                    $d = Process::start("{$spcPath}/bin/spc download {$current}");
                    while ($d->running()) {
                        if ($o2 = $d->latestOutput()) {
                            $this->line($o2);
                        }
                        sleep(1);
                    }
                    if ($d->successful()) {
                        $this->info("Downloaded {$current}, retrying build...");
                        $p = Process::start($cmd);
                    } else {
                        $this->error("Failed to download {$current}");
                        if (! $this->confirm("Continue anyway?")) {
                            return self::FAILURE;
                        }
                    }
                }
            }
            sleep(1);
        }

        if ($p->successful()) {
            $bin = $sapi === 'micro' ? 'micro.sfx' : ($os === 'Windows' ? 'php.exe' : 'php');
            $this->info('Build successful!');
            $this->info("Binary at: {$spcPath}" . ($os === 'Windows' ? "\\buildroot\\bin\\{$bin}" : "/buildroot/bin/{$bin}"));

            $zipName = "php-{$ver}.zip";
            $zipPath = "vendor/nativephp/php-bin/bin/{$os}/x64/{$zipName}";
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile(
                    "{$spcPath}" . ($os === 'Windows' ? "\\buildroot\\bin\\{$bin}" : "/buildroot/bin/{$bin}"),
                    $bin
                );
                $zip->close();
                $this->info("Zipped to {$zipPath}");
            } else {
                $this->error("Failed zip at {$zipPath}");
            }

            if ($sapi === 'micro') {
                if ($os === 'Windows') {
                    $this->info("copy /b {$spcPath}\\buildroot\\bin\\micro.sfx + your-app.php app.exe");
                } else {
                    $this->info("cat {$spcPath}/buildroot/bin/micro.sfx your-app.php > app && chmod +x app");
                }
            }
            return self::SUCCESS;
        }

        $this->error('Build failed.');
        return self::FAILURE;
    }

    protected function checkWindowsRequirements(): void
    {
        $vs = 'C:\Program Files (x86)\Microsoft Visual Studio\Installer\vswhere.exe';
        if (! file_exists($vs)) {
            throw new RuntimeException(
                "Install Visual Studio 2022 with C++ workload and SDKs."
            );
        }
    }

    protected function validateEnvironment(): void
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new RuntimeException('PHP >= 8.1 required.');
        }
        foreach (['mbstring','tokenizer'] as $ext) {
            if (! extension_loaded($ext)) {
                throw new RuntimeException("Extension {$ext} required.");
            }
        }
    }
}
