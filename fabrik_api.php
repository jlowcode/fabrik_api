<?php

//print_r($_REQUEST);die();
//echo '------------------';
//echo '<pre>'.json_decode($_REQUEST['authentication']).'</pre>';
//echo '-----------------';
//die();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');

defined('_JEXEC') or die();

require_once COM_FABRIK_FRONTEND . '/models/plugin-list.php';

class PlgFabrik_ListFabrik_api extends PlgFabrik_List {
    public $response;

    private $authentication;
    private $options;
    private $action;
    private $type;
    private $allowDelete;

    private $fM;
    private $lM;
    
    public function onApiCalled() {
        $this->response->error = false;
        $this->response->msg = '';
        
        if(!isset($_GET['api_key'])){
            $this->authentication = json_decode($_POST['authentication']);
            $this->options = json_decode($_POST['options']);
        }

        if ($this->setData()) {
            if ($this->authenticate()) {
                if ($this->type === 'site') {
                    $this->initiateSite();
                }
                else if ($this->type === 'administrator') {
                    $this->initiateAdministrator();
                }
            }
        }
        echo json_encode($this->response);
    }

    private function setData()  {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                $this->authentication = json_decode($_POST['authentication']);
                $this->options = json_decode($_POST['options']);
                $this->action = 'add';
                $this->type = $this->options->type;
                break;
            case 'PUT':
                parse_str(file_get_contents("php://input"),$requestData);
                $this->authentication = json_decode($requestData['authentication']);
                $this->options = json_decode($requestData['options']);
                $this->action = 'update';
                $this->type = $this->options->type;
                break;
            case 'DELETE':
                parse_str(file_get_contents("php://input"),$requestData);
                $this->authentication = json_decode($requestData['authentication']);
                $this->options = json_decode($requestData['options']);
                $this->action = 'delete';
                $this->type = $this->options->type;
                break;
            case 'GET':
                if(isset($_GET['api_key'])) {
                    $this->authentication->api_key = $_GET['api_key'];
                    $this->authentication->api_secret = $_GET['api_secret'];

                    //https://selecao2.cett.dev.br/index.php?option=com_fabrik&
                        //format=raw&task=plugin.pluginAjax&plugin=fabrik_api&method=apiCalled&g=list&
                        //api_key=83b8bc33f786b5f99186a82b2aaa0073&api_secret=c9914469e8aa414fa66e58cc96e068281bde4e56e7f4fd144cbe9973b3df89a0&
                        //options=list_id:5|data_type:list|type:site

                    //options='list_id:int|data_type:string|type:string|row_id:int|filters:element0#value0..element1#value1..element2#value2..element3#value3';
                    if(isset($_GET['options']))
                    {
                        $options = explode("|",$_GET['options']);
                        
                        foreach($options as $item)
                        {
                            $arrItem = explode(":",$item);
                            if($arrItem[0] != 'filters')
                            {
                                $this->options->{$arrItem[0]} = $arrItem[1];
                            }
                            else
                            {
                                //echo $arrItem[1].'<br/>';
                                $arrFilter = explode("..",$arrItem[1]);
                                foreach($arrFilter as $filter)
                                {
                                    //echo $filter;die();
                                    $arrProp = explode('=',$filter);
                                    //print_r($filter);echo '<br/>';
                                    $this->options->{$arrItem[0]}->{$arrProp[0]} = $arrProp[1];
                                }
                            }
                        }
                        
                    }
                    //print_r($this->options);die();
                }else{
                    // BEGIN - Modification to receive in the same way as PUT and DELETE //
                    parse_str(file_get_contents("php://input"), $requestData);
                    $this->authentication = json_decode($requestData['authentication']);
                    $this->options = json_decode($requestData['options']);
                    $this->action = 'get';
                    $this->type = $this->options->type;
                    // END - Modification to receive in the same way as PUT and DELETE //


                    // Original way that didn't work because it recognized as POST
                    /*$this->authentication = json_decode($_GET['authentication']);
                    $this->options = json_decode($_GET['options']);*/
                }

                $this->action = 'get';
                $this->type = $this->options->type;
                break;
        }

