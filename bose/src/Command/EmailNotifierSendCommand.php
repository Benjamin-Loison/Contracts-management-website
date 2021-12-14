<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

use App\Entity\Mail;
use App\Entity\Contract;
use App\Entity\ContractTime;

use Symfony\Component\DependencyInjection\ContainerInterface;

class EmailNotifierSendCommand extends Command
{
	protected static $defaultName = 'app:email-notifier:send';
	// to test this command use: ~/bose$ php bin/console app:email-notifier:send
    protected static $defaultDescription = 'Sending email notifications';

	private $mailer;
	private $container;

	public function __construct(MailerInterface $mailer, ContainerInterface $container)
	{
		parent::__construct(null);
		$this->mailer = $mailer;
		$this->container = $container;
	}

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

		$doctrine = $this->container->get('doctrine');

		$emails = [];
		$repository = $doctrine->getRepository(Mail::class);
		$mails = $repository->findAll();
		$mailsCount = count($mails);
		for($mailsIndex = 0; $mailsIndex < $mailsCount; $mailsIndex++)
		{
			$mail = $mails[$mailsIndex];
			$email = $mail->getName();
			array_push($emails, $email);
		}

		// copied from index function code in src/Controller/HomeController.php
		$repository = $doctrine->getRepository(Contract::class);
		$repositoryCT = $doctrine->getRepository(ContractTime::class);
        $contracts = $repository->findAll();
        $contractsCount = count($contracts);

		$emailDaysBeforeExpiration = intval(file_get_contents('private/daysBeforeMail.txt'));

        $contractsToRenew = [];
        for($contractsIndex = 0; $contractsIndex < $contractsCount; $contractsIndex++)
        {
            $contract = $contracts[$contractsIndex];
            $beginDates = [];
            $endDates = [];
            $contractTimes = $repositoryCT->findBy(['contractId' => $contract->getId()]);
            $contractTimesCount = count($contractTimes);
            for($contractTimesIndex = 0; $contractTimesIndex < $contractTimesCount; $contractTimesIndex++)
            {
                $contractTime = $contractTimes[$contractTimesIndex];
                array_push($beginDates, $contractTime->getBeginDate());
                array_push($endDates, $contractTime->getEndDate());
            }

            $earliestEndDate = null;
            $endDatesCount = count($endDates);
            for($endDatesIndex = 0; $endDatesIndex < $endDatesCount; $endDatesIndex++)
            {
                $endDate = $endDates[$endDatesIndex];
                if(!in_array($endDate, $beginDates))
				{
					$expiringSoon = $endDate->diff(new \DateTime())->days + 1 /*<*/== $emailDaysBeforeExpiration;
					if($expiringSoon)
                    {
                        if($endDate < $earliestEndDate || $earliestEndDate == null)
                        {
                            $earliestEndDate = $endDate;
                        }
                    }
                }
            }
            if($earliestEndDate != null)
            {
				$contractToRenew = $earliestEndDate->format('d/m/Y') . ' | ' . $contract->getContent();
				array_push($contractsToRenew, $contractToRenew);
            }
		}

		$res = implode("\n", $contractsToRenew);
		if($res != '')
		{
			$content = file_get_contents('.env');
			$lines = explode("\n", $content);
			$linesCount = count($lines);
			$emailFrom = '';
			$prefix = 'MAILER_DSN=smtp://';
			for($linesIndex = 0; $linesIndex < $linesCount; $linesIndex++)
			{
				$line = $lines[$linesIndex];
				if(str_starts_with($line, $prefix))
				{
					$line = str_replace($prefix, '', $line);
					$lineParts = explode(':', $line);
					$linePartsCount = count($lineParts);
					if($linePartsCount >= 3)
					{
						$emailFrom = $lineParts[0];
						break;
					}
				}
			}
			$emailsCount = count($emails);
			for($emailsIndex = 0; $emailsIndex < $emailsCount; $emailsIndex++)
			{
				$email = (new Email())
                	->from(new Address($emailFrom, 'BOSE')) // make an automatic read of .env to change the name ? or can we define the name without giving the email ?
                	->to($emails[$emailsIndex])
                	->subject('BOSE: expiration de contrats')
                	->text("Bonjour\n\nLes contrats suivant arrivent bientôt à expiration:\n\n" . $res . "\n\nCet email a été envoyé automatiquement."); // simple quotes doesn't enable \n to work
			
				$this->mailer->send($email);
			}

			$io->success('Un email a été envoyé car certains contrats arrivent bientôt à expiration.');
		}
		else
		{
			$io->success('Aucun email n\'a été envoyé car aucun contrat n\'arrive bientôt à expiration.'); // how to use translation here ?
		}

        return Command::SUCCESS;
    }
}
