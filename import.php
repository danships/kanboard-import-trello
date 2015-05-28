<?php
require_once(dirname(__FILE__) . '/vendor/autoload.php');
use JsonRPC\Client;

require_once(dirname(__FILE__) . '/util.php');
setTimezone("GMT");

/**------ FUNCTIONS ---------**/
if (!function_exists('json_last_error_msg')) {
    function json_last_error_msg() {
        static $errors = array(
            JSON_ERROR_NONE             => null,
            JSON_ERROR_DEPTH            => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH   => 'Underflow or the modes mismatch',
            JSON_ERROR_CTRL_CHAR        => 'Unexpected control character found',
            JSON_ERROR_SYNTAX           => 'Syntax error, malformed JSON',
            JSON_ERROR_UTF8             => 'Malformed UTF-8 characters, possibly incorrectly encoded'
        );
        $error = json_last_error();
        return array_key_exists($error, $errors) ? $errors[$error] : "Unknown error ({$error})";
    }
}
/**-------- END FUNCTIONS --------------**/

if ($argc !== 4 && $argc !== 5) {
	echo 'Use this small tool to import your Trello JSON export into your kanboard, using it JSON-RPC interface.' . PHP_EOL;
	printf('Usage: php %s http://server/jsonrpc.php apitoken jsonfile%s [userId]', $argv[0], PHP_EOL);
	echo 'The user id is optional. If you provide it, comments will be created with that userId.';
	die;
}

$server = $argv[1];
$token = $argv[2];
$jsonFile = $argv[3];
$userId = null;
if (isset($argv[4])) {
	$userId = $argv[4];
}

if (!is_readable($jsonFile)) {
	printf('Unable to read file %s!', $jsonFile);
	exit(1);
}

$jsonString = file_get_contents($jsonFile);
$trelloObj = json_decode($jsonString);
if (empty($trelloObj)) {
	printf('Unable to parse JSON file %s, is it valid? %s', $jsonFile, json_last_error_msg());
	die(1);
}

//initialize the client
$client = new Client($server);
$client->authentication('jsonrpc', $token);

//verify that we can connect
$projects = null;
try {
	$projects = $client->getAllProjects();
} catch(RuntimeException $e) {
	$projects = null; //explicitly set it to null, to trigger an error
}

if (!is_array($projects)) {
	echo 'Unable to fetch the list of projects, is the server url / token correct?' . PHP_EOL;
	die(1);
}

//variables
$trelloLists = array();
$trelloLabels = array(); //we will store all label names, but not add them immediately, only when used
$trelloCards = array();
$trelloAttachments = array();

//create the project
echo 'Creating project.' . PHP_EOL;
$projectId = $client->createProject($trelloObj->name);

//remove the columns created by default
$columns = $client->getColumns($projectId);
foreach ($columns as $column) {
	$client->removeColumn($column['id']);
}

//set the public/private status of the project
if ($trelloObj->closed) {
	$client->updateProject(array('id' => $projectId, 'is_public' => false));
}

//add the lists
echo 'Adding lists.' . PHP_EOL;
foreach ($trelloObj->lists as $list) {
	if ($list->closed) {
		// ignore archived lists
		continue;
	}
	$columnId = $client->addColumn($projectId, $list->name);
	$trelloLists[$list->id] = $columnId;
}

//add each card
echo 'Adding cards.' . PHP_EOL;
foreach ($trelloObj->cards as $card) {
	if ($card->closed) {
		// ignore archived cards
		continue;
	}

	if (!array_key_exists($card->idList, $trelloLists)) {
		// the list is closed
		continue;
	}

	$columnId = $trelloLists[$card->idList];

	$dueDate = $card->due !== null ? date('Y-m-d', strtotime($card->due)) : null;
	
	//Kanboard supports only one category, take the first one of the Trello labels
	$colorId = null;
	$categoryId = null;
	if (count($card->labels) > 0) {
		$trelloLabel = $card->labels[0];
		$colorId = $trelloLabel->color;
		if (isset($trelloLabels[$trelloLabel->id])) {
			$categoryId = $trelloLabels[$trelloLabel->id];
		} else {
			$categoryId = $client->createCategory($projectId, $trelloLabel->name);
			$trelloLabels[$trelloLabel->id] = $categoryId;
		}
	}
	
	$params = array(
			'title' => $card->name,
			'project_id' => $projectId,
			'column_id' => $columnId
	);
	if ($card->desc !== null) {
		$params['description'] = $card->desc;
	}
	if ($dueDate !== null) {
		$params['date_due'] = $dueDate;
	}
	if ($colorId !== null) {
		$params['color_id'] = $colorId;
	}
	if ($categoryId !== null) {
		$params['category_id'] = $categoryId;
	}
	$taskId = $client->createTask($params);
	$trelloCards[$card->id] = $taskId;
	
	//download attachments
	if (count($card->attachments) > 0) {
		foreach ($card->attachments as $attachment) {
			$filename = $taskId . '_' . $attachment->name;
			printf('Downloading attachment for task %s to %s.%s', $card->name, $filename, PHP_EOL);
			$fpOut = fopen($filename, 'w');
			
			//Here is the file we are downloading, replace spaces with %20
			$ch = curl_init($attachment->url);
			 
			curl_setopt($ch, CURLOPT_TIMEOUT, 50);
			 
			//give curl the file pointer so that it can write to it
			curl_setopt($ch, CURLOPT_FILE, $fpOut);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			 
			$data = curl_exec($ch);//get curl response
			if ($data === false) {
				printf('Unable to download attachment: %s%s', curl_error($ch), PHP_EOL);
			}
			 
			//done
			curl_close($ch);
		}
	}
}

//add checklists as subtasks
echo 'Adding checklists.' . PHP_EOL;
$statusTodo = 0;
$statusDone = 2;
foreach ($trelloObj->checklists as $checkList) {
	foreach ($checkList->checkItems as $checkItem) {
		if (!array_key_exists($checkList->idCard, $trelloCards)) {
			// card is closed
			continue;
		}
		$title = $checkList->name . ' - ' . $checkItem->name;
		$taskId = $trelloCards[$checkList->idCard];
		$status = $checkItem->state === 'incomplete' ? $statusTodo : $statusDone;
		$client->createSubtask(array('task_id' => $taskId, 'title' => $title, 'status' => $status));
	}
}

//process all actions to see if there are comment Actions
if ($userId !== null) {
	echo 'Processing comments.' . PHP_EOL;
	foreach ($trelloObj->actions as $action) {
		if ($action->type === 'commentCard') {
			if (!array_key_exists($action->data->card->id, $trelloCards)) {
				// card is closed
				continue;
			}
			$taskId = $trelloCards[$action->data->card->id];
			$text = $action->data->text;
			$client->createComment($taskId, $userId, $text);
		}
	}
}

echo 'All done!' . PHP_EOL;
die;
