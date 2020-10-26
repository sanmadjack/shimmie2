<?php declare(strict_types=1);

class ImageInfoSetEvent extends Event
{
    /** @var Post */
    public $image;

    public function __construct(Post $image)
    {
        parent::__construct();
        $this->image = $image;
    }
}
