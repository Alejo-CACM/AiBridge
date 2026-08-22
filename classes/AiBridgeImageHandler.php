<?php

require_once dirname(__FILE__) . '/AiBridgeUpload.php';

class AiBridgeImageHandler
{
    private $capturedPositions = array();
    private $capturedCoverId = null;
    private $createdImageId = 0;
    public function normalize($value, Product $product, $shopId)
    {
        if (!is_array($value) || !$value) return null;
        if (isset($value['add']) && isset($value['update'])) return null;
        foreach (array_keys($value) as $key) {
            if (!in_array($key, array('update', 'add'), true)) return null;
        }

        $result = array();
        if (isset($value['add'])) {
            $add = $this->normalizeAdd($value['add'], $product, $shopId);
            if ($add === null) return null;
            $result['add'] = $add['add'];
        }
        if (isset($value['update'])) {
            $update = $this->normalizeUpdate($value['update'], $product, $shopId);
            if ($update === null) return null;
            $result['update'] = $update['update'];
        }
        return $result ?: null;
    }

    public function normalizeCanonical($value, Product $product, $shopId)
    {
        if (!is_array($value) || !$value) return null;
        if (isset($value['add']) && isset($value['update'])) return null;
        $result = array();
        if (isset($value['add'])) {
            if (!$this->isCanonicalAdd($value['add'])) return null;
            $add = $value['add'][0];
            $upload = AiBridgeUpload::findStagedById((int) $add['id_aibridge_upload']);
            if ($upload === null || !$this->matchesUpload($add, $upload)) return null;
            $expectedPosition = count($this->readPositions((int) $product->id, $shopId)) + 1;
            if ((int) $add['target_position'] !== $expectedPosition) return null;
            $result['add'] = $value['add'];
        }
        if (isset($value['update'])) {
            $update = $this->normalizeUpdate($value['update'], $product, $shopId);
            if ($update === null) return null;
            $result['update'] = $update['update'];
        }
        return $result && count($result) === count($value) ? $result : null;
    }
    private function normalizeUpdate($value, Product $product, $shopId)
    {
        if (!is_array($value) || !$value) return null;
        $updates = array();
        $seen = array();
        $coverCount = 0;
        $positions = array();
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['id_image'])) return null;
            $allowed = array('id_image', 'legends', 'cover', 'position');
            foreach (array_keys($entry) as $key) if (!in_array($key, $allowed, true)) return null;
            if (!array_key_exists('legends', $entry) && !array_key_exists('cover', $entry) && !array_key_exists('position', $entry)) return null;
            $imageId = $this->positiveInteger($entry['id_image']);
            if ($imageId === null || isset($seen[$imageId])) return null;
            $image = new Image($imageId, null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) return null;
            $normalized = array('id_image' => $imageId);
            if (array_key_exists('legends', $entry)) {
                $legends = $this->normalizeLegends($entry['legends']);
                if ($legends === null || !$legends) return null;
                $normalized['legends'] = $legends;
            }
            if (array_key_exists('position', $entry)) {
                $position = $this->positiveInteger($entry['position']);
                if ($position === null || isset($positions[$position])) return null;
                $normalized['position'] = $position;
                $positions[$position] = true;
            }
            if (array_key_exists('cover', $entry)) {
                if (!in_array($entry['cover'], array(1, '1'), true)) return null;
                $normalized['cover'] = 1;
                ++$coverCount;
            }
            $updates[] = $normalized;
            $seen[$imageId] = true;
        }
        if ($coverCount > 1 || ($positions && max(array_keys($positions)) > count($this->readPositions((int) $product->id, $shopId)))) return null;
        usort($updates, function ($left, $right) { return $left['id_image'] <=> $right['id_image']; });
        return array('update' => $updates);
    }
    /**
     * Step-by-step re-check of normalize()'s "add" path, only used to build a
     * specific, human-readable error when normalize() returned null. Never
     * used to actually accept data.
     */
    public function diagnose($value, Product $product, $shopId)
    {
        if (!is_array($value) || !$value) {
            return 'images must be a non-empty object with "add" and/or "update".';
        }
        if (isset($value['add']) && isset($value['update'])) {
            return 'images.add and images.update cannot be sent together.';
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, array('update', 'add'), true)) {
                return 'images has an unsupported field: ' . $key . '.';
            }
        }
        if (isset($value['add'])) {
            return $this->diagnoseAdd($value['add'], $product, $shopId);
        }
        if (isset($value['update'])) {
            return $this->diagnoseUpdate($value['update'], $product, $shopId);
        }

        return 'Invalid canonical image add.';
    }

    private function diagnoseAdd($value, Product $product, $shopId)
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value[0]) || !is_array($value[0])) {
            return 'images.add must contain exactly one entry, as an array: "add": [{...}].';
        }
        $entry = $value[0];
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, array('upload_token', 'cover', 'legend'), true)) {
                return 'images.add entry has an unsupported field: ' . $key . '.';
            }
        }
        if (!isset($entry['upload_token']) || !is_string($entry['upload_token'])) {
            return 'images.add requires a string "upload_token" from a prior /imagestage upload.';
        }
        $upload = AiBridgeUpload::findStagedByToken($entry['upload_token']);
        if ($upload === null) {
            return 'upload_token not found, expired (24h), or already consumed by a previous images.add — stage a new image and use its fresh token.';
        }
        if (array_key_exists('cover', $entry) && $this->normalizeAddCover($entry['cover']) === null) {
            return 'images.add "cover" must be a boolean, 0 or 1.';
        }
        if (array_key_exists('legend', $entry)) {
            $legendError = $this->diagnoseLegends($entry['legend']);
            if ($legendError !== null) {
                return 'images.add "legend": ' . $legendError;
            }
        }

        return 'Invalid canonical image add.';
    }

    private function diagnoseUpdate($value, Product $product, $shopId)
    {
        if (!is_array($value) || !$value) {
            return 'images.update must be a non-empty array of entries.';
        }
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['id_image'])) {
                return 'each images.update entry needs "id_image".';
            }
            $allowed = array('id_image', 'legends', 'cover', 'position');
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, $allowed, true)) {
                    return 'images.update entry has an unsupported field: ' . $key . '.';
                }
            }
            if (!array_key_exists('legends', $entry) && !array_key_exists('cover', $entry) && !array_key_exists('position', $entry)) {
                return 'each images.update entry needs at least one of "legends", "cover", or "position".';
            }
            $imageId = $this->positiveInteger($entry['id_image']);
            if ($imageId === null) {
                return 'images.update "id_image" must be a positive integer.';
            }
            $image = new Image($imageId, null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                return 'id_image ' . $imageId . ' does not belong to this product.';
            }
            if (array_key_exists('legends', $entry)) {
                $legendError = $this->diagnoseLegends($entry['legends']);
                if ($legendError !== null) {
                    return 'images.update "legends" for id_image ' . $imageId . ': ' . $legendError;
                }
            }
        }

        return 'Invalid canonical image add.';
    }

    private function diagnoseLegends($value)
    {
        if (!is_array($value)) {
            return 'must be an object mapping language id to text, e.g. {"1": "text"}.';
        }
        foreach ($value as $languageId => $text) {
            $languageId = $this->positiveInteger($languageId);
            if ($languageId === null) {
                return 'keys must be positive language ids.';
            }
            if (!is_string($text) || !$this->validLegend($languageId, $text)) {
                return 'value for language ' . $languageId . ' is not a valid legend (check length/characters).';
            }
        }

        return null;
    }

    private function normalizeAdd($value, Product $product, $shopId)
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value[0]) || !is_array($value[0])) return null;
        $entry = $value[0];
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, array('upload_token', 'cover', 'legend'), true)) return null;
        }
        if (!isset($entry['upload_token']) || !is_string($entry['upload_token'])) return null;
        $upload = AiBridgeUpload::findStagedByToken($entry['upload_token']);
        if ($upload === null) return null;
        $cover = false;
        if (array_key_exists('cover', $entry)) {
            $cover = $this->normalizeAddCover($entry['cover']);
            if ($cover === null) return null;
        }
        $legend = array();
        if (array_key_exists('legend', $entry)) {
            $legend = $this->normalizeLegends($entry['legend']);
            if ($legend === null) return null;
        }
        return array('add' => array(array(
            'id_aibridge_upload' => (int) $upload->id,
            'mime' => (string) $upload->mime,
            'file_size' => (int) $upload->file_size,
            'width' => (int) $upload->width,
            'height' => (int) $upload->height,
            'cover' => $cover,
            'legend' => $legend,
            'target_position' => count($this->readPositions((int) $product->id, $shopId)) + 1,
        )));
    }

    private function normalizeLegends($value)
    {
        if (!is_array($value)) return null;
        $legends = array();
        foreach ($value as $languageId => $text) {
            $languageId = $this->positiveInteger($languageId);
            if ($languageId === null || !is_string($text) || !$this->validLegend($languageId, $text)) return null;
            $legends[$languageId] = str_replace(array("\r\n", "\r"), "\n", $text);
        }
        ksort($legends, SORT_NUMERIC);
        return $legends;
    }
    private function normalizeAddCover($value)
    {
        if (is_bool($value)) return $value;
        if (in_array($value, array(0, 1, '0', '1'), true)) return (bool) $value;
        return null;
    }
    public function buildChange(Product $product, $value, $shopId)
    {
        $normalized = $this->normalize($value, $product, $shopId);
        if ($normalized === null) {
            return $this->change(array(), $this->publicProposal($value), array('Invalid image update.'));
        }
        return $this->buildCanonicalChange($product, $normalized, $shopId);
    }

    public function buildCanonicalChange(Product $product, $canonical, $shopId)
    {
        if (is_array($canonical) && isset($canonical['_invalid_canonical'])) {
            $reason = isset($canonical['_reason']) && is_string($canonical['_reason'])
                ? $canonical['_reason']
                : 'Invalid canonical image add.';

            return $this->change(array(), array(), array($reason));
        }
        if (!is_array($canonical) || !$canonical) {
            return $this->change(array(), array(), array('Invalid canonical image add.'));
        }

        $normalized = array();
        if (isset($canonical['add'])) {
            if (!$this->isCanonicalAdd($canonical['add'])) {
                return $this->change(array(), array(), array('Invalid canonical image add.'));
            }
            $normalized['add'] = $canonical['add'];
        }
        if (isset($canonical['update'])) {
            $update = $this->normalizeUpdate($canonical['update'], $product, $shopId);
            if ($update === null) {
                return $this->change(array(), array(), array('Invalid image update.'));
            }
            $normalized['update'] = $update['update'];
        }
        if (!$normalized || count($normalized) !== count($canonical)) {
            return $this->change(array(), array(), array('Invalid image update.'));
        }

        if (isset($normalized['add']) && !isset($normalized['update'])) {
            $change = $this->change(array('add' => array()), $this->publicProposal($normalized), array());
            if (!empty($normalized['add'][0]['cover'])) {
                $change['current_cover_id'] = $this->coverId((int) $product->id);
                $change['proposed_cover'] = 'new_staged_image';
            }
            return $change;
        }

        $current = array();
        foreach ($normalized['update'] as $update) {
            $image = new Image((int) $update['id_image'], null, (int) $shopId);
            $item = array('id_image' => (int) $update['id_image']);
            if (isset($update['legends'])) {
                $legends = $this->legends($image);
                $item['legends'] = array();
                foreach ($update['legends'] as $languageId => $ignored) {
                    $item['legends'][(int) $languageId] = isset($legends[(int) $languageId]) ? $legends[(int) $languageId] : '';
                }
            }
            if (isset($update['cover'])) $item['cover'] = (int) ((bool) $image->cover);
            if (isset($update['position'])) $item['position'] = (int) $image->position;
            $current[] = $item;
        }
        if (isset($normalized['add'])) {
            $public = $this->publicProposal($normalized);
            return $this->change(array('update' => $current, 'add' => array()), array('update' => $normalized['update'], 'add' => $public['add']), array());
        }
        $change = $this->change($current, $normalized, array());
        if ($this->hasCoverUpdate($normalized)) {
            $change['current_cover_id'] = $this->coverId((int) $product->id);
            $change['proposed_cover_id'] = $this->requestedCoverId($normalized);
        }
        return $change;
    }

    private function isCanonicalAdd($value)
    {
        if (!is_array($value) || count($value) !== 1 || !isset($value[0]) || !is_array($value[0])) return false;
        $entry = $value[0];
        $required = array('id_aibridge_upload', 'mime', 'file_size', 'width', 'height', 'cover', 'legend', 'target_position');
        if (count($entry) !== count($required)) return false;
        foreach ($required as $field) if (!array_key_exists($field, $entry)) return false;
        return $this->positiveInteger($entry['id_aibridge_upload']) !== null
            && in_array($entry['mime'], array('image/jpeg', 'image/png'), true)
            && is_int($entry['file_size']) && $entry['file_size'] >= 0
            && is_int($entry['width']) && $entry['width'] > 0
            && is_int($entry['height']) && $entry['height'] > 0
            && is_bool($entry['cover']) && is_array($entry['legend'])
            && $this->positiveInteger($entry['target_position']) !== null;
    }
    private function publicProposal($value)
    {
        if (!is_array($value) || !isset($value['add']) || !is_array($value['add'])) return array();
        $items = array();
        foreach ($value['add'] as $entry) {
            if (!is_array($entry)) continue;
            $item = array();
            foreach (array('mime', 'file_size', 'width', 'height', 'cover', 'legend', 'target_position') as $key) {
                if (array_key_exists($key, $entry)) $item[$key] = $entry[$key];
            }
            $items[] = $item;
        }
        return array('add' => $items);
    }
    public function capture(Product $product, array $updates, $shopId)
    {
        if (isset($updates['add'])) {
            return array(
                'add' => $updates['add'],
                'created_image_id' => 0,
                'upload_id' => (int) $updates['add'][0]['id_aibridge_upload'],
                'previous_cover_id' => $this->coverId((int) $product->id),
            );
        }
        $snapshot = array(
            'cover_id' => $this->coverId((int) $product->id),
            'cover_touched' => $this->hasCoverUpdate($updates),
            'position_touched' => $this->hasPositionUpdate($updates),
            'positions' => $this->readPositions((int) $product->id, $shopId),
            'legends' => array(),
        );
        foreach ($updates['update'] as $update) {
            $image = new Image((int) $update['id_image'], null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                throw new Exception('Image update failed.');
            }
            if (isset($update['legends'])) {
                $snapshot['legends'][(int) $image->id] = $this->legends($image);
            }
        }
        $this->capturedPositions = $snapshot['positions'];
        $this->capturedCoverId = (int) $snapshot['cover_id'];
        return $snapshot;
    }

    public function apply(Product $product, array $updates, $shopId, array &$snapshot = null)
    {
        if (isset($updates['add'])) {
            return $this->applyAdd($product, $updates['add'], $shopId, $snapshot);
        }
        foreach ($updates['update'] as $update) {
            if (!isset($update['legends'])) {
                continue;
            }
            $image = new Image((int) $update['id_image'], null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                return false;
            }
            $legends = $this->legends($image);
            foreach ($update['legends'] as $languageId => $legend) {
                $legends[(int) $languageId] = $legend;
            }
            $image->legend = $legends;
            if (!$image->update()) {
                return false;
            }
        }

        foreach ($updates['update'] as $update) {
            if (!isset($update['cover'])) {
                continue;
            }
            if (!Image::deleteCover((int) $product->id)) {
                return false;
            }
            $image = new Image((int) $update['id_image'], null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                return false;
            }
            $image->cover = true;
            if (!$image->update()) {
                return false;
            }
        }
        if ($this->hasPositionUpdate($updates)) {
            $expected = $this->expectedPositions($this->capturedPositions ?: $this->readPositions((int) $product->id, $shopId), $updates);
            if ($expected === null || !$this->applyPositions($product, $expected, $shopId)) {
                return false;
            }
        }
        return true;
    }

    public function verify(Product $product, array $updates, $shopId, array $snapshot = null)
    {
        if (isset($updates['add'])) {
            return $this->verifyAdd($product, $updates['add'], $shopId, $snapshot);
        }
        Image::resetStaticCache();
        foreach ($updates['update'] as $update) {
            $image = new Image((int) $update['id_image'], null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                return false;
            }
            if (isset($update['legends'])) {
                $legends = $this->legends($image);
                foreach ($update['legends'] as $languageId => $legend) {
                    if (!array_key_exists((int) $languageId, $legends) || $legends[(int) $languageId] !== $legend) {
                        return false;
                    }
                }
            }
        }

        $actual = $this->readPositions((int) $product->id, $shopId);
        if ($this->hasPositionUpdate($updates)) {
            $expected = $this->expectedPositions($this->capturedPositions, $updates);
            if ($expected === null || $actual !== $expected || !$this->hasUniquePositions((int) $product->id, $shopId)) {
                return false;
            }
        }

        $coverId = $this->coverId((int) $product->id);
        if ($this->hasCoverUpdate($updates)) {
            return $coverId === $this->requestedCoverId($updates)
                && $this->hasExactlyOneCover((int) $product->id, $shopId);
        }
        return $this->capturedCoverId === null || $coverId === (int) $this->capturedCoverId;
    }
    public function restore(Product $product, array $snapshot, $shopId)
    {
        if (isset($snapshot['created_image_id'])) {
            return $this->restoreAdd($product, $snapshot, $shopId);
        }
        if (!empty($snapshot['position_touched']) && !$this->applyPositions($product, $snapshot['positions'], $shopId)) {
            return false;
        }

        foreach ($snapshot['legends'] as $imageId => $legends) {
            Image::resetStaticCache();
        $image = new Image((int) $imageId, null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) {
                return false;
            }
            $image->legend = $legends;
            if (!$image->update()) {
                return false;
            }
        }

if (!empty($snapshot['cover_touched'])) {
            if (!Image::deleteCover((int) $product->id)) {
                return false;
            }
            if ((int) $snapshot['cover_id'] > 0) {
                $cover = new Image((int) $snapshot['cover_id'], null, (int) $shopId);
                if (!Validate::isLoadedObject($cover) || (int) $cover->id_product !== (int) $product->id) {
                    return false;
                }
                $cover->cover = true;
                if (!$cover->update()) {
                    return false;
                }
            }
        }

        foreach ($snapshot['legends'] as $imageId => $legends) {
            Image::resetStaticCache();
        $image = new Image((int) $imageId, null, (int) $shopId);
            if (!Validate::isLoadedObject($image) || $this->legends($image) !== $legends) {
                return false;
            }
        }
        return empty($snapshot['cover_touched'])
            || $this->coverId((int) $product->id) === (int) $snapshot['cover_id'];
    }

    private function applyAdd(Product $product, array $adds, $shopId, array &$snapshot = null)
    {
        if (count($adds) !== 1) return false;
        $add = $adds[0]; $upload = AiBridgeUpload::findStagedById((int) $add['id_aibridge_upload']);
        if ($upload === null || !$this->matchesUpload($add, $upload)) return false;
        $image = new Image();
        $image->id_product = (int) $product->id;
        $image->position = (int) $add['target_position'];
        $image->cover = false;
        $image->legend = $add['legend'];
        if (!$image->add()) return false;

        $this->createdImageId = (int) $image->id;
        if (is_array($snapshot)) $snapshot['created_image_id'] = $this->createdImageId;

        $image->associateTo((int) $shopId, (int) $product->id);
        if (!$image->isAssociatedToShop((int) $shopId)) return false;

        $path = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath();
        $error = 0;
        if (!ImageManager::resize($upload->file_path, $path . '.jpg', null, null, 'jpg', false, $error)) return false;
        foreach (ImageType::getImagesTypes('products') as $type) {
            if (!ImageManager::resize($upload->file_path, $path . '-' . stripslashes($type['name']) . '.jpg', (int) $type['width'], (int) $type['height'], 'jpg', false, $error)) return false;
        }

        if (!empty($add['cover'])) {
            if (!Image::deleteCover((int) $product->id)) return false;
            $image->cover = true;
            if (!$image->update()) return false;
        }

        return true;
    }

    private function verifyAdd(Product $product, array $adds, $shopId, array $snapshot = null)
    {
        if (count($adds) !== 1 || !is_array($snapshot) || (int) $snapshot['created_image_id'] <= 0) return false;
        $add = $adds[0]; Image::resetStaticCache(); $image = new Image((int) $snapshot['created_image_id'], null, (int) $shopId);
        if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id || !$image->isAssociatedToShop((int) $shopId) || (int) $image->position !== (int) $add['target_position']) return false;
        $legends = $this->legends($image); foreach ($add['legend'] as $languageId => $legend) if (!isset($legends[(int) $languageId]) || $legends[(int) $languageId] !== $legend) return false;
        if (!empty($add['cover'])) {
            if ((int) $this->coverId((int) $product->id) !== (int) $image->id || !$this->hasExactlyOneCover((int) $product->id, $shopId)) return false;
        }
        return is_file(_PS_PRODUCT_IMG_DIR_ . $image->getImgPath() . '.jpg');
    }

    public function consumeAddedUpload(array $snapshot)
    {
        if (empty($snapshot['upload_id'])) return true;
        $upload = AiBridgeUpload::findStagedById((int) $snapshot['upload_id']);
        return $upload !== null && AiBridgeUpload::consume($upload);
    }

    private function restoreAdd(Product $product, array $snapshot, $shopId)
    {
        $success = true;
        if ((int) $snapshot['created_image_id'] > 0) {
            $image = new Image((int) $snapshot['created_image_id'], null, (int) $shopId);
            if (Validate::isLoadedObject($image)) {
                $success = (int) $image->id_product === (int) $product->id && $image->delete();
            }
            Image::resetStaticCache();
            $check = new Image((int) $snapshot['created_image_id'], null, (int) $shopId);
            $success = $success && !Validate::isLoadedObject($check);
        }
        if (!empty($snapshot['add'][0]['cover']) && array_key_exists('previous_cover_id', $snapshot)) {
            $previousCoverId = (int) $snapshot['previous_cover_id'];
            if ($previousCoverId > 0) {
                $previousCover = new Image($previousCoverId, null, (int) $shopId);
                if (Validate::isLoadedObject($previousCover) && (int) $previousCover->id_product === (int) $product->id) {
                    $previousCover->cover = true;
                    $success = $previousCover->update() && $success;
                }
            }
        }
        if (!empty($snapshot['upload_id']) && !AiBridgeUpload::ensureStaged((int) $snapshot['upload_id'])) $success = false;
        return $success;
    }

    private function matchesUpload(array $add, AiBridgeUpload $upload)
    {
        return (string) $add['mime'] === (string) $upload->mime && (int) $add['file_size'] === (int) $upload->file_size && (int) $add['width'] === (int) $upload->width && (int) $add['height'] === (int) $upload->height;
    }
    private function hasPositionUpdate(array $updates)
    {
        foreach ($updates['update'] as $update) {
            if (isset($update['position'])) return true;
        }
        return false;
    }

    private function expectedPositions(array $original, array $updates)
    {
        if (!$original) return null;
        $targets = array();
        foreach ($updates['update'] as $update) {
            if (isset($update['position'])) $targets[(int) $update['position']] = (int) $update['id_image'];
        }
        if (!$targets) return $original;
        ksort($targets, SORT_NUMERIC);
        $orderedIds = array_keys($original);
        $remaining = array_values(array_diff($orderedIds, array_values($targets)));
        $expected = array();
        for ($position = 1; $position <= count($orderedIds); ++$position) {
            $imageId = isset($targets[$position]) ? $targets[$position] : array_shift($remaining);
            if ($imageId === null) return null;
            $expected[(int) $imageId] = $position;
        }
        asort($expected, SORT_NUMERIC);
        return $expected;
    }

    private function applyPositions(Product $product, array $expected, $shopId)
    {
        asort($expected, SORT_NUMERIC);
        foreach ($expected as $imageId => $position) {
            $actual = $this->readPositions((int) $product->id, $shopId);
            if (!isset($actual[(int) $imageId])) return false;
            if ((int) $actual[(int) $imageId] === (int) $position) continue;
            if (!$this->moveToPosition($product, (int) $imageId, (int) $position, $shopId)) return false;
            $after = $this->readPositions((int) $product->id, $shopId);
            if (!isset($after[(int) $imageId]) || (int) $after[(int) $imageId] !== (int) $position) return false;
        }
        return $this->readPositions((int) $product->id, $shopId) === $expected;
    }
    private function moveToPosition(Product $product, $imageId, $position, $shopId)
    {
        Image::resetStaticCache();
        $image = new Image((int) $imageId, null, (int) $shopId);
        if (!Validate::isLoadedObject($image) || (int) $image->id_product !== (int) $product->id) return false;
        $current = (int) $image->position;
        return $current === (int) $position || (bool) $image->updatePosition($current < (int) $position ? 1 : 0, (int) $position);
    }

    private function readPositions($productId, $shopId)
    {
        $rows = Image::getImages((int) Configuration::get('PS_LANG_DEFAULT'), (int) $productId, null, (int) $shopId);
        if (!is_array($rows)) return array();
        $positions = array();
        foreach ($rows as $row) $positions[(int) $row['id_image']] = (int) $row['position'];
        asort($positions, SORT_NUMERIC);
        return $positions;
    }

    private function hasUniquePositions($productId, $shopId)
    {
        $positions = $this->readPositions($productId, $shopId);
        return count($positions) === count(array_unique(array_values($positions)));
    }

    private function restorePositions(Product $product, array $positions, $shopId)
    {
        asort($positions, SORT_NUMERIC);
        foreach ($positions as $imageId => $position) {
            if (!$this->moveToPosition($product, (int) $imageId, (int) $position, $shopId)) return false;
        }
        return $this->readPositions((int) $product->id, $shopId) === $positions;
    }

    private function hasCoverUpdate(array $updates)
    {
        foreach ($updates['update'] as $update) {
            if (isset($update['cover'])) {
                return true;
            }
        }
        return false;
    }
    private function requestedCoverId(array $updates)
    {
        foreach ($updates['update'] as $update) {
            if (isset($update['cover'])) {
                return (int) $update['id_image'];
            }
        }
        return 0;
    }
    private function coverId($productId)
    {
        $cover = Image::getCover((int) $productId);
        return is_array($cover) && isset($cover['id_image']) ? (int) $cover['id_image'] : 0;
    }

    private function hasExactlyOneCover($productId, $shopId)
    {
        $images = Image::getImages((int) Configuration::get('PS_LANG_DEFAULT'), (int) $productId, null, (int) $shopId);
        if (!is_array($images)) {
            return false;
        }
        $count = 0;
        foreach ($images as $image) {
            if (!empty($image['cover'])) {
                ++$count;
            }
        }
        return $count === 1;
    }

    private function legends(Image $image)
    {
        $legends = is_array($image->legend) ? $image->legend : array();
        $result = array();
        foreach ($legends as $languageId => $legend) {
            $result[(int) $languageId] = (string) $legend;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private function validLegend($languageId, $legend)
    {
        $language = new Language((int) $languageId);
        if (!Validate::isLoadedObject($language)) {
            return false;
        }
        $definition = Image::$definition['fields']['legend'];
        $validator = isset($definition['validate']) ? $definition['validate'] : null;
        return isset($definition['size']) && Tools::strlen($legend) <= (int) $definition['size']
            && is_string($validator) && method_exists('Validate', $validator)
            && (bool) call_user_func(array('Validate', $validator), $legend);
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

    private function change($current, $proposed, array $errors)
    {
        return array(
            'field' => 'images',
            'current' => $current,
            'proposed' => $proposed,
            'changed' => $current !== $proposed,
            'validation' => array('ok' => !$errors, 'errors' => $errors),
        );
    }
}