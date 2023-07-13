<?php

namespace KelkooXml\Controller;

use Exception;
use KelkooXml\KelkooXml;
use KelkooXml\Model\KelkooxmlFeed;
use KelkooXml\Model\KelkooxmlFeedQuery;
use KelkooXml\Model\KelkooxmlLogQuery;
use KelkooXml\Model\KelkooxmlXmlFieldAssociation;
use KelkooXml\Model\KelkooxmlXmlFieldAssociationQuery;
use KelkooXml\Tools\EanChecker;
use PDO;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Action\Image;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Model\AreaDeliveryModuleQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\OrderPostage;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleQuery;
use Thelia\Module\BaseModule;
use Thelia\TaxEngine\Calculator;
use Thelia\Tools\URL;

#[Route('/kelkooxml/feed/xml', name: 'kelkoo_feed_xml_')]
class FeedXmlController extends BaseFrontController
{
    const MAX_CHAR_TITLE = 80;
    const MAX_CHAR_DESCRIPTION = 300;

    /**
     * @var KelkooxmlLogQuery $logger
     */
    private KelkooxmlLogQuery $logger;

    private $nb_pse;
    private $nb_pse_invisible;
    private $nb_pse_error;


    private $ean_rule;

    const EAN_RULE_ALL = "all";
    const EAN_RULE_CHECK_FLEXIBLE = "check_flexible";
    const EAN_RULE_CHECK_STRICT = "check_strict";
    const EAN_RULE_NONE = "none";

    const DEFAULT_EAN_RULE = self::EAN_RULE_CHECK_STRICT;

    /**
     * @throws Exception
     */
    #[Route('/{feedId}/feed.xml', name: 'get_feed')]
    public function getFeedXmlAction(
        EventDispatcherInterface $eventDispatcher,
        RequestStack $requestStack,
        int $feedId
    ): Response
    {
        $this->logger = KelkooxmlLogQuery::create();
        $this->ean_rule = KelkooXml::getConfigValue("ean_rule", self::DEFAULT_EAN_RULE);

        $feed = KelkooxmlFeedQuery::create()->findOneById($feedId);

        $request = $requestStack->getCurrentRequest();

        $limit = $request->get('limit');
        $offset = $request->get('offset');

        if ($feed == null) {
            $this->pageNotFound();
        }

        try {
            $shippingArray = $this->buildShippingArray($feed);

            $pseArray = $this->getProductItems($feed, $limit, $offset);
            $this->injectUrls($pseArray, $feed);
            $this->injectTaxedPrices($pseArray, $feed);
            $this->injectCustomAssociationFields($pseArray, $feed);
            $this->injectAttributesInTitle($pseArray, $feed);
            $this->injectImages($eventDispatcher, $pseArray);

            $this->nb_pse = 0;
            $this->nb_pse_invisible = 0;
            $this->nb_pse_error = 0;
            $content = $this->renderXmlAll($feed, $pseArray, $shippingArray);

            if ($this->nb_pse_invisible > 0) {
                $this->logger->logInfo(
                    $feed,
                    null,
                    Translator::getInstance()->trans('%nb product item(s) have been skipped because they were set as not visible.', ['%nb' => $this->nb_pse_invisible], KelkooXml::DOMAIN_NAME),
                    Translator::getInstance()->trans('You can set your product s visibility in the product edit tool by checking the box [This product is online].', [], KelkooXml::DOMAIN_NAME)
                );
            }

            if ($this->nb_pse_error > 0) {
                $this->logger->logInfo(
                    $feed,
                    null,
                    Translator::getInstance()->trans('%nb product item(s) have been skipped because of errors.', ['%nb' => $this->nb_pse_error], KelkooXml::DOMAIN_NAME),
                    Translator::getInstance()->trans('Check the ERROR messages below to get further details about the error.', [], KelkooXml::DOMAIN_NAME)
                );
            }

            if ($this->nb_pse <= 0) {
                $this->logger->logFatal(
                    $feed,
                    null,
                    Translator::getInstance()->trans('No product in the feed', [], KelkooXml::DOMAIN_NAME),
                    Translator::getInstance()->trans('Your products may not have been included in the feed due to errors. Check the others messages in this log.', [], KelkooXml::DOMAIN_NAME)
                );
            } else {
                $nb_line_xml = substr_count($content, PHP_EOL);
                if ($nb_line_xml <= 8) {
                    $this->logger->logFatal(
                        $feed,
                        null,
                        Translator::getInstance()->trans('Empty generated XML file', [], KelkooXml::DOMAIN_NAME),
                        Translator::getInstance()->trans('Your products may not have been included in the feed due to errors. Check the others messages in this log.', [], KelkooXml::DOMAIN_NAME)
                    );
                } else {
                    $this->logger->logSuccess($feed, null, Translator::getInstance()->trans('The XML file has been successfully generated with %nb product items.', ['%nb' => $this->nb_pse], KelkooXml::DOMAIN_NAME));
                }
            }

            $response = new Response();
            $response->setContent($content);
            $response->headers->set('Content-Type', 'application/xml');

            return $response;

        } catch (Exception $ex) {
            $this->logger->logFatal($feed, null, $ex->getMessage(), $ex->getFile()." at line ".$ex->getLine());
            throw $ex;
        }
    }

