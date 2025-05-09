<?php

return [
    // Default path to static-php-cli installation
    'default_path' => 'D:\\custom-static-php\\static-php-cli',

    // Supported PHP extensions
    'available_extensions' => [
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
        'pdo_sqlsrv',
    ],

    // Required libraries for building extensions
    'required_libraries' => [
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
        'libzip',
    ],
];