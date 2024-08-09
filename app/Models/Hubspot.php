<?php

namespace App\Models;

use GuzzleHttp\ClientInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use HubSpot\Factory;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class Hubspot extends User
{
    use HasFactory, SoftDeletes;

    protected $SCOPES = 'crm.objects.companies.read crm.objects.contacts.read crm.objects.deals.read crm.objects.line_items.read crm.objects.deals.write crm.objects.owners.read contacts oauth tickets e-commerce';
    protected $factory;
    protected $table = 'users';

    private static ?ClientInterface $clientInterface = null;

    public static function setClientInterface(?ClientInterface $clientInterface = null)
    {
        self::$clientInterface = $clientInterface;
    }

    public function initialise() {
        if ($this->hubspot_state === "CONNECTED") {
            $this->refreshHubspotAcessToken();
        }

        $this->factory = Factory::createWithAccessToken($this->hubspot_access_token, self::$clientInterface);
    }

    public function refreshHubspotAcessToken() {
        if (empty($this->hubspot_access_token)) {
            Log::warning('Hubspot.refreshAndGetAccessToken - HubSpot has not been authorised');
            // throw new UnauthorisedException("HubSpot has not been authorised");
        }

        // If token expire then generate new and  update into database
        if (time() > $this->hubspot_access_token_expires_in) {
            Log::info('Hubspot.refreshAndGetAccessToken - Refresh access token', ["integration"=>$this]);
            $tokens = Factory::create()->auth()->oAuth()->tokensApi()->createToken(
                'refresh_token',
                null,
                env('HUBSPOT_REDIRECT_URL'),
                env('HUBSPOT_CLIENT_ID'),
                env('HUBSPOT_CLIENT_SECRET'),
                $this->hubspot_refresh_token
            );
            Log::info('Hubspot.refreshAndGetAccessToken - Update tokens', ["integration"=>$this]);
            
            $this->hubspot_access_token = $tokens->getAccessToken();
            $this->hubspot_refresh_token = $tokens->getRefreshToken();
            $this->hubspot_access_token_expires_in = time() + ($tokens->getExpiresIn() * 0.95);
            $this->save();
        }
    }

    public function getContact($id) {
        $this->initialise();
        $contact = $this->factory->crm()->contacts()->basicApi()->getById($id, [env('HUBSPOT_CONTACT_PROPERTIES')]);
        return $contact;
    }
    public function createContact($newProperties) {
        $this->initialise();
        $contactInput = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput();
        $contactInput->setProperties($newProperties);
        $contact = $this->factory->crm()->contacts()->basicApi()->create($contactInput);
        return $contact;
    }

    public function updateContact($id, $newProperties) {
        $this->initialise();
        $contactInput = new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput();
        $contactInput->setProperties($newProperties);

        $contact = $this->factory->crm()->contacts()->basicApi()->update($id, $contactInput);
        return $contact;
    }
    

    public function getDeal($dealId) {
        $this->initialise();

        $deal = $this->factory->crm()->deals()->basicApi()->getById($dealId, [env('HUBSPOT_DEAL_PROPERTIES')]);

        return $deal;
    }

    public function getDealContactAssociations($dealId) {
        $this->initialise();

        $dealAssociations = $this->factory->crm()->deals()->associationsApi()->getAll($dealId, self::CONTACTS);

        return $dealAssociations;
    }

    public function getBatchContacts($ids) {
        $this->initialise();

        $contactIds = array_map(function($id) {
            return new \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectId(["id"=>$id]);
        }, $ids);

        $BatchReadInputSimplePublicObjectId = new \HubSpot\Client\Crm\Contacts\Model\BatchReadInputSimplePublicObjectId([
            'properties' => [env('HUBSPOT_CONTACT_PROPERTIES')], 'inputs' => $contactIds]);

        $contacts = $this->factory->crm()->contacts()->batchApi()->read($BatchReadInputSimplePublicObjectId);
        return $contacts;
    }

    public function getCompanies($id) {
        $this->initialise();
        $contact = $this->factory->crm()->companies()->basicApi()->getById($id, [env('HUBSPOT_COMPANY_PROPERTIES')]);
        return $contact;
    }

    public function createCompanies($newProperties) {
        Log::info('properties', ['properties' => $newProperties]);
        $this->initialise();
        $companyInput = new \HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput();
        $companyInput->setProperties($newProperties);

        $contact = $this->factory->crm()->companies()->basicApi()->create($companyInput);
        return $contact;
    }

    public function updateCompanies($id, $newProperties) {
        $this->initialise();
        $contactInput = new \HubSpot\Client\Crm\Companies\Model\SimplePublicObjectInput();
        $contactInput->setProperties($newProperties);

        $contact = $this->factory->crm()->companies()->basicApi()->update($id, $contactInput);
        return $contact;
    }

    public function createCompanyContactAssociate($companyId, $contactId) {
        $this->initialise();
        $associationSpec2 = [
            "associationCategory" => "HUBSPOT_DEFINED",
            "associationTypeId" => 279
        ];
        $dealAssociations = $this->factory->crm()->contacts()->associationsApi()->create($contactId, 'COMPANIES', $companyId, [$associationSpec2]);
        return $dealAssociations;
    }

    public function initialiseSchedulingProperties() {
        $this->initialise();

        try {
            // Create new contact group of properties
            $PropertyGroupCreate = new \HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate(['name' => "myob", 'label' => "MYOB"]);
            $apiResponse = $this->factory->crm()->properties()->groupsApi()->create("company", $PropertyGroupCreate);
        } catch (\HubSpot\Client\Crm\Properties\ApiException $e) {
            // If the group already exists, ignore the error
            if ($e->getCode() != 409) {
                throw $e;
            }
        }

        //Ensure all required contact properties are created
        $companyProperties = new \HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate(['inputs' => 
            [
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Account Number",
                    "name"=>"account_number",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Email",
                    "name"=>"email",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Alpha Code",
                    "name"=>"alphacode",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Account Group",
                    "name"=>"account_group",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Account Group 2",
                    "name"=>"account_group2",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Sales Person Number",
                    "name"=>"sales_no",
                    "type"=>"number",
                    "fieldType"=>"number",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Currency Id",
                    "name"=>"currency_id",
                    "type"=>"number",
                    "fieldType"=>"number",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 1",
                    "name"=>"delivery_address1",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 2",
                    "name"=>"delivery_address2",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 3",
                    "name"=>"delivery_address3",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 4",
                    "name"=>"delivery_address4",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 5",
                    "name"=>"delivery_address5",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 6",
                    "name"=>"delivery_address6",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Active",
                    "name"=>"active",
                    "type"=>"enumeration",
                    "fieldType"=>"select",
                    "options" => [
                        [
                            "label" => "Yes",
                            "value" => "Yes",
                            "hidden" => false,
                        ],
                        [
                            "label" => "No",
                            "value" => "No",
                            "hidden" => false,
                        ],
                    ]
                ],
            ]
        ]);
        $this->factory->crm()->properties()->batchApi()->create("company", $companyProperties);

        try {
            // Create new contact group of properties
            $PropertyGroupCreate = new \HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate(['name' => "myob", 'label' => "MYOB"]);
            $apiResponse = $this->factory->crm()->properties()->groupsApi()->create("contact", $PropertyGroupCreate);
        } catch (\HubSpot\Client\Crm\Properties\ApiException $e) {
            // If the group already exists, ignore the error
            if ($e->getCode() != 409) {
                throw $e;
            }
        }

        //Ensure all required contact properties are created
        $contactProperties = new \HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate(['inputs' => 
            [
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Active",
                    "name"=>"active",
                    "type"=>"enumeration",
                    "fieldType"=>"select",
                    "options" => [
                        [
                            "label" => "Yes",
                            "value" => "Yes",
                            "hidden" => false,
                        ],
                        [
                            "label" => "No",
                            "value" => "No",
                            "hidden" => false,
                        ],
                    ]
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 1",
                    "name"=>"delivery_address1",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 2",
                    "name"=>"delivery_address2",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 3",
                    "name"=>"delivery_address3",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 4",
                    "name"=>"delivery_address4",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 5",
                    "name"=>"delivery_address5",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 6",
                    "name"=>"delivery_address6",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
            ]
        ]);
        $this->factory->crm()->properties()->batchApi()->create("contact", $contactProperties);

        try {
            // Create new contact group of properties
            $PropertyGroupCreate = new \HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate(['name' => "myob", 'label' => "MYOB"]);
            $apiResponse = $this->factory->crm()->properties()->groupsApi()->create("deal", $PropertyGroupCreate);
        } catch (\HubSpot\Client\Crm\Properties\ApiException $e) {
            // If the group already exists, ignore the error
            if ($e->getCode() != 409) {
                throw $e;
            }
        }

        //Ensure all required contact properties are created
        $dealProperties = new \HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate(['inputs' => 
            [
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Order Number",
                    "name"=>"order_number",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Due Date",
                    "name"=>"due_date",
                    "type"=>"date",
                    "fieldType"=>"date",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Order Date",
                    "name"=>"order_date",
                    "type"=>"date",
                    "fieldType"=>"date",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Account",
                    "name"=>"account",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Sales Person",
                    "name"=>"sales_person",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Customer Order Number",
                    "name"=>"customer_order_number",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Default Location",
                    "name"=>"default_location",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 1",
                    "name"=>"delivery_address1",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 2",
                    "name"=>"delivery_address2",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 3",
                    "name"=>"delivery_address3",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 4",
                    "name"=>"delivery_address4",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 5",
                    "name"=>"delivery_address5",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Delivery Address 6",
                    "name"=>"delivery_address6",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
            ]
        ]);
        $this->factory->crm()->properties()->batchApi()->create("deal", $dealProperties);

        try {
            // Create new line items group of properties
            $PropertyGroupCreate = new \HubSpot\Client\Crm\Properties\Model\PropertyGroupCreate(['name' => "myob", 'label' => "MYOB"]);
            $apiResponse = $this->factory->crm()->properties()->groupsApi()->create("line_items", $PropertyGroupCreate);
        } catch (\HubSpot\Client\Crm\Properties\ApiException $e) {
            // If the group already exists, ignore the error
            if ($e->getCode() != 409) {
                throw $e;
            }
        }

        //Ensure all required line items properties are created
        $lineItemsProperties = new \HubSpot\Client\Crm\Properties\Model\BatchInputPropertyCreate(['inputs' => 
            [
                [
                    "groupName"=>"myob",
                    "hidden"=>false,
                    "label"=>"Stock Code",
                    "name"=>"myob_stock_code",
                    "type"=>"string",
                    "fieldType"=>"text",
                ],
            ]
        ]);

        $apiResponse = $this->factory->crm()->properties()->batchApi()->create("line_items", $lineItemsProperties);

    }

    public function getDealLineItemAssociations($dealId) {
        $this->initialise();

        $dealAssociations = $this->factory->crm()->deals()->associationsApi()->getAll($dealId, self::LINEITEMS);
        return $dealAssociations;
    }

    public function getBatchLineItems($ids) {
        $this->initialise();

        $lineItemIds = array_map(function($id) {
            return new \HubSpot\Client\Crm\LineItems\Model\SimplePublicObjectId(["id"=>$id]);
        }, $ids);

        $BatchReadInputSimplePublicObjectId = new \HubSpot\Client\Crm\LineItems\Model\BatchReadInputSimplePublicObjectId(['properties' => [env('HUBSPOT_LINE_ITEM_PROPERTIES')], 'inputs' => $lineItemIds]);

        $lineItems = $this->factory->crm()->lineitems()->batchApi()->read($BatchReadInputSimplePublicObjectId);
        return $lineItems;
    }

    public function createLineItem($properties, $productId) {
        $this->initialise();
        $lineItemInput = new \HubSpot\Client\Crm\LineItems\Model\SimplePublicObjectInput();
        $lineItemInput->setProperties($this->formatLineItems($properties, $productId));

        $lineItem = $this->factory->crm()->lineItems()->basicApi()->create($lineItemInput);
        return $lineItem->getProperties();
    }

    public function createProduct($properties) {
        $this->initialise();
        $lineItemInput = new \HubSpot\Client\Crm\Products\Model\SimplePublicObjectInput();
        $lineItemInput->setProperties($this->formatProducts($properties));

        $lineItem = $this->factory->crm()->products()->basicApi()->create($lineItemInput);
        return $lineItem->getProperties();
    }

    public function createDealLineItemAssociate($dealId, $lineItemId) {
        $this->initialise();
        $associationSpec2 = [
            "associationCategory" => "HUBSPOT_DEFINED",
            "associationTypeId" => 20
        ];
        $dealAssociations = $this->factory->crm()->lineItems()->associationsApi()->create($lineItemId, 'DEAL', $dealId, [$associationSpec2]);
        return $dealAssociations;
    }

    private function formatLineItems($properties, $productId) {
        return [
            'name' => 'L-'.$properties->id,
            'quantity' => $properties->totalinstock, /** Need to update into quantity */
            'price' => $properties->averagecost, /** Need to update into price */
            'myob_stock_code' => $properties->id,
            'hs_product_id' => $productId
        ];
    }

    private function formatProducts($properties) {
        return [
            'name' => 'P-'.$properties->id,
            'description' => $properties->description, /** Need to update into quantity */
            'price' => $properties->averagecost, /** Need to update into price */
        ];
    }
}
