# Delete files when time is right

[![Latest Version on Packagist](https://img.shields.io/packagist/v/medilies/rm-q.svg?style=flat-square)](https://packagist.org/packages/medilies/rm-q)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/medilies/rm-q/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/medilies/rm-q/actions?query=workflow%3Arun-tests+branch%3Amain)
[![phpstan](https://img.shields.io/github/actions/workflow/status/medilies/rm-q/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/medilies/rm-q/actions?query=workflow%3A"phpstan"+branch%3Amain)
<!-- [![Total Downloads](https://img.shields.io/packagist/dt/medilies/rm-q.svg?style=flat-square)](https://packagist.org/packages/medilies/rm-q) -->

Since file deletion is often irreversible, this Laravel package queues file deletions within a database transaction, allowing for rollback in case of errors.

## The problem

Let's say you have a use case that resembles this:

```php
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

DB::transaction(function () use ($fileIds) {
    $images = Image::whereIn('id', $fileIds)->get();

    foreach ($images as $image) {
        if (Storage::exists($image->path)) {
            Storage::delete($image->path);
        }

        // more logic ...

        $image->delete();
    }
});
```

If an error occurs while handling the second image, the database rows for both the first and second images will be rolled back by the transaction, but the actual file for the first image will be gone forever.

## The solution

```php
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;

DB::transaction(function () use ($fileIds) {
    $images = Image::whereIn('id', $fileIds)->get();

    $files = [];
    foreach ($images as $image) {
        if (Storage::exists($image->path)) {
            $files[] = $image->path;
        }

        // more logic ...

        $image->delete();
    }

    RmQ::stage($files);
});
```

This way, the file deletion is queued and the deletion can be fully rolled back.

## Installation

Requirements:

- PHP >= 8.2
- Laravel >= 10 (not tested on lower versions)

Install the package via composer:

```bash
composer require medilies/rm-q
```

Publish and run the migrations with:

```bash
php artisan vendor:publish --tag="rm-q-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="rm-q-config"
```

## Usage

### Phase 1: Staging the files

```php
use Medilies\RmQ\Facades\RmQ;

DB::transaction(function () {
    // ...
    
    $files = '/path/to/file';
    // or
    $files = [
        '/path/to/file1',
        '/path/to/file2',
    ];

    // ...

    RmQ::stage($files);
});
```

> [!WARNING]  
> Make sure to not call `Rmq::stage` within a loop since each call does a database insertion.

### Phase 2: Deleting the files

Delete the files staged by the singleton:

```php
use Medilies\RmQ\Facades\RmQ;

RmQ::delete();
```

Delete all the staged files:

```php
use Medilies\RmQ\Facades\RmQ;

RmQ::deleteAll();
```

Delete all the staged files using a command (you can also [schedule](https://laravel.com/docs/11.x/scheduling#scheduling-artisan-commands) it):

```shell
php artisan rm-q:delete
```

> `deleteAll` takes into consideration the `after` config to fetch staged entries.

Automatically delete the staged files at the end of the request using the middleware:

```php
use Medilies\RmQ\Middleware\RmqMiddleware;

Route::put('edit-user-details', function (Request $request) {
    // ...
})->middleware(RmqMiddleware::class);
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [medilies](https://github.com/medilies)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
