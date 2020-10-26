<?php declare(strict_types=1);

/*
 * DisplayingImageEvent:
 *   $image -- the image being displayed
 *   $page  -- the page to display on
 *
 * Sent when an image is ready to display. Extensions who
 * wish to appear on the "view" page should listen for this,
 * which only appears when an image actually exists.
 */
class DisplayingImageEvent extends Event
{
    /** @var Post  */
    public $image;

    public function __construct(Post $image)
    {
        parent::__construct();
        $this->image = $image;
    }

    public function get_image(): Post
    {
        return $this->image;
    }
}
