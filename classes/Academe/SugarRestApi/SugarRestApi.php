<?php

/**
 * @todo some properties need to persist from one page to another, to avoid
 * having to log in over and over. The sessionId is the main thing. [done, but needs review]
 * @todo Automatically transform name/value pairs to associative arrays in get-methods.
 * @todo A general parameter validation framework to check data before it is passed on.
 */

namespace Academe\SugarRestApi;

class SugarRestApi
{
    // The rest controller.
    // We will be using resty/resty for now, but I have no idea how generic that is,
    // and so how easily it can be swapped out for something else.
    // TODO: see if https://github.com/mnapoli/PHP-DI is of any use here.
    public $rest = NULL;

    // The URL of the REST entry point.
    public $entryPoint = '{protocol}://{site_domain}/service/v4/rest.php';

    // The username and password used to log in.
    // To be persisted in the session.
    public $authUsername = '';
    public $authPassword = '';
    public $authVersion = '1';

    // The current session ID and the user ID this corresponds to.
    // To be persisted in the session.
    public $sessionId;
    public $userId;

    // The name of this application making the requests.
    public $applicationName = 'Academe_SugarRestApi';

    // The input type (format of data sent to the API) and
    // response type (format of data coming back).
    // For this to be useful, the encoding and decoding must all be done
    // through overridable methods.
    public $apiInputType = 'JSON';
    public $apiResponseType = 'JSON';

    // Default headers and options for the REST controller.
    public $restHeaders = NULL;
    public $restOptions = NULL;

    // Details of any error message.
    public $error = array(
        'name' => '',
        'number' => '',
        'description' => '',
    );

    // Automatically log out when we close.
    // If true, an attempt is made to log out before the object is destroyed.
    // If false, the remote API session is kept open and can be used on the next page request.
    public $autologout = false;


    // Get data that should be persisted to
    // avoid having to log in again on each page requst.
    public function getJsonData()
    {
        return json_encode(array(
            'authUsername' => $this->authUsername,
            // Don't store the password, as it could end up being scattered over
            // session tables and other storage.
            //$this->authPassword,
            'authVersion' => $this->authVersion,
            'sessionId' => $this->sessionId,
            'userId' => $this->userId,
            // Save the name of the REST class.
            'restClass' => get_class($this->rest),
        ));
    }

    // When conveting to a string for storage, return the object as a json structure.
    public function __toString()
    {
        return $this->getJsonData();
    }

    // Set the REST entry point URL
    public function setEntryPoint($url)
    {
        $this->entryPoint = $url;
    }

    // Allow persistent data to be restored from the session.
    public function __construct($jsonData = '')
    {
        if (!empty($jsonData)) {
            // TODO: is there a better way of masking decoding errors?
            $data = @json_decode($jsonData, true);
            if (is_array($data)) {
                foreach($data as $name => $value) {
                    // Restore the REST class, if it can be instantiated.
                    if ($name == 'restClass' && class_exists($value)) {
                        $this->rest = new $value();
                    } elseif (property_exists($this, $name)) {
                        $this->$name = $value;
                    }
                }
            }
        }
    }

    // Autologout, if required.
    public function __destruct()
    {
        if ($this->autologout) $this->logout;
    }

    // Setter/injecter for the rest controller.
    // Perhaps we need to be able to default to resty/resty here, assuming it is set as
    // a dependancy.
    public function setRest($restController)
    {
        $this->rest = $restController;
    }

    // Set the username and password authentication credentials.
    // They can be passed in here or wait until logging in.
    // If the details are not the same as already stored, then make
    // sure the sesion is cleared.
    public function setAuth($username = NULL, $password = NULL, $version = NULL)
    {
        $detailsChanged = false;

        if (isset($username)) {
            if ($this->authUsername != $username) $detailsChanged = true;
            $this->authUsername = $username;
        }
        if (isset($password)) {
            //if ($this->authPassword = $password) $detailsChanged = true;
            $this->authPassword = $password;
        }
        if (isset($version)) $this->authVersion = $version;

        // If the credentials hjave changed, then we need to log in as a different user,
        // and so the current session should be cleared.
        if ($detailsChanged) {
            $this->clearSession();
        }
    }

