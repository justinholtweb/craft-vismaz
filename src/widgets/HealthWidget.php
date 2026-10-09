<?php

namespace justinholtweb\vismaz\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\vismaz\Plugin;

/**
 * A dashboard tile: whether Vismaz is connected, how the documents and payments stand, and any
 * open alert.
 *
 * The Documents screen is where the detail lives; this is what makes somebody go there. It shows
 * the same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('vismaz', 'Visma health');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/vismaz/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('vismaz-viewDocuments');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('vismaz', 'Visma health');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('vismaz-viewDocuments')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('vismaz/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
        ]);
    }
}
