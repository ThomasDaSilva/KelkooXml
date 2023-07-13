<?php

namespace KelkooXml\Controller;

use KelkooXml\Model\KelkooxmlLog;
use KelkooXml\Model\KelkooxmlLogQuery;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

#[Route('/admin/module/KelkooXml/log', name: 'kelkoo_log_')]
class LogController extends BaseAdminController
{
    /**
     * @throws PropelException
     */
    #[Route('/get', name: 'get', methods: 'GET')]
    public function getLogAction(RequestStack $requestStack)
    {
        if (null !== $response = $this->checkAuth(array(AdminResources::MODULE), array('KelkooXml'), AccessManager::CREATE)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();

        $limit = $request->get('limit', 50);
        $offset = $request->get('offset');
        $levels_checked = [];

        if ($request->get('info') == 1) $levels_checked[] = KelkooxmlLogQuery::LEVEL_INFORMATION;
        if ($request->get('success') == 1) $levels_checked[] = KelkooxmlLogQuery::LEVEL_SUCCESS;
        if ($request->get('warning') == 1) $levels_checked[] = KelkooxmlLogQuery::LEVEL_WARNING;
        if ($request->get('error') == 1) $levels_checked[] = KelkooxmlLogQuery::LEVEL_ERROR;
        if ($request->get('fatal') == 1) $levels_checked[] = KelkooxmlLogQuery::LEVEL_FATAL;

        $query = KelkooxmlLogQuery::create()
            ->orderByCreatedAt('desc')
            ->orderById('desc')
            ->limit($limit);

        for ($i = 0; $i < count($levels_checked); $i++) {
            if ($i > 0) {
                $query->_or();
            }
            $query->filterByLevel($levels_checked[$i]);
        }

        if (!empty($offset)) {
            $query->offset($offset);
        }

        $logCollection = $query->find();

        $logResults = [];

        /** @var KelkooxmlLog $log **/
        foreach ($logCollection as $log) {
            $logArray = [];
            $logArray['date'] = $log->getCreatedAt()->format('d/m/Y H:i:s');
            $logArray['feed_id'] = $log->getFeedId();
            $logArray['feed_label'] = $log->getKelkooxmlFeed()->getLabel();
            $logArray['level'] = $log->getLevel();
            $logArray['message'] = $log->getMessage();
            $logArray['help'] = $log->getHelp();
            $logArray['product_id'] = !empty($log->getProductSaleElements()) ? $log->getProductSaleElements()->getProductId() : null;
            $logArray['product_ref'] = !empty($log->getProductSaleElements()) ? $log->getProductSaleElements()->getProduct()->getRef() : null;

            $logResults[] = $logArray;
        }

        return $this->jsonResponse(json_encode($logResults));
    }
}
