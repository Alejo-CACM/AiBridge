<?php

class AiBridgeFeatureHandler
{
    private $lastFailure = null;

    public function normalize($value)
    {
        if (!is_array($value)) {
            return null;
        }

        $features = array();
        $seen = array();

        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['id_feature'])) {
                return null;
            }

            $featureId = $this->normalizePositiveInteger($entry['id_feature']);
            $hasValue = array_key_exists('id_feature_value', $entry);
            $hasCustom = array_key_exists('custom_values', $entry);

            if ($featureId === null || isset($seen[$featureId]) || $hasValue === $hasCustom
                || !$this->featureExists($featureId)) {
                return null;
            }

            $seen[$featureId] = true;

            if ($hasValue) {
                $featureValueId = $this->normalizePositiveInteger($entry['id_feature_value']);

                if ($featureValueId === null || !$this->featureValueBelongsToFeature(
                    $featureValueId,
                    $featureId
                )) {
                    return null;
                }

                $features[] = array(
                    'id_feature' => $featureId,
                    'id_feature_value' => $featureValueId,
                );
                continue;
            }

            $customValues = $this->normalizeCustomValues($entry['custom_values']);

            if ($customValues === null) {
                return null;
            }

            $features[] = array(
                'id_feature' => $featureId,
                'custom_values' => $customValues,
            );
        }

        usort($features, function ($left, $right) {
            return $left['id_feature'] <=> $right['id_feature'];
        });

        return $features;
    }

    public function buildChange(Product $product, $proposed)
    {
        $current = $this->read($product);
        $normalized = $this->normalize($proposed);
        $errors = $normalized === null
            ? array('Features must be a complete valid feature list.')
            : array();
        $target = $normalized === null ? $proposed : $normalized;
        $change = array(
            'field' => 'features',
            'current' => $current,
            'proposed' => $target,
            'changed' => $current !== $target,
            'validation' => array(
                'ok' => !$errors,
                'errors' => $errors,
            ),
        );

        if (!$errors) {
            $change['added'] = $this->featureDifferences($target, $current);
            $change['removed'] = $this->featureDifferences($current, $target);
            $change['modified'] = $this->modifiedFeatures($current, $target);
        }

        return $change;
    }

    public function read(Product $product)
    {
        Product::resetStaticCache();

        $rows = $product->getFeatures();
        $features = array();

        if (!is_array($rows)) {
            return $features;
        }

        foreach ($rows as $row) {
            if (!isset($row['id_feature'], $row['id_feature_value'])) {
                continue;
            }

            $featureId = (int) $row['id_feature'];
            $featureValue = new FeatureValue((int) $row['id_feature_value']);

            if ($featureId <= 0 || !Validate::isLoadedObject($featureValue)
                || (int) $featureValue->id_feature !== $featureId) {
                continue;
            }

            if ((bool) $featureValue->custom) {
                $customValues = $this->normalizeStoredCustomValues($featureValue->value);

                if ($customValues === null) {
                    continue;
                }

                $features[] = array(
                    'id_feature' => $featureId,
                    'custom_values' => $customValues,
                );
                continue;
            }

            $features[] = array(
                'id_feature' => $featureId,
                'id_feature_value' => (int) $featureValue->id,
            );
        }

        usort($features, function ($left, $right) {
            return $left['id_feature'] <=> $right['id_feature'];
        });

        return $features;
    }

    public function apply(Product $product, array $features)
    {
        $this->lastFailure = null;

        if (!$product->deleteFeatures()) {
            $this->lastFailure = 'delete';
            return false;
        }

        foreach ($features as $feature) {
            if (isset($feature['id_feature_value'])) {
                $featureValueId = $product->addFeaturesToDB(
                    (int) $feature['id_feature'],
                    (int) $feature['id_feature_value'],
                    0
                );

                if ((int) $featureValueId <= 0) {
                    $this->lastFailure = 'existing value';
                    return false;
                }
                continue;
            }

            $featureValueId = $product->addFeaturesToDB((int) $feature['id_feature'], 0, 1);

            if ((int) $featureValueId <= 0) {
                $this->lastFailure = 'custom value';
                return false;
            }

            foreach ($feature['custom_values'] as $languageId => $customValue) {
                if (!$product->addFeaturesCustomToDB($featureValueId, $languageId, $customValue)) {
                    $this->lastFailure = 'custom value';
                    return false;
                }
            }
        }

        return true;
    }

    public function verify(Product $product, array $expected)
    {
        $verified = $this->read($product) === $expected;

        if (!$verified) {
            $this->lastFailure = 'verification';
        }

        return $verified;
    }

    public function restore(Product $product, array $snapshot)
    {
        return $this->apply($product, $snapshot) && $this->verify($product, $snapshot);
    }

    public function getLastFailure()
    {
        return $this->lastFailure;
    }

    private function featureDifferences(array $source, array $other)
    {
        $otherById = $this->indexByFeatureId($other);
        $result = array();

        foreach ($source as $feature) {
            $featureId = $feature['id_feature'];

            if (!isset($otherById[$featureId])) {
                $result[] = $feature;
            }
        }

        return $result;
    }

    private function modifiedFeatures(array $current, array $proposed)
    {
        $currentById = $this->indexByFeatureId($current);
        $proposedById = $this->indexByFeatureId($proposed);
        $result = array();

        foreach ($proposedById as $featureId => $feature) {
            if (isset($currentById[$featureId]) && $currentById[$featureId] !== $feature) {
                $result[] = array(
                    'id_feature' => $featureId,
                    'current' => $currentById[$featureId],
                    'proposed' => $feature,
                );
            }
        }

        return $result;
    }

    private function indexByFeatureId(array $features)
    {
        $indexed = array();

        foreach ($features as $feature) {
            $indexed[(int) $feature['id_feature']] = $feature;
        }

        return $indexed;
    }

    private function normalizeCustomValues($value)
    {
        if (!is_array($value) || !$value) {
            return null;
        }

        $values = array();

        foreach ($value as $languageId => $text) {
            $languageId = $this->normalizePositiveInteger($languageId);

            if ($languageId === null || !is_string($text) || trim($text) === ''
                || !$this->languageExists($languageId) || Tools::strlen($text) > 255
                || !Validate::isGenericName($text)) {
                return null;
            }

            $values[$languageId] = $text;
        }

        ksort($values, SORT_NUMERIC);

        return $values;
    }

    private function normalizeStoredCustomValues($values)
    {
        if (!is_array($values)) {
            return null;
        }

        $normalized = array();

        foreach ($values as $languageId => $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }

            $normalized[(int) $languageId] = $text;
        }

        if (!$normalized) {
            return null;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function featureExists($featureId)
    {
        return Validate::isLoadedObject(new Feature((int) $featureId));
    }

    private function featureValueBelongsToFeature($featureValueId, $featureId)
    {
        $featureValue = new FeatureValue((int) $featureValueId);

        return Validate::isLoadedObject($featureValue)
            && (int) $featureValue->id_feature === (int) $featureId;
    }

    private function languageExists($languageId)
    {
        return Validate::isLoadedObject(new Language((int) $languageId));
    }

    private function normalizePositiveInteger($value)
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
