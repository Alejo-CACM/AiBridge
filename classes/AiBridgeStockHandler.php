<?php

class AiBridgeStockHandler
{
    public function normalize($value, Product $product)
    {
        if (!is_array($value) || (array_key_exists('simple_quantity', $value) && array_key_exists('combinations', $value))) {
            return null;
        }

        $hasCombinations = (bool) $product->hasAttributes();
        if (array_key_exists('simple_quantity', $value)) {
            $quantity = $this->quantity($value['simple_quantity']);
            return !$hasCombinations && $quantity !== null ? array('simple_quantity' => $quantity) : null;
        }

        if (!array_key_exists('combinations', $value) || !$hasCombinations || !is_array($value['combinations'])) {
            return null;
        }

        $result = array(); $seen = array();
        foreach ($value['combinations'] as $entry) {
            if (!is_array($entry) || !isset($entry['id_product_attribute'], $entry['quantity'])) return null;
            $id = $this->positive($entry['id_product_attribute']); $quantity = $this->quantity($entry['quantity']);
            $combination = $id ? new Combination($id) : null;
            if ($id === null || $quantity === null || isset($seen[$id]) || !Validate::isLoadedObject($combination) || (int)$combination->id_product !== (int)$product->id) return null;
            $seen[$id] = true; $result[] = array('id_product_attribute' => $id, 'quantity' => $quantity);
        }
        usort($result, function($a,$b){return $a['id_product_attribute'] <=> $b['id_product_attribute'];});
        return array('combinations' => $result);
    }

    public function read(Product $product, $shopId)
    {
        $result = array();
        if (!$product->hasAttributes()) return array('simple_quantity' => (int)StockAvailable::getQuantityAvailableByProduct($product->id, 0, $shopId));
        foreach (Product::getProductAttributesIds((int)$product->id) as $row) {
            $id = (int)$row['id_product_attribute'];
            $result[] = array('id_product_attribute' => $id, 'quantity' => (int)StockAvailable::getQuantityAvailableByProduct($product->id, $id, $shopId));
        }
        usort($result,function($a,$b){return $a['id_product_attribute'] <=> $b['id_product_attribute'];});
        return array('combinations'=>$result);
    }

    public function capture(Product $product, array $stock, $shopId)
    {
        if (isset($stock['simple_quantity'])) {
            return array('simple_quantity' => (int) StockAvailable::getQuantityAvailableByProduct($product->id, 0, $shopId));
        }

        $original = array('combinations' => array());
        foreach ($stock['combinations'] as $entry) {
            $original['combinations'][] = array(
                'id_product_attribute' => (int) $entry['id_product_attribute'],
                'quantity' => (int) StockAvailable::getQuantityAvailableByProduct($product->id, (int) $entry['id_product_attribute'], $shopId),
            );
        }

        return $original;
    }

    public function apply(Product $product, array $stock, $shopId)
    {
        foreach (isset($stock['combinations']) ? $stock['combinations'] : array(array('id_product_attribute'=>0,'quantity'=>$stock['simple_quantity'])) as $entry) {
            StockAvailable::setQuantity((int)$product->id, (int)$entry['id_product_attribute'], (int)$entry['quantity'], $shopId, false);
        }
        Cache::clean('StockAvailable::*');
        return $this->verify($product, $stock, $shopId);
    }

    public function verify(Product $product, array $expected, $shopId)
    {
        $actual=$this->read($product,$shopId);
        if(isset($expected['simple_quantity'])) return isset($actual['simple_quantity']) && $actual['simple_quantity']===$expected['simple_quantity'];
        foreach($expected['combinations'] as $entry){$found=false;foreach($actual['combinations'] as $current){if($current['id_product_attribute']===$entry['id_product_attribute'] && $current['quantity']===$entry['quantity']){$found=true;break;}}if(!$found)return false;}
        return true;
    }

    public function buildChange(Product $product, $value, $shopId)
    {
        $normalized=$this->normalize($value,$product);$current=$this->read($product,$shopId);$target=$normalized===null?$value:$normalized;
        return array('field'=>'stock','current'=>$current,'proposed'=>$target,'changed'=>$current!==$target,'validation'=>array('ok'=>$normalized!==null,'errors'=>$normalized===null?array('Invalid stock payload.'):array()));
    }

    private function quantity($value){if(is_bool($value))return null; if(is_int($value)&&$value>=0)return $value;if(is_string($value)&&ctype_digit($value))return (int)$value;return null;}
    private function positive($value){return (is_int($value)&&$value>0)?$value:((is_string($value)&&ctype_digit($value)&&(int)$value>0)?(int)$value:null);}
}
