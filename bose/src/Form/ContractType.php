<?php 

namespace App\Form;

use App\Entity\Contract;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use App\Entity\Supplier;
use App\Entity\Collaborator;
use App\Entity\Domain;
use App\Entity\User;

class ContractType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options) // we can't overload this function to give the doctrine variable
	{
		$doc = $options['entity_manager']; // free name like 'translator' aren't available

		$JS_MAX_SAFE_INT = 9007199254740991;

		function getArr($class, $doc, $JS_MAX_SAFE_INT)
		{
			$repository = $doc->getRepository($class);
        	$els = $repository->findAll();
        	$elsCount = count($els);
        	$elsArr = array('' => $JS_MAX_SAFE_INT);
        	for($i = 0; $i < $elsCount; $i++)
        	{
            	$el = $els[$i];
            	$elName = $class == User::class ? $el->getUserId() : $el->getName();
            	$elId = $el->getId();
            	$elsArr[$elName] = $elId;
        	}
			return $elsArr;
		}

		$suppliersArr = getArr(Supplier::class, $doc, $JS_MAX_SAFE_INT);
		$collaboratorsArr = getArr(Collaborator::class, $doc, $JS_MAX_SAFE_INT);
		$domainsArr = getArr(Domain::class, $doc, $JS_MAX_SAFE_INT);
		$usersArr = getArr(User::class, $doc, $JS_MAX_SAFE_INT);
		$activeArr = ['' => $JS_MAX_SAFE_INT, 'non' => 0, 'oui' => 1];

		$builder
			->add('number', TextType::class, array('label' => false, 'attr' => array('autofocus' => true)))
            ->add('date', DateType::class, array('label' => false, 'widget' => 'single_text', 'disabled' => true)) // some parameters aren't available for change (like last modification date/user...)
			->add('supplier_id', ChoiceType::class, [ // ChoiceType looks more appropriate than RadioType
					'label' => false,
					'choices' => $suppliersArr,
					'choice_translation_domain' => false
				])
			->add('content', TextType::class, array('label' => false))
			->add('contract_leader_id', ChoiceType::class, [
					'label' => false,
					'choices' => $collaboratorsArr,
					'choice_translation_domain' => false
				])
			->add('applicative_leader_id', ChoiceType::class, [
					'label' => false,
					'choices' => $collaboratorsArr,
					'choice_translation_domain' => false
				])
			->add('domain_id', ChoiceType::class, [
					'label' => false,
					'choices' => $domainsArr,
					'choice_translation_domain' => false
				])
			->add('active', ChoiceType::class, [
                    'label' => false,
                    'choices' => $activeArr,
                    'choice_translation_domain' => false
                ])
			->add('modification_date', DateType::class, array('label' => false, 'widget' => 'single_text', 'disabled' => true))
			->add('modification_user_id', ChoiceType::class, [
                    'label' => false,
                    'choices' => $usersArr,
					'choice_translation_domain' => false,
					'disabled' => true
				])
			;
    }
    
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Contract::class,
		]);
		$resolver->setRequired('entity_manager');
    }
}
