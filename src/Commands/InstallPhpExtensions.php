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

        // Properly format the path for Windows
        $spcPath = $this->option('path') ?: $this->defaultPath;
        $spcPath = str_replace('/', DIRECTORY_SEPARATOR, $spcPath);

        if (!file_exists($spcPath)) {
            $this->info("static-php-cli not found. Cloning into {$spcPath}...");

            // Create directory if it doesn't exist
            if (!file_exists(dirname($spcPath))) {
                mkdir(dirname($spcPath), 0777, true);
            }

            // Clone the official static-php-cli repository
            $result = Process::run("git clone https://github.com/crazywhalecc/static-php-cli.git \"{$spcPath}\"");
            if (!$result->successful()) {
                throw new RuntimeException("Failed to clone static-php-cli: " . $result->errorOutput());
            }

            // Update composer.json to be compatible with PHP 8.3
            $composerJsonPath = $spcPath . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerJsonPath)) {
                $composerJson = json_decode(file_get_contents($composerJsonPath), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $composerJson['require']['php'] = '>=8.1';  // More permissive PHP requirement
                    file_put_contents($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }

            // Ensure the path exists and is properly formatted before running composer
            if (!file_exists($spcPath)) {
                throw new RuntimeException("Failed to create directory: {$spcPath}");
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

    protected function extractPhpSource(string $phpArchive, string $targetDir): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Try to find 7-Zip
            $sevenZipPaths = [
                'C:\\Program Files\\7-Zip\\7z.exe',
                'C:\\Program Files (x86)\\7-Zip\\7z.exe'
            ];

            $sevenZipExe = null;
            foreach ($sevenZipPaths as $path) {
                if (file_exists($path)) {
                    $sevenZipExe = $path;
                    break;
                }
            }

            if (!$sevenZipExe) {
                $this->warn('7-Zip not found. Installing via winget...');
                Process::run('winget install -e --id 7zip.7zip');

                // Check again after install
                foreach ($sevenZipPaths as $path) {
                    if (file_exists($path)) {
                        $sevenZipExe = $path;
                        break;
                    }
                }

                if (!$sevenZipExe) {
                    throw new RuntimeException('Failed to find or install 7-Zip. Please install it manually.');
                }
            }

            // Extract using 7-Zip
            $this->info('Extracting with 7-Zip...');

            // First extract .tar.xz to .tar
            $tarFile = str_replace('.tar.xz', '.tar', $phpArchive);
            $extractXz = Process::run("\"{$sevenZipExe}\" x \"{$phpArchive}\" -o\"" . dirname($phpArchive) . "\" -y");

            if (!$extractXz->successful()) {
                throw new RuntimeException("Failed to extract .xz: " . $extractXz->errorOutput());
            }

            // Then extract .tar
            $extractTar = Process::run("\"{$sevenZipExe}\" x \"{$tarFile}\" -o\"{$targetDir}\" -y");

            if (!$extractTar->successful()) {
                throw new RuntimeException("Failed to extract .tar: " . $extractTar->errorOutput());
            }

            // Clean up .tar file
            @unlink($tarFile);

            return true;
        } else {
            // Use tar on Unix systems
            $extractResult = Process::run("tar -xf \"{$phpArchive}\" -C \"{$targetDir}\"");
            return $extractResult->successful();
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

        // Set up VS environment
        if ($os === 'Windows') {
            $this->info("Setting up Visual Studio environment...");

            // Set required environment variables
            putenv("VS_BUILDTOOLS=C:\\Program Files (x86)\\Microsoft Visual Studio\\2022\\BuildTools");
            putenv("PHP_SDK_VS=vs17");
            putenv("PHP_SDK_ARCH=x64");

            // Initialize VS environment
            $vsPath = "C:\\Program Files (x86)\\Microsoft Visual Studio\\2022\\BuildTools";
            if (!file_exists($vsPath)) {
                $vsPath = "C:\\Program Files\\Microsoft Visual Studio\\2022\\Community";
            }

            if (!file_exists($vsPath)) {
                throw new RuntimeException("Visual Studio 2022 with C++ workload is required");
            }

            // Run vcvars64.bat to set up environment
            $vcvarsPath = "{$vsPath}\\VC\\Auxiliary\\Build\\vcvars64.bat";
            if (file_exists($vcvarsPath)) {
                $this->info("Initializing Visual Studio environment...");
                Process::run("call \"{$vcvarsPath}\"");
            }
        }

        // Extract PHP source
        $phpSourcePath = $spcPath . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'php-src';
        if (!file_exists($phpSourcePath)) {
            $this->info("Extracting PHP source...");
            $phpArchive = $downloadsPath . DIRECTORY_SEPARATOR . "php-{$phpVersion}.tar.xz";

            if (!file_exists($phpArchive)) {
                throw new RuntimeException("PHP source archive not found at {$phpArchive}");
            }

            // Create source directory
            if (!file_exists(dirname($phpSourcePath))) {
                mkdir(dirname($phpSourcePath), 0777, true);
            }

            if (!$this->extractPhpSource($phpArchive, dirname($phpSourcePath))) {
                throw new RuntimeException("Failed to extract PHP source");
            }
        }

        // Build process
        $list = implode(',', $exts);
        $sapi = $this->option('sapi');

        $this->info("Building PHP with: {$list}...");

        // Configure build command with debug flags
        $buildCmd = ($os === 'Windows' ? "{$spcPath}\\spc.exe" : "{$spcPath}/bin/spc")
            . " build \"{$list}\" --build-cli"
            . " --with-clean" // Clean build directory
            . " --verbose"    // Add verbose output
            . " --debug"      // Add debug information
            . ($this->option('upx') ? ' --with-upx-pack' : '');

        $this->comment("Running: {$buildCmd}");

        try {
            // Create a log file for build output
            $logFile = $spcPath . DIRECTORY_SEPARATOR . 'build.log';
            $this->info("Build output will be logged to: {$logFile}");

            $process = Process::timeout(3600) // 1 hour timeout
                ->env([
                    'PATH' => getenv('PATH'),
                    'TEMP' => getenv('TEMP'),
                    'TMP' => getenv('TMP'),
                    'VS_BUILDTOOLS' => getenv('VS_BUILDTOOLS'),
                    'PHP_SDK_VS' => getenv('PHP_SDK_VS'),
                    'PHP_SDK_ARCH' => getenv('PHP_SDK_ARCH'),
                    'SPC_DOWNLOAD_PATH' => getenv('SPC_DOWNLOAD_PATH')
                ])
                ->start($buildCmd);

            // Stream output to both console and log file
            while ($process->running()) {
                if ($output = $process->output()) {
                    $this->line($output);
                    file_put_contents($logFile, $output, FILE_APPEND);
                }
                if ($error = $process->errorOutput()) {
                    $this->error($error);
                    file_put_contents($logFile, "[ERROR] " . $error, FILE_APPEND);
                }
                sleep(1);
            }

            // Check final process status
            $result = $process->wait();
            if ($result === 0) {
                $bin = $sapi === 'micro' ? 'micro.sfx' : ($os === 'Windows' ? 'php.exe' : 'php');
                $this->info('Build successful!');
                $binPath = "{$spcPath}/buildroot/bin/{$bin}";
                $this->info("Binary at: {$binPath}");

                // Create zip file
                $zipName = "php-{$phpVersion}.zip";
                $zipPath = "vendor/nativephp/php-bin/bin/{$os}/x64/{$zipName}";

                if (!file_exists(dirname($zipPath))) {
                    mkdir(dirname($zipPath), 0777, true);
                }

                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
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

                return self::SUCCESS;
            }

            $this->error('Build failed! Check build.log for details');
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
