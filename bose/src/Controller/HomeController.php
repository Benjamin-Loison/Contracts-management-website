<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Form\SupplierType;
use App\Entity\Supplier;
use App\Form\ContractType;
use App\Entity\Contract;
use App\Entity\ContractTime;
use App\Entity\Collaborator;
use App\Form\CollaboratorType;
use App\Form\DomainType;
use App\Entity\Domain;
use App\Entity\Unit;
use App\Form\UnitType;
use App\Entity\Section;
use App\Form\SectionType;
use App\Entity\User;
use App\Form\UserType;
use App\Entity\Mail;
use App\Form\MailType;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Id\AssignedGenerator;

use Symfony\Component\Validator\Constraints\DateTime;
use SimpleSAML\Auth\Simple;

class HomeController extends AbstractController
{
    private $session, $userId, $permissionLevel, $permissionLevelStr, $hasPermissionToEdit, $hasPermissionToManage,
            $tabNames = array('supplier', 'unit', 'collaborator', 'domain', 'mail'), $fullArr = [], $BOM = "\xEF\xBB\xBF", // BOM is required for accents
            $PhPSessionInitialized = false, $SAMLInitialized = false,
            $JS_MAX_SAFE_INT = 9007199254740991,
            $appEntity = 'App\\Entity\\', $privateFolder = '../private/', $debugAdminId = 'debugAdmin';

    // configuration settings
    private $logoutURL = 'https://dsi1.bose.fr/logout.php',
            $accessForEveryone = false,
            $alsoLogNonEditingActions = false,
            $lookUpForChangeEveryNus = 100 * 1000; // increasing this value decrease load on server but increase waiting time for a change to propagate with AJAX on other browsers of users viewing the page changed

    public function getIndexArr(TranslatorInterface $translator, $page)
    {
        if($page == 'users') $page = 'admin';
        $indexArr = array();
        $pages = array('reception', 'suppliers', 'units', 'collaborators', 'domains', 'mails', 'contracts', 'admin', 'contractForm', 'logs');
        $indexArr['currentTab'] = $translator->trans($page);
        for($i = 0; $i < count($pages); $i++)
        {
            $indexArr[$pages[$i] . 'Tab'] = $translator->trans($pages[$i]); // need to name Tab otherwise confusing with contracts data for instance
            $indexArr['option' . $i] = 'other';
        }
        $indexArr['logout'] = $translator->trans('log out');
        $indexArr['currentPage'] = $page;
        $indexArr['formTabName'] = $translator->trans('form');
        $indexArr['connectedAs'] = $translator->trans('you have access');
        $indexArr['parameters'] = $translator->trans('parameters');
        $indexArr['logoutURL'] = $this->logoutURL;
        $indexSearched = array_search($page, $pages);
        $indexArr['option' . $indexSearched] = 'current';

        if(!$this->accessForEveryone)
        {
            $repository = $this->getDoctrine()->getRepository(User::class);
            $user = $repository->findOneBy(['userId' => $this->userId]);
        }
        $indexArr['userIdId'] = $this->accessForEveryone ? $this->debugAdminId : $user->getId();

        $indexArr['userId'] = $this->userId;
        $indexArr['permissionLevelStr'] = $translator->trans($this->permissionLevelStr);

        return $indexArr;
    }

    public function twig($filePath, $arr, $translator, $page)
    {
        $arr = array_merge($arr, $this->getIndexArr($translator, $page));
        return $this->render($filePath, $arr);
    }

    public function getPermissionLevelStr($permissionLevel)
    {
        $permissionLevelStrs = array('viewer', 'editor', 'administrator');
        return $permissionLevelStrs[$permissionLevel];
    }

    public function requireSAML($translator)
    {
        if($this->SAMLInitialized) return;

        require_once('../simplesamlphp/lib/_autoload.php');

        $this->SAMLInitialized = true;  

        $auth = new Simple("dsibosefr");
        if(!$auth->isAuthenticated())
        {
            $auth->requireAuth();
        }
        $uid = $auth->getAttributes()['uid'][0];

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository(User::class);
        $user = $repository->findOneBy(['userId' => $uid]); // all 'id' fields are internal to the database and never used by the end user
        if(!$this->accessForEveryone)
        {
            function refuse($translator, $reason, $logoutURL)
            {
                die($reason . ' <a href="' . $logoutURL . '">' . $translator->trans('Log out') . '</a>');
            }

            if($user == null)
                refuse($translator, $translator->trans('Sorry but no administrator has registered you.'), $this->logoutURL);
            $expirationDate = $user->getExpirationDate();
            $currentDate = new \DateTime();
            $diff = $expirationDate->diff($currentDate);
            $diffDays = $diff->days; // absolute difference
            $diffed = intval($diff->format('%r%a'));
            if($diffed > 0)
                refuse($translator, $translator->trans('Sorry but your account has expired.'), $this->logout);
            $this->userId = $uid;
            $this->permissionLevel = $user->getPermissionLevel();
            $this->hasPermissionToEdit = $this->permissionLevel >= 1;
            $this->hasPermissionToManage = $this->permissionLevel >= 2;
        }
        else
        {
            $this->userId = $this->debugAdminId;
            $this->permissionLevel = 2;
            $this->hasPermissionToEdit = true;
            $this->hasPermissionToManage = true;
        }
        $this->permissionLevelStr = $this->getPermissionLevelStr($this->permissionLevel);
        
        //print_r($auth->getAttributes()); // may be useful for customization
    }

    public function deleteCookie($cookieName)
    {
        setcookie($cookieName, '', time() - 3600);
    }

    /**
     * @Route("/logout")
     */
    public function logout()
    {
        $previousURL = $_SERVER['HTTP_REFERER'];
        $cookies = ['SimpleSAMLAuthToken', 'SimpleSAML'];
        array_walk($cookies, 'self::deleteCookie');
        $websiteURL = 'https://dsi.bose.fr/';
        $newRoute = 'index';
        if(str_starts_with($previousURL, $websiteURL) && $previousURL != $websiteURL)
        {
            $newRoute = str_replace($websiteURL, '', $previousURL);
        }
        return $this->redirectToRoute($newRoute);
    }

    function array_count_values_of($value, $array)
    {
        $counts = array_count_values($array);
        return $counts[$value];
    }

