<?php declare(strict_types=1);

// TODO: Add support for generating an icon from embedded cover art
// TODO: MORE AUDIO FORMATS

class ModelFileHandler extends DataHandlerExtension
{
    protected $SUPPORTED_MIME = [MimeType::STL];

    protected function media_check_properties(MediaCheckPropertiesEvent $event): void
    {
        $event->image->audio = false;
        $event->image->video = false;
        $event->image->lossless = true;
        $event->image->image = false;
        $event->image->width = 0;
        $event->image->height = 0;
        // TODO: ->length = ???
    }

    protected function create_thumb(string $hash, string $type): bool
    {
        try {
            $render3d = new \Libre3d\Render3d\Render3d();

            $temp_path = tempnam(sys_get_temp_dir(), "shimmie_model_thumb");


            $render3d->workingDir($temp_path);

            $render3d->executable('povray', '/path/to/povray');


            $file = warehouse_path(Image::IMAGE_DIR, $hash);
            $thumbFile = warehouse_path(Image::THUMBNAIL_DIR, $hash);

            $render3d->filename($file);

            $renderedImagePath = $render3d->render('povray');

            $orig_size = getimagesize($renderedImagePath);
            $scaled_size = get_thumbnail_size($orig_size[0], $orig_size[1], true);

            create_scaled_image($renderedImagePath, $thumbFile, $scaled_size, MimeType::PNG);
        } catch (Exception $e) {
            log_warning(ModelFileHandlerInfo::KEY, "Error while rendering STL file: ".$e->getMessage());
            copy("ext/handle_model/thumb.jpg", warehouse_path(Image::THUMBNAIL_DIR, $hash));
        }
        return true;
    }

    protected function check_contents(string $tmpname): bool
    {
        return MimeType::get_for_file($tmpname) === MimeType::MP3;
    }
}
