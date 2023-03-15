<?php

namespace Maatoo\Maatoo\Setup\Patch\Data;

use Exception;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\ResourceModel\Attribute as AttributeResource;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class BirthdayAttribute implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var AttributeResource
     */
    private $attributeResource;

    /**
     * @var CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    private const ATTRIBUTE_NAME = 'birthday';

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     * @param AttributeResource $attributeResource
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory     $customerSetupFactory,
        AttributeResource        $attributeResource, \Magento\Framework\Message\ManagerInterface $messageManager
    )
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeResource = $attributeResource;
        $this->messageManager = $messageManager;
    }

    /**
     * Creates birthday attribute for customer address entity
     *
     * @return BirthdayAttribute|void
     * @throws Exception
     */
    public function apply()
    {
        // Start setup
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        try {
            // Add customer attribute with settings
            $customerSetup->addAttribute(
                AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
                self::ATTRIBUTE_NAME,
                [
                    'type' => 'datetime',
                    'input' => 'date',
                    'label' => 'Birthday',
                    'required' => 0,
                    'position' => 101,
                    'system' => 0,
                    'user_defined' => 1,
                    'is_used_in_grid' => 1,
                    'is_visible_in_grid' => 1,
                    'is_filterable_in_grid' => 1,
                    'is_searchable_in_grid' => 1,
                ]
            );

            // Add attribute to default attribute set and group
            $customerSetup->addAttributeToSet(
                AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
                AddressMetadataInterface::ATTRIBUTE_SET_ID_ADDRESS,
                '',
                self::ATTRIBUTE_NAME
            );

            // Get the newly created attribute's model
            $attribute = $customerSetup->getEavConfig()
                ->getAttribute(AddressMetadataInterface::ENTITY_TYPE_ADDRESS, self::ATTRIBUTE_NAME);

            // Make attribute visible in Admin customer form
            $attribute->setData('used_in_forms', [
                'adminhtml_customer',
                'adminhtml_checkout',
                'adminhtml_customer_address',
                'customer_account_edit',
                'customer_address_edit',
                'customer_register_address'
            ]);

            // Save attribute using its resource model
            $this->attributeResource->save($attribute);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage(__('Something went wrong'));
        }

        // End setup
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Revert birthday attribute
     *
     * @return void
     */
    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $customerSetup->removeAttribute(AddressMetadataInterface::ENTITY_TYPE_ADDRESS, self::ATTRIBUTE_NAME);
        $this->moduleDataSetup->getConnection()->endSetup();
    }
}
