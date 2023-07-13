<?php

namespace KelkooXml\Controller;

use Exception;
use KelkooXml\KelkooXml;
use KelkooXml\Model\KelkooxmlXmlFieldAssociation;
use KelkooXml\Model\KelkooxmlXmlFieldAssociationQuery;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;

#[Route('/admin/module/KelkooXml/advanced', name: 'kelkoo_xml_field_association_')]
class FieldAssociationController extends BaseAdminController
{
    const ASSO_TYPE_FIXED_VALUE = 1;
    const ASSO_TYPE_RELATED_TO_THELIA_ATTRIBUTE = 2;
    const ASSO_TYPE_RELATED_TO_THELIA_FEATURE = 3;

    // The following are already defined in the XML output file by the module and cannot be overwritten.
    const FIELDS_NATIVELY_DEFINED = array(
        'offer-id', 'title', 'product-url', 'landing-page-url', 'price', 'price-no-rebate', 'description', 'image-url',
        'ean', 'merchant-category', 'currency', 'zipcode'
    );

    // All the Kelkoo XML fields available (https://www.kelkoogroup.com/fr/structure-du-fichier-et-details-des-champs )
    const KELKOO_FIELD_LIST = array(
        'offer-id', 'title', 'product-url', 'landing-product-url', 'price', 'brand', 'description', 'image-url', 'ean',
        'merchant-category', 'availability', 'delivery-cost', 'delivery-time', 'condition', 'ecotax', 'warranty',
        'mobile-url', 'mpn', 'sku', 'color', 'unit-price', 'merchant-info', 'currency', 'image-url-2', 'image-url-3',
        'image-url-4', 'green-product', 'green-label', 'sales-rank', 'unit-quantity', 'made-in', 'efficiency-class',
        'shipping-method', 'delivery-cost-2', 'shipping-method-2', 'delivery-cost-3', 'shipping-method-3',
        'delivery-cost-4', 'shipping-method-4', 'zip-code', 'stock-quantity', 'shipping-weight', 'payment-methods',
        'voucher-title', 'voucher-url', 'voucher-code', 'voucher-start-date', 'voucher-end-date', 'price-no-rebate',
        'percentage-promo', 'occasion', 'promo-start-date', 'promo-end-date', 'user-rating', 'nb-reviews',
        'user-review-link', 'video-link', 'video-title', 'fashion-type', 'fashion-gender', 'fashion-size', 'color',
        'software-platform', 'property-type', 'property-source', 'property-garage-parking', 'property-city',
        'property-zip-code', 'property-number-rooms', 'property-surface', 'property-publication-date',
        'property-tenure', 'movie-director', 'movie-actors', 'movie-format', 'movie-region', 'movie-languages',
        'movie-release-date', 'movie-media-rating', 'music-artist', 'music-media', 'music-genre', 'music-format',
        'music-record-label', 'music-language', 'music-release-date', 'book-author', 'book-format', 'book-edition',
        'book-publisher', 'book-genre', 'book-language', 'book-release-date', 'book-audience', 'book-number-of-pages',
        'wine-country', 'wine-year', 'wine-domain', 'wine-zone', 'wine-number-bottles', 'wine-capacity',
        'tyre-wet-grip', 'tyre-noise-class', 'mobilephone-network', 'mobilephone-contract-type',
        'mobilephone-contract-length', 'mobilephone-contract-total-cost', 'mobilephone-tariff',
        'mobilephone-monthly-hours', 'vehicle-year', 'vehicle-mileage', 'vehicle-vendor-type', 'vehicle-city',
        'vehicle-zip-code', 'vehicle-doors', 'vehicle-chassis', 'vehicle-engine-size', 'vehicle-transmission',
        'vehicle-fuel-type', 'vehicle-bonus-malus', 'vehicle-all-fuel-types', 'vehicle-consumption-highway',
        'vehicle-consumption-city', 'vehicle-consumption-mixed', 'vehicle-electric-consumption-highway',
        'vehicle-electric-consumption-city', 'vehicle-electric-consumption-mixed', 'vehicle-co2-emmission',
        'event-zip-code', 'event-city', 'event-location', 'event-start-date', 'event-end-date', 'event-genre'
    );

    protected Request $request;

    public function __construct(RequestStack $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }


