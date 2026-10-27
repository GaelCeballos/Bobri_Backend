<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Fotos de tickets: SIEMPRE privadas. Se ven solo con un enlace temporal firmado
         * (FotoTicket::urlTemporal) que se entrega al Administrador.
         * - Local (TICKETS_DRIVER=local): storage/app/private/tickets.
         * - Producción (TICKETS_DRIVER=s3): bucket privado de Cloudflare R2 (compatible con S3;
         *   requiere `composer require league/flysystem-aws-s3-v3`). R2 usa la región "auto".
         */
        'tickets' => env('TICKETS_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('TICKETS_R2_KEY'),
                'secret' => env('TICKETS_R2_SECRET'),
                'region' => 'auto',
                'bucket' => env('TICKETS_R2_BUCKET'),
                'endpoint' => env('TICKETS_R2_ENDPOINT'),
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/tickets'),
                // `serve` permite enlaces temporales firmados también en local. Cada disco servido
                // necesita su propia ruta (el disco `local` ya usa /storage)
                'serve' => true,
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/fotos-tickets',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
