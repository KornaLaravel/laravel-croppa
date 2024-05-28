# Croppa

[![Packagist](https://img.shields.io/packagist/v/bkwld/croppa.svg)](https://packagist.org/packages/bkwld/croppa)

Croppa is a thumbnail generator bundle for Laravel. It follows a different approach from libraries that store your thumbnail dimensions in the model. Instead, the resizing and cropping instructions come from specially formatted URLs.

    /storage/uploads/09/03/screenshot.png

To produce a 300x200 thumbnail of this, you would change the path to:

    /storage/uploads/09/03/screenshot-300x200.png

This file, of course, doesn’t exist yet. Croppa listens for specifically formatted image routes and builds this thumbnail on the fly, outputting the image data (with correct headers) to the browser instead of returning a 404 response.

At the same time, it saves the newly cropped image to the disk in the same location (the "…-300x200.png" path) that you requested. As a result, **all future requests get served directly from the disk**, bypassing PHP and avoiding unnecessary overhead. In other words, **your app does not need to boot up just to serve an image**. This is a key differentiator compared to other similar libraries.

Since version 4.0, Croppa allows images to be stored on remote disks such as S3, Dropbox, FTP, and more, thanks to [Flysystem integration](http://flysystem.thephpleague.com/).

## Server Requirements:

- [gd](http://php.net/manual/en/book.image.php)
- [exif](http://php.net/manual/en/book.exif.php) - Required if you want to have Croppa auto-rotate images from devices like mobile phones based on exif meta data.

### Nginx

When using [Nginx HTTP server boilerplate configs](https://github.com/h5bp/server-configs-nginx), add `error_page 404 = /index.php?$query_string;` in the location block for Media, located in file h5bp/location/expires.conf.

```nginx
# Media: images, icons, video, audio, HTC
location ~* \.(?:jpg|jpeg|gif|png|ico|cur|gz|svg|svgz|mp4|ogg|ogv|webm|htc)$ {
  error_page 404 = /index.php?$query_string;
  expires 1M;
  access_log off;
  add_header Cache-Control "public";
}
```

## Installation

Add Croppa to your project: `composer require bkwld/croppa`

## Configuration

Read the [source of the config file](https://github.com/BKWLD/croppa/blob/master/src/config/config.php) for documentation of the config options. Here are some examples of common setups (additional [examples can be found here](https://github.com/BKWLD/croppa/wiki/Examples)):

You can publish the config file into your app’s config directory, by running the following command:

```php
php artisan vendor:publish --tag=croppa-config
```

#### Local src and crops directories

The most common scenario, the src images and their crops are created in the default ”public” Laravel disk.

```php
return [
    'src_disk' => 'public',
    'crops_disk' => 'public',
    'path' => 'storage/(.*)$',
];
```

Thus, if you have `<img src="{{ Croppa::url('file.jpg', 200) }}">`, the returned URL will be `/storage/file-200x_.jpg`, the source image will be looked for at `'/storage/app/public/file.jpg'`, and the new crop will be created at `'/storage/app/public/file-200x_.jpg'`. And because the URL generated by `Croppa::url()` points to the location where the crop was created, the web server (Apache, etc) will directly serve it on the next request (your app won’t boot just to serve an image).

#### Src images on S3, local crops

This is a good solution for a load balanced enviornment. Each app server will end up with it’s own cache of cropped images, so there is some wasted space. But the web server (Apache, etc) can still serve the crops directly on subsequent crop requests.

```php
// Croppa config.php
return [
    'src_disk' => 's3',
    'crops_disk' => 'public',
    'path' => 'storage/(.*)$',
];
```

Thus, if you have `<img src="{{ Croppa::url('file.jpg', 200, 100) }}">`, the returned URL will be `/storage/file-200x100.jpg`, the source image will be looked for immediately within the S3 bucket that was configured as part of the Flysystem instance, and the new crop will be created at `/storage/app/public/file-200x100.jpg`.

## Usage

The URL schema that Croppa uses is:

    /path/to/image-widthxheight-option1-option2(arg1,arg2).ext

So these are all valid:

    /storage/image-300x200.webp             // Crop to fit in 300x200
    /storage/image-_x200.webp               // Resize to height of 200px
    /storage/image-300x_.webp               // Resize to width of 300px
    /storage/image-300x200-resize.webp      // Resize to fit within 300x200
    /storage/image-300x200-quadrant(T).webp // See the quadrant description below

#### Croppa::url($url, $width, $height, $options)

To make preparing the URLs that Croppa expects an easier job, you can use the following view helper:

```php
<img src="{{ Croppa::url($url, $width, $height, $options) }}" />
<!-- Examples (that would produce the URLs above) -->
<img src="{{ Croppa::url('storage/image.webp', 300, 200) }}" />
<img src="{{ Croppa::url('storage/image.webp', null, 200) }}" />
<img src="{{ Croppa::url('storage/image.webp', 300) }}" />
<img src="{{ Croppa::url('storage/image.webp', 300, 200, ['resize']) }}" />
<img src="{{ Croppa::url('storage/image.webp', 300, 200, ['pad']) }}" />
<img src="{{ Croppa::url('storage/image.webp', 300, 200, ['pad' => [45,168,147]]) }}" />
<img src="{{ Croppa::url('storage/image.webp', 300, 200, ['quadrant' => 'T']) }}" />
<!-- Or, if there were multiple arguments for the last example -->
<img src="{{ Croppa::url('/uploads/image.png', 300, 200, ['quadrant' => ['T']]) }}" />
```

These are the arguments that Croppa::url() takes:

- $url : The URL of your source image. The path to the image relative to the `src_disk` will be extracted using the `path` config regex.
- $width : A number or null for wildcard
- $height : A number or null for wildcard
- $options - An array of key value pairs, where the value is an optional array of arguments for the option. Supported option are:
  - `resize` - Make the image fit in the provided width and height through resizing. When omitted, the default is to crop to fit in the bounds (unless one of sides is a wildcard).
  - `pad` - Pad an image to desired dimensions. Moves the image into the center and fills the rest with given color. If no color is given, it will use white [255,255,255]
  - `quadrant($quadrant)` - Crop the remaining overflow of an image using the passed quadrant heading. The supported `$quadrant` values are: `T` - Top (good for headshots), `B` - Bottom, `L` - Left, `R` - Right, `C` - Center (default).
  - `trim($x1, $y1, $x2, $y2)` - Crop the source image to the size defined by the two sets of coordinates ($x1, $y1, ...) BEFORE applying the $width and $height parameters. This is designed to be used with a frontend cropping UI like [jcrop](http://deepliquid.com/content/Jcrop.html) so that you can respect a cropping selection that the user has defined but then output thumbnails or sized down versions of that selection with Croppa.
  - `trim_perc($x1_perc, $y1_perc, $x2_perc, $y2_perc)` - Has the same effect as `trim()` but accepts coordinates as percentages. Thus, the the upper left of the image is "0" and the bottom right of the image is "1". So if you wanted to trim the image to half the size around the center, you would add an option of `trim_perc(0.25,0.25,0.75,0.75)`
  - `quality($int)` - Set the jpeg compression quality from 0 to 100.
  - `interlace($bool)` - Set to `1` or `0` to turn interlacing on or off
  - `upsize($bool)` - Set to `1` or `0` to allow images to be upsized. If falsey and you ask for a size bigger than the source, it will **only** create an image as big as the original source.

#### Croppa::render($cropurl)

If you want to create the image programmatically you can pass to this function the url generated by Croppa::url.
This will only create the thumbnail and exit.

```php
Croppa::render('image-300x200.png');
```

or

```php
Croppa::render(Croppa::url('image.png', 300, 200));
```

#### Croppa::delete($url)

You can delete a source image and all of its crops by running:

```php
Croppa::delete('/path/to/src.png');
```

#### Croppa::reset($url)

Similar to `Croppa::delete()` except the source image is preserved, only the crops are deleted.

```php
Croppa::reset('/path/to/src.png');
```

## Console commands

#### `croppa:purge`

Deletes **all** crops. This works by scanning the `crops_disk` recursively and matching all files that have the Croppa naming convention where a corresponding `src` file can be found. Accepts the following options:

- `--filter` - Applies a whitelisting regex filter to the crops. For example: `--filter=^01/` matches all crops in the "./public/uploads/01/" directory
- `--dry-run` - Ouputs the files that would be deleted to the console, but doesn’t actually remove

## croppa.js

A module is included to prepare formatted URLs from JS. This can be helpful when you are creating views from JSON responses from an AJAX request; you don’t need to format the URLs on the server. It can be loaded via Require.js, CJS, or as browser global variable.

### croppa.url(url, width, height, options)

Works just like the PHP `Croppa::url` except for how options get formatted (since JS doesn’t have associative arrays).

```js
croppa.url('/path/to/img.jpg', 300, 200, ['resize']);
croppa.url('/path/to/img.jpg', 300, 200, ['resize', { quadrant: 'T' }]);
croppa.url('/path/to/img.jpg', 300, 200, ['resize', { quadrant: ['T'] }]);
```

Run `php artisan asset:publish bkwld/croppa` to have Laravel copy the JS to your public directory. It will go to /public/packages/bkwld/croppa/js by default.

## History

Read the Github [project releases](https://github.com/BKWLD/croppa/releases) for release notes.

This package uses [Intervention Image](https://image.intervention.io/) to do all the image resizing. "Crop" is equivalent to its cover() method and "resize" is scale(). Support for interacting with non-local disks provided by [Flysystem](http://flysystem.thephpleague.com/).