    /**
     * @Route("/", name="index")
     */
    public function index(MailerInterface $mailer, Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->requireSAML($translator);
        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed the dashboard'));
        $this->session->set('page', 'index');

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $repositoryCT = $this->getDoctrine()->getRepository(ContractTime::class);
        $contracts = $repository->findAll();
        $contractsCount = count($contracts);
        $contractsRealCount = $contractsCount;

        $emailDaysBeforeExpiration = intval(file_get_contents($this->privateFolder . 'daysBeforeMail.txt'));

        $contractsToRenew = []; // example: ['14/06/2021 | Content of the contract', ...]
        for($contractsIndex = 0; $contractsIndex < $contractsCount; $contractsIndex++)
        {
            $contract = $contracts[$contractsIndex];
            if(!$contract->getActive())
            {
                $contractsRealCount--;
                continue;
            }
            $beginDates = $endDates = [];
            $contractTimes = $repositoryCT->findBy(['contractId' => $contract->getId()]);
            $contractTimesCount = count($contractTimes);
            $hasMaintenanceThisYear = false;
            $currentDateTime = new \DateTime();
            $currentYear = intval($currentDateTime->format('Y'));
            for($contractTimesIndex = 0; $contractTimesIndex < $contractTimesCount; $contractTimesIndex++)
            {
                $contractTime = $contractTimes[$contractTimesIndex];
                $beginDate = $contractTime->getBeginDate();
                $endDate = $contractTime->getEndDate();

                if(!$hasMaintenanceThisYear)
                {
                    $beginYear = intval($beginDate->format('Y'));
                    $endYear = intval($endDate->format('Y'));
                    if($beginYear <= $currentYear && $currentYear <= $endYear)
                        $hasMaintenanceThisYear = true;
                }

                array_push($beginDates, $beginDate);
                array_push($endDates, $endDate);
            }
            if(!$hasMaintenanceThisYear)
            {
                $contractsRealCount--;
                continue;
            }
            $earliestEndDate = null;
            $endDatesCount = count($endDates);
            for($endDatesIndex = 0; $endDatesIndex < $endDatesCount; $endDatesIndex++)
            {
                $endDate = $endDates[$endDatesIndex];
                if(!in_array($endDate, $beginDates)) // we make the assumptions that all contracts are single-day and we also assume that if a contract is splited in multiple periods then the begin and end date of each match (there isn't two periods overlapping
                {
                    $diffed = (new \DateTime())->diff($endDate)->format('%r%a');
                    $expiringSoon = intval($diffed) < $emailDaysBeforeExpiration;
                    if($expiringSoon) // endDate < current + emailDaysBeforeExpiration
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

        $doctrine = $this->getDoctrine();
        $JS_MAX_SAFE_INT = $this->JS_MAX_SAFE_INT;

        function getSortArray($class, $doctrine, $JS_MAX_SAFE_INT, $translator)
        {
            $repository = $doctrine->getRepository($class);
            $els = $repository->findAll();
            $elsCount = count($els);
            $elsRes = [[$JS_MAX_SAFE_INT, ucfirst($translator->trans('all of'))]];
            for($elsIndex = 0; $elsIndex < $elsCount; $elsIndex++)
            {
                $el = $els[$elsIndex];
                array_push($elsRes, [$el->getId(), $el->getName()]);
            }
            return $elsRes;
        }

        $unitsRes = getSortArray(Unit::class, $doctrine, $JS_MAX_SAFE_INT, $translator);
        $suppliersRes = getSortArray(Supplier::class, $doctrine, $JS_MAX_SAFE_INT, $translator);

        $arr = [
            'contractsToRenew' => $contractsToRenew,
            'dashboard' => $translator->trans('dashboard'),
            'unit' => $translator->trans('unit'),
            'units' => $unitsRes,
            'supplier' => $translator->trans('supplier'),
            'suppliers' => $suppliersRes,
            'contract' => $translator->trans('contract'),
            'contracts' => $translator->trans('contracts'),
            'contracts_count' => $contractsRealCount,
            'contracts_to_renew_in_next_6_months' => $translator->trans('contracts to renew in next 6 months'),
            'download' => $translator->trans('Download all data as CSV'),
            'upload' => $translator->trans('Upload all data as CSV'),
            'dashboard' => $translator->trans('dashboard'),
            'updateWebsiteData' => $translator->trans('update website data'),
            'browserNotSupported' => $translator->trans('your browser doesn\'t support canvas elements.'),
            'amount_per_year' => $translator->trans('amount per year'),
            'contracts_per_direction' => $translator->trans('contracts per direction'),
            'contracts_per_year' => $translator->trans('contracts per year'),
            'moneySymbol' => $translator->trans('$'),
            'mails' => $translator->trans('mails')
        ];
        if($this->hasPermissionToEdit)
            $arr['edit'] = '';
        if($this->hasPermissionToManage)
            $arr['manage'] = '';

        return $this->twig('index.html.twig', $arr, $translator, 'reception');
    }
    
    public function commonDownloadGen($translator, $csvFileName, $keysStrArrs, $onlyBackup = false)
    {
        $fileName = $csvFileName . '.csv';
        $filePath = $this->privateFolder . $fileName;
        $backupPath = $this->privateFolder . 'backups/' . date('d-m-Y H-i-s', time()) . '.csv';
        $keysStrArrsCount = count($keysStrArrs);
        $fp = fopen($filePath, 'w');
        fwrite($fp, $this->BOM);
        for($keysStrArrsIndex = 0; $keysStrArrsIndex < $keysStrArrsCount; $keysStrArrsIndex++)
        {
            $keyStrArr = $keysStrArrs[$keysStrArrsIndex];
            if($keyStrArr !== '')
            {
                $key = $keyStrArr[0];
                $strArr = $keyStrArr[1];
                $name = $keyStrArr[2];
                fputcsv($fp, array($name));
                fputcsv($fp, $key);
                foreach($strArr as $line)
                {
                    fputcsv($fp, $line);
                }
            }
            else
            {
                fputcsv($fp, []);
            }
        }
        
        fclose($fp);
        if(!$onlyBackup)
        {
            $maxRead = 1 * 1024 * 1024;
            $fh = fopen($filePath, 'r');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            while(!feof($fh))
            {
                echo fread($fh, $maxRead);
                ob_flush();
            }
            fclose($fh);
        }
        else
        {
            copy($filePath, $backupPath);
        }
        unlink($filePath);
        if(!$onlyBackup)
        {
            $this->backup($translator);
            die(''); // otherwise the toolbar code is added if it is activated
        }
    }
    
    public function getKeyStrArrEntity(TranslatorInterface $translator, $entitiesNames)
    {
        $entityClass = $this->getEntityClass($entitiesNames);
        $repository = $this->getDoctrine()->getRepository($entityClass);
        $entities = $repository->findAll();
        $realEntityName = substr($entitiesNames, 0, strlen($entitiesNames) - 1);
        $entitiesCount = count($entities);
        $entitiesStrArr = array();
        for($i = 0; $i < $entitiesCount; $i++)
        {
            $entity = $entities[$i];
            $entityId = $entity->getId();
            $entityName = $entity->getName();
            $arr = array(
                'name' => $entityName
            );
            array_push($entitiesStrArr, $arr);
        }
        $key = array($translator->trans($realEntityName));
        $name = $translator->trans($entitiesNames);
        return array($key, $entitiesStrArr, $name);
    }

    public function arrayMerge($arrs, $arr)
    {
        $arr = array($arr);
        array_push($arr, '');
        return array_merge($arrs, $arr);
    }

    public function fullArrForDownloadAll(TranslatorInterface $translator)
    {
        if($this->fullArr != []) return $this->fullArr;
        $arrs = array();
        foreach($this->tabNames as $tabName)
        {
            if($tabName == 'mail' && !$this->hasPermissionToManage) continue;
            $arrs = $this->arrayMerge($arrs, $tabName == 'collaborator' ? $this->getKeyStrArrCollaborator($translator) : $this->getKeyStrArrEntity($translator, $tabName . 's'));
        }
        if($this->hasPermissionToManage)
            $arrs = $this->arrayMerge($arrs, $this->getKeyStrArrUser($translator));
        $arrs = $this->arrayMerge($arrs, $this->getKeyStrArrContract($translator));
        $this->fullArr = $arrs;
        return $arrs;
    }

    /**
     * @Route("/download")
     */
    public function download(TranslatorInterface $translator)
    {
        $this->requireSAML($translator);
        $this->log($translator, $translator->trans('downloaded the whole website data'));
        $arrs = $this->fullArrForDownloadAll($translator);
        $this->commonDownloadGen($translator, $translator->trans('all'), $arrs);
    }
    
    public function getIdByName($class, $name, $nameAttribute = 'name')
    {
        $repository = $this->getDoctrine()->getRepository($class);
        $el = $repository->findOneBy([$nameAttribute => $name]);
        return $el->getId();
    }
    
    public function getEntityFromData($data, $entityClassName, TranslatorInterface $translator)
    {
        $entitiesList = array();
        $entity = new ($this->appEntity . $entityClassName)();
        if($entityClassName == 'Collaborator')
        {
            $entity->setName($data[0]);
            $unitId = $this->getIdByName(Unit::class, $data[1]);
            $entity->setUnitId($unitId);
        }
        else if($entityClassName == 'User')
        {
            $entity->setUserId($data[0]);
            // we do by hand because Symfony doesn't provide any function to reverse translation
            $permissionLevel = 0;
            if($data[1] == 'contributeur') $permissionLevel = 1;
            else if($data[1] == 'administrateur') $permissionLevel = 2;
            $entity->setPermissionLevel($permissionLevel);
            $entity->setCreationDate(\DateTime::createFromFormat('d/m/Y', $data[2]));
            $entity->setExpirationDate(\DateTime::createFromFormat('d/m/Y', $data[3]));
        }
        else if($entityClassName == 'Contract')
        {
            $entity->setNumber($data[0]);
            $date = \DateTime::createFromFormat('d/m/Y', $data[1]);
            $entity->setDate($date);
            $supplierId = $this->getIdByName(Supplier::class, $data[2]);
            $entity->setSupplierId($supplierId); // we make the assumption that every name is unique
            $entity->setContent($data[3]);
            $leaderId = $this->getIdByName(Collaborator::class, $data[4]);
            $entity->setContractLeaderId($leaderId);
            $applicativeLeaderId = $this->getIdByName(Collaborator::class, $data[5]);
            $entity->setApplicativeLeaderId($applicativeLeaderId);
            $domainId = $this->getIdByName(Domain::class, $data[6]);
            $entity->setDomainId($domainId);
            $entity->setActive($data[7] == $translator->trans('yes') ? 1 : 0);
            $entity->setModificationDate(\DateTime::createFromFormat('d/m/Y', $data[8]));
            $userId = $this->getIdByName(User::class, $data[9], 'userId');
            $entity->setModificationUserId($userId);

            $shift = 10;
            $contractTimeSize = 8;
            $dataCount = count($data) - $shift;
            while($dataCount > 0)
            {
                $contractTime = new ContractTime();
                $beginDate = \DateTime::createFromFormat('d/m/Y', $data[$shift]);
                $contractTime->setBeginDate($beginDate);
                $endDate = \DateTime::createFromFormat('d/m/Y', $data[$shift + 1]);
                $contractTime->setEndDate($endDate);
                $contractTime->setAmount($data[$shift + 2]);
                $contractTime->setBuyId($data[$shift + 3]);
                $contractTime->setMarketId($data[$shift + 4]);
                $contractTime->setCommandId($data[$shift + 5]);
                $contractTime->setPosteId($data[$shift + 6]);
                $contractTime->setComment($data[$shift + 7]);
                array_push($entitiesList, $contractTime);
                $dataCount -= $contractTimeSize;
                $shift += $contractTimeSize;
            }
        }
        else
        {
            $entity->setName($data[0]);
        }
        array_unshift($entitiesList, $entity);
        return $entitiesList;
    }

    public function dropTable($em, $entityClass)
    {
        $repository = $this->getDoctrine()->getRepository($entityClass);

        $entities = $repository->findAll();
        foreach($entities as $entity)
        {
            $em->remove($entity);
        }
        $em->flush();
    }
    
    public function manageUpload(Request $request, TranslatorInterface $translator, $forceIndex = false) // last argument is here because Symfony isn't well working with session
    {
        $this->checkManagePermission($translator);
        $this->backup($translator);
        if(isset($_POST["submit"]))
        {
            $fileName = $_FILES["fileToUpload"]["name"];
            $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if($fileType != "csv")
            {
                die($translator->trans("Please upload a CSV file."));
            }
            $targetFile = $this->privateFolder . 'all.csv';
            if(move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $targetFile))
            {
                $this->initializeSession($request);

                $em = $this->getDoctrine()->getManager();
                if($forceIndex)
                {
                    foreach($this->tabNames as $tabName)
                    {
                        $entityClass = $this->getEntityClass($tabName);
                        $this->dropTable($em, $entityClass);
                    }
                    $this->dropTable($em, User::class);
                    $this->dropTable($em, Contract::class);
                }
                else
                {
                    $currentPage = $this->session->get('page');
                    if($currentPage == 'admin') $currentPage = 'users';
                    $entityClass = $this->getEntityClass($currentPage);
                    if(in_array($currentPage, ['suppliers', 'collaborators', 'units', 'users', 'domains', 'contracts']))
                    {
                        $this->dropTable($em, Contract::class);
                        $this->dropTable($em, ContractTime::class);
                    }
                    if($currentPage == 'units')
                    {
                        $this->dropTable($em, Collaborator::class);
                    }

                    $this->dropTable($em, $entityClass);
                }

                $workingOn = '';
                $classNames = array();
                foreach($this->tabNames as $tabName)
                    $classNames[$translator->trans($tabName . 's')] = $tabName;
                $classNames[$translator->trans('users')] = 'user';
                $classNames[$translator->trans('contracts')] = 'contract';
        
                if(($handle = fopen($targetFile, "r")) !== FALSE)
                {
                    if(fgets($handle, 4) !== $this->BOM)
                    {
                        // BOM not found - rewind pointer to start of file.
                        rewind($handle);
                    }
                    
                    while(($data = fgetcsv($handle)) !== FALSE)
                    {
                        $num = count($data);
                        if($data[0] == '' && $num == 1) continue;
                        $types = array_map(array($translator, 'trans'), array('suppliers', 'units', 'collaborators', 'domains', 'users', 'contracts', 'mails'));
                        if(in_array($data[0], $types))
                        {
                            $workingOn = $data[0];
                            fgetcsv($handle);
                        }
                        else
                        {
                            $itemsToUpload = $this->getEntityFromData($data, ucfirst($classNames[$workingOn]), $translator);
                            $itemsToUploadCount = count($itemsToUpload);
                            $contractId = null;
                            for($itemsToUploadIndex = 0; $itemsToUploadIndex < $itemsToUploadCount; $itemsToUploadIndex++) // need to loop and add data to database now in case of full upload
                            {
                                $itemToUpload = $itemsToUpload[$itemsToUploadIndex];
                                $className = get_class($itemToUpload);
                                $isContractTime = $className == $this->appEntity . 'ContractTime';
                                if($isContractTime)
                                    $itemToUpload->setContractId($contractId);
                                $em->persist($itemToUpload);
                                $em->flush(); // otherwise $contractId isn't set
                                if($className == $this->appEntity . 'Contract')
                                $contractId = $itemToUpload->getId();
                                $className = get_class($itemToUpload);
                                if($isContractTime)
                                    continue;

                                $parts = explode('\\', strtolower($className) . 's');
                                $associatedPage = $parts[count($parts) - 1];
                                if($associatedPage == 'users') $associatedPage = 'admin';
                                $this->addContentToQueue('*', '', $associatedPage);
                            }
                        }
                    }
                    fclose($handle);
                }
                unlink($targetFile);
            }
            else
            {
                die($translator->trans("Sorry, there was an error uploading your file."));
            }
        }
    }
    
    /**
    * @Route("/upload")
    */
    public function upload(Request $request, TranslatorInterface $translator)
    {
        $this->checkEditPermission($translator);
        $this->log($translator, $translator->trans('uploaded the whole website data'));
        $this->manageUpload($request, $translator, true);
        return $this->redirectToRoute('index');
    }

    public function initializeSession(Request $request)
    {
        if($this->PhPSessionInitialized) return;
        $this->PhPSessionInitialized = true;
        $this->session = $request->getSession();
        $this->session->start();
    }

    public function getFolderPath($folder)
    {
        return $this->privateFolder . 'lookFor/' . $folder . '/';
    }

    public function getFilePath($folder, $id)
    {
        return $this->getFolderPath($folder) . $id . '.txt';
    }

    /**
     * @Route("/lookFor/{lookForId}")
     */
    public function lookFor(TranslatorInterface $translator, Request $request, $lookForId)
    {
        $this->initializeSession($request);
        $this->requireSAML($translator);
        $historyPath = $this->getFilePath($this->session->get('page'), $lookForId);
        $initialTime = time();
        $this->session->save();
        $fileExists = false;
        $content = '';
        //$fd = inotify_init(); // using inotify could reduce CPU load
        if(false && function_exists('inotify_init'))
        {
            //$fd = inotify_init();
            //$watch_descriptor = inotify_add_watch($fd, __FILE__, IN_ATTRIB);
            die("!" . __FILE__ . "!");
        }
        else
        {
            do
            {
                if($fileExists || file_exists($historyPath)) // these checks remove a parallel error on page load
                {
                    $fileExists = true;
                    $content = file_get_contents($historyPath);
                    if($content == '')
                        usleep($this->lookUpForChangeEveryNus);
                }
                else
                    usleep($this->lookUpForChangeEveryNus);
            }
            while($content == '' && time() <= $initialTime + 2);
        }
        file_put_contents($historyPath, '');
        die($content);
    }
    
    public function checkManagePermission($translator)
    {
        $this->requireSAML($translator);
        if(!$this->hasPermissionToManage) die($translator->trans('You don\'t have the permission for managing.'));
    }

    public function checkEditPermission($translator)
    {
        $this->requireSAML($translator);
        if(!$this->hasPermissionToEdit) die($translator->trans('You don\'t have the permission for editing.'));
    }

    public function entitiesDataAux(Request $request, TranslatorInterface $translator, $entityClass)
    {
        $repository = $this->getDoctrine()->getRepository($entityClass);
        $entities = $repository->findAll();
        $this->requireSAML($translator);
        $entitiesCount = count($entities);
        for($i = 0; $i < $entitiesCount; $i++)
        {
            if($i != 0)
                echo "\n";
            $entity = $entities[$i];
            $entityName = $entity->getName();
            $entityId = $entity->getId();
            echo $entityId . '|' . $entityName;
        }
        die('');
    }

    /**
     * @Route("/{entities}Data")
     */
    public function entitiesData(Request $request, TranslatorInterface $translator, $entities)
    {
        $entities = ucfirst(substr($entities, 0, strlen($entities) - 1));
        $entityClass = $this->appEntity . $entities;
        $this->entitiesDataAux($request, $translator, $entityClass);
    }

    /**
     * @Route("/declare/{lookForId}")
     */
    public function declare(TranslatorInterface $translator, Request $request, $lookForId)
    {
        $this->initializeSession($request);
        $this->requireSAML($translator); // can't be before initializeSession

        $historyFolder = $this->getFolderPath($this->session->get('page'));
        $files = array_slice(scandir($historyFolder), 2);
        $filesCount = count($files);
        for($filesIndex = 0; $filesIndex < $filesCount; $filesIndex++)
        {
            $file = $files[$filesIndex];
            $filePath = $historyFolder . $file;
            if(fileatime($filePath) < time() - 300) // 5 minutes
            {
                unlink($filePath);
            }
        }

        $filePath = $this->getFilePath($this->session->get('page'), $lookForId);
        file_put_contents($filePath, '');
        die();
    }

    public function addContentToQueue($toAdd, $lookForId = '', $page = '')
    {
        if($page == '')
            $page = $this->session->get('page');
        $historyFolder = $this->getFolderPath($page);
        $files = array_slice(scandir($historyFolder), 2);
        $filesCount = count($files);
        for($filesIndex = 0; $filesIndex < $filesCount; $filesIndex++)
        {
            $file = $files[$filesIndex];
            if($file != $lookForId . '.txt')
            {
                $filePath = $historyFolder . $file;
                $fp = fopen($filePath, 'a');
                $toWrite = '';
                if(filesize($filePath) != 0)
                    $toWrite = '\n';
                $toWrite .= $toAdd;
                fwrite($fp, $toWrite); 
                fclose($fp);
            }
        }
    }

    public function logModification(TranslatorInterface $translator, $realEntityName, $oldName, $entityName)
    {
        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans(lcfirst($realEntityName)) . ' ' . $translator->trans('from') . ' ' . $oldName . ' ' . $translator->trans('to') . ' ' . $entityName);
    }

    public function modifyAux(Request $request, TranslatorInterface $translator, $id, $entityName, $lookForId, $entityClass, $realEntityName)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository($entityClass);
        $entity = $repository->findOneBy(['id' => $id]);

        $this->logModification($translator, $realEntityName, $entity->getName(), $entityName);
        // current ajax system can be quite heavy in logs... - if logs in the function which calls modifyAux we don't check permissions for logging

        $entity->setName($entityName);
        $em->flush();

        $this->addContentToQueue('_' . $id . '|' . $entityName, $lookForId);

        die();
    }