    // Validate the current session.
    // If the session is not valid, then clear the session properties.
    // Returns true if the user is logged on, false otherwise.
    public function validateSession()
    {
        // If we have no session ID, then we are certain not to be logged on.
        if (!isset($this->sessionId)) return false;

        // We have a session ID - test it.
        $userId = $this->getUserId();
        if ($userId = $this->userId) {
            return true;
        } else {
            // The user ID for the session does not match that stored,
            // or the user ID could not be retrived. The session is bad, so
            // clear it.
            $this->clearSession();

            return false;
        }
    }

    // Clear details of the current session, so the next call results in (or requires) a login first.
    public function clearSession($destroyCredentials = false)
    {
        $this->sessionId = null;
        $this->userId = null;

        if ($destroyCredentials) {
            $this-setAuth('', '');
        }
    }

    // Make the REST POST call.
    // We aim to return the payload, decoded into an array.
    public function apiPost($method, $data = array())
    {
        // Wrap the data in a standard structure.
        $postData = array(
            'method' => $method,
            'input_type' => $this->apiInputType,
            'response_type' => $this->apiResponseType,
            'rest_data' => json_encode($data),
        );

        $payload = $this->callRest($postData, 'POST');

        // TODO: Here we check that the call succeeded, and raise exceptions as needed.
        return $this->extractPayload($payload);
    }

    // Perform the REST call.
    // Override this method for other REST libraries.
    // TODO: GET, PUT etc.
    public function callRest($data, $method = 'POST')
    {
        switch (strtoupper($method)) {
            case 'POST':
                return $this->rest->post($this->entryPoint, $data, $this->restHeaders, $this->restOptions);
                break;
        }
    }

    // Extract the return data from an API call.
    // This function is given whatever the result is of the REST call.
    // Override this method if a different REST library is used and the payload
    // is extracted in a different way.
    // Also detect errors in the return value.
    public function extractPayload($returnData)
    {
        // TODO: check that there is valid data first.
        // States could be: network error, not authenticated, ...?
        
        // The return data should be JSON encoded and in the body element.
        if (isset($returnData['body'])) {
            // TODO: if this is not decodable JSON?
            // CHECKME: do we want to decode to arraus or objects?
            // CHECKME: why isn't this automatically decoded already by Resty? It should be.
            // CHECKME: why does Resty always return "error"=>true? What does that mean?
            $decodeBody = json_decode($returnData['body'], true);

            // Errors are returned as a triplet of properties: name, number and description.
            // If we find these three, then assue an error has occurred and the method call was
            // not successful.
            if (
                count($decodeBody) == 3 
                && isset($decodeBody['name']) 
                && isset($decodeBody['number']) 
                && isset($decodeBody['description'])
            ) {
                $this->error = $decodeBody;
            } else {
                $this->error['number'] = '';
            }

            return $decodeBody;
        } else {
            // No body set.
            // TODO: what to do?
        }

        return null;
    }

    // Indicate whether the last method call was successful or not.
    // Returns true if successful.
    public function success()
    {
        return ($this->error['number'] == '');
    }

    // Returns the error details, an array of name, number and description.
    public function error()
    {
        if ($this->success) {
            return array(
                'name' => '',
                'number' => '',
                'description' => '',
            );
        } else {
            return $this->error;
        }
    }

    // Main API methods.

    // Log into the CRM.
    // TODO: if the sessionId is already set, then check we are logged in as the
    // correct user, so we don't need to log in again.
    // Returns true if successful.
    public function login($username = NULL, $password = NULL)
    {
        // Save the username and password so we know if we are logging in as a different user.
        $this->setAuth($username, $password);

        // Check if we have a valid session. If we do then no further login is needed.
        if ($this->validateSession()) return true;

        $parameters = array(
            'user_auth' => array(
                'user_name' => $this->authUsername,
                'password' => md5($this->authPassword),
                'version' => $this->authVersion,
            ),
            'application_name' => $this->applicationName,
            'name_value_list' => array(),
        ); 

        // Attempt to log in.
        $result = $this->apiPost('login', $parameters);

        if ($this->success()) {
            // Extract the session ID and user ID.
            $this->sessionId = $result['id'];
            $this->userId = $result['name_value_list']['user_id']['value'];
            return true;
        } else {
            return false;
        }
    }

