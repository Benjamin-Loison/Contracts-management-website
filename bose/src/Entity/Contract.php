<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use DateTime;

/**
* @ORM\Entity()
* @ORM\Table(name="contracts")
* */
class Contract
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
	public $number;

    /**
    * @ORM\Column(type="datetime")
    */
	public $date;

	/**
	 * @ORM\Column(type="integer")
	 */
	public $supplierId;

	/**
	 * @Assert\NotBlank(message = "le contenu ne peut pas être vide.")
	 * @ORM\Column(type="string", length=1000)
	 */
	public $content;

	/**
	 * @ORM\Column(type="integer")
	 */
	public $contractLeaderId;

	/**
	 * @ORM\Column(type="integer")
	 */
	public $applicativeLeaderId;

	/**
	 * @ORM\Column(type="integer")
	 */
	public $domainId;

	/**
     * @ORM\Column(type="integer")
     */
    public $active;

	/**
     * @ORM\Column(type="datetime")
     */
    public $modificationDate;

	/**
	 * @ORM\Column(type="integer")
	 */
	public $modificationUserId;

	public function __construct()
	{
		$this->date = new DateTime(); // likewise when we create a Contract instance the date and modification date are the current date
		$this->modificationDate = new DateTime();
	}

	public function getId()
	{
		return $this->id;
	}

	public function setId($id)
	{
		$this->id = $id;
	}

	public function getNumber()
	{
		return $this->number;
	}

	public function setNumber($number)
	{
		$this->number = $number;
	}

	public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
	}

	public function getSupplierId()
	{
		return $this->supplierId;
	}

	public function setSupplierId($supplierId)
	{
		$this->supplierId = $supplierId;
	}

	public function getContent()
	{
		return $this->content;
	}

	public function setContent($content)
	{
		$this->content = $content;
	}

	public function getContractLeaderId()
	{
		return $this->contractLeaderId;
	}

	public function setContractLeaderId($contractLeaderId)
	{
		$this->contractLeaderId = $contractLeaderId;
	}

	public function getApplicativeLeaderId()
    {
        return $this->applicativeLeaderId;
    }

    public function setApplicativeLeaderId($applicativeLeaderId)
    {
        $this->applicativeLeaderId = $applicativeLeaderId;
	}

	public function getDomainId()
	{
		return $this->domainId;
	}

	public function setDomainId($domainId)
	{
		$this->domainId = $domainId;
	}

	public function getActive()
    {
        return $this->active;
    }

    public function setActive($active)
    {
        $this->active = $active;
    }

	public function getModificationDate()
    {
        return $this->modificationDate;
    }

    public function setModificationDate($modificationDate)
    {
        $this->modificationDate = $modificationDate;
    }

	public function getModificationUserId()
	{
		return $this->modificationUserId;
	}

	public function setModificationUserId($modificationUserId)
	{
		$this->modificationUserId = $modificationUserId;
	}
}
