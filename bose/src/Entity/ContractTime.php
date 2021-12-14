<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
* @ORM\Entity()
* @ORM\Table(name="contractTimes")
* */
class ContractTime 
{

	// the ORM annotation system doesn't allow multiplive variables declaration in a single line (having the same characteristics)

    /**
    * @ORM\Id()
    * @ORM\GeneratedValue(strategy="AUTO")
    * @ORM\Column(type="integer")
    */
    public $id;

	/**
	* @ORM\Column(type="integer")
	*/
	public $contractId;

	/**
    * @ORM\Column(type="datetime")
    */
	public $beginDate;

	/**
    * @ORM\Column(type="datetime")
    */
	public $endDate;

	/**
	* @ORM\Column(type="float")
	*/
	public $amount;

	/**
    * @ORM\Column(type="integer")
    */
	public $marketId;

	/**
    * @ORM\Column(type="integer")
    */
	public $commandId;

	/**
    * @ORM\Column(type="integer")
    */
	public $posteId;

	/**
    * @ORM\Column(type="string")
    */
	public $buyId;
	
	/**
    * @ORM\Column(type="string")
    */
	public $comment;

	public function getId()
	{
		return $this->id;
	}

	public function setId($id)
	{
		$this->id = $id;
	}

	public function getContractId()
    {
        return $this->contractId;
    }

    public function setContractId($contractId)
    {
        $this->contractId = $contractId;
    }

	public function getBeginDate()
	{
		return $this->beginDate;
	}

	public function setBeginDate($beginDate)
	{
		$this->beginDate = $beginDate;
	}

	public function getEndDate()
    {
        return $this->endDate;
    }

    public function setEndDate($endDate)
    {
        $this->endDate = $endDate;
	}

	public function getAmount()
	{
		return $this->amount;
	}

	public function setAmount($amount)
	{
		$this->amount = $amount;
	}

	public function getBuyId()
	{
		return $this->buyId;
	}

	public function setBuyId($buyId)
	{
		$this->buyId = $buyId;
	}

	public function getMarketId()
    {
        return $this->marketId;
    }

    public function setMarketId($marketId)
    {
        $this->marketId = $marketId;
    }

	public function getCommandId()
    {
        return $this->commandId;
    }

    public function setCommandId($commandId)
    {
        $this->commandId = $commandId;
    }

	public function getPosteId()
    {
        return $this->posteId;
    }

    public function setPosteId($posteId)
    {
        $this->posteId = $posteId;
    }

	public function getComment()
    {
        return $this->comment;
    }

    public function setComment($comment)
    {
        $this->comment = $comment;
    }
}