    // Log out of the API.
    // TODO: if we have a session going, then log out of the remote API too, before
    // we discard all the session details locally.
    // Do not discard the login credentials (username and password) at this point.
    public function logout()
    {
        // If the session is open to the CRM, then log out of that.
        if (isset($this->sessionId)) {
            $parameters = array(
                'session' => $this->sessionId,
            );
            $this->apiPost('logout', $parameters);
        }

        $this->clearSession();
    }

    // Get a list of fields for a module.
    public function getModuleFields($moduleName, $fieldList = array())
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'fields' => $fieldList,
        );

        return $this->apiPost('get_module_fields', $parameters);
    }

    // Get the current user ID, given the session.
    public function getUserId()
    {
        $parameters = array(
            'session' => $this->sessionId,
        );

        return $this->apiPost('get_user_id', $parameters);
    }

    // Retrieve a list of SugarBean based on provided IDs.
    // This API will not wotk with report module.
    // Each SugarBean will inckude an array of name/value pairs in the array 'name_value_list', but
    // not as an associative array. It may be helpful to convert this to an associative
    // array for each bean returned. The key/value pair structure is great for other languages that
    // don't have associatived arrays, such as C#, and converts easily into dictionary structures.
    // But that is not so easy to handle in PHP.
    // A supplied ID that does not mnatch a Sugarbean that the user can access, will return with
    // a "warning" name/value pair explaining why.
    public function getEntries($moduleName, $ids = array(), $selectFields = array(), $linkNameFields = array())
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'ids' => $ids,
            'select_fields' => $selectFields,
            'link_name_to_fields_array' => $linkNameFields,
        );

        return $this->apiPost('get_entries', $parameters);
    }

    // Retrieve a list of beans.
    // This is the primary method for getting list of SugarBeans from Sugar.
    public function getEntryList($moduleName, $query = NULL, $order = NULL, $offset = 0, $fields = array(), $linkNameFields = array(), $limit = NULL, $deleted = false, $favourites = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'query' => $query,
            'order_by' => $order,
            'offset' => $offset,
            'select_fields' => $fields,
            'link_name_to_fields_array' => $linkNameFields,
            'max_results' => $limit,
            'deleted' => $deleted,
            'favorites' => $favourites,
        );

        return $this->apiPost('get_entry_list', $parameters);
    }

    // Retrieve the layout metadata for a given modules given a specific types and views.
    // Types include: default, wireless
    // Views include: edit, detail, list, subpanel
    public function getModuleLayout($moduleNames, $types = array('default'), $views = array('detail'), $aclCheck = true, $md5 = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'a_module_names' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
            'a_type' => (is_string($types) ? array($types) : $types),
            'a_view' => (is_string($views) ? array($views) : $views),
            'acl_check' => $aclCheck,
            'md5' => $md5
        );

        return $this->apiPost('get_module_layout', $parameters);
    }

    // Search modules.
    // At least one module must be supplied.
    // Supported modules are Accounts, Bug Tracker, Cases, Contacts, Leads, Opportunities, Project, ProjectTask, Quotes.
    public function searchByModule($searchString, $moduleNames, $offset = 0, $limit = NULL, $assignedUserId = NULL, $fields = array(), $unifiedSearchOnly = true, $favourites = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'search_string' => $searchString,
            'modules' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
            'offset' => $offset,
            'nax_results' => $limit,
            'assigned_user_id' => $assignedUserId,
            'select_fields' => $fields,
            'unified_search_only' => $unifiedSearchOnly,
            'favorites' => $favourites,
        );

        return $this->apiPost('search_by_module', $parameters);
    }

    // Get OAuth request token
    public function oauthRequestToken()
    {
        return $this->apiPost('oauth_request_token');
    }

    // Get OAuth access token
    public function oauthAccessToken()
    {
        $parameters = array(
            'session' => $this->sessionId,
        );

        return $this->apiPost('oauth_access', $parameters);
    }

    // Get next job from the queue
    public function jobQueueNext($clientId)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'clientid ' => $clientId,
        );

        return $this->apiPost('job_queue_next', $parameters);
    }

    // Run cleanup and schedule.
    public function jobQueueCycle($clientId)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'clientid ' => $clientId,
        );

        return $this->apiPost('job_queue_cycle', $parameters);
    }

    // Run job from queue.
    public function jobQueueRun($jobId, $clientId)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'jobid ' => $jobId,
            'clientid ' => $clientId,
        );

        return $this->apiPost('job_queue_run', $parameters);
    }

    // Retrieve a single SugarBean based on ID.
    public function getEntry($moduleName, $id, $fields = array(), $linkNameFields = array(), $trackView = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'id' => $id,
            'select_fields' => $fields,
            'link_name_to_fields_array' => $linkNameFields,
            'track_view' => $trackView,
        );

        return $this->apiPost('get_entry', $parameters);
    }

    // Retrieve the md5 hash of the vardef entries for a particular module.
    public function getModuleFieldsMd5($moduleName)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
        );

        return $this->apiPost('get_module_fields_md5', $parameters);
    }

    // Retrieve the md5 hash of a layout metadata for a given module given a specific type and view.
    // Types include: default, wireless
    // Views include: edit, detail, list, subpanel
    public function getModuleLayoutMd5($moduleNames, $types = array('default'), $views = array('detail'), $aclCheck = true)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
            'type' => (is_string($types) ? array($types) : $types),
            'view' => (is_string($views) ? array($views) : $views),
            'acl_check' => $aclCheck,
        );

        return $this->apiPost('get_module_layout_md5', $parameters);
    }

    // Update or create a single SugarBean.
    public function setEntry($moduleName, $data, $trackView = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'name_value_list' => $data,
            'track_view' => $trackView,
        );

        return $this->apiPost('set_entry', $parameters);
    }

    // Retrieve the list of available modules on the system available to the currently logged in user.
    // filter is all, default or mobile
    public function getAvailableModules($failter = 'all', $moduleNames = array())
    {
        $parameters = array(
            'session' => $this->sessionId,
            'filter' => $filter,
            'modules' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
        );

        return $this->apiPost('get_available_modules', $parameters);
    }

    // ???
    // @todo check if the MD5 parameter should be upper case.
    public function getLanguageDefinition($moduleNames = array(), $md5 = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'modules' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
            'MD5' => $md5,
        );

        return $this->apiPost('get_language_definition', $parameters);
    }

    // Get server information.
    public function getServerInfo()
    {
        return $this->apiPost('get_server_info');
    }

    // Retrieve a list of recently viewed records by a module.
    // Documentation on this one is not clear. Multiple modules can be supplied, but
    // the returned results will vary depending on the order of the modules in the array.
    public function getLastViewed($moduleNames = array())
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_names' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
        );

        return $this->apiPost('get_last_viewed', $parameters);
    }

    // Retrieve a list of upcoming activities including Calls, Meetings,Tasks and Opportunities.
    public function getUpcomingActivities()
    {
        $parameters = array(
            'session' => $this->sessionId,
        );

        return $this->apiPost('get_upcoming_activities', $parameters);
    }

    // Retrieve a collection of beans that are related to the specified bean and optionally return
    // relationship data for those related beans.
    public function getRelationships($moduleName, $beanId, $linkFieldName, $relatedModuleQuery, $relatedFields, $relatedModuleLinkNameFields, $deleted = false, $orderBy = '')
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'module_id' => $beanId,
            'link_field_name' => $linkFieldName,
            'related_module_query' => $relatedModuleQuery,
            'related_fields' => $relatedFields,
            'related_module_link_name_to_fields_array' => $relatedModuleLinkNameFields,
            'deleted' => $deleted,
            'order_by' => $orderBy,
        );

        return $this->apiPost('get_relationships', $parameters);
    }

    // Set a single relationship between two beans. The items are related by module name and id.
    public function setRelationship($moduleName, $beanId, $linkFieldName, $relatedIds, $data, $delete = 0)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'module_id' => $beanId,
            'link_field_name' => $linkFieldName,
            'related_ids' => $relatedIds,
            'name_value_list' => $data,
            'delete' => $delete,
        );

        return $this->apiPost('set_relationship', $parameters);
    }

    // Set a single relationship between two beans. The items are related by module name and id.
    // @todo Description is wrong.
    public function setRelationships($moduleNames, $beanIds, $linkFieldNames, $relatedIds, $data, $delete = array())
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_names' => (is_string($moduleNames) ? array($moduleNames) : $moduleNames),
            'module_ids' => (is_string($beanIds) ? array($beanIds) : $beanIds),
            'link_field_names' => $linkFieldNames,
            'related_ids' => $relatedIds,
            'name_value_lists' => $data,
            'delete_array' => $delete,
        );

        return $this->apiPost('set_relationships', $parameters);
    }

    // Update or create a list of SugarBeans
    public function updateEntries($moduleName, $data)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'name_value_lists' => $data,
        );

        return $this->apiPost('update_entries', $parameters);
    }

    // Perform a seamless login. This is used internally during the sync process.
    public function seamlessLogin()
    {
        return $this->apiPost('seamless_login');
    }

    // Add or replace the attachment on a Note.
    // Optionally you can set the relationship of this note to Accounts/Contacts and so on by 
    // setting related_module_id, related_module_name.
    public function setNoteAttachment($id_or_note, $filename = '', $fileContent = '', $moduleId = '', $moduleName = '')
    {
        if (is_array($id_or_note)) {
            $note = $id_or_note;
        } else {
            $note = array(
                'id' => $id,
                'filename' => $filename,
                'file' => $fileContent,
                'related_module_id' => $moduleId,
                'related_module_name' => $moduleName,
            );
        }

        $parameters = array(
            'session' => $this->sessionId,
            'note' => $note,
        );

        return $this->apiPost('set_note_attachment', $parameters);
    }

    // Retrieve an attachment from a note.
    public function getNoteAttachment($id)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'id' => $id,
        );

        return $this->apiPost('get_note_attachment', $parameters);
    }

    // Sets a new revision for this document.
    public function setDocumentRevision($id_or_revision, $documentName = '', $revision = '', $filename = '', $fileContent = '')
    {
        if (is_array($id_or_revision)) {
            $revision = $id_or_revision;
        } else {
            $revision = array(
                'id' => $id_or_revision,
                'document_name' => $documentName,
                'revision' => $revision,
                'filename' => $filename,
                'file' => $fileContent,
            );
        }

        $parameters = array(
            'session' => $this->sessionId,
            'document_revision' => $revision,
        );

        return $this->apiPost('set_document_revision', $parameters);
    }

    // This method is used as a result of the .htaccess lock down on the cache directory.
    // It will allow a properly authenticated user to download a document that they have
    // proper rights to download.
    public function getDocumentRevision($id)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'id' => $id,
        );

        return $this->apiPost('get_document_revision', $parameters);
    }

    // Once we have successfuly done a mail merge on a campaign, we need to notify
    // Sugar of the targets and the campaign_id for tracking purposes.
    public function setCampaignMerge($targets, $campaignId)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'targets' => $targets,
            'campaign_id' => $campaignId,
        );

        return $this->apiPost('set_campaign_merge', $parameters);
    }

    public function getEntriesCount($moduleName, $campaignId, $query, $deleted = false)
    {
        $parameters = array(
            'session' => $this->sessionId,
            'module_name' => $moduleName,
            'query' => $query,
            'deleted' => $deleted,
        );

        return $this->apiPost('get_entries_count', $parameters);
    }
}
