<?php

/**
 * Manages exactly one "flat discount for everyone" specific_price row per
 * product (id_shop=0, id_currency=0, id_country=0, id_group=0, id_customer=0,
 * id_cart=0, id_specific_price_rule=0, id_product_attribute=0). Any other
 * specific_price rows on the product (e.g. created manually from the Back
 * Office for a specific customer group or cart rule) are left untouched.
 *
 * normalize() output is itself valid input (idempotent), because the rest of
 * the module always re-normalizes the already-canonicalized payload.
 */
class AiBridgeDiscountHandler
{
    private const NO_DATE = '0000-00-00 00:00:00';
    private const SET_FIELDS = array('active', 'reduction_type', 'reduction', 'from_quantity', 'from', 'to');

    public function normalize($value)
    {
        if (!is_array($value) || !$value) return null;

        if (array_key_exists('active', $value) && $value['active'] === false) {
            return count($value) === 1 ? array('active' => false) : null;
        }

        foreach (array_keys($value) as $field) {
            if (!in_array($field, self::SET_FIELDS, true)) return null;
        }
        if (!isset($value['reduction_type'], $value['reduction'])) return null;

        if (!in_array($value['reduction_type'], array('percentage', 'amount'), true)) return null;
        $reductionType = $value['reduction_type'];

        if (!is_numeric($value['reduction']) || is_bool($value['reduction'])) return null;
        $reduction = (float) $value['reduction'];
        if ($reductionType === 'percentage' && ($reduction <= 0 || $reduction > 1)) return null;
        if ($reductionType === 'amount' && $reduction <= 0) return null;
        if (!Validate::isPrice($reduction)) return null;

        $fromQuantity = 1;
        if (array_key_exists('from_quantity', $value)) {
            $input = $value['from_quantity'];
            if (is_bool($input) || !(is_int($input) || (is_string($input) && ctype_digit($input))) || (int) $input < 1) return null;
            $fromQuantity = (int) $input;
        }

        $from = self::NO_DATE;
        if (array_key_exists('from', $value) && $value['from'] !== null && $value['from'] !== '') {
            $from = $this->normalizeDate($value['from']);
            if ($from === null) return null;
        }

        $to = self::NO_DATE;
        if (array_key_exists('to', $value) && $value['to'] !== null && $value['to'] !== '') {
            $to = $this->normalizeDate($value['to']);
            if ($to === null) return null;
        }

        if ($from !== self::NO_DATE && $to !== self::NO_DATE && $from > $to) return null;

        return array(
            'active' => true,
            'reduction_type' => $reductionType,
            'reduction' => $reduction,
            'from_quantity' => $fromQuantity,
            'from' => $from,
            'to' => $to,
        );
    }

    public function buildChange(Product $product, $value)
    {
        $current = $this->read($product);
        $normalized = $this->normalize($value);
        $errors = $normalized === null
            ? array('Discount payload must be {"active": false} to remove, or {"active": true (default), "reduction_type": "percentage"|"amount", "reduction": number, ...}.')
            : array();
        $currentView = $this->publicView($current);
        $target = $normalized === null ? $value : $this->publicView($normalized);

        return array(
            'field' => 'discount',
            'current' => $currentView,
            'proposed' => $target,
            'changed' => $currentView !== $target,
            'validation' => array('ok' => !$errors, 'errors' => $errors),
        );
    }

    public function read(Product $product)
    {
        $row = $this->findManagedRow((int) $product->id);
        if ($row === null) {
            return null;
        }

        return array(
            'active' => true,
            'reduction_type' => (string) $row['reduction_type'],
            'reduction' => (float) $row['reduction'],
            'from_quantity' => (int) $row['from_quantity'],
            'from' => (string) $row['from'],
            'to' => (string) $row['to'],
        );
    }

    public function capture(Product $product)
    {
        return $this->read($product);
    }

    public function apply(Product $product, array $normalized)
    {
        $existing = $this->findManagedRow((int) $product->id);

        if ($normalized['active'] === false) {
            if ($existing === null) {
                return true;
            }
            $specificPrice = new SpecificPrice((int) $existing['id_specific_price']);

            return Validate::isLoadedObject($specificPrice) && $specificPrice->delete();
        }

        $specificPrice = $existing !== null
            ? new SpecificPrice((int) $existing['id_specific_price'])
            : new SpecificPrice();

        if ($existing === null) {
            $specificPrice->id_product = (int) $product->id;
            $specificPrice->id_product_attribute = 0;
            $specificPrice->id_shop = 0;
            $specificPrice->id_shop_group = 0;
            $specificPrice->id_currency = 0;
            $specificPrice->id_country = 0;
            $specificPrice->id_group = 0;
            $specificPrice->id_customer = 0;
            $specificPrice->id_cart = 0;
            $specificPrice->id_specific_price_rule = 0;
            $specificPrice->price = -1;
            $specificPrice->reduction_tax = 1;
        }

        $specificPrice->reduction_type = $normalized['reduction_type'];
        $specificPrice->reduction = $normalized['reduction'];
        $specificPrice->from_quantity = $normalized['from_quantity'];
        $specificPrice->from = $normalized['from'];
        $specificPrice->to = $normalized['to'];

        return $existing !== null ? $specificPrice->update() : $specificPrice->add();
    }

    public function verify(Product $product, array $normalized)
    {
        $current = $this->read($product);

        if ($normalized['active'] === false) {
            return $current === null;
        }

        return $current !== null
            && $current['reduction_type'] === $normalized['reduction_type']
            && (float) $current['reduction'] === (float) $normalized['reduction']
            && $current['from_quantity'] === $normalized['from_quantity']
            && $current['from'] === $normalized['from']
            && $current['to'] === $normalized['to'];
    }

    public function restore(Product $product, $snapshot)
    {
        $target = $snapshot === null ? array('active' => false) : $snapshot;

        return $this->apply($product, $target) && $this->verify($product, $target);
    }

    private function publicView($normalizedOrCurrent)
    {
        if ($normalizedOrCurrent === null || $normalizedOrCurrent['active'] === false) {
            return null;
        }

        return array(
            'reduction_type' => $normalizedOrCurrent['reduction_type'],
            'reduction' => $normalizedOrCurrent['reduction'],
            'from_quantity' => $normalizedOrCurrent['from_quantity'],
            'from' => $normalizedOrCurrent['from'] === self::NO_DATE ? null : $normalizedOrCurrent['from'],
            'to' => $normalizedOrCurrent['to'] === self::NO_DATE ? null : $normalizedOrCurrent['to'],
        );
    }

    private function findManagedRow($productId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price`
            WHERE id_product = ' . (int) $productId . '
            AND id_product_attribute = 0 AND id_shop = 0 AND id_shop_group = 0
            AND id_currency = 0 AND id_country = 0 AND id_group = 0 AND id_customer = 0
            AND id_cart = 0 AND id_specific_price_rule = 0'
        );

        return is_array($row) ? $row : null;
    }

    private function normalizeDate($value)
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 00:00:00';
        }
        if (!Validate::isDateFormat($value)) return null;

        return $value;
    }
}
