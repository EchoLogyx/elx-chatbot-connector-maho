<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Settings extends Mage_Core_Model_Abstract
{
    private const SCOPE = 'default';

    public function getValue(string $path): string
    {
        $row = $this->loadRow($path);
        if (!$row->getId()) {
            return '';
        }

        return (string) $row->getData('value');
    }

    public function saveValue(string $path, string $value): void
    {
        $row = $this->loadRow($path);
        if (!$row->getId()) {
            $row->setData('scope', self::SCOPE);
            $row->setData('scope_id', 0);
            $row->setData('path', $path);
        }
        $row->setData('value', $value);
        $row->save();
    }

    public function deleteValue(string $path): void
    {
        $row = $this->loadRow($path);
        if ($row->getId()) {
            $row->delete();
        }
    }

    private function loadRow(string $path): Elx_ChatbotConnector_Model_Setting
    {
        $collection = Mage::getModel('elx_chatbotconnector/setting')->getCollection();
        $collection->addFieldToFilter('scope', self::SCOPE);
        $collection->addFieldToFilter('scope_id', 0);
        $collection->addFieldToFilter('path', $path);

        /** @var Elx_ChatbotConnector_Model_Setting $row */
        $row = $collection->getFirstItem();

        return $row;
    }
}
