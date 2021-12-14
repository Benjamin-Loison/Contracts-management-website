<?php 

namespace App\Form;

use App\Entity\Collaborator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use App\Entity\Unit;

class CollaboratorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $doc = $options['entity_manager'];

        $repository = $doc->getRepository(Unit::class);
        $units = $repository->findAll();
        $unitsCount = count($units);
        $unitsArr = array('' => 9007199254740991); // JavaScript MAX_SAFE_INTEGER
        for($i = 0; $i < $unitsCount; $i++)
        {
            $unit = $units[$i];
            $unitName = $unit->getName();
            $unitId = $unit->getId();
            $unitsArr[$unitName] = $unitId;
        }

        $builder // we manage all labels by hand without using Symfony
            ->add('name', null, array('label' => false, 'attr' => array('autofocus' => true)))
            ->add('unit_id', ChoiceType::class, [
                    'label' => false,
                    'choices' => $unitsArr, // the point is to generate the select list of options available on the front-end
                    'choice_translation_domain' => false
                ])
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Collaborator::class,
        ]);
        $resolver->setRequired('entity_manager');
    }
}
