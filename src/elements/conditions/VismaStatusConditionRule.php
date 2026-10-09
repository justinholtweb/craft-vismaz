<?php

namespace justinholtweb\vismaz\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\OrderStatus;

/**
 * "Visma status" on Commerce's Orders index filters (and anywhere else an order condition is
 * built: custom sources, discounts, shipping rules).
 *
 * The query side and the element side are both {@see OrderStatus}' sets, so a custom source
 * "Failed in Visma" and the Visma column on the same rows can never disagree.
 *
 * Registered unconditionally — never behind a setting or a permission: Craft drops an
 * unregistered rule from a saved condition, and a custom source would silently widen to every
 * order.
 */
class VismaStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('vismaz', 'Visma status');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    /**
     * Keeps every value that was chosen, known or not. A status a later release renames or drops
     * must stay on the saved rule: stripping it here would turn "is one of <that status>" into an
     * empty rule — no filter at all — and re-saving the source would lose the choice for good.
     * Only {@see knownValues()} ever reach the query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        $clean = [];

        foreach ((array)$values as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $clean[] = (string)$value;
            }
        }

        parent::setValues(array_values(array_unique($clean)));
    }

    /**
     * The chosen values Vismaz knows. A hand-edited or stale condition cannot smuggle anything
     * else into the query.
     *
     * @return string[]
     */
    private function knownValues(): array
    {
        return array_values(array_intersect($this->getValues(), array_keys(OrderStatus::options())));
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [];

        foreach (OrderStatus::options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        // Nothing chosen is no filter, as everywhere in Craft.
        if ($this->getValues() === []) {
            return;
        }

        $known = $this->knownValues();
        $notIn = $this->operator === self::OPERATOR_NOT_IN;

        // Chosen, but none of it is a status any more: "is one of" matches nothing, rather than
        // widening a saved source to every order; "is not one of" excludes nothing.
        if ($known === []) {
            if (!$notIn) {
                $query->andWhere('0=1');
            }

            return;
        }

        $statuses = Plugin::getInstance()->getOrderStatus();
        $condition = ['or'];

        foreach ($known as $status) {
            $condition[] = $statuses->condition($status);
        }

        $query->andWhere($notIn ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        if ($this->getValues() === []) {
            return true;
        }

        if (!$element instanceof Order || !$element->id) {
            $status = OrderStatus::NONE;
        } else {
            $status = Plugin::getInstance()->getOrderStatus()->orderStatuses([$element->id])[$element->id] ?? OrderStatus::NONE;
        }

        // The same known-values set modifyQuery() uses, so a stale value cannot make the two disagree.
        $in = in_array($status, $this->knownValues(), true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }
}
