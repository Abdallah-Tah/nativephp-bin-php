# NativePHP Custom PHP Extensions Installer

This Laravel package provides a command-line tool to install PHP extensions for NativePHP using the `static-php-cli` tool. It supports building custom PHP binaries with selected extensions for various operating systems.

## Features

- Detects the operating system (Windows, macOS, Linux).
- Downloads and builds required libraries for PHP extensions.
- Supports multiple PHP extensions, including `pdo`, `curl`, `mbstring`, and more.
- Provides an interactive multi-select prompt for choosing extensions.
- Compatible with NativePHP and `static-php-cli`.

## Requirements

- PHP >= 8.1
- Laravel Framework >= 10.0
- `static-php-cli` installed on your system.
- Required PHP extensions: `mbstring`, `tokenizer`.

## Installation

### Prerequisites

Before installing this package, ensure you have the following:

- **PHP**: Version 8.1 or higher.
- **Composer**: Installed globally on your system.
- **Laravel Framework**: Version 10.0 or higher.
- **static-php-cli**: Download and install from [static-php-cli GitHub repository](https://github.com/crazywhalecc/static-php-cli).

### Step-by-Step Installation

1. **Install the Package**

   Run the following command to install the package via Composer:

   ```bash
   composer require amohamed/nativephp-custom-php
   ```

2. **Publish the Service Provider (Optional)**

   If you want to customize the package configuration, publish the service provider:

   ```bash
   php artisan vendor:publish --provider="Amohamed\NativePhpCustomPhp\NativePhpCustomPhpServiceProvider"
   ```

3. **Verify Installation**

   Ensure the package is installed correctly by running:

   ```bash
   php artisan list
   ```

   You should see the `php-ext:install` command listed.

## NativePHP Requirement

This package requires [NativePHP](https://nativephp.dev/) to be installed and configured in your Laravel project. NativePHP is a framework for building native desktop applications using Laravel.

### Installing NativePHP

1. **Add NativePHP to Your Project**

   Run the following command to install NativePHP:

   ```bash
   composer require nativephp/nativephp
   ```

2. **Publish NativePHP Assets**

   Publish the NativePHP configuration and assets:

   ```bash
   php artisan nativephp:install
   ```

3. **Verify Installation**

   Ensure NativePHP is installed correctly by running:

   ```bash
   php artisan nativephp:serve
   ```

   This will start the NativePHP development server.

### Using This Package with NativePHP

Once NativePHP is installed, you can use this package to build custom PHP binaries with the required extensions for your NativePHP application. Follow the usage instructions provided above to install and configure the extensions.

## Usage

1. **Run the Command**

   Execute the following Artisan command to start the installation process:

   ```bash
   php artisan php-ext:install
   ```

2. **Follow the Prompts**

   - The tool will detect your operating system.
   - It will prompt you to select the PHP extensions you want to install using an interactive multi-select menu.

3. **Wait for the Process to Complete**

   - The tool will download required libraries and build the selected extensions.
   - Upon completion, it will generate a custom PHP binary with the selected extensions.

4. **Locate the Custom PHP Binary**

   The custom PHP binary will be available in the `buildroot/bin` directory under your `static-php-cli` installation path.

## Troubleshooting

- **Missing static-php-cli**: Ensure `static-php-cli` is installed and accessible in your system's PATH.
- **PHP Version Issues**: Verify that your PHP version meets the minimum requirement (>= 8.1).
- **Required Extensions**: Ensure `mbstring` and `tokenizer` extensions are enabled in your PHP installation.

For additional help, open an issue on the [GitHub repository](https://github.com/Abdallah-Tah/nativephp-php-custom).

## Testing

Run the tests using PHPUnit:

```bash
composer test
```

## Contributing

Contributions are welcome! Please submit a pull request or open an issue on the [GitHub repository](https://github.com/Abdallah-Tah/nativephp-php-custom).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
