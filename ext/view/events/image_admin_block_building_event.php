<?php declare(strict_types=1);

class ImageAdminBlockBuildingEvent extends Event
{
    /** @var string[] */
    public $parts = [];
    /** @var Post  */
    public $image = null;
    /** @var User  */
    public $user = null;

    public function __construct(Post $image, User $user)
    {
        parent::__construct();
        $this->image = $image;
        $this->user = $user;
    }

    public function add_part(string $html, int $position=50)
    {
        while (isset($this->parts[$position])) {
            $position++;
        }
        $this->parts[$position] = $html;
    }
}
