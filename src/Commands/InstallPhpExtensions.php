<?php

namespace Amohamed\NativePhpCustomPhp\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

class InstallPhpExtensions extends Command
{
    protected $signature = 'php-ext:install
                         {--path= : Path to static-php-cli installation}
                         {--sapi=cli : SAPI to build (cli, micro)}
                         {--upx : Enable UPX compression}';

    protected $description = 'Install PHP extensions for NativePHP using static-php-cli';

    protected array $availableExtensions;
    protected array $requiredLibraries;
    protected string $defaultPath;

    public function __construct()
    {
        parent::__construct();

        $this->availableExtensions = [
            'bcmath',
            'bz2',
            'ctype',
            'curl',
            'dom',
            'fileinfo',
            'filter',
            'gd',
            'iconv',
            'mbstring',
            'opcache',
            'openssl',
            'pdo',
            'pdo_sqlite',
            'pdo_mysql',
            'pdo_pgsql',
            'phar',
            'session',
            'simplexml',
            'sockets',
            'sqlite3',
            'tokenizer',
            'xml',
            'zip',
            'zlib',
            'sqlsrv',
            'pdo_sqlsrv'
        ];

        $this->requiredLibraries = [
            'bzip2',
            'zlib',
            'openssl',
            'libssh2',
            'libiconv-win',
            'libxml2',
            'nghttp2',
            'curl',
            'libpng',
            'sqlite',
            'xz',
            'libzip'
        ];

        // Update default path to use static-php-cli
        $this->defaultPath = app()->basePath('static-php-cli');
    }

