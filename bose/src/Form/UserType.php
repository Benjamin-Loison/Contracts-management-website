<?php 

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
		$builder
			->add('userId', TextType::class, array('label' => false, 'attr' => array('autofocus' => true)))
			->add('permissionLevel', ChoiceType::class, array('label' => false,
					'row_attr' => ['class' => 'center'],
					'choices' => [
						'' => 9007199254740991,	
						'viewer' => 0,
						'editor' => 1,
						'administrator' => 2
				]))
			->add('creationDate', DateType::class, array('label' => false, 'widget' => 'single_text', 'disabled' => true))
			->add('expirationDate', DateType::class, array('label' => false, 'widget' => 'single_text'))
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
