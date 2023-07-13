<?php


namespace KelkooXml\Form;

use KelkooXml\KelkooXml;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

class FeedManagementForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add('id', NumberType::class, array(
                'required'    => false
            ))
            ->add('feed_label', TextType::class, array(
                'required'    => true,
                'label' => Translator::getInstance()->trans('Feed label', array(), KelkooXml::DOMAIN_NAME),
                'label_attr' => array(
                    'for' => 'title'
                ),
            ))
            ->add('lang_id', TextType::class, array(
                'required'    => true,
                'label' => Translator::getInstance()->trans('Lang', array(), KelkooXml::DOMAIN_NAME),
                'label_attr' => array(
                    'for' => 'lang_id'
                )
            ))
            ->add('country_id', TextType::class, array(
                'required'    => true,
                'label' => Translator::getInstance()->trans('Country', array(), KelkooXml::DOMAIN_NAME),
                'label_attr' => array(
                    'for' => 'country_id'
                )
            ))
            ->add("currency_id", TextType::class, array(
                'required'    => true,
                'label' => Translator::getInstance()->trans('Currency', array(), KelkooXml::DOMAIN_NAME),
                'label_attr' => array(
                    'for' => 'currency_id'
                )
            ));
    }
}