    /**
     * @throws PropelException
     */
    protected function renderXmlAll($feed, &$pseArray, $shippingArray): string
    {
        $checkAvailability = ConfigQuery::checkAvailableStock();
        $storeInfos = array(
            "zipcode" => ConfigQuery::read("store_zipcode")
        );

        $str = '<?xml version="1.0" encoding="UTF-8" ?>'.PHP_EOL;
        $str .= '<products>'.PHP_EOL;

        $shippingStr = '';

        $nbShipping = count($shippingArray);
        for ($i=0; $i < $nbShipping && $i < 4; $i++) {
            $shipping = $shippingArray[$i];
            if ($i > 0) {
                $suffix = '-'.($i + 1);
            } else {
                $suffix = '';
            }

            $shippingStr .= '<delivery-cost' . $suffix . '>' . $shipping['price'] . '</delivery-cost' . $suffix.'>'.PHP_EOL;
            $shippingStr .= '<shipping-method' . $suffix . '>' . $shipping['service'] . '</shipping-method' . $suffix.'>'.PHP_EOL;
        }

        foreach ($pseArray as &$pse) {
            if ($pse['PRODUCT_VISIBLE'] == 1) {
                $xmlPse = $this->renderXmlOnePse($feed, $pse, $shippingStr, $checkAvailability, $storeInfos);
                if (!empty($xmlPse)) {
                    $this->nb_pse++;
                } else {
                    $this->nb_pse_error++;
                }
                $str .= $xmlPse;
            } else {
                $this->nb_pse_invisible++;
            }
        }

        $str .= '</products>';
        return $str;
    }

