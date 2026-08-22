<?php

class AiBridgeTagHandler
{
    private const MAX_TAG_LENGTH = 32;
    private const MAX_TAGS_PER_LANGUAGE = 50;

    public function normalize($value)
    {
        if (!is_array($value) || !$value) {
            return null;
        }

        $normalized = array();

        foreach ($value as $languageId => $tags) {
            $languageId = $this->positiveInteger($languageId);

            if ($languageId === null || !$this->languageExists($languageId) || !is_array($tags)
                || count($tags) > self::MAX_TAGS_PER_LANGUAGE) {
                return null;
            }

            $list = array();
            $seen = array();

            foreach ($tags as $tag) {
                if (!is_string($tag)) {
                    return null;
                }

                $tag = trim($tag);

                if ($tag === '' || Tools::strlen($tag) > self::MAX_TAG_LENGTH
                    || !Validate::isGenericName($tag)) {
                    return null;
                }

                $key = Tools::strtolower($tag);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $list[] = $tag;
            }

            sort($list, SORT_STRING);
            $normalized[$languageId] = $list;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    public function buildChange(Product $product, $proposed)
    {
        $current = $this->read($product);
        $normalized = $this->normalize($proposed);
        $errors = $normalized === null
            ? array('Tags must be a map of language id to a list of tag strings.')
            : array();
        $target = $normalized === null ? $proposed : $normalized;

        $changed = false;
        if ($normalized !== null) {
            foreach ($normalized as $languageId => $tags) {
                $existing = isset($current[$languageId]) ? $current[$languageId] : array();
                if ($existing !== $tags) {
                    $changed = true;
                    break;
                }
            }
        }

        return array(
            'field' => 'tags',
            'current' => $current,
            'proposed' => $target,
            'changed' => $changed,
            'validation' => array('ok' => !$errors, 'errors' => $errors),
        );
    }

    public function read(Product $product)
    {
        $rows = Tag::getProductTags((int) $product->id);

        if (!is_array($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $languageId => $tags) {
            $languageId = (int) $languageId;
            $tags = array_values(array_unique(array_map('strval', (array) $tags)));
            sort($tags, SORT_STRING);
            $result[$languageId] = $tags;
        }

        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * Only the languages present as keys in $tags are touched; languages not
     * mentioned keep their existing tags untouched. Pass an empty array for a
     * language to clear its tags.
     */
    public function capture(Product $product, array $tags)
    {
        $current = $this->read($product);
        $snapshot = array();

        foreach (array_keys($tags) as $languageId) {
            $languageId = (int) $languageId;
            $snapshot[$languageId] = isset($current[$languageId]) ? $current[$languageId] : array();
        }

        return $snapshot;
    }

    public function apply(Product $product, array $tags)
    {
        foreach ($tags as $languageId => $list) {
            $languageId = (int) $languageId;

            if (!Tag::deleteProductTagsInLang((int) $product->id, $languageId)) {
                return false;
            }

            if ($list && !Tag::addTags($languageId, (int) $product->id, $list)) {
                return false;
            }
        }

        return true;
    }

    public function verify(Product $product, array $expected)
    {
        $current = $this->read($product);

        foreach ($expected as $languageId => $list) {
            $languageId = (int) $languageId;
            $actual = isset($current[$languageId]) ? $current[$languageId] : array();

            if ($actual !== $list) {
                return false;
            }
        }

        return true;
    }

    public function restore(Product $product, array $snapshot)
    {
        return $this->apply($product, $snapshot) && $this->verify($product, $snapshot);
    }

    private function languageExists($languageId)
    {
        $language = new Language((int) $languageId);

        return Validate::isLoadedObject($language) && (bool) $language->active;
    }

    private function positiveInteger($value)
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