        if ((empty($this->authentication)) || (empty($this->options))) {
            $this->response->error = true;
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_NO_DATA');
            return false;
        }

        return true;
    }

    private function authenticate() {
        $key = $this->authentication->api_key;
        $secret = $this->authentication->api_secret;
        $access_token = base64_encode("$key:$secret");

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query
            ->select('id')
            ->from('#__fabrik_api_access')
            ->where("client_id = '{$key}'")
            ->where("client_secret = '{$secret}'")
            ->where("access_token = '{$access_token}'");
        $db->setQuery($query);
        $result = $db->loadResult();
        
        if (!$result) {
            $this->response->error = true;
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_NO_ACCESS');
            return false;
        }

        return true;
    }

    private function initiateSite() {
        $listId = $this->options->list_id;

        if ((!isset($listId)) || (empty($listId))) {
            $this->response->error = true;
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_NO_LIST_DEFINED');
            return;
        }

        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($listId);
        $this->lM = $listModel;
        $this->fM = $listModel->getFormModel();

        if (!$this->validateSite()) {
            return;
        }

        $this->defineActionSite();
    }

    private function initiateAdministrator() {
        if (!$this->validateAdministrator()) {
            return;
        }

        $this->defineActionAdministrator();
    }

    private function verifyIfRowExists($table, $id) {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id')->from($table)->where('id = ' . (int) $id);
        $db->setQuery($query);
        $result = $db->loadResult();

        if (!$result) {
            return false;
        }

        return true;
    }

    private function validateSite() {
        $action = $this->action;
        $options = $this->options;
        $table = $this->lM->getTable()->db_table_name;

        if ($action === 'add') {
            if ((!isset($options->row_data)) || (!is_array($options->row_data))) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_ADD_RECORD_NO_DATA_RECORD');
                return false;
            }
        }
        else if ($action === 'update') {
            if ((!isset($options->row_id)) || (empty($options->row_id))) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_UPDATE_RECORD_NO_ROW_ID');
                return false;
            }

            if (!$this->verifyIfRowExists($table, $options->row_id)) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_UPDATE_RECORD_DOESNT_EXIST');
                return false;
            }

            if ((!isset($options->row_data)) || (empty($options->row_data))) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_UPDATE_RECORD_NO_DATA_RECORD');
                return false;
            }
        }
        else if ($action === 'delete') {
            if ((!isset($options->row_id)) || (empty($options->row_id)) || (!is_array($options->row_id))) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_DELETE_RECORD_NO_ROW_ID');
                return false;
            }

            $toDelete = array();
            foreach($options->row_id as $id) {
                if ($this->verifyIfRowExists($table, $id)) {
                    $toDelete[] = $id;
                }
            }
            if (empty($toDelete)) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_DELETE_RECORD_DOESNT_EXIST');
                return false;
            }
        }
        else if ($action === 'get') {
            if ((!isset($options->data_type)) || (empty($options->data_type))) {
                $this->response->error = true;
                $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_GET_LIST_DATA_NO_DATA_TYPE');
                return false;
            }
        }

        return true;
    }

    private function validateAdministrator() {
        $options = $this->options;

        if (!$options->g) {
            $this->response->error = true;
            return false;
        }

        $validation = false;
        switch ($options->g) {
            case 'element':
                $validation = $this->validateElement();
                break;
        }

        return $validation;
    }

    private function validateElement() {
        $action = $this->action;
        $options = $this->options;

        if ($action === 'add') {
            if ((!isset($options->list_id)) || (!isset($options->group_id)) || (!isset($options->name)) || (!isset($options->label))) {
                $this->response->error = true;
                return false;
            }
        }
        else if (($action === 'update') || ($action === 'delete')) {
            if (!isset($options->element_id)) {
                $this->response->error = true;
                return false;
            }
        }

        return true;
    }

    private function defineActionSite() {
        $action = $this->action;

        switch ($action) {
            case 'add':
                $this->addRowsToList();
                break;
            case 'update':
                $this->updateRowOfList();
                break;
            case 'delete':
                $this->deleteRowsOfList();
                break;
            case 'get':
                $this->getListData();
                break;
        }
    }

    private function defineActionAdministrator() {
        $action = $this->action;
        $g = $this->options->g;

        switch ($g) {
            case 'element':
                switch ($action) {
                    case 'add':
                        $this->addElement();
                        break;
                    case 'update':
                        $this->updateElement();
                        break;
                    case 'delete':
                        $this->deleteElement();
                        break;
                }
                break;
        }
    }

    private function addRowsToList() {
        $options = $this->options;
        $formModel = $this->fM;
        $table = $formModel->getTableName();

        $rows_ids = array();
        $errors = false;
        foreach ($options->row_data as $record) {
            $data = (array) $record;
            $formModel->setRowId('0');
            $formModel->formData = $formModel->getData();
            $formModel->updateFormData("{$table}___id", $options->row_id, true);

            foreach ($data as $key => $item) {
                $formModel->updateFormData($key, $item, true);
            }

            if (!$formModel->process()) {
                $errors = true;
                break;
            }

            $rows_ids[] = $formModel->formData[$table . '___id'];
            $this->verifyProcess($table, $record, $rows_ids[0]);

            $formModel->unsetData();
        }

        if (!$errors) {
            $data_return = new stdClass();
            $data_return->row_id = $rows_ids;
            $this->response->data = $data_return;
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_ADD_RECORD_SUCCESS');
        }
        else {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_ADD_RECORD_ERROR');
        }
    }

    private function verifyProcess($table, $record, $row_id) {
        $db = JFactory::getDbo();
        $data = (array) $record;
        $columns = Array();
        $obj = new stdClass();

        foreach ($data as $key => $item) {
            $columns[] = explode("___", $key)[1];
        }

		$query = $db->getQuery(true)
			->select('`' . implode('`,`', $columns) . '`')
			->from($db->quoteName($table))
			->where('id = ' . $db->quote($row_id));
		$db->setQuery($query);
		$result = $db->loadObjectList();

        foreach((array) $result[0] as $keyTable => $value) {
            if(!$value) {
                $keyObject = $table . '___' . $keyTable;
                $obj->$keyTable = $record->$keyObject;
            }
        }

        $obj->id = $row_id;
        $db->updateObject($table, $obj, 'id');

        return true;
    }

    private function updateRowOfList() {
        $options = $this->options;
        $formModel = $this->fM;
        $table = $formModel->getTableName();

        $data = (array) $options->row_data;
        $formModel->setRowId($options->row_id);

        $formData = (array) $formModel->getData();
        
        $fileuploads = array();
        $db_joins_single = array();
        $db_joins_multi = array();
        $groups = $formModel->getGroupsHiarachy();

        foreach ($groups as $groupModel)
        {
            $elementModels = $groupModel->getPublishedElements();
            foreach ($elementModels as $elementModel)
            {
                if ($elementModel->element->plugin === 'fileupload') {
                    $fileuploads[] = (string) $elementModel->getFullName();
                }
                else if ($elementModel->element->plugin === 'databasejoin') {
                    $params = json_decode($elementModel->element->params);
                    if (($params->database_join_display_type === 'checkbox') || ($params->database_join_display_type === 'multilist')) {
                        $db_joins_multi[] = (string) $elementModel->getFullName();
                    }
                    else {
                        $db_joins_single[] = (string) $elementModel->getFullName();
                    }
                }
                else if ($elementModel->element->plugin === 'tags') {
                    $db_joins_multi[] = (string) $elementModel->getFullName();
                }
            }
        }

        $formModel->formData = $formModel->getData();
        $formModel->updateFormData("{$table}___id", $options->row_id, true);
        foreach ($fileuploads as $key => $item) {
            $formModel->updateFormData($item, $formData[$item], true);
        }
        foreach ($db_joins_multi as $key => $item) {
            $formModel->updateFormData($item, $formData[$item . '_id'], true);
        }
        foreach ($db_joins_single as $key => $item) {
            $formModel->updateFormData($item, $formData[$item . '_raw'], true);
        }
        foreach ($data as $key => $item) {
            $formModel->updateFormData($key, $item, true);
        }

        if ($formModel->process()) {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_UPDATE_RECORD_SUCCESS');
        }
        else {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_UPDATE_RECORD_ERROR');
        }
    }

    private function changeAllowDelete($listId, $value = 0) {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('params')->from('#__fabrik_lists')->where('id = ' . (int) $listId);
        $db->setQuery($query);
        $result = $db->loadResult();
        $result = json_decode($result);

        if ($value === 0) {
            $this->allowDelete->allow_delete = $result->allow_delete;
            $this->allowDelete->allow_delete2 = $result->allow_delete2;

            $result->allow_delete = "1";
            $result->allow_delete2 = "";
        }
        else if ($value === 1) {
            $result->allow_delete = $this->allowDelete->allow_delete;
            $result->allow_delete2 = $this->allowDelete->allow_delete2;
        }

        $new = new stdClass();
        $new->id = $listId;
        $new->params = json_encode($result);

        $db->updateObject('#__fabrik_lists', $new, 'id');
    }

    private function deleteRowsOfList() {
        $options = $this->options;
        $listModel = $this->lM;

        $this->changeAllowDelete($listModel->getId());

        if ($listModel->deleteRows($options->row_id)) {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_DELETE_RECORD_SUCCESS');
        }
        else {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_DELETE_RECORD_ERROR');
        }

        $this->changeAllowDelete($listModel->getId(), 1);
    }

    private function getListData()
    {
        $options = $this->options;
        $options->filters = (array) $options->filters;

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $table = $this->lM->getTable()->db_table_name;

        $columns = '*';
        if(isset($options->cols) && trim($options->cols) != '') {
            $columns = $options->cols;
        }

        $query->select($columns)->from($table);
        if(isset($options->filters) && sizeof($options->filters) > 0) {
            foreach ($options->filters as $key=>$item) {
                $query->where($key . ' = "' . $item . '"');
            }
        }

        $offset = 0;
        $limit = 30;

        if(isset($options->o) && trim($options->o) != '') {
            $offset = $options->o;
        }

        if(isset($options->l) && trim($options->l) != '') {
            $limit = $options->l;
        }

        $db->setQuery($query,$offset,$limit);
        $data = $db->loadAssocList();

        $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_GET_LIST_DATA_SUCCESS');
        $this->response->data = $data;
    }

    private function getListDataOld() {
        //Resolvi inativar esta funcão, porque foi criado um novo padrão para atender nossas necessidades.
        //Porem deixei aqui caso seja necessario aproveitar este código no futuro por outros dev's.
        $options = $this->options;
        $options->filters = (array) $options->filters;

        //$url = COM_FABRIK_LIVESITE . "index.php?option=com_fabrik&view=list&listid={$options->list_id}&format=json&limit{$options->list_id}=-1";
        $url = COM_FABRIK_LIVESITE . "index.php?option=com_fabrik&view=list&listid={$options->list_id}&format=json";
        if($options->limit)
            $url .= "&limit{$options->list_id}=".$options->limit;
        else
            $url .= "&limit{$options->list_id}=10";

        if (!empty($options->filters)) {
            $url .= "&resetfilters=1";
            foreach($options->filters as $filter => $value) {
                $url .= "&{$filter}={$value}";
            }
        }

        //echo $url; die();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $output = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($output)[0];

        if (empty($data)) {
            $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_GET_LIST_DATA_ERROR');
            return;
        }

        if ($options->data_type === 'form') {
            $ids = array();
            foreach($data as $item) {
                $ids[] = $item->__pk_val;
            }
            $this->formatDataToForm($ids);
            return;
        }

        if ((!empty($options->row_id)) && (is_array($options->row_id))) {
            $new_data = array();
            foreach($data as $item) {
                if (in_array($item->__pk_val, $options->row_id)) {
                    $new_data[] = $item;
                }
            }
            $data = $new_data;
        }

        $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_GET_LIST_DATA_SUCCESS');
        $this->response->data = $data;
    }

    private function formatDataToForm($ids) {
        $formModel = $this->fM;

        $data = array();
        foreach($ids as $id) {
            $formModel->setRowId($id);
            $data[] = $formModel->getData();
            $formModel->unsetData();
        }

        $this->response->msg = JText::sprintf('PLG_FABRIK_LIST_FABRIK_API_GET_LIST_DATA_SUCCESS');
        $this->response->data = $data;
    }

    private function addElement() {
        $options = $this->options;

        $element = new stdClass();
        $element->id                   = 0;
        $element->name                 = FabrikString::dbFieldName($options->name);
        $element->label                = JString::strtolower($options->label);
        $element->plugin               = $options->plugin;
        $element->group_id             = $options->group_id;
        $element->eval                 = 0;
        $element->published            = 1;
        $element->width                = 255;
        $element->created              = date('Y-m-d H:i:s');
        $element->created_by           = $this->user->get('id');
        $element->created_by_alias     = $this->user->get('username');
        $element->checked_out          = 0;
        $element->show_in_list_summary = 1;
        $element->ordering             = 0;

        $pluginManager = FabrikWorker::getPluginManager();
        $elementModel  = $pluginManager->getPlugIn($options->plugin, 'element');
        $params = json_decode($elementModel->getDefaultAttribs());

        foreach ($options->params as $key => $value) {
            if (!empty($value)) {
                $params->$key = $value;
            }
        }

        $element->params = json_encode($params);

        $db = JFactory::getDbo();
        $insert = $db->insertObject('#__fabrik_elements', $element, 'id'); // ERRO 404 (#___fabrik_elements)
        $modify = $this->modifyTable($element, $db->insertid());

        if (($insert) && ($modify)) {
            $this->response->msg = "Sucesso!";
        }
    }

    private function modifyTable($element, $element_id) {
        $options = $this->options;

        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($options->list_id);
        $table = $listModel->getTable()->db_table_name;

        $db = JFactory::getDbo();
        $query = "
            ALTER TABLE {$table}
            ADD COLUMN {$element->name} VARCHAR(255);
        ";
        $db->setQuery($query);
        $db->execute();

        $params = json_decode($element->params);
        if ($element->plugin === 'databasejoin') {
            if (($params->database_join_display_type === 'checkbox') || ($params->database_join_display_type === 'multilist')) {
                $this->addTableRepeat($element, $table, $element_id);
            }
        }
        else if ($element->plugin === 'fileupload') {
            if ((bool) $params->ajax_upload) {
                $this->addTableRepeat($element, $table, $element_id);
            }
        }
        else if (($element->plugin === 'survey') || ($element->plugin === 'tags')) {
            $this->addTableRepeat($element, $table, $element_id);
        }

        return true;
    }

    private function addTableRepeat($element, $table, $element_id) {
        $options = $this->options;

        $db = JFactory::getDbo();
        $query = "
            CREATE TABLE IF NOT EXISTS `{$table}_repeat_{$element->name}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `parent_id` int(11) DEFAULT NULL,
                `{$element->name}` VARCHAR(255) DEFAULT NULL,
                `params` text DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `fb_parent_fk_parent_id_INDEX` (`parent_id`)
            );
        ";
        $db->setQuery($query);
        $db->execute();

        $join = new stdClass();
        $join->id = 0;
        $join->list_id = $options->list_id;
        $join->element_id = $element_id;
        $join->join_from_table = $table;
        $join->table_join = $table . '_repeat_' . $element->name;
        $join->table_key = $element->name;
        $join->table_join_key = "parent_id";
        $join->join_type = "left";
        $join->group_id = $element->group_id;

        $params = new stdClass();
        $params->type = "repeatElement";
        $params->pk = "`{$table}_repeat_{$element->name}`.`id`";

        $join->params = json_encode($params);

        $db->insertObject("#__fabrik_joins", $join, 'id');
    }

    private function updateElement() {
        $options = $this->options;

        $element = new stdClass();
        $element->id = $options->element_id;
        $element->label = JString::strtolower($options->label);
        
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select("params")->from("#__fabrik_elements")->where("id = " . (int) $element->id);
        $db->setQuery($query);
        $params = $db->loadResult();
        $params = json_decode($params);

        foreach ($options->params as $key => $value) {
            if (!empty($value)) {
                $params->$key = $value;
            }
        }

        $element->params = json_encode($params);

        $db = JFactory::getDbo();
        $update = $db->updateObject('#__fabrik_elements', $element, 'id'); // ERRO 404 (#___fabrik_elements)

        if ($update) {
            $this->response->msg = "Sucesso!";
        }
    }
}