    /**
     * @param KelkooxmlFeed $feed
     * @param array $pse
     * @param string $shippingStr
     * @param bool $checkAvailability
     * @param array $storeInfos
     * @return string
     * @throws PropelException
     * @throws PropelException
     */
    protected function renderXmlOnePse(
        KelkooxmlFeed $feed,
        array &$pse,
        string $shippingStr,
        bool $checkAvailability,
        array $storeInfos
    ): string
    {
        $str = '<product>'.PHP_EOL;
        $str .= '<offer-id>'.$pse['ID'].'</offer-id>'.PHP_EOL;


        // **************** Title ****************

        if (empty($pse['TITLE'])) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product title for the language "%lang"', ['%lang' => $feed->getLang()->getTitle()], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('Check that this product has a valid title in this langage.', [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }

        if (mb_strlen($pse['TITLE']) > self::MAX_CHAR_TITLE) {
            $this->logger->logWarning(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Product title exceeds the limit of %nb characters in "%lang"', ['%nb' => self::MAX_CHAR_TITLE, '%lang' => $feed->getLang()->getTitle()], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('The title has been truncated in the XML file.', [], KelkooXml::DOMAIN_NAME)
            );
            $pseTitle = mb_substr($pse['TITLE'], 0, self::MAX_CHAR_TITLE - 3).'...';
        } else {
            $pseTitle = $pse['TITLE'];
        }

        $str .= '<title>'.$this->xmlSafeEncode($pseTitle).'</title>'.PHP_EOL;


        // **************** URL ****************

        if (empty($pse['URL'])) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product URL', [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }

        $pseUrl = $this->xmlSafeEncode($pse['URL']);
        $str .= "<product-url>$pseUrl</product-url>".PHP_EOL;
        $str .= "<landing-page-url>$pseUrl</landing-page-url>".PHP_EOL;



        // **************** Price ****************

        if (empty($pse['TAXED_PRICE'])) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product price for the currency "%code"', ['%code' => $feed->getCurrency()->getCode()], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('Unable to compute a price for this product and this currency. Specify one manually or check [Apply exchange rates] in the Edit Product page for this currency.' , [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }


        if (!empty($pse['TAXED_PROMO_PRICE']) && $pse['TAXED_PROMO_PRICE'] < $pse['TAXED_PRICE']) {
            $str .= '<price>'.$pse['TAXED_PROMO_PRICE'].'</price>'.PHP_EOL;
            $str .= '<price-no-rebate>'.$pse['TAXED_PRICE'].'</price-no-rebate>'.PHP_EOL;
        } else {
            $str .= '<price>'.$pse['TAXED_PRICE'].'</price>'.PHP_EOL;
        }



        // **************** Brand ****************

        if (!$this->hasCustomField($pse, "brand") && !empty($pse['BRAND_TITLE'])) {
            $str .= '<brand>' . $this->xmlSafeEncode($pse['BRAND_TITLE']) . '</brand>' . PHP_EOL;
        }



        // **************** Description ****************

        $description = html_entity_decode(trim(strip_tags($pse['DESCRIPTION'])));

        if (empty($description)) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product description for the language "%lang"', ['%lang' => $feed->getLang()->getTitle()], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('Check that this product has a valid description in this langage.', [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }

        if (mb_strlen($description) > self::MAX_CHAR_DESCRIPTION) {
            $this->logger->logWarning(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Product description exceeds the limit of %nb characters in "%lang"', ['%nb' => self::MAX_CHAR_DESCRIPTION, '%lang' => $feed->getLang()->getTitle()], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('The description has been truncated in the XML file.', [], KelkooXml::DOMAIN_NAME)
            );
            $description = mb_substr($description, 0, self::MAX_CHAR_DESCRIPTION - 3).'...';
        }

        $str .= '<description>'.$this->xmlSafeEncode($description).'</description>'.PHP_EOL;



        // **************** Image path ****************

        if (empty($pse['IMAGE_PATH'])) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product image', [], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('Please add an image for this product.', [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }

        $str .= '<image-url>'.$this->xmlSafeEncode($pse['IMAGE_PATH']).'</image-url>'.PHP_EOL;




        // **************** EAN / GTIN code ****************

        $include_ean = false;

        if ($this->ean_rule == self::EAN_RULE_ALL) {
            $include_ean = true;
        }

        if ((new EanChecker())->isValidEan($pse['EAN_CODE'])) {
            $include_ean = true;
        }

        if ($this->ean_rule == self::EAN_RULE_CHECK_STRICT) {
            $this->logger->logError(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Invalid GTIN/EAN code : "%code"', ["%code" => $pse['EAN_CODE']], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('The product s identification code seems invalid. You can set a valid EAN code in the Edit product page or disable the verification in the [Advanced configuration] tab.', [], KelkooXml::DOMAIN_NAME)
            );
            return '';
        }

        if ($include_ean) {
            $str .= '<ean>'.$pse['EAN_CODE'].'</ean>'.PHP_EOL;
        }


        // **************** Category ****************


        if (empty($pse['CATEGORY_TITLE'])) {
            $this->logger->logWarning(
                $feed,
                $pse['ID'],
                Translator::getInstance()->trans('Missing product category for this product.', [], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('The [merchant-category] field is not mandatory but highly recommended. Please consider fill it in.' , [], KelkooXml::DOMAIN_NAME)
            );
        } else {
            $str .= '<merchant-category>'.$pse['CATEGORY_TITLE'].'</merchant-category>'.PHP_EOL;
        }



        // **************** Availability ****************
        // 1 = En stock
        // 4 = En précommande
        // 5 = Disponible sur commande

        if (!$this->hasCustomField($pse, "availability")) {
            if ($checkAvailability && $pse['QUANTITY'] <= 0) {
                $str .= '<availability>5</availability>' . PHP_EOL;
            } else {
                $str .= '<availability>1</availability>' . PHP_EOL;
            }
        }



        // **************** Others ****************


        $str .= $shippingStr;


        if (!$this->hasCustomField($pse, "condition")) {
            $str .= '<condition>0</condition>'.PHP_EOL;
        }

        if (!$this->hasCustomField($pse, "ecotax")) {
            $str .= '<ecotax>0</ecotax>'.PHP_EOL;
        }

        $str .= $this->buildEmptyField($pse, "warranty");
        $str .= $this->buildEmptyField($pse, "mpn");

        $str .= '<currency>'.$feed->getCurrency()->getCode().'</currency>'.PHP_EOL;

        if (!$this->hasCustomField($pse, "stock-quantity")) {
            $str .= '<stock-quantity>' . $pse['QUANTITY'] . '</stock-quantity>' . PHP_EOL;
        }

        if (!empty($storeInfos["zipcode"])) {
            $str .= '<zipcode>'.$storeInfos["zipcode"].'</zipcode>'.PHP_EOL;
        }

        foreach ($pse['CUSTOM_FIELD_ARRAY'] as $field) {
            $str .= '<'.$field['FIELD_NAME'].'>'.$this->xmlSafeEncode($field['FIELD_VALUE']).'</'.$field['FIELD_NAME'].'>'.PHP_EOL;
        }

        return $str.'</product>'.PHP_EOL;
    }

    protected function xmlSafeEncode($str): string
    {
        return htmlspecialchars($str, ENT_XML1);
    }

    protected function hasCustomField($pse, $fieldName): bool
    {
        foreach ($pse['CUSTOM_FIELD_ARRAY'] as $field) {
            if ($field['FIELD_NAME'] == $fieldName) {
                return true;
            }
        }
        return false;
    }

    protected function buildEmptyField($pse, $fieldName): string
    {
        if (!$this->hasCustomField($pse, $fieldName)) {
            return '<'.$fieldName.'></'.$fieldName.'>'.PHP_EOL;
        }
        return '';
    }

    /**
     * @param KelkooxmlFeed $feed
     * @param null $limit
     * @param null $offset
     * @return bool|array
     * @throws PropelException
     */
    protected function getProductItems(KelkooxmlFeed $feed, $limit = null, $offset = null): bool|array
    {
        $sql = 'SELECT 

                pse.ID AS ID,
                product.ID AS ID_PRODUCT,
                product.REF AS REF_PRODUCT,
                product.VISIBLE AS PRODUCT_VISIBLE,
                product_i18n.TITLE AS TITLE,
                product_i18n.DESCRIPTION AS DESCRIPTION,
                COALESCE (brand_i18n_with_locale.TITLE, brand_i18n_without_locale.TITLE) AS BRAND_TITLE,
                pse.QUANTITY AS QUANTITY,
                pse.EAN_CODE AS EAN_CODE,
                product_category.CATEGORY_ID AS CATEGORY_ID,
                COALESCE(cati18n_with_locale.TITLE, cati18n_without_locale.TITLE) AS CATEGORY_TITLE,
                product.TAX_RULE_ID AS TAX_RULE_ID,
                COALESCE(price_on_currency.PRICE, CASE WHEN NOT ISNULL(price_default.PRICE) THEN ROUND(price_default.PRICE * :currate, 2) END) AS PRICE,
                COALESCE(price_on_currency.PROMO_PRICE, CASE WHEN NOT ISNULL(price_default.PROMO_PRICE) THEN ROUND(price_default.PROMO_PRICE * :currate, 2) END) AS PROMO_PRICE,
                rewriting_url.URL AS REWRITTEN_URL,
                COALESCE(product_image_on_pse.FILE, product_image_default.FILE) AS IMAGE_NAME
                
                FROM product_sale_elements AS pse
                
                INNER JOIN product ON (pse.PRODUCT_ID = product.ID)
                LEFT OUTER JOIN product_price price_on_currency ON (pse.ID = price_on_currency.PRODUCT_SALE_ELEMENTS_ID AND price_on_currency.CURRENCY_ID = :currid)
                LEFT OUTER JOIN product_price price_default ON (pse.ID = price_default.PRODUCT_SALE_ELEMENTS_ID AND price_default.FROM_DEFAULT_CURRENCY = 1)
                LEFT OUTER JOIN product_category ON (pse.PRODUCT_ID = product_category.PRODUCT_ID AND product_category.DEFAULT_CATEGORY = 1)
                LEFT OUTER JOIN category_i18n cati18n_with_locale ON (product_category.CATEGORY_ID = cati18n_with_locale.id AND cati18n_with_locale.locale = :locale)
                LEFT OUTER JOIN category_i18n cati18n_without_locale ON (product_category.CATEGORY_ID = cati18n_without_locale.id)
                LEFT OUTER JOIN product_i18n ON (pse.PRODUCT_ID = product_i18n.ID AND product_i18n.LOCALE = :locale)
                LEFT OUTER JOIN brand_i18n brand_i18n_with_locale ON (product.BRAND_ID = brand_i18n_with_locale.ID AND brand_i18n_with_locale.LOCALE = :locale)
                LEFT OUTER JOIN brand_i18n brand_i18n_without_locale ON (product.BRAND_ID = brand_i18n_without_locale.ID)
                LEFT OUTER JOIN rewriting_url ON (pse.PRODUCT_ID = rewriting_url.VIEW_ID AND rewriting_url.view = \'product\' AND rewriting_url.view_locale = :locale AND rewriting_url.redirected IS NULL)
                LEFT OUTER JOIN product_sale_elements_product_image pse_image ON (pse.ID = pse_image.PRODUCT_SALE_ELEMENTS_ID)
                LEFT OUTER JOIN product_image product_image_default ON (pse.PRODUCT_ID = product_image_default.PRODUCT_ID AND product_image_default.POSITION = 1)
                LEFT OUTER JOIN product_image product_image_on_pse ON (product_image_on_pse.ID = pse_image.PRODUCT_IMAGE_ID)

                GROUP BY pse.ID';

        $limit = $this->checkPositiveInteger($limit);
        $offset = $this->checkPositiveInteger($offset);

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        if ($offset) {
            if (!$limit) {
                $sql .= " LIMIT 99999999999";
            }
            $sql .= " OFFSET $offset";
        }

        $con = Propel::getConnection();
        $stmt = $con->prepare($sql);
        $stmt->bindValue(':locale', $feed->getLang()->getLocale());
        $stmt->bindValue(':currid', $feed->getCurrencyId(), PDO::PARAM_INT);
        $stmt->bindValue(':currate', $feed->getCurrency()->getRate());

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function checkPositiveInteger($var)
    {
        $var = filter_var($var, FILTER_VALIDATE_INT);
        return ($var !== false && $var >= 0) ? $var : null;
    }


    /**
     * @param KelkooxmlFeed $feed
     * @param array $pseArray
     * @throws PropelException
     */
    protected function injectUrls(array &$pseArray, KelkooxmlFeed $feed)
    {
        $urlManager = URL::getInstance();
        foreach ($pseArray as &$pse) {
            if ($pse['REWRITTEN_URL'] == null) {
                $pse['URL'] = $urlManager->retrieve('product', $pse['ID_PRODUCT'], $feed->getLang()->getLocale())->toString();
            } else {
                $pse['URL'] = $urlManager->absoluteUrl($pse['REWRITTEN_URL']);
            }
        }
    }


    /**
     * @param KelkooxmlFeed $feed
     * @param array $pseArray
     * @throws PropelException
     * @throws PropelException
     */
    protected function injectTaxedPrices(array &$pseArray, KelkooxmlFeed $feed)
    {
        $taxRulesCollection = TaxRuleQuery::create()->find();
        $taxRulesArray = [];
        /** @var TaxRule $taxRule **/
        foreach ($taxRulesCollection as $taxRule) {
            $taxRulesArray[$taxRule->getId()] = $taxRule;
        }

        $taxCalculatorsArray = [];

        foreach ($pseArray as &$pse) {
            $taxRuleId = $pse['TAX_RULE_ID'];
            $taxRule = $taxRulesArray[$taxRuleId];

            if (!array_key_exists($taxRuleId, $taxCalculatorsArray)) {
                $calculator = new Calculator();
                $calculator->loadTaxRuleWithoutProduct($taxRule, $feed->getCountry());
                $taxCalculatorsArray[$taxRuleId] = $calculator;
            } else {
                $calculator = $taxCalculatorsArray[$taxRuleId];
            }

            $pse['TAXED_PRICE'] = !empty($pse['PRICE']) ? $calculator->getTaxedPrice($pse['PRICE']) : null;
            $pse['TAXED_PROMO_PRICE'] = !empty($pse['PROMO_PRICE']) ? $calculator->getTaxedPrice($pse['PROMO_PRICE']) : null;
        }
    }

    /**
     * @param KelkooxmlFeed $feed
     * @param array $pseArray
     * @throws PropelException
     */
    protected function injectCustomAssociationFields(array &$pseArray, KelkooxmlFeed $feed)
    {
        $attributesArray = [];
        $featuresArray = [];

        $fieldAssociationCollection = KelkooxmlXmlFieldAssociationQuery::create()->find();

        foreach ($fieldAssociationCollection as $fieldAssociation) {
            $fieldName = $fieldAssociation->getXmlField();
            if (in_array($fieldName, FieldAssociationController::FIELDS_NATIVELY_DEFINED)) {
                $this->logger->logWarning(
                    $feed,
                    null,
                    Translator::getInstance()->trans('XML field "%field" already defined', ['%field' => $fieldName], KelkooXml::DOMAIN_NAME),
                    Translator::getInstance()->trans('You manually specified a field that is already defined by the module and that does not support overriding. It may cause issues as the field is defined twice.', [], KelkooXml::DOMAIN_NAME)
                );
            } elseif (!in_array($fieldName, FieldAssociationController::KELKOO_FIELD_LIST)) {
                $this->logger->logWarning(
                    $feed,
                    null,
                    Translator::getInstance()->trans('Unknown XML field "%field".', ['%field' => $fieldName], KelkooXml::DOMAIN_NAME),
                    Translator::getInstance()->trans('You manually specified a field that does not seem to be a valid Kelkoo XML field. You may have a typo that will cause issues.', [], KelkooXml::DOMAIN_NAME)
                );
            }
        }

        foreach ($pseArray as &$pse) {
            $customFieldArray = [];
            /** @var KelkooxmlXmlFieldAssociation $fieldAssociation */
            foreach ($fieldAssociationCollection as $fieldAssociation) {
                $found = false;
                $customField = ['FIELD_NAME' => $fieldAssociation->getXmlField()];
                switch ($fieldAssociation->getAssociationType()) {
                    case FieldAssociationController::ASSO_TYPE_FIXED_VALUE:
                        $customField['FIELD_VALUE'] = $fieldAssociation->getFixedValue();
                        $found = true;
                        break;
                    case FieldAssociationController::ASSO_TYPE_RELATED_TO_THELIA_ATTRIBUTE:
                        $idAttribute = $fieldAssociation->getIdRelatedAttribute();
                        if (!array_key_exists($idAttribute, $attributesArray)) {
                            $attributesArray[$idAttribute] = $this->getArrayAttributesConcatValues($feed->getLang()->getLocale(), $idAttribute, ';');
                        }
                        if ($found = array_key_exists($pse['ID'], $attributesArray[$idAttribute])) {
                            $customField['FIELD_VALUE'] = $attributesArray[$idAttribute][$pse['ID']];
                        }
                        break;
                    case FieldAssociationController::ASSO_TYPE_RELATED_TO_THELIA_FEATURE:
                        $idFeature = $fieldAssociation->getIdRelatedFeature();
                        if (!array_key_exists($idFeature, $featuresArray)) {
                            $featuresArray[$idFeature] = $this->getArrayFeaturesConcatValues($feed->getLang()->getLocale(), $idFeature, ';');
                        }
                        if ($found = array_key_exists($pse['ID_PRODUCT'], $featuresArray[$idFeature])) {
                            $customField['FIELD_VALUE'] = $featuresArray[$idFeature][$pse['ID_PRODUCT']];
                        }
                        break;
                }
                if ($found) {
                    $customFieldArray[] = $customField;
                }
            }
            $pse['CUSTOM_FIELD_ARRAY'] = $customFieldArray;
        }
    }

    /**
     * @param KelkooxmlFeed $feed
     * @param array $pseArray
     * @throws PropelException
     */
    protected function injectAttributesInTitle(&$pseArray, $feed)
    {
        $attributesConcatArray = $this->getArrayAttributesConcatValues($feed->getLang()->getLocale(), null, ' - ');
        foreach ($pseArray as &$pse) {
            if (array_key_exists($pse['ID'], $attributesConcatArray)) {
                $pse['TITLE'] .= ' - ' . $attributesConcatArray[$pse['ID']];
            }
        }
    }


    /**
     * @param array $pseArray
     */
    protected function injectImages(EventDispatcherInterface $eventDispatcher, &$pseArray)
    {
        foreach ($pseArray as &$pse) {
            if ($pse['IMAGE_NAME'] != null) {
                $imageEvent = $this->createImageEvent($pse['IMAGE_NAME'], 'product');
                $eventDispatcher->dispatch($imageEvent, TheliaEvents::IMAGE_PROCESS);
                $pse['IMAGE_PATH'] = $imageEvent->getFileUrl();
            } else {
                $pse['IMAGE_PATH'] = null;
            }
        }
    }


    /**
     * @param string $imageFile
     * @param string $type
     * @return ImageEvent
     */
    protected function createImageEvent($imageFile, string $type)
    {
        $imageEvent = new ImageEvent();
        $baseSourceFilePath = ConfigQuery::read('images_library_path');
        if ($baseSourceFilePath === null) {
            $baseSourceFilePath = THELIA_LOCAL_DIR . 'media' . DS . 'images';
        } else {
            $baseSourceFilePath = THELIA_ROOT . $baseSourceFilePath;
        }
        // Put source image file path
        $sourceFilePath = sprintf(
            '%s/%s/%s',
            $baseSourceFilePath,
            $type,
            $imageFile
        );
        $imageEvent->setSourceFilepath($sourceFilePath);
        $imageEvent->setCacheSubdirectory($type);
        $imageEvent->setResizeMode(Image::EXACT_RATIO_WITH_BORDERS);
        return $imageEvent;
    }



    protected function getArrayAttributesConcatValues($locale, $attribute_id = null, $separator = ';'): array
    {
        $con = Propel::getConnection();

        $sql = 'SELECT attribute_combination.product_sale_elements_id AS PSE_ID, GROUP_CONCAT(attribute_av_i18n.title SEPARATOR \''.$separator.'\') AS CONCAT FROM attribute_combination
                INNER JOIN attribute_av_i18n ON (attribute_combination.attribute_av_id = attribute_av_i18n.id)
                WHERE attribute_av_i18n.locale = :locale';

        if ($attribute_id != null) {
            $sql .= ' AND attribute_combination.attribute_id = :attrid';
        }

        $sql .= ' GROUP BY attribute_combination.product_sale_elements_id';

        $stmt = $con->prepare($sql);
        $stmt->bindValue(':locale', $locale, PDO::PARAM_STR);
        if ($attribute_id != null) {
            $stmt->bindValue(':attrid', $attribute_id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attrib_by_pse = array();
        foreach ($rows as $row) {
            $attrib_by_pse[$row['PSE_ID']] = $row['CONCAT'];
        }
        return $attrib_by_pse;
    }


    protected function getArrayFeaturesConcatValues($locale, $feature_id): array
    {
        $con = Propel::getConnection();

        $sql = 'SELECT feature_product.product_id AS PRODUCT_ID, GROUP_CONCAT(feature_av_i18n.title SEPARATOR \'/\') AS CONCAT FROM feature_product
                INNER JOIN feature_av_i18n ON (feature_product.feature_av_id = feature_av_i18n.id)
                WHERE feature_av_i18n.locale = :locale
                AND feature_product.feature_id = :featid
                GROUP BY feature_product.product_id';

        $stmt = $con->prepare($sql);
        $stmt->bindValue(':locale', $locale, PDO::PARAM_STR);
        $stmt->bindValue(':featid', $feature_id, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attrib_by_pse = array();
        foreach ($rows as $row) {
            $attrib_by_pse[$row['PRODUCT_ID']] = $row['CONCAT'];
        }
        return $attrib_by_pse;
    }

    /**
     * @param KelkooxmlFeed $feed
     * @return array
     * @throws PropelException
     */
    protected function buildShippingArray(KelkooxmlFeed $feed): array
    {
        $resultArray = [];

        $shippingInfoArray = $this->getShippings($feed);

        foreach ($shippingInfoArray as $moduleTitle => $postagePrice) {
            $shippingItem = [];
            $shippingItem['country_code'] = $feed->getCountry()->getIsoalpha2();
            $shippingItem['service'] = $moduleTitle;
            $shippingItem['price'] = $postagePrice;
            $shippingItem['currency_id'] = $feed->getCurrencyId();
            $resultArray[] = $shippingItem;
        }

        if (empty($resultArray)) {
            $this->logger->logError(
                $feed,
                null,
                Translator::getInstance()->trans('No shipping informations.', [], KelkooXml::DOMAIN_NAME),
                Translator::getInstance()->trans('The feed doesn t have any shippings informations. Check that at least one delivery module covers the country aimed by your feed.', [], KelkooXml::DOMAIN_NAME)
            );
        }

        return $resultArray;
    }

    /**
     * @param KelkooxmlFeed $feed
     * @return array
     * @throws PropelException
     */
    protected function getShippings(KelkooxmlFeed $feed): array
    {
        $country = $feed->getCountry();

        $search = ModuleQuery::create()
            ->filterByActivate(1)
            ->filterByType(BaseModule::DELIVERY_MODULE_TYPE, Criteria::EQUAL)
            ->find();

        $deliveries = array();

        /** @var Module $deliveryModule */
        foreach ($search as $deliveryModule) {
            $deliveryModule->setLocale($feed->getLang()->getLocale());

            $areaDeliveryModule = AreaDeliveryModuleQuery::create()
                ->findByCountryAndModule($country, $deliveryModule);

            if (null === $areaDeliveryModule) {
                continue;
            }

            $moduleInstance = $deliveryModule->getDeliveryModuleInstance($this->container);

            if ($moduleInstance->isValidDelivery($country)) {
                $postage = OrderPostage::loadFromPostage($moduleInstance->getPostage($country));
                $price = $postage->getAmount() * $feed->getCurrency()->getRate();

                $deliveries[$deliveryModule->getTitle()] = $price;
            }
        }

        return $deliveries;
    }
}
