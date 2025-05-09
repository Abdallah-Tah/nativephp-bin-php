<?php

namespace Amohamed\NativePhpCustomPhp\Commands;

use Illuminate\Console\Command;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Progress;
use function Laravel\Prompts\multiselect;
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

    protected $requiredLibraries = [
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

    protected $defaultPath = 'D:\custom-static-php\static-php-cli';

    protected function detectOS(): string
    {
        $osFamily = PHP_OS_FAMILY;
        return match ($osFamily) {
            'Windows' => 'Windows',
            'Darwin' => 'macOS',
            'Linux' => 'Linux',
            default => throw new RuntimeException("Unsupported operating system: {$osFamily}")
        };
    }

    public function handle(): int
    {
        $this->validateEnvironment();

        $spcPath = $this->option('path') ?: $this->defaultPath;
        if (!file_exists($spcPath)) {
            throw new RuntimeException("static-php-cli not found at {$spcPath}. Please install it first from https://github.com/crazywhalecc/static-php-cli");
        }

        $os = $this->detectOS();
        $this->info("Detected operating system: {$os}");

        if ($os === 'Windows') {
            $this->checkWindowsRequirements();
        }

        // Download required libraries first
        $this->downloadRequiredLibraries($spcPath);

        $extensions = multiselect(
            'Which PHP extensions would you like to install?',
            $this->availableExtensions
        );

        if (empty($extensions)) {
            $this->warn('No extensions selected.');
            return self::SUCCESS;
        }

        return $this->buildPhp($extensions, $os, $spcPath);
    }

    protected function downloadRequiredLibraries(string $spcPath): void
    {
        $this->info('Checking and downloading required libraries...');

        foreach ($this->requiredLibraries as $library) {
            $this->info("Checking library: {$library}");

            // Try building to see if library exists
            $process = Process::start("{$spcPath}\\bin\\spc build-library {$library}");

            while ($process->running()) {
                if ($process->latestOutput() && str_contains($process->latestOutput(), 'not downloaded or not locked')) {
                    $this->warn("Library {$library} not found, downloading...");

                    // Download the library
                    $downloadProcess = Process::start("{$spcPath}\\bin\\spc download {$library}");

                    while ($downloadProcess->running()) {
                        if ($downloadProcess->latestOutput()) {
                            $this->line($downloadProcess->latestOutput());
                        }
                        sleep(1);
                    }

                    if ($downloadProcess->status() === 0) {
                        $this->info("Successfully downloaded {$library}");
                    } else {
                        $this->error("Failed to download {$library}. Error: " . $downloadProcess->errorOutput());
                        if (!$this->confirm("Would you like to continue without {$library}?")) {
                            throw new RuntimeException("Cannot continue without required library: {$library}");
                        }
                    }

                    break;
                }
                sleep(1);
            }
        }
    }

    protected function buildPhp(array $extensions, string $os, string $spcPath): int
    {
        $extensionsString = implode(',', $extensions);
        $sapi = $this->option('sapi');
        $phpVersion = $this->option('php-version');

        $this->info("Building PHP {$phpVersion} with selected extensions using static-php-cli...");
        $this->info('This may take several minutes...');

        $command = $os === 'Windows'
            ? "{$spcPath}\\bin\\spc"
            : "{$spcPath}/bin/spc";

        $command .= " build \"{$extensionsString}\" --build-{$sapi}";

        if ($this->option('upx')) {
            $command .= ' --with-upx';
        }

        if ($sapi === 'micro') {
            $command .= ' --with-micro';
        }

        $this->comment('Running command: ' . $command);

        $process = Process::start($command);
        $currentLibrary = null;

        while ($process->running()) {
            $output = $process->latestOutput();
            if ($output) {
                $this->line($output);

                // Check for library download/build issues
                if (preg_match('/Building required lib \[(\w+)\]/', $output, $matches)) {
                    $currentLibrary = $matches[1];
                } elseif ($currentLibrary && str_contains($output, 'not downloaded or not locked')) {
                    $this->warn("Library {$currentLibrary} missing, attempting to download...");

                    // Try to download the missing library
                    $downloadProcess = Process::start("{$spcPath}\\bin\\spc download {$currentLibrary}");
                    while ($downloadProcess->running()) {
                        if ($downloadProcess->latestOutput()) {
                            $this->line($downloadProcess->latestOutput());
                        }
                        sleep(1);
                    }

                    if ($downloadProcess->status() === 0) {
                        $this->info("Successfully downloaded {$currentLibrary}, continuing build...");
                        // Restart the main build process
                        $process = Process::start($command);
                    } else {
                        $this->error("Failed to download {$currentLibrary}. You may need to download it manually.");
                        if (!$this->confirm("Would you like to continue anyway?")) {
                            return self::FAILURE;
                        }
                    }
                }
            }
            sleep(1);
        }

        if ($process->status() === 0) {
            $binaryPath = $sapi === 'micro' ? 'micro.sfx' : ($os === 'Windows' ? 'php.exe' : 'php');
            $this->info('Build completed successfully!');
            $this->info("Your custom PHP binary with selected extensions is available at: {$spcPath}" .
                ($os === 'Windows' ? "\\buildroot\\bin\\{$binaryPath}" : "/buildroot/bin/{$binaryPath}"));

            // Create a zip file for the built PHP binary
            $zipFileName = "php-{$phpVersion}.zip";
            $zipFilePath = "vendor/nativephp/php-bin/bin/{$os}/x64/{$zipFileName}";

            $zip = new ZipArchive();
            if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $binaryFullPath = $spcPath . ($os === 'Windows' ? "\\buildroot\\bin\\{$binaryPath}" : "/buildroot/bin/{$binaryPath}");
                $zip->addFile($binaryFullPath, $binaryPath);
                $zip->close();

                $this->info("PHP binary has been zipped as {$zipFileName} and placed at {$zipFilePath}");
            } else {
                $this->error("Failed to create zip file at {$zipFilePath}");
            }

            if ($sapi === 'micro') {
                $this->info('To create a self-contained executable, use:');
                if ($os === 'Windows') {
                    $this->info("copy /b {$spcPath}\\buildroot\\bin\\micro.sfx + your-app.php app.exe");
                } else {
                    $this->info("cat {$spcPath}/buildroot/bin/micro.sfx your-app.php > app && chmod +x app");
                }
            }

            return self::SUCCESS;
        } else {
            $this->error('Build failed. Please check the error messages above.');
            return self::FAILURE;
        }
    }

    protected function checkWindowsRequirements(): void
    {
        // Check for Visual Studio
        $vsWhere = 'C:\Program Files (x86)\Microsoft Visual Studio\Installer\vswhere.exe';
        if (!file_exists($vsWhere)) {
            throw new RuntimeException(
                "Visual Studio not found. Please install Visual Studio 2022 with:\n" .
                "- Desktop development with C++\n" .
                "- Windows 10/11 SDK\n" .
                "- MSVC v143 VS 2022 C++ x64/x86 build tools\n" .
                "- Windows Universal CRT SDK"
            );
        }

        // You can add more Windows-specific requirement checks here
    }

    protected function validateEnvironment(): void
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new RuntimeException('PHP >= 8.1 is required to run this command.');
        }

        if (!extension_loaded('mbstring')) {
            throw new RuntimeException('The mbstring extension is required.');
        }

        if (!extension_loaded('tokenizer')) {
            throw new RuntimeException('The tokenizer extension is required.');
        }
    }
}