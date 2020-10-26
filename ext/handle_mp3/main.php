<?php declare(strict_types=1);

// TODO: Add support for generating an icon from embedded cover art
// TODO: MORE AUDIO FORMATS

class MP3FileHandler extends DataHandlerExtension
{
    protected $SUPPORTED_MIME = [MimeType::MP3];

    protected function media_check_properties(MediaCheckPropertiesEvent $event): void
    {
        $event->image->audio = true;
        $event->image->video = false;
        $event->image->lossless = false;
        $event->image->image = false;
        $event->image->width = 0;
        $event->image->height = 0;
        // TODO: ->length = ???
    }

    protected function create_thumb(string $hash, string $type): bool
    {
        copy("ext/handle_mp3/thumb.jpg", warehouse_path(Post::THUMBNAIL_DIR, $hash));
        return true;
    }

    protected function check_contents(string $tmpname): bool
    {
        return MimeType::get_for_file($tmpname) === MimeType::MP3;
    }
}
