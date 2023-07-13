<?php

namespace KelkooXml\Controller;

use Exception;
use KelkooXml\Form\FeedManagementForm;
use KelkooXml\KelkooXml;
use KelkooXml\Model\KelkooxmlFeedQuery;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;

#[Route('/admin/module/KelkooXml/feed', name: 'kelkoo_feed_config')]
class FeedConfigController extends BaseAdminController
{
    public function __construct(
        protected RequestStack $requestStack,
    ) {}

    #[Route('/add', name: 'add', methods: 'POST')]
    public function addFeedAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::CREATE)) {
            return $response;
        }

        return $this->addOrUpdateFeed();
    }

    #[Route('/update', name: 'update', methods: 'POST')]
    public function updateFeedAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::UPDATE)) {
            return $response;
        }

        return $this->addOrUpdateFeed();
    }

    protected function addOrUpdateFeed(): RedirectResponse|Response
    {
        $form = $this->createForm(FeedManagementForm::getName());

        try {
            $formData = $this->validateForm($form)->getData();

            $feed = KelkooxmlFeedQuery::create()
                ->filterById($formData['id'])
                ->findOneOrCreate();

            $feed->setLabel($formData['feed_label'])
                ->setLangId($formData['lang_id'])
                ->setCurrencyId($formData['currency_id'])
                ->setCountryId($formData['country_id'])
                ->save();

        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->setupFormErrorContext(
                Translator::getInstance()->trans("KelkooXml configuration", [], KelkooXml::DOMAIN_NAME),
                $message,
                $form,
                $e
            );
        }

        return $this->generateRedirectFromRoute(
            "admin.module.configure",
            array(),
            array(
                'module_code' => 'KelkooXml',
                'current_tab' => 'feeds'
            )
        );
    }

    /**
     * @throws PropelException
     */
    #[Route('/delete', name: 'delete', methods: 'POST')]
    public function deleteFeedAction()
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::DELETE)) {
            return $response;
        }

        $feedId = $this->requestStack->getCurrentRequest()->request->get('id_feed_to_delete');

        $feed = KelkooxmlFeedQuery::create()->findOneById($feedId);
        $feed?->delete();

        return $this->generateRedirectFromRoute(
            "admin.module.configure",
            array(),
            array(
                'module_code' => 'KelkooXml',
                'current_tab' => 'feeds'
            )
        );
    }
}
