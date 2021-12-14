<?php 

namespace App\Form;

use App\Entity\Contract;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

class ContractTimeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('beginDate', DateType::class, array('label' => false))
            ->add('endDate', DateType::class, array('label' => false))
            ->add('amount', NumberType::class, array('label' => false))
            ->add('buyId', null, array('label' => false)) // null means default string
            ->add('marketId', NumberType::class, array('label' => false))
            ->add('commandId', NumberType::class, array('label' => false))
            ->add('posteId', NumberType::class, array('label' => false))
            ->add('comment', TextareaType::class, array('label' => false))
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => ContractTime::class,
        ]);
    }
}
