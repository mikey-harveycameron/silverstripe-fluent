<?php

namespace TractorCow\Fluent\Extension;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\Requirements;
use TractorCow\Fluent\Extension\Traits\FluentAdminTrait;
use TractorCow\Fluent\Extension\Traits\FluentBadgeTrait;

/**
 * @property LeftAndMain $owner
 */
class FluentLeftAndMainExtension extends Extension
{
    use FluentAdminTrait;
    use FluentBadgeTrait;

    public function init()
    {
        Requirements::javascript("tractorcow/silverstripe-fluent:client/dist/js/fluent.js");
        Requirements::css("tractorcow/silverstripe-fluent:client/dist/styles/fluent.css");
    }

    /**
     * @see CMSMain::Breadcrumbs()
     * @param ArrayList $breadcrumbs
     */
    public function updateBreadcrumbs(ArrayList $breadcrumbs)
    {
        $record = $this->owner->currentPage();
        if (!$record) {
            return;
        }

        // Get a possibly existing badge field from the last item in the breadcrumbs list
        $lastItem = $breadcrumbs->last();
        $badgeField = $lastItem->hasField('Extra') ? $lastItem->getField('Extra') : null;
        $newBadge = $this->addFluentBadge($badgeField, $record);

        $lastItem->setField('Extra', $newBadge);
    }

    /**
     * @param $form
     * @param $message
     * @return \SilverStripe\Control\HTTPResponse
     * @throws \SilverStripe\Control\HTTPResponse_Exception
     */
    public function actionComplete($form, $message)
    {
        $request = $this->owner->getRequest();

        $response = $this->owner->getResponseNegotiator()->respond($request);

        // Pass on message
        $response->addHeader('X-Status', rawurlencode($message));

        return $response;
    }
}