    public function deleteAux(Request $request, TranslatorInterface $translator, $id, $lookForId, $entityClass, $entityName)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);
    
        $originalEntityName = $entityName;  
        if($entityName == 'Collaborator') $entityName = 'ContractLeader';

        $em = $this->getDoctrine()->getManager();
        
        $contract = null;
        if($entityName != 'Unit' && $entityName != 'Section' && $entityName != 'Mail')
        {
            $repository = $this->getDoctrine()->getRepository(Contract::class);
            $idName = lcfirst($entityName) . 'Id';
            $contract = $repository->findOneBy([$idName => $id]);
        }
        $repository = $this->getDoctrine()->getRepository($entityClass);
        $entity = $repository->findOneBy(['id' => $id]);
        if($contract == null)
        {
            $em->remove($entity);
            $em->flush();

            $this->addContentToQueue('-' . $id, $lookForId);

            $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans(lcfirst($originalEntityName)) . ' ' . $entity->getName());

            die("");
        }

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('failed to delete') . ' ' . $translator->trans(lcfirst($originalEntityName)) . ' ' . $entity->getName() . ' ' . $translator->trans('because it is used in a contract'));
        die("N");
    }

    public function addAux(Request $request, TranslatorInterface $translator, $name, $lookForId, $entity, $entityClass, $entityName)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $this->log($translator, $translator->trans('added') . ' ' . $translator->trans(lcfirst($entityName)) . ' ' . $name);

        $em = $this->getDoctrine()->getManager();

        $entity->setName($name);

        $em->persist($entity);
        $em->flush();

        $repository = $this->getDoctrine()->getRepository($entityClass);
        $entity = $repository->findOneBy(['name' => $name]);

        $entityId = $entity->getId();

        $this->addContentToQueue('+' . $entityId . '|' . $name, $lookForId);

        die(strval($entityId));
    }

    public function entitiesAux(Request $request, TranslatorInterface $translator, $entities, $entity, $entityTypeClass)
    {
        $this->initializeSession($request);
        $this->session->set('page', $entities); // maybe there is a data access security threat by not having access and then asking lookForId

        $entity->setName('');

        $form = $this->createForm($entityTypeClass, $entity);

        if($entities == 'mails')
            $this->checkManagePermission($translator);
        else
            $this->requireSAML($translator); // if done before createForm function, createForm has the same behaviour as die('')

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed to') . ' ' . $translator->trans(lcfirst($entities)));

        $entityName = substr($entities, 0, -1);
        $arr = array(
            $entityName => $translator->trans($entityName),
            'form' => $form->createView(),
            'previousPage' => $translator->trans('previous page'),
            'nextPage' => $translator->trans('next page'),
            'download' => $translator->trans('Download as CSV'),
            'upload' => $translator->trans('Upload as CSV'),
            'deletionCouldntBePerformedBecauseUsedInAContract' => $translator->trans("deletion couldn't be performed because this element is used in a contract !"),
            'name_cant_be_null' => $translator->trans("Name can't be null"),
            'name_already_typed' => $translator->trans("Name already in the list")
        );

        if($this->hasPermissionToEdit)
        {
            $arrEditing = array(
                'actions' => $translator->trans('actions'),
                'add' => $translator->trans('add'),
                'delete' => $translator->trans('delete'),
                'edit' => '' // just to exist
            );
        
            if($this->hasPermissionToManage)
                $arrEditing['manage'] = '';
            $arr = array_merge($arr, $arrEditing);
        }

        return $this->twig($entities . '.html.twig', $arr, $translator, $entities);
    }

    public function getEntityClass($entityName)
    {
        $lowercaseEntityName = strtolower($entityName);
        if($lowercaseEntityName[-1] == 's')
            $lowercaseEntityName = substr($lowercaseEntityName, 0, -1);
        return $this->appEntity . ucfirst($lowercaseEntityName);
    }

    public function getEntity($entityName)
    {
        return new ($this->appEntity . $entityName)();
    }

    /**
     * @Route("/modify{entityName}/{id}/{name}/{lookForId}", requirements={"name"=".*"})
     */
    public function modifyEntity(Request $request, TranslatorInterface $translator, $entityName, $id, $name, $lookForId)
    {
        $this->modifyAux($request, $translator, $id, $name, $lookForId, $this->getEntityClass($entityName), $entityName);
    }

    /**
     * @Route("/delete{entityName}/{id}/{lookForId}")
     */
    public function deleteEntity(Request $request, TranslatorInterface $translator, $entityName, $id, $lookForId)
    {
        $this->deleteAux($request, $translator, $id, $lookForId, $this->getEntityClass($entityName), $entityName);
    }

    // need lookForId requirements otherwise addContract matches here
    /** 
    * @Route("/add{entityName}/{name}/{lookForId}", requirements={"name"=".*", "lookForId"="[a-zA-Z0-9]{16}"})
    */
    public function addEntity(Request $request, TranslatorInterface $translator, $entityName, $name, $lookForId)
    {
        $this->addAux($request, $translator, $name, $lookForId, $this->getEntity($entityName), $this->getEntityClass($entityName), $entityName);
    }

    /**
    * @Route("/{entitiesName}Upload")
    */  
    public function entitiesUpload(Request $request, TranslatorInterface $translator, $entitiesName)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $this->log($translator, $translator->trans('uploaded all') . ' ' . $translator->trans(lcfirst($entitiesName)));

        $this->manageUpload($request, $translator);
        return $this->redirectToRoute($entitiesName == 'mails' ? 'index' : $entitiesName);
    }

    public function backup(TranslatorInterface $translator)
    {
        $arrs = $this->fullArrForDownloadAll($translator);
        $this->commonDownloadGen($translator, $translator->trans('all'), $arrs, true);
    }

    /**
     * @Route("/{entities}Download")
     */
    public function entitiesDownload(Request $request, TranslatorInterface $translator, $entities)
    {
        $this->initializeSession($request);
        $this->requireSAML($translator);
        
        $this->log($translator, $translator->trans('downloaded all') . ' ' . $translator->trans(lcfirst($entities)));

        $arr = array($this->getKeyStrArrEntity($translator, $entities));
        $this->commonDownloadGen($translator, $arr[0][2], $arr);
    }

    /// SUPPLIERS

    /**
     * @Route("/suppliers", name="suppliers")
     */
    public function suppliers(Request $request, TranslatorInterface $translator)
    {
        return $this->entitiesAux($request, $translator, 'suppliers', new Supplier(), SupplierType::class);
    }

    /// DOMAINS
    
    /**
     * @Route("/domains", name="domains")
     */
    public function domains(Request $request, TranslatorInterface $translator)
    {
        return $this->entitiesAux($request, $translator, 'domains', new Domain(), DomainType::class);
    }

    /// UNITS

    /**
     * @Route("/units", name="units")
     */
    public function units(Request $request, TranslatorInterface $translator) // could make a single rule with a regex which check if webpage is unit or domain or ... - not that easy because name annotation is used...
    {
        return $this->entitiesAux($request, $translator, 'units', new Unit(), UnitType::class);
    }

    /// MAILS - email name for object is already taken

    /**
     * @Route("/mails", name="emails")
     */
    public function emails(Request $request, TranslatorInterface $translator)
    {
        return $this->entitiesAux($request, $translator, 'mails', new Mail(), MailType::class);
    }

    /// COLLABORATORS

    /**
     * @Route("/collaborators", name="collaborators")
     */
    public function collaborators(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'collaborators');

        $entity = new Collaborator();
        $entity->setName('');

        $doc = $this->getDoctrine();

        $form = $this->createForm(CollaboratorType::class, $entity, [
            'entity_manager' => $doc,
        ]);

        $this->requireSAML($translator);

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed to') . ' ' . $translator->trans('collaborators'));

        $entityName = substr('collaborators', 0, -1);

        $arr = array(
            $entityName => $translator->trans($entityName),
            'unit' => $translator->trans('unit'),
            'form' => $form->createView(),
            'download' => $translator->trans('Download as CSV'),
            'upload' => $translator->trans('Upload as CSV'),
            'previousPage' => $translator->trans('previous page'),
            'nextPage' => $translator->trans('next page'),
            'aValueForUnitMustBeProvided' => $translator->trans("a value for unit must be provided"),
            'deletionCouldntBePerformedBecauseUsedInAContract' => $translator->trans("deletion couldn't be performed because this element is used in a contract !"),
            'name_cant_be_null' => $translator->trans("Name can't be null"),
            'name_already_typed' => $translator->trans("Name already in the list")
        );

        if($this->hasPermissionToEdit)
        {
            $arrEditing = array(
                'actions' => $translator->trans('actions'),
                'add' => $translator->trans('add'),
                'delete' => $translator->trans('delete'),
                'edit' => ''
            );

            if($this->hasPermissionToManage)
                $arrEditing['manage'] = '';
            $arr = array_merge($arr, $arrEditing);
        }

        return $this->twig('collaborators.html.twig', $arr, $translator, 'collaborators');
    }

    public function getKeyStrArrCollaborator(TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $entities = $repository->findAll();
        $entitiesCount = count($entities);
        $entitiesStrArr = array();
        for($i = 0; $i < $entitiesCount; $i++)
        {
            $entity = $entities[$i];
            $entityId = $entity->getId();
            $entityName = $entity->getName();
            $entityUnitId = $entity->getUnitId();
            $arr = array(
                'name' => $entityName,
                'unitId' => $this->getNameFromId(Unit::class, $entityUnitId, $translator)
            );
            array_push($entitiesStrArr, $arr);
        }
        $key = array($translator->trans("name"), $translator->trans("unit"));
        $name = $translator->trans('collaborators');
        $arr = array($key, $entitiesStrArr, $name);
        return $arr;
    }

    /**
     * @Route("/collaboratorsDownload", priority=1)
     */
    public function collaboratosDownload(TranslatorInterface $translator)
    {
        $this->requireSAML($translator);

        $this->log($translator, $translator->trans('downloaded all') . ' ' . $translator->trans('collaborators'));

        $arr = array($this->getKeyStrArrCollaborator($translator));
        $this->commonDownloadGen($translator, $arr[0][2], $arr);
    }

    /**
     * @Route("/modifyCollaborator/{id}/{name}/{unitId}/{lookForId}", requirements={"name"=".*"}, priority=1)
     */
    public function modifyCollaborator(Request $request, TranslatorInterface $translator, $id, $name, $unitId, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $entity = $repository->findOneBy(['id' => $id]);

        $repositoryUnit = $this->getDoctrine()->getRepository(Unit::class);
        $oldUnit = $repositoryUnit->findOneBy(['id' => $entity->getUnitId()])->getName();
        $newUnit = $repositoryUnit->findOneBy(['id' => $unitId])->getName();

        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans('collaborator') . ' (' . $translator->trans('name') . ' ' . $entity->getName() . ' -> ' . $name . ', ' . $translator->trans('unit') . ' ' . $oldUnit . ' -> ' . $newUnit . ')');

        $entity->setName($name);
        $entity->setUnitId($unitId);
        $em->flush();

        $this->addContentToQueue('_' . $id . '|' . $name . '|' . $unitId, $lookForId);

        die();
    }

    /**
     * @Route("/collaboratorsData", priority=1)
     */
    public function collaboratorsData(Request $request, TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $entities = $repository->findAll();
        $entitiesCount = count($entities);
        $res = '';
        for($i = 0; $i < $entitiesCount; $i++)
        {
            if($i != 0)
                $res .= "\n";
            $entity = $entities[$i];
            $entityName = $entity->getName();
            $entityId = $entity->getId();
            $entityUnitId = $entity->getUnitId();
            $res .= $entityId . '|' . $entityName . '|' . $entityUnitId;
        }
        $this->requireSAML($translator);
        die($res);
    }

    /**
    * @Route("/addCollaborator/{name}/{unitId}/{lookForId}", requirements={"name"=".*", "lookForId"="[a-zA-Z0-9]{16}"}, priority=1)
    */
    public function addCollaborator(Request $request, TranslatorInterface $translator, $name, $unitId, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $em = $this->getDoctrine()->getManager();
        $repository = $this->getDoctrine()->getRepository(Unit::class);
        $unit = $repository->findOneBy(['id' => $unitId]);

        $this->log($translator, $translator->trans('added') . ' ' . $translator->trans('collaborator') . ' ' . $name . ' ' . $translator->trans('associated to unit') . ' ' . $unit->getName());

        $em = $this->getDoctrine()->getManager();

        $entity = new Collaborator();
        $entity->setName($name);
        $entity->setUnitId($unitId);

        $em->persist($entity);
        $em->flush();

        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $entity = $repository->findOneBy(['name' => $name]);

        $entityId = $entity->getId();

        $this->addContentToQueue('+' . $entityId . '|' . $name . '|' . $unitId, $lookForId);

        die(strval($entityId));
    }

    /// ADMINISTRATION

    /**
     * @Route("/updateDaysBeforeMail/{daysBeforeMail}")
     */
    public function updateDaysBeforeMail(Request $request, TranslatorInterface $translator, $daysBeforeMail)
    {
        $this->checkManagePermission($translator);

        $daysBeforeMailFile = $this->privateFolder . 'daysBeforeMail.txt';
        $content = file_get_contents($daysBeforeMailFile); // using database just for this is kind of overkill

        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans('number of days before mailing') . ' ' . $translator->trans('from') . ' ' . $content . ' ' . $translator->trans('to') . ' ' . $daysBeforeMail);

        file_put_contents($daysBeforeMailFile, $daysBeforeMail);
        die('');
    }
        
    /**
     * @Route("/admin", name="admin")
     */
    public function admin(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'admin');

        $user = new User();

        $form = $this->createForm(UserType::class, $user);

        $this->checkManagePermission($translator);

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed the administrator panel'));

        $repository = $this->getDoctrine()->getRepository(User::class);
        $users = $repository->findAll();
        $usersCount = count($users);
        $usersStrArr = array();
        for($i = 0; $i < $usersCount; $i++)
        {
            $user = $users[$i];
            $userId = $user->getId();
            $userUserId = $user->getUserId();
            $userPermissionLevel = $user->getPermissionLevel();
            $userCreationDate = $user->getCreationDate();
            $userExpirationDate = $user->getExpirationDate();
            $arr = array(
                'id' => $userId,
                'userId' => $userUserId,
                'permissionLevel' => $userPermissionLevel,
                'creationDate' => $userCreationDate,
                'expirationDate' => $userExpirationDate
            );
            array_push($usersStrArr, $arr);
        }

        $arr = array(
            'user' => $translator->trans('user'),
            'form' => $form->createView(),
            'users' => $usersStrArr,
            'permissionLevel' => $translator->trans('permission level'),
            'creationDate' => $translator->trans('creation date'),
            'expirationDate' => $translator->trans('expiration date'),
            'viewer' => $translator->trans('viewer'),
            'editor' => $translator->trans('editor'),
            'administrator' => $translator->trans('administrator'),
            'download' => $translator->trans('Download as CSV'),
            'upload' => $translator->trans('Upload as CSV'),
            'previousPage' => $translator->trans('previous page'),
            'nextPage' => $translator->trans('next page'),
            'aValueForPermissionLevelMustBeProvided' => $translator->trans('a value for permission level must be provided'),
            'aValueForExpirationDateMustBeProvided' => $translator->trans('a value for expiration date must be provided'),
            'modifyYourOwnPermissionsWasDisableForSecurity' => $translator->trans('modify your own permissions was disable for security'),
            'manage' => '',
            'yes' => $translator->trans('yes'),
            'no' => $translator->trans('no'),
            'active' => $translator->trans('active'),
            'logs' => $translator->trans('logs'),
            'daysBeforeMail' => $translator->trans('number of days before mailing'),
            'daysBeforeMailValue' => file_get_contents($this->privateFolder . 'daysBeforeMail.txt')
        );

        $arrEditing = array(
            'actions' => $translator->trans('actions'),
            'add' => $translator->trans('add'),
            'delete' => $translator->trans('delete'),
            'user_id_cant_be_null' => $translator->trans("User id can't be null")
        );
        $arr = array_merge($arr, $arrEditing);

        return $this->twig('users.html.twig', $arr, $translator, 'users');
    }

    public function log(TranslatorInterface $translator, $toLog)
    {
        $folderPath = $this->privateFolder . 'logs/';
        $filePath = $folderPath . 'latest.txt';
        if(file_exists($filePath) && filesize($filePath) > 1024 * 1024) // backup and use a fresh file (to improve speed) if latest logs exceeds 1 Mo
        {
            $date = date("d-m-Y H-i-s", time()); // ':' doesn't work on Windows for instance
            rename($filePath, $folderPath . $date . '.txt');
            file_put_contents($filePath, '');
        }

        // all fail user login have to be checked in web server logs
        $date = date("d-m-Y H:i:s", time());
        $toLog = $date . ' (' . $translator->trans('user') . ': ' . $this->userId . '): ' . $toLog . "\n";
        file_put_contents($filePath, $toLog, FILE_APPEND);
    }

    /**
     * @Route("/logs")
     */
    public function logs(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->checkManagePermission($translator); // let's not log fail operations
        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed the logs'));
        $filePath = $this->privateFolder . 'logs/latest.txt';
        $content = file_exists($filePath) ? file_get_contents($filePath) : '';
        $lines = explode("\n", $content);
        $lines = array_reverse($lines);
        $content = implode("\n", $lines);
        if($content == '') $content = $translator->trans('Nothing in logs.');
        return $this->twig('logs.html.twig', ['logs' => $translator->trans('logs'), 'manage' => '', 'content' => $content], $translator, 'logs');
    }

    /**
     * @Route("/usersData", priority=1)
     */
    public function usersData(Request $request, TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(User::class);
        $users = $repository->findAll();
        $usersCount = count($users);
        $res = '';
        for($i = 0; $i < $usersCount; $i++)
        {
            if($i != 0)
                $res .= "\n";
            $user = $users[$i];
            $userId = $user->getId();
            $userUserId = $user->getUserId();
            $userPermissionLevel = $user->getPermissionLevel();
            $userCreationDate = $user->getCreationDate()->format('Y-m-d');
            $userExpirationDate = $user->getExpirationDate()->format('Y-m-d');
            $res .= $userId . '|' . $userUserId . '|' . $userPermissionLevel . '|' . $userCreationDate . '|' . $userExpirationDate;
        }
        $this->checkManagePermission($translator);
        die($res);
    }
    
    /**
     * @Route("/deleteUser/{id}/{lookForId}", priority=1)
     */
    public function deleteUser(Request $request, TranslatorInterface $translator, $id, $lookForId)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'admin');
        $em = $this->getDoctrine()->getManager();
        $this->checkManagePermission($translator);

        $repository = $this->getDoctrine()->getRepository(User::class);
        $user = $repository->findOneBy(['id' => $id]);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('user') . ' ' . $user->getUserId());

        $em->remove($user);
        $em->flush();
        
        $this->addContentToQueue('-' . $id, $lookForId);

        die("");
    }

    // for debug purpose
    /**
     * @Route("/deleteUsers")
     */
    public function deleteUsers(Request $request, TranslatorInterface $translator)
    {
        $em = $this->getDoctrine()->getManager();
        $this->checkManagePermission($translator);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('all elements of') . ' ' . $translator->trans('users'));

        $repository = $this->getDoctrine()->getRepository(User::class);
        $users = $repository->findAll();

        foreach($users as $user)
            $em->remove($user);
        $em->flush();

        die("");
    }
    
    /**
     * @Route("/modifyUser/{id}/{userId}/{permissionLevel}/{creationDate}/{expirationDate}/{lookForId}", requirements={"userId"=".*"}, priority=1)
     */
    public function modifyUser(Request $request, TranslatorInterface $translator, $id, $userId, $permissionLevel, $creationDate, $expirationDate, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkManagePermission($translator);

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository(User::class);
        $user = $repository->findOneBy(['id' => $id]);

        $permissionLevelName = $translator->trans($this->getPermissionLevelStr($user->getPermissionLevel()));
        $newPermissionLevelName = $translator->trans($this->getPermissionLevelStr($permissionLevel));
        $expirationDateClean = $this->cleanDate($expirationDate);

        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans('user') . ' ' . $translator->trans('from') . ' ' . $user->getUserId() . ' ' . $translator->trans('with') . ' ' . $translator->trans('access') . ' ' . $permissionLevelName . ' ' . $translator->trans('expiring on') . ' ' . $user->getExpirationDate()->format('d-m-Y') . ' ' . $translator->trans('to') . ' ' . $userId . ' ' . $translator->trans('with') . ' ' . $translator->trans('access') . ' ' . $newPermissionLevelName . ' ' . $translator->trans('expiring on') . ' ' . $expirationDateClean);

        $user->setUserId($userId);
        $user->setPermissionLevel($permissionLevel);
        $user->setCreationDate(new \DateTime($creationDate));
        $user->setExpirationDate(new \DateTime($expirationDate));
        $em->flush();

        $this->addContentToQueue('_' . $id . '|' . $userId . '|' . $permissionLevel . '|' . $creationDate . '|' . $expirationDate);

        die();
    }

    public function cleanDate($date)
    {
        $dateParts = explode('-', $date);
        $dateClean = $dateParts[2] . '/' . $dateParts[1] . '/' . $dateParts[0];
        return $dateClean;
    }

    /**
    * @Route("/addUser/{userId}/{permissionLevel}/{creationDate}/{expirationDate}/{lookForId}", requirements={"userId"=".*"}, priority=1)
    */
    public function addUser(Request $request, TranslatorInterface $translator, $userId, $permissionLevel, $creationDate, $expirationDate, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkManagePermission($translator);

        $permissionLevelName = $translator->trans($this->getPermissionLevelStr($permissionLevel));
        $expirationDateClean = $this->cleanDate($expirationDate);

        $this->log($translator, $translator->trans('added') . ' ' . $translator->trans('user') . ' ' . $userId . ' ' . $translator->trans('with') . ' ' . $translator->trans('access') . ' ' . $permissionLevelName . ' ' . $translator->trans('expiring on') . ' ' . $expirationDateClean);

        $em = $this->getDoctrine()->getManager();

        $user = new User();
        $user->setUserId($userId);
        $user->setPermissionLevel($permissionLevel);
        $user->setCreationDate(new \DateTime($creationDate));
        $user->setExpirationDate(new \DateTime($expirationDate));

        $em->persist($user);
        $em->flush();

        $repository = $this->getDoctrine()->getRepository(User::class);

        $id = $user->getId();

        $this->addContentToQueue('+' . $id . '|' . $userId . '|' . $permissionLevel . '|' . $creationDate . '|' . $expirationDate, $lookForId);

        die(strval($id));
    }

    public function dateToInt($date)
    {
        return intval(str_replace('-', '', $date));
    }

    public function isBefore($date0, $date1)
    {
        return $this->dateToInt($date0) <= $this->dateToInt($date1);
    }

    public function getKeyStrArrUser(TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(User::class);
        $entities = $repository->findAll();
        $entitiesCount = count($entities);
        $entitiesStrArr = array();
        for($i = 0; $i < $entitiesCount; $i++)
        {
            $entity = $entities[$i];
            $entityId = $entity->getId();
            $entityUserId = $entity->getUserId();
            $entityPermissionLevel = $entity->getPermissionLevel();
            $entityCreationDate = $entity->getCreationDate()->format('d/m/Y');
            $entityExpirationDate = $entity->getExpirationDate()->format('d/m/Y');
            $currentDate = (new \DateTime())->format('Y/m/d');
            $expirationDate = $entity->getExpirationDate()->format('Y/m/d');
            $active = $this->isBefore($currentDate, $expirationDate);
            $arr = array(
                'userId' => $entityUserId,
                'permissionLevel' => $translator->trans($this->getPermissionLevelStr($entityPermissionLevel)),
                'creationDate' => $entityCreationDate,
                'expirationDate' => $entityExpirationDate,
                'active' => $translator->trans($active ? 'yes' : 'no')
            );
            array_push($entitiesStrArr, $arr);
        }
        $key = array($translator->trans("user id"), $translator->trans("permission level"), $translator->trans('creation date'), $translator->trans('expiration date'), $translator->trans('active'));
        $name = $translator->trans('users');
        return array($key, $entitiesStrArr, $name);
    }
    
    /**
     * @Route("/usersDownload", priority=1)
     */
    public function usersDownload(TranslatorInterface $translator)
    {
        $this->checkManagePermission($translator);
        $this->log($translator, $translator->trans('downloaded all') . ' ' . $translator->trans('users'));
        $arr = array($this->getKeyStrArrUser($translator));
        $this->commonDownloadGen($translator, $arr[0][2], $arr);
    }
    
    /**
    * @Route("/usersUpload", priority=1)
    */  
    public function usersUpload(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->checkManagePermission($translator);

        $this->log($translator, $translator->trans('uploaded all') . ' ' . $translator->trans('users'));

        $this->manageUpload($request, $translator);
        return $this->redirectToRoute('admin');
    }

    /// CONTRACTS

    /**
     * @Route("/contractsData", priority=1)
     */
    public function contractsData(Request $request, TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contracts = $repository->findAll();
        $contractsCount = count($contracts);
        $res = '';
        for($i = 0; $i < $contractsCount; $i++)
        {
            if($i != 0)
                $res .= "\n";
            $contract = $contracts[$i];
            $contractId = $contract->getId();
            $contractNumber = $contract->getNumber();
            $contractDate = $contract->getDate()->format('Y-m-d');
            $contractSupplierId = $contract->getSupplierId();
            $contractContent = $contract->getContent();
            $contractLeaderId = $contract->getContractLeaderId();
            $contractApplicativeLeaderId = $contract->getApplicativeLeaderId();
            $contractDomainId = $contract->getDomainId();
            $contractActive = $contract->getActive();
            $contractModificationDate = $contract->getModificationDate()->format('Y-m-d');
            $contractModificationUserId = $contract->getModificationUserId();
            $res .= $contractId . '|' . $contractNumber . '|' . $contractDate . '|' . $contractSupplierId . '|' . $contractContent . '|' . $contractLeaderId . '|' . $contractApplicativeLeaderId . '|' . $contractDomainId . '|' . $contractActive . '|' . $contractModificationDate . '|' . $contractModificationUserId;
        }
        $this->requireSAML($translator);
        die($res);
    }

    /**
     * @Route("/contractTimesData", priority=1)
     */
    public function contractTimesData(Request $request, TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(ContractTime::class);
        $contractTimes = $repository->findAll();
        $contractTimesCount = count($contractTimes);
        $res = '';
        for($i = 0; $i < $contractTimesCount; $i++)
        {
            if($i != 0)
                $res .= "\n";
            $contractTime = $contractTimes[$i];
            $contractTimeId = $contractTime->getId();
            $contractTimeContractId = $contractTime->getContractId();
            $contractTimeBeginDate = $contractTime->getBeginDate()->format('Y-m-d');
            $contractTimeEndDate = $contractTime->getEndDate()->format('Y-m-d');
            $contractTimeAmount = $contractTime->getAmount();
            $contractTimeBuyId = $contractTime->getBuyId();
            $contractTimeMarketId = $contractTime->getMarketId();
            $contractTimeCommandId = $contractTime->getCommandId();
            $contractTimePosteId = $contractTime->getPosteId();
            $contractTimeComment = $contractTime->getComment();
            $res .= $contractTimeId . '|' . $contractTimeContractId . '|' . $contractTimeBeginDate . '|' . $contractTimeEndDate . '|' . $contractTimeAmount . '|' . $contractTimeBuyId . '|' . $contractTimeMarketId . '|' . $contractTimeCommandId . '|' . $contractTimePosteId . '|' . $contractTimeComment;
        }
        $this->requireSAML($translator);
        die($res);
    }

    /**
    * @Route("/addContractTime/contractId={contractId}/beginDate={beginDate}/endDate={endDate}/amount={amount}/buyId={buyId}/marketId={marketId}/commandId={commandId}/posteId={posteId}/comment={comment}/lookForId={lookForId}", requirements={"amount"=".*", "buyId"=".*", "marketId"=".*", "commandId"=".*", "posteId"=".*", "comment"=".*", "lookForId"=".*"})
    */
    public function addContractTime(Request $request, TranslatorInterface $translator, $contractId, $beginDate, $endDate, $amount, $buyId, $marketId, $commandId, $posteId, $comment, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $beginDateClean = $this->cleanDate($beginDate);
        $endDateClean = $this->cleanDate($endDate);

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contract = $repository->findOneBy(['id' => $contractId]);

        if($amount == "")
            $amount = "0";

        $this->log($translator, $translator->trans('added') . ' ' . $translator->trans('contract time') . ' ' . $translator->trans('with') . ' ' . $translator->trans('number') . ' ' . $contract->getNumber() . ' (' . $translator->trans('contract id') . ' = ' . $contractId . ', ' . $translator->trans('begin date') . ' = ' . $beginDateClean . ', ' . $translator->trans('end date') . ' = ' . $endDateClean . ', ' . $translator->trans('amount') . ' = ' . $amount . ', ' . $translator->trans('buy id') . ' = ' . $buyId . ', ' . $translator->trans('market id') . ' = ' . $marketId . ', ' . $translator->trans('command id') . ' = ' . $commandId . ', ' . $translator->trans('poste id') . ' = ' . $posteId . ', ' . $translator->trans('comment') . ' = ' . $comment . ')');

        $em = $this->getDoctrine()->getManager();

        $contractTime = new ContractTime();
        $contractTime->setContractId($contractId);
        $contractTime->setBeginDate(new \DateTime($beginDate));
        $contractTime->setEndDate(new \DateTime($endDate));
        $contractTime->setAmount($amount);
        $contractTime->setBuyId($buyId);
        $contractTime->setMarketId($marketId);
        $contractTime->setCommandId($commandId);
        $contractTime->setPosteId($posteId);
        $contractTime->setComment($comment);

        $em->persist($contractTime);
        $em->flush();

        $contractTimeId = $contractTime->getId();

        $this->addContentToQueue('cT+' . $contractTimeId . '|' . $contractId . '|' . $beginDate . '|' . $endDate . '|' . $amount . '|' . $buyId . '|' . $marketId . '|' . $commandId . '|' . $posteId . '|' . $comment, $lookForId);
        $this->addContentToQueue('_' . $contractId . '|' . $contract->getNumber() . '|' . $contract->getDate()->format('Y-m-d') . '|' . $contract->getSupplierId() . '|' . $contract->getContent() . '|' . $contract->getContractLeaderId() . '|' . $contract->getApplicativeLeaderId() . '|' . $contract->getDomainId() . '|' . $contract->getActive() . '|' . $contract->getModificationDate()->format('Y-m-d') . '|' . $contract->getModificationUserId(), $lookForId);

        die(strval($contractTimeId));
    }

    // using identifier for each parameter even with Symfony defaults annotation doesn't make it work
    // it's quite bad to "receive moditification data" like date and collaborator but let's do like this for generality (and so ease) and in case we want admin to be able to change them
    /**
    * @Route("/addContract/number={number}/date={date}/supplierId={supplierId}/content={content}/leaderId={leaderId}/applicativeLeaderId={applicativeLeaderId}/domainId={domainId}/active={active}/modificationDate={modificationDate}/modificationUserId={modificationUserId}/lookForId={lookForId}", requirements={"number"=".*", "content"=".*", "lookForId"=".*"})
    */
    public function addContract(Request $request, TranslatorInterface $translator, $number, $date, $supplierId, $content, $leaderId, $applicativeLeaderId, $domainId, $active, $modificationDate, $modificationUserId, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $repository = $this->getDoctrine()->getRepository(Supplier::class);
        $supplier = $repository->findOneBy(['id' => $supplierId]);

        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $leader = $repository->findOneBy(['id' => $leaderId]);
        $applicativeLeader = $repository->findOneBy(['id' => $applicativeLeaderId]);

        $repository = $this->getDoctrine()->getRepository(Domain::class);
        $domain = $repository->findOneBy(['id' => $domainId]);

        $dateClean = $this->cleanDate($date);

        $this->log($translator, $translator->trans('added') . ' ' . $translator->trans('contract') . ' (' . $translator->trans('number') . ' = ' . $number . ', ' . $translator->trans('date') . ' = ' . $dateClean . ', ' . $translator->trans('supplier') . ' = ' . $supplier->getName() . ', ' . $translator->trans('content') . ' = ' . $content . ', ' . $translator->trans('leader') . ' = ' . $leader->getName() . ', ' . $translator->trans('applicative leader') . ' = ' . $applicativeLeader->getName() . ', ' . $translator->trans('domain') . ' = ' . $domain->getName() . ', ' . $translator->trans('active') . ' = ' . $translator->trans($active ? 'yes' : 'no') . ')');

        $em = $this->getDoctrine()->getManager();

        $contract = new Contract();
        $contract->setNumber($number);
        $contract->setDate(new \DateTime($date));
        $contract->setSupplierId($supplierId);
        $contract->setContent($content); // make an escape system for '|' ?
        $contract->setContractLeaderId($leaderId);
        $contract->setApplicativeLeaderId($applicativeLeaderId);
        $contract->setDomainId($domainId);
        $contract->setActive($active);
        $contract->setModificationDate(new \DateTime($modificationDate));
        $contract->setModificationUserId($modificationUserId);

        $em->persist($contract);
        $em->flush();

        $contractId = $contract->getId();

        $this->addContentToQueue('+' . $contractId . '|' . $number . '|' . $date . '|' . $supplierId . '|' . $content . '|' . $leaderId . '|' . $applicativeLeaderId . '|' . $domainId . '|' . $active . '|' . $modificationDate . '|' . $modificationUserId, $lookForId);

        die(strval($contractId));
    }

    /**
     * @Route("/deleteContract/{id}/{lookForId}", priority=1)
     */
    public function deleteContract(Request $request, TranslatorInterface $translator, $id, $lookForId)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'contracts');
        $em = $this->getDoctrine()->getManager();
        $this->checkEditPermission($translator);

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contract = $repository->findOneBy(['id' => $id]);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('contract') . ' ' . $translator->trans('with') . ' ' . $translator->trans('number') . ' ' . $contract->getNumber());

        $repository = $this->getDoctrine()->getRepository(ContractTime::class);
        $contractTimes = $repository->findBy(['contractId' => $id]);

        $contractTimesCount = count($contractTimes);
        for($contractTimesIndex = 0; $contractTimesIndex < $contractTimesCount; $contractTimesIndex++)
        {
            $contractTime = $contractTimes[$contractTimesIndex];
            $em->remove($contractTime);
        }

        $em->remove($contract);
        $em->flush();
        
        $this->addContentToQueue('-' . $id, $lookForId);

        die("");
    }

    /**
     * @Route("/deleteContractTime/{id}/{lookForId}", priority=1)
     */
    public function deleteContractTime(Request $request, TranslatorInterface $translator, $id, $lookForId)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'contracts');
        $em = $this->getDoctrine()->getManager();
        $this->checkEditPermission($translator);

        $repository = $this->getDoctrine()->getRepository(ContractTime::class);
        $contractTime = $repository->findOneBy(['id' => $id]);

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contract = $repository->findOneBy(['id' => $contractTime->getContractId()]);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('contract time') . ' ' . $translator->trans('with') . ' ' . $translator->trans('number') . ' ' . $contract->getNumber() . ' (' . $translator->trans('begin date') . ' = ' . $contractTime->getBeginDate()->format('d/m/Y') . ', ' . $translator->trans('end date') . ' = ' . $contractTime->getEndDate()->format('d/m/Y') . ', ' . $translator->trans('amount') . ' = ' . $contractTime->getAmount() . ', ' . $translator->trans('buy id') . ' = ' . $contractTime->getBuyId() . ', ' . $translator->trans('market id') . ' = ' . $contractTime->getMarketId() . ', ' . $translator->trans('command id') . ' = ' . $contractTime->getCommandId() . ', ' . $translator->trans('poste id') . ' = ' . $contractTime->getPosteId() . ', ' . $translator->trans('comment') . ' = ' . $contractTime->getComment() . ')');

        $em->remove($contractTime);
        $em->flush();

        $this->addContentToQueue('cT-' . $id, $lookForId);

        die("");
    }

    // for debug purpose
    /**
     * @Route("/deleteContracts")
     */
    public function deleteContracts(Request $request, TranslatorInterface $translator)
    {
        $em = $this->getDoctrine()->getManager();
        $this->checkEditPermission($translator);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('all elements of') . ' ' . $translator->trans('contracts'));

        $this->dropTable($em, Contract::class);
        $this->dropTable($em, ContractTime::class);

        die("");
    }

    // for debug purpose
    /**
     * @Route("/deleteContractTimes")
     */
    public function deleteContractTimes(Request $request, TranslatorInterface $translator)
    {
        $em = $this->getDoctrine()->getManager();
        $this->checkEditPermission($translator);

        $this->log($translator, $translator->trans('deleted') . ' ' . $translator->trans('all elements of') . ' ' . $translator->trans('contract times'));
    
        $this->dropTable($em, ContractTime::class);

        die("");
    }

    public function getNameFromId($class, $id, TranslatorInterface $translator)
    {
        return $translator->trans($this->getDoctrine()->getRepository($class)->findOneBy(['id' => $id])->getName());
    }

    public function getKeyStrArrContract(TranslatorInterface $translator)
    {
        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $repositoryCT = $this->getDoctrine()->getRepository(ContractTime::class);
        $contracts = $repository->findAll();
        $contractsCount = count($contracts);
        $contractsStrArr = array();
        $maxRealContractTimesCount = 0;
        for($i = 0; $i < $contractsCount; $i++)
        {
            $contract = $contracts[$i];
            $contractId = $contract->getId();
            $contractNumber = $contract->getNumber();
            $contractDate = $contract->getDate()->format('d/m/Y');
            $contractSupplierId = $contract->getSupplierId();
            $contractContent = $contract->getContent();
            $contractLeaderId = $contract->getContractLeaderId();
            $contractApplicativeLeaderId = $contract->getApplicativeLeaderId();
            $contractDomainId = $contract->getDomainId();
            $contractActive = $contract->getActive();
            $contractModificationDate = $contract->getModificationDate()->format('d/m/Y');
            $contractModificationUserId = $contract->getModificationUserId();
            $arr = array(
                'number' => $contractNumber,
                'date' => $contractDate,
                'supplier' => $this->getNameFromId(Supplier::class, $contractSupplierId, $translator),
                'content' => $contractContent,
                'contract_leader' => $this->getNameFromId(Collaborator::class, $contractLeaderId, $translator),
                'applicative_leader' => $this->getNameFromId(Collaborator::class, $contractApplicativeLeaderId, $translator),
                'domain_id' => $this->getNameFromId(Domain::class, $contractDomainId, $translator),
                'active' => $translator->trans($contractActive ? 'yes' : 'no'),
                'modification_date' => $contractModificationDate,
                'modification_user' => $translator->trans($this->getDoctrine()->getRepository(User::class)->findOneBy(['id' => $contractModificationUserId])->getUserId())
            );
            $contractTimes = $repositoryCT->findBy(['contractId' => $contractId]);
            $contractTimesCount = count($contractTimes);
            $realContractTimesCount = min(5, $contractTimesCount);
            if($realContractTimesCount > $maxRealContractTimesCount)
                $maxRealContractTimesCount = $realContractTimesCount;
            for($contractTimesIndex = 0; $contractTimesIndex < $realContractTimesCount; $contractTimesIndex++)
            {
                $contractTime = $contractTimes[$contractTimesIndex];
                $contractTimeArr = array(
                    'begin_date' => $contractTime->getBeginDate()->format('d/m/Y'),
                    'end_date' => $contractTime->getEndDate()->format('d/m/Y'),
                    'amount' => $contractTime->getAmount(),
                    'buy_id' => $contractTime->getBuyId(),
                    'market_id' => $contractTime->getMarketId(),
                    'command_id' => $contractTime->getCommandId(),
                    'poste_id' => $contractTime->getPosteId(),
                    'comment' => $contractTime->getComment(),
                );
                foreach($arr as $k => $v)
                {
                    $arr[$k . $contractTimesIndex] = $v;
                    unset($arr[$k]);
                }
                $arr = array_merge($arr, $contractTimeArr);
            }
            array_push($contractsStrArr, $arr);
        }
        $key = array($translator->trans("number"), $translator->trans("date"), $translator->trans("supplier"), $translator->trans("content"), $translator->trans("contract_leader"), $translator->trans("applicative_leader"), $translator->trans("domain"), $translator->trans('active'), $translator->trans("modification_date"), $translator->trans("modification_user"));
        $contractTimeKey = array($translator->trans("begin date"), $translator->trans("end date"), $translator->trans("amount"), $translator->trans("buy id"), $translator->trans("market id"), $translator->trans("command id"), $translator->trans("poste id"), $translator->trans("comment"));
        for($i = 0; $i < $maxRealContractTimesCount; $i++)
        {
            $key = array_merge($key, $contractTimeKey);
        }

        $name = $translator->trans('contracts');
        return array($key, $contractsStrArr, $name);
    }
    
    /**
     * @Route("/contractsDownload", priority="1")
     */
    public function contractsDownload(TranslatorInterface $translator)
    {
        $arr = array($this->getKeyStrArrContract($translator));
        $this->requireSAML($translator);
        $this->log($translator, $translator->trans('downloaded all') . ' ' . $translator->trans('contracts'));
        $this->commonDownloadGen($translator, $arr[0][2], $arr);
    }
    
    /**
    * @Route("/contractsUpload", priority="1")
    */  
    public function contractsUpload(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $this->log($translator, $translator->trans('uploaded all') . ' ' . $translator->trans('contracts'));

        $this->manageUpload($request, $translator);
        return $this->redirectToRoute('contracts');
    }

    /**
     * @Route("/modifyContract/id={id}/number={number}/date={date}/supplierId={supplierId}/content={content}/leaderId={leaderId}/applicativeLeaderId={applicativeLeaderId}/domainId={domainId}/active={active}/modificationDate={modificationDate}/modificationUserId={modificationUserId}/lookForId={lookForId}", requirements={"number"=".*", "content"=".*"}, priority=1)
     */
    public function modifyContract(Request $request, TranslatorInterface $translator, $id, $number, $date, $supplierId, $content, $leaderId, $applicativeLeaderId, $domainId, $active, $modificationDate, $modificationUserId, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contract = $repository->findOneBy(['id' => $id]);

        $repository = $this->getDoctrine()->getRepository(Supplier::class);
        $supplier = $repository->findOneBy(['id' => $contract->getSupplierId()]);
        $newSupplier = $repository->findOneBy(['id' => $supplierId]);

        $repository = $this->getDoctrine()->getRepository(Collaborator::class);
        $leader = $repository->findOneBy(['id' => $contract->getContractLeaderId()]);
        $newLeader = $repository->findOneBy(['id' => $leaderId]);
        $applicativeLeader = $repository->findOneBy(['id' => $contract->getApplicativeLeaderId()]);
        $newApplicativeLeader = $repository->findOneBy(['id' => $applicativeLeaderId]);

        $repository = $this->getDoctrine()->getRepository(Domain::class);
        $domain = $repository->findOneBy(['id' => $contract->getDomainId()]);
        $newDomain = $repository->findOneBy(['id' => $domainId]);

        // could display only modified fields
        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans('contract') . ' (' . $translator->trans('number') . ' = ' . $contract->getNumber() . ' -> ' . $number . ', ' . $translator->trans('date') . ' = ' . $contract->getDate()->format('d/m/Y') . ' -> ' . $this->cleanDate($date) . ', ' . $translator->trans('supplier') . ' = ' . $supplier->getName() . ' -> ' . $newSupplier->getName() . ', ' . $translator->trans('content') . ' = ' . $contract->getContent() . ' -> ' . $content . ', ' . $translator->trans('leader') . ' = ' . $leader->getName() . ' -> ' . $newLeader->getName() . ', ' . $translator->trans('applicative leader') . ' = ' . $applicativeLeader->getName() . ' -> ' . $newApplicativeLeader->getName() . ', ' . $translator->trans('domain') . ' = ' . $domain->getName() . ' -> ' . $newDomain->getName() . ')');

        $contract->setNumber($number);
        $contract->setDate(new \DateTime($date));
        $contract->setSupplierId($supplierId);
        $contract->setContent($content);
        $contract->setContractLeaderId($leaderId);
        $contract->setApplicativeLeaderId($applicativeLeaderId);
        $contract->setDomainId($domainId);
        $contract->setActive($active);

        $trustClient = false; // used for debugging

        $repository = $this->getDoctrine()->getRepository(User::class);
        $user = $repository->findOneBy(['userId' => $this->userId]);

        $contract->setModificationDate($trustClient ? new \DateTime($modificationDate) : new \DateTime());
        $contract->setModificationUserId($trustClient ? $modificationUserId : $user->getId());
        $em->flush();

        $this->addContentToQueue('_' . $id . '|' . $number . '|' . $date . '|' . $supplierId . '|' . $content . '|' . $leaderId . '|' . $applicativeLeaderId . '|' . $domainId . '|' . $active . '|' . $modificationDate . '|' . $modificationUserId, $lookForId);

        die();
    }

    /**
     * @Route("/modifyContractTime/id={id}/beginDate={beginDate}/endDate={endDate}/amount={amount}/buyId={buyId}/marketId={marketId}/commandId={commandId}/posteId={posteId}/comment={comment}/lookForId={lookForId}", requirements={"amount"=".*", "buyId"=".*", "marketId"=".*", "commandId"=".*", "posteId"=".*", "comment"=".*"}, priority=1)
     */
    public function modifyContractTime(Request $request, TranslatorInterface $translator, $id, $beginDate, $endDate, $amount, $buyId, $marketId, $commandId, $posteId, $comment, $lookForId)
    {
        $this->initializeSession($request);
        $this->checkEditPermission($translator);

        $em = $this->getDoctrine()->getManager();

        $repository = $this->getDoctrine()->getRepository(ContractTime::class);
        $contractTime = $repository->findOneBy(['id' => $id]);

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contract = $repository->findOneBy(['id' => $contractTime->getContractId()]);

        $this->log($translator, $translator->trans('modified') . ' ' . $translator->trans('contract time') . ' (' . $translator->trans('contract') . ' = ' . $contract->getNumber() . ', ' . $translator->trans('begin date') . ' = ' . $contractTime->getBeginDate()->format('d/m/Y') . ' -> ' . $this->cleanDate($beginDate) . ', ' . $translator->trans('end date') . ' = ' . $contractTime->getEndDate()->format('d/m/Y') . ' -> ' . $this->cleanDate($endDate) . ', ' . $translator->trans('amount') . ' = ' . $contractTime->getAmount() . ' -> ' . $amount . ', ' . $translator->trans('buy id') . ' = ' . $contractTime->getBuyId() . ' -> ' . $buyId . ', ' . $translator->trans('market id') . ' = ' . $contractTime->getMarketId() . ' -> ' . $marketId . ', ' . $translator->trans('command id') . ' = ' . $contractTime->getCommandId() . ' -> ' . $commandId . ', ' . $translator->trans('poste id') . ' = ' . $contractTime->getPosteId() . ' -> ' . $posteId . ', ' . $translator->trans('comment') . ' = ' . $comment . ')');

        $contractTime->setBeginDate(new \DateTime($beginDate));
        $contractTime->setEndDate(new \DateTime($endDate));
        if($amount == '') $amount = 0;
        $contractTime->setAmount($amount);
        $contractTime->setBuyId($buyId);
        $contractTime->setMarketId($marketId);
        $contractTime->setCommandId($commandId);
        $contractTime->setPosteId($posteId);
        $contractTime->setComment($comment);

        $repository = $this->getDoctrine()->getRepository(User::class);
        $user = $repository->findOneBy(['userId' => $this->userId]);

        $contract->setModificationDate(new \DateTime()); // we used a single lookFor loop in JavaScript in order to keep speed (because multithreading in JavaScript on client side isn't allowed to modify the DOM)
        $contract->setModificationUserId($user->getId());
        $em->flush();

        $this->addContentToQueue('cT_' . $id . '|' . $contractTime->getContractId() . '|' . $beginDate . '|' . $endDate . '|' . $amount . '|' . $buyId . '|' . $marketId . '|' . $commandId . '|' . $posteId . '|' . $comment, $lookForId);
        $this->addContentToQueue('_' . $contractTime->getContractId() . '|' . $contract->getNumber() . '|' . $contract->getDate()->format('Y-m-d') . '|' . $contract->getSupplierId() . '|' . $contract->getContent() . '|' . $contract->getContractLeaderId() . '|' . $contract->getApplicativeLeaderId() . '|' . $contract->getDomainId() . '|' . $contract->getActive() . '|' . $contract->getModificationDate()->format('Y-m-d') . '|' . $contract->getModificationUserId(), $lookForId);

        die();
    }

    /**
     * @Route("/contractsPerYear/{unitId}/{supplierId}")
     */
    public function contractsPerYear(TranslatorInterface $translator, $unitId, $supplierId)
    {
        $this->contractsPer($translator, 'Year', $unitId, $supplierId);
    }

    /**
     * @Route("/contractsPer{id}")
     */
    public function contractsPer(TranslatorInterface $translator, $id, $unitId = -1, $supplierId = -1)
    {
        if($unitId == -1) $unitId = $this->JS_MAX_SAFE_INT;
        if($supplierId == -1) $supplierId = $this->JS_MAX_SAFE_INT;
        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $repositoryUnit = $this->getDoctrine()->getRepository(Unit::class);
        $els = [];
        $contracts = $repository->findAll();
        $repositoryCT = $this->getDoctrine()->getRepository(ContractTime::class);
        $repositoryCollaborator = $this->getDoctrine()->getRepository(Collaborator::class);
        if($id == 'Direction')
            $repositoryDomain = $this->getDoctrine()->getRepository(Domain::class);
        $contractsCount = count($contracts);
        for($contractsIndex = 0; $contractsIndex < $contractsCount; $contractsIndex++)
        {
            $contract = $contracts[$contractsIndex];
            if(!$contract->getActive()) continue; // could add a checkbox to choose which but as always would need to checkbox also to enable inactive, active and both
            $contractId = $contract->getId();
            if($id != 'Direction')
            {
                if($unitId != $this->JS_MAX_SAFE_INT || $supplierId != $this->JS_MAX_SAFE_INT || $id == 'Amount')
                {
                    if($id != 'Amount')
                        $contractSupplierId = $contract->getSupplierId();
                    $contractCollaboratorId = $contract->getContractLeaderId();
                    $contractCollaborator = $repositoryCollaborator->findOneBy(['id' => $contractCollaboratorId]);
                    if($unitId != $this->JS_MAX_SAFE_INT && $unitId != $contractCollaborator->getUnitId())
                        continue;
                    if($supplierId != $this->JS_MAX_SAFE_INT && $supplierId != $contractSupplierId)
                        continue;
                }
            }
            $contractDomainId = $contract->getDomainId();
            if($id == 'Direction')
            {
                $domain = $repositoryDomain->findOneBy(['id' => $contractDomainId]);
                $elName = $domain->getName();
            }
            else
                $elName = $contract->getDate()->format('Y');
            if($id == 'Amount')
            {
                $contractCollaboratorUnitId = $contractCollaborator->getUnitId();
                $contractCollaboratorUnit = $repositoryUnit->findOneBy(['id' => $contractCollaboratorUnitId]);
                $elName .= '_' . $contractCollaboratorUnit->getName();
            }
            $contractTimes = $repositoryCT->findBy(['contractId' => $contractId]);
            $contractTimesCount = count($contractTimes);
            $contractsNumberAmount = array_key_exists($elName, $els) ? $els[$elName] : [0, 0];
            if($id == 'Direction')
                $els[$elName] = [$contractsNumberAmount[0] + 1, $contractsNumberAmount[1]];
            $totalAmountIsZero = true;
            if($id != 'Direction')
            {
                $hasMaintenanceThisYear = false;
                $currentDatetime = new \Datetime();
                $currentYear = intval($currentDatetime->format('Y'));
            }
            for($contractTimesIndex = 0; $contractTimesIndex < $contractTimesCount; $contractTimesIndex++)
            {
                $contractTime = $contractTimes[$contractTimesIndex];
                if($id == 'Direction')
                    $contractsNumberAmount = array_key_exists($elName, $els) ? $els[$elName] : [0, 0];
                if($contractTime->getAmount() > 0)
                    $totalAmountIsZero = false;
                if($id != 'Direction')
                {
                    $beginDate = $contractTime->getBeginDate();
                    $endDate = $contractTime->getEndDate();
                    if(!$hasMaintenanceThisYear)
                    {
                        $beginYear = intval($beginDate->format('Y'));
                        $endYear = intval($endDate->format('Y'));
                        for($currentYear = $beginYear; $currentYear <= $endYear; $currentYear++)
                        {
                            $toAdd = $contractTime->getAmount();
                            if($endYear != $beginYear)
                                $toAdd /= ($endYear - $beginYear);
                            $key = $currentYear . ($id == 'Amount' ? '_' . $contractCollaboratorUnit->getName() : '');
                            $contractsNumberAmount = array_key_exists($key, $els) ? $els[$key] : [0, 0];
                            $els[$key] = [$contractsNumberAmount[0] + 1, $contractsNumberAmount[1] + $toAdd];
                            $hasMaintenanceThisYear = true;
                        }
                    }
                }
                else
                {
                    $els[$elName] = [$contractsNumberAmount[0], $contractsNumberAmount[1] + $contractTime->getAmount()];
                }
            }
            if($id == 'Year' && !$hasMaintenanceThisYear)
            {
                unset($els[$elName]);
            }
            else if($totalAmountIsZero)
            {
                $contractsNumberAmount = $els[$elName];
                if($contractsNumberAmount[0] == 1)
                    unset($els[$elName]);
            }
        }
        $res = '';
        $elsCount = count($els);
        $elsIndex = 0;
        ksort($els);
        foreach($els as $domain => $contractsNumberAmount)
        {
            $res .= $domain . '|' . $contractsNumberAmount[0] . '|' . $contractsNumberAmount[1];
            if($elsIndex < $elsCount - 1)
                $res .= "\n";
            $elsIndex++;
        }
        $this->requireSAML($translator);
        die($res);
    }
    
    public function contractCommon($translator, $form)
    {
        return array(
            'number' => $translator->trans('number'),
            'supplier' => $translator->trans('supplier'),
            'content' => $translator->trans('content'),
            'leader' => $translator->trans('leader'),
            'applicative_leader' => $translator->trans('applicative leader'),
            'domain' => $translator->trans('domain'),
            'active' => $translator->trans('active'),
            'unit' => $translator->trans('unit'),
            'form' => $form->createView(),
            'beginDate' => $translator->trans('begin date'),
            'endDate' => $translator->trans('end date'),
            'amount' => $translator->trans('amount'),
            'buyId' => $translator->trans('buy id'),
            'marketId' => $translator->trans('market id'),
            'commandId' => $translator->trans('command id'),
            'posteId' => $translator->trans('poste id'),
            'comment' => $translator->trans('comment'),
            'yes' => $translator->trans('yes'),
            'no' => $translator->trans('no'),
            'aValueForSupplierMustBeProvided' => $translator->trans('a value for supplier must be provided'),
            'aValueForLeaderMustBeProvided' => $translator->trans('a value for leader must be provided'),
            'aValueForApplicativeLeaderMustBeProvided' => $translator->trans('a value for applicative leader must be provided'),
            'aValueForDomainMustBeProvided' => $translator->trans('a value for domain must be provided'),
            'aValueForActiveMustBeProvided' => $translator->trans('a value for active must be provided'),
            'aValueForBeginDateMustBeProvided' => $translator->trans('a value for begin date must be provided'),
            'aValueForEndDateMustBeProvided' => $translator->trans('a value for end date must be provided'),
        );
    }

    /**
     * @Route("/contractForm")
     */
    public function contractForm(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $contract = new Contract();

        $doc = $this->getDoctrine();

        $form = $this->createForm(ContractType::class, $contract, [
            'entity_manager' => $doc,
        ]);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $em->persist($contract);
            $em->flush();
        }

        $arr = array(
            'formToAddContract' => $translator->trans('form to add a contract'),
            'contractPeriod' => $translator->trans('contract period'),
            'today' => (new \DateTime())->format('Y-m-d'),
            'addAContractTime' => $translator->trans('add a contract time'),
            'deleteThisContractTime' => $translator->trans('delete this contract time'),
            'addThisContract' => $translator->trans('add this contract')
        );
        
        $arr = array_merge($arr, $this->contractCommon($translator, $form));

        $this->checkEditPermission($translator);

        $arr = array_merge($arr, $this->contractHelp($translator));

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed to') . ' ' . $translator->trans('contract form'));

        if($this->hasPermissionToManage)
            $arrEditing['manage'] = '';
        $arr = array_merge($arr, $arrEditing);

        return $this->twig('contractForm.html.twig', $arr, $translator, 'contractForm');
    }

    public function contractHelp(TranslatorInterface $translator)
    {
        $arr = array(
            'dateHelp' => $translator->trans('date help'),
            'numberHelp' => $translator->trans('number help'),
            'supplierHelp' => $translator->trans('supplier help'),
            'contentHelp' => $translator->trans('content help'),
            'leaderHelp' => $translator->trans('leader help'),
            'applicative_leaderHelp' => $translator->trans('applicative leader help'),
            'domainHelp' => $translator->trans('domain help'),
            'activeHelp' => $translator->trans('active help'),
            'modification_dateHelp' => $translator->trans('modification date help'),
            'modification_user_idHelp' => $translator->trans('modification user help'),
            'beginDateHelp' => $translator->trans('begin date help'),
            'endDateHelp' => $translator->trans('end date help'),
            'amountHelp' => $translator->trans('amount help'),
            'buyIdHelp' => $translator->trans('buy id help'),
            'marketIdHelp' => $translator->trans('market id help'),
            'commandIdHelp' => $translator->trans('command id help'),
            'posteIdHelp' => $translator->trans('poste id help'),
            'commentHelp' => $translator->trans('comment help')
        );
        return $arr;
    }

    /**
     * @Route("/contracts", name="contracts")
     */
    public function contracts(Request $request, TranslatorInterface $translator)
    {
        $this->initializeSession($request);
        $this->session->set('page', 'contracts');
        $contract = new Contract();

        $doc = $this->getDoctrine();

        $form = $this->createForm(ContractType::class, $contract, [
            'entity_manager' => $doc,
        ]);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $em->persist($contract);
            $em->flush();
        }
        
        function getArray($doc, $class)
        {
            $repository = $doc->getRepository($class);
            $elements = $repository->findAll();
            $elementsCount = count($elements);
            $contractElements = array();
            for($i = 0; $i < $elementsCount; $i++)
            {
                $element = $elements[$i];
                $elementName = $class == User::class ? $element->getUserId() : $element->getName();
                $elementId = $element->getId();
                $contractElements[$elementId] = $elementName;
            }
            return $contractElements;
        }
        
        $contractSuppliers = getArray($doc, Supplier::class);
        $contractDomains = getArray($doc, Domain::class);
        $contractLeaders = getArray($doc, Collaborator::class);
        $contractUsers = getArray($doc, User::class);

        $repository = $this->getDoctrine()->getRepository(Contract::class);
        $contracts = $repository->findAll();
        $contractsCount = count($contracts);
        $contractsStrArr = array();
        for($i = 0; $i < $contractsCount; $i++)
        {
            $contract = $contracts[$i];
            $contractId = $contract->getId();
            $contractNumber = $contract->getNumber();
            $contractDate = $contract->getDate()->format('d/m/Y');
            
            $contractSupplierId = $contract->getSupplierId();
            $contractSupplier = $contractSuppliers[$contractSupplierId];
            
            $contractContent = $contract->getContent();
            
            $contractLeaderId = $contract->getContractLeaderId();
            $contractLeader = $contractLeaders[$contractLeaderId];
            
            $contractApplicativeLeaderId = $contract->getApplicativeLeaderId();
            $contractApplicativeLeader = $contractLeaders[$contractApplicativeLeaderId];
            
            $contractDomainId = $contract->getDomainId();
            $contractDomain = $contractDomains[$contractDomainId];

            $contractModificationDate = $contract->getModificationDate()->format('d/m/Y');

            $contractModificationUserId = $contract->getModificationUserId();
            $contractModificationUser = $contractUsers[$contractModificationUserId];

            $contractActive = $contract->getActive();

            // or could use https://symfony.com/doc/current/doctrine.html#querying-for-objects-the-repository
            $arr = array(
                'id' => $contractId,
                'number' => $contractNumber,
                'date' => $contractDate,
                'supplier' => $contractSupplier,
                'content' => $contractContent,
                'contract_leader' => $contractLeader,
                'applicative_leader' => $contractApplicativeLeader,
                'domain' => $contractDomain,
                'active' => $contractActive,
                'modification_date' => $contractModificationDate,
                'modification_user_id' => $contractModificationUserId
            );
            array_push($contractsStrArr, $arr);
        }

        $arr = array(
            'allOf' => $translator->trans('all of'),
            'all' => $translator->trans('all'),
            'date' => $translator->trans('date'),
            'modification_date' => $translator->trans('modification date'),
            'modification_user_id' => $translator->trans('modification user'),
            'actions' => $translator->trans('actions'),
            'contracts' => $contractsStrArr,
            'editMsg' => $translator->trans('edit'),
            'add' => $translator->trans('add'),
            'delete' => $translator->trans('delete'),
            'download' => $translator->trans('Download as CSV'),
            'upload' => $translator->trans('Upload as CSV'),
            'previousPage' => $translator->trans('previous page'),
            'nextPage' => $translator->trans('next page')
        );
        $arr = array_merge($arr, $this->contractCommon($translator, $form));

        $this->requireSAML($translator);

        if($this->alsoLogNonEditingActions)
            $this->log($translator, $translator->trans('accessed to') . ' ' . $translator->trans('contracts'));

        if($this->hasPermissionToEdit)
        {
            $arrEditing = array(
                'actions' => $translator->trans('actions'),
                'add' => $translator->trans('add'),
                'delete' => $translator->trans('delete'),
                'edit' => '' // just to exist
            );
        
            if($this->hasPermissionToManage)
                $arrEditing['manage'] = '';
                //array_push($arrEditing, 'manage'); // this doesn't work
            $arr = array_merge($arr, $arrEditing);
        }

        $arr = array_merge($arr, $this->contractHelp($translator));

        return $this->twig('contracts.html.twig', $arr, $translator, 'contracts');
    }
}