    #[Route('/field/add', name: 'add', methods: 'POST')]
    public function addFieldAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::CREATE)) {
            return $response;
        }

        $message = null;

        try {
            $fieldAssociation = new KelkooxmlXmlFieldAssociation();
            $this->hydrateFieldAssociationObjectWithRequestContent($fieldAssociation, $this->request);
            $fieldAssociation->save();
        } catch (Exception $e) {
            $message = $e->getMessage();
        }

        $redirectParameters = array(
            'module_code' => 'KelkooXml',
            'current_tab' => 'advanced'
        );

        if (!empty($message)) {
            $redirectParameters['error_message_advanced_tab'] = $message;
        }

        return $this->generateRedirectFromRoute("admin.module.configure", array(), $redirectParameters);
    }

    #[Route('/field/update', name: 'update', methods: 'POST')]
    public function updateFieldAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::UPDATE)) {
            return $response;
        }

        $message = null;

        try {
            $httpRequest = $this->request;
            $fieldAssociation = KelkooxmlXmlFieldAssociationQuery::create()
                ->findOneById($httpRequest->request->get('id'));
            if ($fieldAssociation != null) {
                $this->hydrateFieldAssociationObjectWithRequestContent($fieldAssociation, $this->request);
                $fieldAssociation->save();
            } else {
                throw new Exception(Translator::getInstance()->trans('Unable to find the field association to update.', [], KelkooXml::DOMAIN_NAME));
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
        }

        $redirectParameters = array(
            'module_code' => 'KelkooXml',
            'current_tab' => 'advanced'
        );

        if (!empty($message)) {
            $redirectParameters['error_message_advanced_tab'] = $message;
        }

        return $this->generateRedirectFromRoute("admin.module.configure", array(), $redirectParameters);
    }

    #[Route('/field/delete', name: 'delete', methods: 'POST')]
    public function deleteFieldAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::DELETE)) {
            return $response;
        }

        $message = null;

        try {
            $httpRequest = $this->request;
            $fieldAssociation = KelkooxmlXmlFieldAssociationQuery::create()
                ->findOneById($httpRequest->request->get('id_field_to_delete'));
            if ($fieldAssociation != null) {
                $fieldAssociation->delete();
            } else {
                throw new Exception(Translator::getInstance()->trans('Unable to find the field association to delete.', [], KelkooXml::DOMAIN_NAME));
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
        }

        $redirectParameters = array(
            'module_code' => 'KelkooXml',
            'current_tab' => 'advanced'
        );

        if (!empty($message)) {
            $redirectParameters['error_message_advanced_tab'] = $message;
        }

        return $this->generateRedirectFromRoute("admin.module.configure", array(), $redirectParameters);
    }


    /**
     * @param KelkooxmlXmlFieldAssociation $fieldAssociation
     * @param Request $httpRequest
     * @return KelkooxmlXmlFieldAssociation
     * @throws Exception
     */
    protected function hydrateFieldAssociationObjectWithRequestContent(
        KelkooxmlXmlFieldAssociation $fieldAssociation,
        Request $httpRequest
    ): KelkooxmlXmlFieldAssociation
    {
        $request = $httpRequest->request;

        // ********   Kelkoo field   ********

        $kelkooAttribute = $request->get('kelkoo_attribute');

        if (empty($kelkooAttribute)) {
            throw new Exception(Translator::getInstance()->trans('The Kelkoo attribute cannot be empty.', [], KelkooXml::DOMAIN_NAME));
        }

        $kelkooAttribute = strtolower($kelkooAttribute);

        if (in_array($kelkooAttribute, self::FIELDS_NATIVELY_DEFINED)) {
            throw new Exception(Translator::getInstance()->trans(
                'The Kelkoo attribute "%name" cannot be redefined here as it is already defined by the module.',
                array('%name' => $kelkooAttribute),
                KelkooXml::DOMAIN_NAME
            ));
        }

        $fieldAssociation->setXmlField($kelkooAttribute);


        // ********   Association type   *********

        $associationType = $request->get('association_type');

        switch ($associationType) {
            case self::ASSO_TYPE_FIXED_VALUE:
                $fixedValue = $request->get('fixed_value');
                if (empty($fixedValue)) {
                    throw new Exception(Translator::getInstance()->trans('The fixed value cannot be empty if you have chosen the "Fixed value" association type.', [], KelkooXml::DOMAIN_NAME));
                }
                $fieldAssociation->setFixedValue($fixedValue);
                break;
            case self::ASSO_TYPE_RELATED_TO_THELIA_ATTRIBUTE:
                $thelia_attribute_id = $request->get('thelia_attribute');
                if (empty($thelia_attribute_id)) {
                    throw new Exception(Translator::getInstance()->trans('The Thelia attribute cannot be empty if you have chosen the "Linked to a Thelia attribute" association type.', [], KelkooXml::DOMAIN_NAME));
                }
                $fieldAssociation->setIdRelatedAttribute($thelia_attribute_id);
                break;
            case self::ASSO_TYPE_RELATED_TO_THELIA_FEATURE:
                $thelia_feature_id = $request->get('thelia_feature');
                if (empty($thelia_feature_id)) {
                    throw new Exception(Translator::getInstance()->trans('The Thelia feature cannot be empty if you have chosen the "Linked to a Thelia feature" association type.', [], KelkooXml::DOMAIN_NAME));
                }
                $fieldAssociation->setIdRelatedFeature($thelia_feature_id);
                break;
            default:
                throw new Exception(Translator::getInstance()->trans('The chosen association type is unknown.', [], KelkooXml::DOMAIN_NAME));
        }

        $fieldAssociation->setAssociationType($associationType);
        return $fieldAssociation;
    }

    #[Route('/setEanRule', name: 'ean_rule', methods: 'POST')]
    public function setEanRuleAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::UPDATE)) {
            return $response;
        }

        $ruleArray = [
            FeedXmlController::EAN_RULE_ALL,
            FeedXmlController::EAN_RULE_CHECK_FLEXIBLE,
            FeedXmlController::EAN_RULE_CHECK_STRICT,
            FeedXmlController::EAN_RULE_NONE
        ];

        $httpRequest = $this->request;
        $gtinRule = $httpRequest->request->get('gtin_rule');
        if ($gtinRule != null && in_array($gtinRule, $ruleArray)) {
            KelkooXml::setConfigValue("ean_rule", $gtinRule);
        }

        $redirectParameters = array(
            'module_code' => 'KelkooXml',
            'current_tab' => 'advanced'
        );

        if (!empty($message)) {
            $redirectParameters['error_message_advanced_tab'] = $message;
        }

        return $this->generateRedirectFromRoute("admin.module.configure", [], $redirectParameters);
    }
}
