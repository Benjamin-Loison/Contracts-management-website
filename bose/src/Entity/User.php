<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use DateTime;

/**
* @ORM\Entity()
* @ORM\Table(name="users")
* */
class User
{
    /**
	 * @ORM\Id()
	 * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer")
     */
    public $id;

	/**
	 * @ORM\Column(type="string")
	 */
	public $userId;

    /**
	 * @ORM\Column(type="integer")
	 */
    public $permissionLevel;

	/**
    * @ORM\Column(type="datetime")
    */
	public $creationDate;

	/**
    * @ORM\Column(type="datetime")
    */
	public $expirationDate;

	// let's not use an active parameter because it would in many cases not be perfectly up to date so we just compute it when required

	public function __construct()
    {
        $this->creationDate = new DateTime();
    }

	public function getId()
	{
		return $this->id;
	}

	public function setId($id)
	{
		$this->id = $id;
	}

	public function getUserId()
    {
        return $this->userId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
	}

	public function getPermissionLevel()
    {
        return $this->permissionLevel;
    }

    public function setPermissionLevel($permissionLevel)
    {
        $this->permissionLevel = $permissionLevel;
	}

	public function getCreationDate()
	{
		return $this->creationDate;
	}

	public function setCreationDate($creationDate)
	{
		$this->creationDate = $creationDate;
	}

	public function getExpirationDate()
	{
		return $this->expirationDate;
	}

	public function setExpirationDate($expirationDate)
	{
		$this->expirationDate = $expirationDate;
	}
}