    protected function detectOS(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'Windows',
            'Darwin' => 'macOS',
            'Linux' => 'Linux',
            default => throw new RuntimeException("Unsupported OS: " . PHP_OS_FAMILY),
        };
    }

    public function handle(): int
    {
        $this->validateEnvironment();

        // Get available PHP versions
        $availableVersions = $this->getAvailablePhpVersions();

        // PHP version selection with only stable versions
        $phpVersion = $this->choice(
            'Which PHP version would you like to build?',
            $availableVersions,
            end($availableVersions) // Default to latest version
        );

        $spcPath = $this->option('path') ?: $this->defaultPath;

        if (!file_exists($spcPath)) {
            $this->info("static-php-cli not found. Cloning into {$spcPath}...");

            // Clone the official static-php-cli repository
            $result = Process::run("git clone https://github.com/crazywhalecc/static-php-cli.git \"{$spcPath}\"");
            if (!$result->successful()) {
                throw new RuntimeException("Failed to clone static-php-cli: " . $result->errorOutput());
            }

            // Update composer.json to be compatible with PHP 8.3
            $composerJsonPath = $spcPath . '/composer.json';
            if (file_exists($composerJsonPath)) {
                $composerJson = json_decode(file_get_contents($composerJsonPath), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $composerJson['require']['php'] = '>=8.1';  // More permissive PHP requirement
                    file_put_contents($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }

            // Run composer install in the cloned directory
            $this->info("Installing dependencies in {$spcPath}...");
            $composerResult = Process::path($spcPath)->run('composer install');
            if (!$composerResult->successful()) {
                // If composer install fails, try composer update
                $this->info("Initial install failed, trying composer update...");
                $composerResult = Process::path($spcPath)->run('composer update');
                if (!$composerResult->successful()) {
                    throw new RuntimeException("Failed to install dependencies: " . $composerResult->errorOutput());
                }
            }

            // Initialize the static-php-cli configuration
            $this->info("Initializing static-php-cli configuration...");
            $initResult = Process::path($spcPath)->run('bin/spc doctor');
            if (!$initResult->successful()) {
                throw new RuntimeException("Failed to initialize static-php-cli: " . $initResult->errorOutput());
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
        if (!$indexes) {
            $this->warn('No extensions selected.');
            return self::SUCCESS;
        }

        $selected = array_intersect_key(
            $this->availableExtensions,
            array_flip(array_map('trim', explode(',', $indexes)))
        );
        if (!$selected) {
            $this->warn('No valid extensions selected.');
            return self::SUCCESS;
        }

        return $this->buildPhp($selected, $os, $spcPath, $phpVersion);
    }

    protected function getAvailablePhpVersions(): array
    {
        $versions = [
            '8.2.16',
            '8.3.21', // Latest 8.3.x
            '8.4',  // Latest 8.4.x
        ];

        // Sort versions from newest to oldest
        usort($versions, function ($a, $b) {
            return version_compare($b, $a);
        });

        return $versions;
    }

    protected function manualDownloadDependency(string $spcPath, string $lib): bool
    {
        $this->info("Attempting manual download of {$lib}...");

        // Use spc.exe from root directory on Windows, bin/spc on Unix
        $spcBinary = PHP_OS_FAMILY === 'Windows'
            ? $spcPath . DIRECTORY_SEPARATOR . 'spc.exe'
            : $spcPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'spc';

        // Run spc download command directly
        $downloadCmd = "\"{$spcBinary}\" download {$lib}";

        $this->comment("Running: {$downloadCmd}");
        $process = Process::path($spcPath)->timeout(300)->run($downloadCmd);

        if ($process->successful()) {
            $this->info("Successfully downloaded {$lib}");
            return true;
        }

        $this->error("Failed to download {$lib}: " . $process->errorOutput());
        return false;
    }

    protected function downloadRequiredLibraries(string $spcPath): void
    {
        $this->info('Checking and downloading required libraries...');

        // Ensure the downloads directory exists
        $downloadsPath = $spcPath . DIRECTORY_SEPARATOR . 'downloads';
        if (!file_exists($downloadsPath)) {
            mkdir($downloadsPath, 0777, true);
        }

        foreach ($this->requiredLibraries as $lib) {
            $maxRetries = 3;
            $retryCount = 0;
            $downloaded = false;

            do {
                $this->info("Checking library: {$lib}" . ($retryCount > 0 ? " (Attempt {$retryCount}/{$maxRetries})" : ""));
                try {
                    // First try with build-library to check if it exists
                    $p = Process::path($spcPath)->start('bin/spc build-library ' . $lib);
                    $needsDownload = false;

                    while ($p->running()) {
                        $out = $p->latestOutput();
                        if ($out && str_contains($out, 'not downloaded or not locked')) {
                            $needsDownload = true;
                            break;
                        }
                        if ($out) {
                            $this->line($out);
                        }
                        sleep(1);
                    }

                    if ($needsDownload) {
                        // Try manual download first
                        if ($this->manualDownloadDependency($spcPath, $lib)) {
                            $downloaded = true;
                            break;
                        }

                        // If manual download fails, try the original method
                        $this->warn("Manual download failed for {$lib}, trying alternative method...");
                        $d = Process::timeout(300)->path($spcPath)->start("bin/spc download {$lib}");

                        while ($d->running()) {
                            if ($o = $d->latestOutput()) {
                                $this->line($o);
                            }
                            sleep(1);
                        }

                        $status = $d->wait();
                        if ($status === 0) {
                            $downloaded = true;
                            $this->info("Successfully downloaded {$lib}");
                            break;
                        }
                    } else {
                        // Library exists or was built successfully
                        $downloaded = true;
                        break;
                    }
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    if (++$retryCount === $maxRetries) {
                        if (!$downloaded && !$this->confirm("Failed to download {$lib} after {$maxRetries} attempts. Would you like to try downloading it manually?")) {
                            throw new RuntimeException("Cannot continue without {$lib}");
                        }
                        // Try manual download as last resort
                        if ($this->manualDownloadDependency($spcPath, $lib)) {
                            $downloaded = true;
                            break;
                        }
                        throw new RuntimeException("All attempts to download {$lib} have failed");
                    }
                    $this->warn("Retrying download...");
                    sleep(2);
                }
            } while ($retryCount < $maxRetries && !$downloaded);

            if (!$downloaded) {
                throw new RuntimeException("Failed to download {$lib} after all attempts");
            }
        }
    }

    protected function ensureSpcBinaryExists(string $spcPath): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $spcExePath = "{$spcPath}\\spc.exe";
            if (!file_exists($spcExePath)) {
                $this->info("Copying spc.exe to build directory...");

                // Try to find existing spc.exe in known locations
                $possibleLocations = [
                    app()->basePath('spc.exe'),
                    app()->basePath('nativephp-php-custom/spc.exe'),
                ];

                $sourceSpc = null;
                foreach ($possibleLocations as $location) {
                    if (file_exists($location)) {
                        $sourceSpc = $location;
                        break;
                    }
                }

                if (!$sourceSpc) {
                    throw new RuntimeException("Could not find spc.exe in any known location. Please ensure spc.exe exists in the project root or nativephp-php-custom directory.");
                }

                // Create directory if it doesn't exist
                if (!file_exists($spcPath)) {
                    mkdir($spcPath, 0777, true);
                }

                // Copy the file
                if (!copy($sourceSpc, $spcExePath)) {
                    throw new RuntimeException("Failed to copy spc.exe to {$spcExePath}");
                }

                $this->info("Copied spc.exe successfully");
            }
        }
    }

    protected function downloadAndBuildDependencies(array $exts, string $os, string $spcPath): bool
    {
        // Create downloads directory in the spcPath if it doesn't exist
        $downloadsPath = $spcPath . DIRECTORY_SEPARATOR . 'downloads';
        if (!file_exists($downloadsPath)) {
            mkdir($downloadsPath, 0777, true);
        }

        // Set SPC_DOWNLOAD_PATH environment variable to use our custom downloads path
        putenv("SPC_DOWNLOAD_PATH={$downloadsPath}");

        // Check if spc.exe exists and copy if needed
        $this->ensureSpcBinaryExists($spcPath);

        // First ensure we have PHP SDK on Windows
        if ($os === 'Windows') {
            if (!file_exists('php-sdk-binary-tools')) {
                $this->info("Downloading PHP SDK...");
                Process::run('git clone https://github.com/php/php-sdk-binary-tools.git');
            }
        }

        // Define core dependencies needed for SQL Server extensions
        $coreDeps = [
            'libxml2',
            'openssl',
            'zlib',
            'bzip2',
            'libssh2',
            'nghttp2',
            'curl',
            'libpng',
            'libiconv-win'
        ];

        // Download and build each dependency
        foreach ($coreDeps as $dep) {
            $this->info("Processing dependency: {$dep}");

            // First try to download
            $downloadCmd = ($os === 'Windows' ? "{$spcPath}\\spc.exe" : "{$spcPath}/bin/spc")
                . " download {$dep}";

            $this->comment("Running: {$downloadCmd}");
            $process = Process::start($downloadCmd);
            putenv("SPC_DOWNLOAD_PATH={$downloadsPath}");

            while ($process->running()) {
                if ($output = $process->output()) {
                    $this->line($output);
                }
                sleep(1);
            }

            if ($process->wait() !== 0) {
                $this->error("Failed to download {$dep}");
                return false;
            }

            // Then extract
            $extractCmd = ($os === 'Windows' ? "{$spcPath}\\spc.exe" : "{$spcPath}/bin/spc")
                . " extract {$dep}";

            $this->comment("Running: {$extractCmd}");
            $extractProcess = Process::start($extractCmd);

            while ($extractProcess->running()) {
                if ($output = $extractProcess->output()) {
                    $this->line($output);
                }
                sleep(1);
            }

            if ($extractProcess->wait() !== 0) {
                $this->error("Failed to extract {$dep}");
                return false;
            }

            // Then build on Windows
            if ($os === 'Windows') {
                $buildCmd = "php-sdk-binary-tools\\phpsdk-vs17-x64.bat -t {$spcPath}\\source\\wrapper.bat"
                    . " --task-args \"--build build --config Release --target install -j8\"";

                $this->comment("Running: {$buildCmd}");
                $process = Process::path("{$spcPath}\\source\\{$dep}")
                    ->timeout(1800) // 30 minutes timeout for build
                    ->start($buildCmd);

                while ($process->running()) {
                    if ($output = $process->output()) {
                        $this->line($output);
                    }
                    sleep(1);
                }

                if ($process->wait() !== 0) {
                    $this->error("Failed to build {$dep}");
                    return false;
                }
            }
        }

        return true;
    }

    protected function downloadPhpSource(string $version, string $spcPath): void
    {
        $downloadsPath = $spcPath . DIRECTORY_SEPARATOR . 'downloads';
        $phpArchive = "php-{$version}.tar.xz";

        if (!file_exists($downloadsPath . DIRECTORY_SEPARATOR . $phpArchive)) {
            $this->info("Downloading PHP {$version}...");
            $url = "https://www.php.net/distributions/{$phpArchive}";

            if (!file_exists($downloadsPath)) {
                mkdir($downloadsPath, 0777, true);
            }

            $result = Process::timeout(300)->run("curl -L {$url} -o \"{$downloadsPath}/{$phpArchive}\"");

            if (!$result->successful()) {
                throw new RuntimeException("Failed to download PHP source: " . $result->errorOutput());
            }
        }
    }

    protected function buildPhp(array $exts, string $os, string $spcPath, string $phpVersion): int
    {
        // Ensure spc binary exists before trying to use it
        $this->ensureSpcBinaryExists($spcPath);

        // Download PHP source if needed
        $this->downloadPhpSource($phpVersion, $spcPath);

        // Set up downloads path
        $downloadsPath = $spcPath . DIRECTORY_SEPARATOR . 'downloads';
        if (!file_exists($downloadsPath)) {
            mkdir($downloadsPath, 0777, true);
        }

        putenv("SPC_DOWNLOAD_PATH={$downloadsPath}");

        // First download and build all dependencies
        try {
            if (!$this->downloadAndBuildDependencies($exts, $os, $spcPath)) {
                if ($this->confirm('Would you like to try downloading dependencies manually?')) {
                    foreach ($this->requiredLibraries as $lib) {
                        if (!$this->manualDownloadDependency($spcPath, $lib)) {
                            if (!$this->confirm("Failed to download {$lib}. Continue anyway?")) {
                                return self::FAILURE;
                            }
                        }
                    }
                } else {
                    $this->error("Failed to prepare all required dependencies");
                    return self::FAILURE;
                }
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            if ($this->confirm('Would you like to try downloading dependencies manually?')) {
                foreach ($this->requiredLibraries as $lib) {
                    if (!$this->manualDownloadDependency($spcPath, $lib)) {
                        if (!$this->confirm("Failed to download {$lib}. Continue anyway?")) {
                            return self::FAILURE;
                        }
                    }
                }
            } else {
                return self::FAILURE;
            }
        }

        $list = implode(',', $exts);
        $sapi = $this->option('sapi');

        $this->info("Building PHP with: {$list}...");
        $buildCmd = ($os === 'Windows' ? "{$spcPath}\\spc.exe" : "{$spcPath}/bin/spc")
            . " build \"{$list}\" --build-{$sapi}"
            . ($this->option('upx') ? ' --with-upx-pack' : '');

        $this->comment("Running: {$buildCmd}");

        try {
            $process = Process::timeout(3600)->start($buildCmd); // 1 hour timeout for build

            while ($process->running()) {
                if ($output = $process->output()) {
                    $this->line($output);
                }
                if ($error = $process->errorOutput()) {
                    $this->error($error);
                }
                sleep(1);
            }

            // Check final process status
            $result = $process->wait();
            if ($result === 0) {
                $bin = $sapi === 'micro' ? 'micro.sfx' : ($os === 'Windows' ? 'php.exe' : 'php');
                $this->info('Build successful!');
                $this->info("Binary at: {$spcPath}" . ($os === 'Windows' ? "\\buildroot\\bin\\{$bin}" : "/buildroot/bin/{$bin}"));

                $zipName = "php-{$phpVersion}.zip";
                $zipPath = "vendor/nativephp/php-bin/bin/{$os}/x64/{$zipName}";

                // Ensure directory exists
                if (!file_exists(dirname($zipPath))) {
                    mkdir(dirname($zipPath), 0777, true);
                }

                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $binPath = "{$spcPath}" . ($os === 'Windows' ? "\\buildroot\\bin\\{$bin}" : "/buildroot/bin/{$bin}");
                    if (file_exists($binPath)) {
                        $zip->addFile($binPath, $bin);
                        $zip->close();
                        $this->info("Zipped to {$zipPath}");
                    } else {
                        $this->error("Binary file not found at {$binPath}");
                        return self::FAILURE;
                    }
                } else {
                    $this->error("Failed to create zip at {$zipPath}");
                    return self::FAILURE;
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

            $this->error('Build failed with output:');
            $this->error($process->errorOutput());
            return self::FAILURE;

        } catch (\Exception $e) {
            $this->error("Build failed with exception: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function checkWindowsRequirements(): void
    {
        $vs = 'C:\Program Files (x86)\Microsoft Visual Studio\Installer\vswhere.exe';
        if (!file_exists($vs)) {
            throw new RuntimeException("Install Visual Studio 2022 with C++ workload and SDKs.");
        }
    }

    protected function validateEnvironment(): void
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new RuntimeException('PHP >= 8.1 required.');
        }
        foreach (['mbstring', 'tokenizer'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException("Extension {$ext} required.");
            }
        }
    }
}
