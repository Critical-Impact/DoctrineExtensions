<?php

namespace Sluggable\Fixture;

use Gedmo\Sluggable\Sluggable;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class ConfigurationArticle implements Sluggable
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private $id;

    /**
     * @Gedmo\Sluggable
     * @ORM\Column(name="title", type="string", length=64)
     */
    private $title;

    /**
     * @Gedmo\Sluggable
     * @ORM\Column(name="code", type="string", length=16)
     */
    private $code;

    /**
     * @Gedmo\Slug(updatable=false, unique=false)
     * @ORM\Column(name="slug", type="string", length=32)
     */
    private $slug;

    public function getId()
    {
        return $this->id;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setCode($code)
    {
        $this->code = $code;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function getSlug()
    {
        return $this->slug;
    }
}