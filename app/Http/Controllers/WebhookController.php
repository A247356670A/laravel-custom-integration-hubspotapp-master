<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Hubspot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{

    const CONTACT_CREATION = 'contact.creation';
    const CONTACT_PROPERTY_CHANGE = 'contact.propertyChange';
    const CONTACT_ASSOCIATION_CHANGE = 'contact.associationChange';
    const CONTACT_DELETION = 'contact.deletion';
    const DEAL_CREATION = 'deal.creation';
    const DEAL_PROPERTY_CHANGE = 'deal.propertyChange';
    const DEAL_DELETION = 'deal.deletion';
    const COMPANY_CREATION = 'company.creation';
    const COMPANY_PROPERTY_CHANGE = 'company.propertyChange';
    const COMPANY_DELETION = 'company.deletion';

    public function handleHubspotWebhook(Request $request)
    {
        Log::info("WebhookController@handleHubspotWebhook - begin!", ["req" => ['ip' => $request->ip(), 'user' => 'hubspot_webhooks']]);
        try {
            $events = json_decode($request->getContent());
            foreach ($events as $event) {
                $integration = Hubspot::where(['hubspot_account_id' => $event->portalId, 'hubspot_state' => 'CONNECTED'])->first();

                if (!$integration) {
                    Log::warning("WebhookController@handleHubspotWebhook - HubSpot integration is not connected for " . $event->portalId, ["event" => $event, "req" => ['ip' => $request->ip(), 'user' => 'hubspot_webhooks']]);
                    continue;
                }
                $changeSource = $event->changeSource;
                if ($changeSource !== 'INTEGRATION') {
                    switch ($event->subscriptionType) {
                        case self::CONTACT_CREATION:
                            Log::info("WebhookController.SwitchCase@CONTACT_CREATION", ["event" => $event, "integration" => $integration]);
                            //Get contact details form HB using object-id from webhook event
                            $contact = $integration->getContact($event->objectId)->getProperties();
                            Log::info('contact', ['contact' => $contact]);

                            //Save contact to database
                            Contact::create([
                                'hs_contact_id' => $contact['hs_object_id'],
                                'contact_name' => $contact['firstname'] . ' ' . $contact['lastname'],
                                'contact_email' => $contact['email'],
                                //include other columns as well.
                            ]);
                            break;
                        case self::CONTACT_DELETION:
                            //Delete from Database
                            Log::info("WebhookController.SwitchCase@CONTACT_DELETION", ["event" => $event, "integration" => $integration]);
                            $hs_contact_id = $event->objectId;
                            $contact = Contact::where('hs_contact_id', $hs_contact_id)->first();

                            if ($contact) {
                                $contact->delete();
                                Log::info('Contact deleted', ['hs_contact_id' => $hs_contact_id]);
                            } else {
                                Log::warning('Contact not found for deletion', ['hs_contact_id' => $hs_contact_id]);
                            }
                            break;
                        case self::CONTACT_PROPERTY_CHANGE:
                            //Update contact in database
                            Log::info("WebhookController.SwitchCase@CONTACT_PROPERTY_CHANGE", ["event" => $event, "integration" => $integration]);
                            if ($event->propertyName === 'email' || $event->propertyName === 'firstname' || $event->propertyName === 'lastname') {
                                //     $contact = Contact::where('hs_contact_id', $event->objectId)->first();
                                //     if ($contact) {
                                //         log::info("event propertyName: ",["event propertyName" => $event->propertyName]);
                                //         return $event->propertyName;
                                //     }



                                // }
                                $hs_contact_id = $event->objectId;
                                $propertyName = $event->propertyName;
                                $propertyValue = $event->propertyValue;

                                // Update contact in database
                                $contact = Contact::where('hs_contact_id', $hs_contact_id)->first();

                                if ($contact) {
                                    $current_name = $contact->contact_name;
                                    // Log::info('Name', [
                                    //     'current_name' => $current_name,
                                    //     'Split' => explode(' ', $current_name, 2),
                                    //     // 'Split[1]' => explode(' ', $current_name, 2)[1],
                                    // ]);
                                    $current_firstname = explode(' ', $current_name, 2)[0];
                                    $current_lastname = explode(' ', $current_name,2)[1];
                                    if ($propertyName === 'email') {
                                        $contact->contact_email = $propertyValue;
                                    } elseif ($propertyName === 'firstname') {
                                        Log::info('update firstName', [
                                            'Orign_lastname' => $contact->lastname,
                                            'Orign_firstname' => $contact->firstname,
                                            'propertyName' => $propertyName, 'propertyValue' => $propertyValue
                                        ]);

                                        $contact->contact_name = trim($propertyValue . ' ' . $current_lastname);
                                    } elseif ($propertyName === 'lastname') {
                                        $contact->contact_name = trim($current_firstname . ' ' . $propertyValue);
                                    }
                                    // Include other properties as needed
                                    $contact->save();
                                    Log::info('Contact updated', ['hs_contact_id' => $hs_contact_id, 'propertyName' => $propertyName, 'propertyValue' => $propertyValue]);
                                } else {
                                    Log::warning('Contact not found for update', ['hs_contact_id' => $hs_contact_id]);
                                }
                            }
                            break;

                        case self::COMPANY_CREATION:
                            Log::info("WebhookController.SwitchCase@COMPANY_CREATION", ["event" => $event, "integration" => $integration]);
                            //Get contact details form HB using object-id from webhook event
                            $company = $integration->getCompanies($event->objectId)->getProperties();
                            Log::info('company', ['company' => $company]);

                            //Save contact to database
                            Company::create([
                                'hs_company_id' => $company['hs_object_id'],
                                'company_name' => $company['name'],
                            ]);
                            break;  
                        case self::COMPANY_PROPERTY_CHANGE:
                            break;
                        case self::COMPANY_DELETION:
                            break;

                        default:
                            break;
                    }
                } else  if ($changeSource !== 'CRM_UI_BULK_ACTION') {
                    Log::info("WebhookController@Switch_Case.CRM_UI_BULK_ACTION:: webhooks from CRM_UI_BULK_ACTION - no need to process", ["eventType" => $event->changeSource, "event" => $integration]);
                    return;
                } else {
                    Log::info("WebhookController@Switch_Case.TNTEGRATION:: webhooks from INTEGRATION - no need to process", ["eventType" => $event->changeSource, "event" => $integration]);
                    return;
                }
            }
        } catch (\Exception $e) {
            Log::error("WebhookController@handleHubspotWebhook - Something has gone wrong: " . $e->getMessage(), [
                "error" => ['message' => $e->getMessage(), 'code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine()],
                "req" => ['ip' => $request->ip(), 'user' => 'hubspot_webhooks'],
            ]);
            return response()->json([], 400);
        }
    }
}
