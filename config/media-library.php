<?php

use App\Utils\Media\ConversionFileNamer;
use App\Utils\Media\PathGenerator;
use Spatie\ImageOptimizer\Optimizers;
use Spatie\MediaLibrary\Conversions\ImageGenerators;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

return [

    /*
     * The disk on which to store added files and derived images by default. Choose
     * one or more of the disks you've configured in config/filesystems.php.
     */
    'disk_name' => env('MEDIA_DISK', 'local'),

    /*
     * The maximum file size of an item in bytes.
     * Adding a larger file will result in an exception.
     */
    'max_file_size' => 1024 * 1024 * 10,

    /*
     * This queue will be used to generate derived and responsive images.
     * Leave empty to use the default queue.
     */
    'queue_name' => '',

    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => Media::class,

    'remote' => [
        /*
         * Any extra headers that should be included when uploading media to
         * a remote disk. Even though supported headers may vary between
         * different drivers, a sensible default has been provided.
         *
         * Supported by S3: CacheControl, Expires, StorageClass,
         * ServerSideEncryption, Metadata, ACL, ContentEncoding
         */
        'extra_headers' => [
            'CacheControl' => 'max-age=604800',
        ],
    ],

    'responsive_images' => [

        /*
         * This class is responsible for calculating the target widths of the responsive
         * images. By default we optimize for filesize and create variations that each are 20%
         * smaller than the previous one. More info in the documentation.
         *
         * https://docs.spatie.be/laravel-medialibrary/v8/advanced-usage/generating-responsive-images
         */
        'width_calculator' => FileSizeOptimizedWidthCalculator::class,

        /*
         * By default rendering media to a responsive image will add some javascript and a tiny placeholder.
         * This ensures that the browser can already determine the correct layout.
         */
        'use_tiny_placeholders' => true,

        /*
         * This class will generate the tiny placeholder used for progressive image loading. By default
         * the medialibrary will use a tiny blurred jpg image.
         */
        'tiny_placeholder_generator' => Blurred::class,
    ],

    /*
     * When converting Media instances to response the medialibrary will add
     * a `loading` attribute to the `img` tag. Here you can set the default
     * value of that attribute.
     *
     * Possible values: 'auto', 'lazy' and 'eager,
     *
     * More info: https://css-tricks.com/native-lazy-loading/
     */
    'default_loading_attribute_value' => 'auto',

    /*
     * This is the class that is responsible for naming conversion files. By default,
     * it will use the filename of the original and concatenate the conversion name to it.
     */
    'conversion_file_namer' => ConversionFileNamer::class,

    /*
     * The class that contains the strategy for determining a media file's path.
     */
    'path_generator' => PathGenerator::class,

    /*
     * When urls to files get generated, this class will be called. Use the default
     * if your files are stored locally above the site root or on s3.
     */
    'url_generator' => DefaultUrlGenerator::class,

    /*
     * Whether to activate versioning when urls to files get generated.
     * When activated, this attaches a ?v=xx query string to the URL.
     */
    'version_urls' => false,

    /*
     * The media library will try to optimize all converted images by removing
     * metadata and applying a little bit of compression. These are
     * the optimizers that will be used by default.
     */
    'image_optimizers' => [
        Optimizers\Jpegoptim::class => [
            '--strip-all', // this strips out all text information such as comments and EXIF data
            '--all-progressive', // this will make sure the resulting image is a progressive one
        ],
        Optimizers\Pngquant::class => [
            '--force', // required parameter for this package
        ],
        Optimizers\Optipng::class => [
            '-i0', // this will result in a non-interlaced, progressive scanned image
            '-o2', // this set the optimization level to two (multiple IDAT compression trials)
            '-quiet', // required parameter for this package
        ],
        Optimizers\Svgo::class => [
            '--disable=cleanupIDs', // disabling because it is known to cause troubles
        ],
        Optimizers\Gifsicle::class => [
            '-b', // required parameter for this package
            '-O3', // this produces the slowest but best results
        ],
    ],

    /*
     * These generators will be used to create an image of media files.
     */
    'image_generators' => [
        ImageGenerators\Image::class,
        ImageGenerators\Webp::class,
        ImageGenerators\Pdf::class,
        ImageGenerators\Svg::class,
        ImageGenerators\Video::class,
    ],

    /*
     * The engine that should perform the image conversions.
     * Should be either `gd` or `imagick`.
     */
    'image_driver' => env('IMAGE_DRIVER', 'gd'),

    /*
     * FFMPEG & FFProbe binaries paths, only used if you try to generate video
     * thumbnails and have installed the php-ffmpeg/php-ffmpeg composer
     * dependency.
     */
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),

    /*
     * The path where to store temporary files while performing image conversions.
     * If set to null, storage_path('media-library/temp') will be used.
     */
    'temporary_directory_path' => null,

    /*
     * Here you can override the class names of the jobs used by this package. Make sure
     * your custom jobs extend the ones provided by the package.
     */
    'jobs' => [
        'perform_conversions' => PerformConversionsJob::class,
        'generate_responsive_images' => GenerateResponsiveImagesJob::class,
    ],
];
