<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
* @ORM\Entity()
* @ORM\Table(name="collaborators")
* */
class Collaborator
{
    /**
    * @ORM\Id()
    * @ORM\GeneratedValue(strategy="AUTO")
    * @ORM\Column(type="integer")
    */
    public $id;

    /**
     * @ORM\Column(type="string")
     * @Assert\NotBlank(message = "le contenu ne peut pas être vide.")
     */
    public $name;
    // on simple tables (id, name) for suppliers, units, collaborators, domains and emails there are checks discard empty input but not in more complex because adding empty data and then fill them is more user friendly (even if data are not in a proper state during a few seconds)

    /**
     * @ORM\Column(type="integer")
     */
    public $unitId;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getUnitId()
    {
        return $this->unitId;
    }

    public function setUnitId($unitId)
    {
        $this->unitId = $unitId;
    }
}
