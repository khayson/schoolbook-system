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

        // Nightly database backups (spatie/laravel-backup). Not web-accessible.
        'backups' => [
            'driver' => 'local',
            'root' => env('BACKUP_LOCAL_ROOT', storage_path('app/backups')),
            'throw' => true,
        ],

        // Off-server copy. Defaults to S3-compatible storage (AWS, Backblaze B2, Wasabi,
        // DigitalOcean Spaces); requires `composer require league/flysystem-aws-s3-v3`.
        // Enable with BACKUP_DISKS=backups,offsite. See docs/operations.md.
        'offsite' => [
            'driver' => env('BACKUP_OFFSITE_DRIVER', 's3'),
            'key' => env('BACKUP_OFFSITE_KEY'),
            'secret' => env('BACKUP_OFFSITE_SECRET'),
            'region' => env('BACKUP_OFFSITE_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_OFFSITE_BUCKET'),
            'endpoint' => env('BACKUP_OFFSITE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_OFFSITE_PATH_STYLE', false),
            'root' => env('BACKUP_OFFSITE_ROOT', ''),
            'throw' => true,
        ],

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
