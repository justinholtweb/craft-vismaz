<?php

namespace justinholtweb\vismaz\services;

use craft\commerce\base\Purchasable;
use craft\commerce\models\LineItem;
use craft\helpers\Db;
use DateTime;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\EntityRecord;
use Throwable;
use yii\base\Component;

/**
 * Commerce purchasables → Visma articles.
 *
 * Optional: an invoice made entirely of free-text rows is perfectly valid, and for a shop with a
 * 40,000-SKU catalogue it is the only sane choice. Turning article sync on is for merchants who
 * want Visma's own sales-per-article reporting to work.
 *
 * Articles are looked up by SKU, not created blindly — a shop that already keeps its articles in
 * Visma must not end up with a duplicate register.
 */
class Articles extends Component
{
    public const ENTITY_TYPE = 'article';

    /**
     * The Visma article GUID for a line item, or null when article sync is off or the line has
     * no purchasable behind it.
     */
    public function resolveForLineItem(LineItem $lineItem): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->syncArticles) {
            return null;
        }

        $sku = trim((string)$lineItem->getSku());

        if ($sku === '') {
            return null;
        }

        $existing = $this->findMapping($sku);

        if ($existing !== null && $existing->vismaId) {
            return $existing->vismaId;
        }

        try {
            return $this->resolveBySku($sku, $lineItem);
        } catch (Throwable $e) {
            // An article that cannot be created must not sink the invoice — the row falls back to
            // free text, which is a worse report and a correct set of books.
            $plugin->getLog()->warning('article.sync', $e->getMessage());

            return null;
        }
    }

    private function resolveBySku(string $sku, LineItem $lineItem): ?string
    {
        $plugin = Plugin::getInstance();

        $matches = $plugin->getApi()->getAll(
            'articles',
            ['$filter' => sprintf("Number eq '%s'", Customers::escapeOData($sku))],
            1
        );

        if (!empty($matches[0]['Id'])) {
            $this->storeMapping($sku, $matches[0]['Id'], $sku);

            return $matches[0]['Id'];
        }

        $created = $plugin->getApi()->post('articles', $this->buildPayload($sku, $lineItem));
        $vismaId = is_array($created) ? ($created['Id'] ?? null) : null;

        if ($vismaId !== null) {
            $this->storeMapping($sku, $vismaId, $sku);
        }

        return $vismaId;
    }

    /**
     * Visma's `Article` shape.
     */
    public function buildPayload(string $sku, LineItem $lineItem): array
    {
        $tax = Plugin::getInstance()->getTax();
        $rate = $tax->lineItemVatRate($lineItem);

        $purchasable = null;

        try {
            $purchasable = $lineItem->getPurchasable();
        } catch (Throwable) {
            // A purchasable can be deleted out from under a historical order; the SKU is enough.
        }

        return array_filter([
            'Number' => $sku,
            'Name' => mb_substr((string)($lineItem->getDescription() ?: $sku), 0, 100),
            'IsActive' => true,
            'NetPrice' => Money::round((float)$lineItem->salePrice),
            'CodingId' => null,
            'UnitId' => null,
            'IsStockItem' => $purchasable instanceof Purchasable ? $purchasable->getIsShippable() : false,
            // Visma wants the *rate*, and derives the VAT code from the coding on the article.
            'VatPercent' => $rate,
        ], static fn($v): bool => $v !== null);
    }

    public function findMapping(string $sku): ?EntityRecord
    {
        /** @var EntityRecord|null $record */
        $record = EntityRecord::find()
            ->where(['entityType' => self::ENTITY_TYPE, 'localId' => $sku])
            ->one();

        return $record;
    }

    public function storeMapping(string $sku, ?string $vismaId, ?string $number): void
    {
        $record = $this->findMapping($sku) ?? new EntityRecord([
            'entityType' => self::ENTITY_TYPE,
            'localId' => $sku,
        ]);

        $record->vismaId = $vismaId;
        $record->vismaNumber = $number;
        $record->dateSynced = Db::prepareDateForDb(new DateTime());
        $record->save(false);
    }
}
