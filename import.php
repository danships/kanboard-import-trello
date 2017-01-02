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

if ($argc !== 6 && $argc !== 7) {
	echo 'Use this small tool to import your Trello JSON export into your kanboard, using it JSON-RPC interface.' . PHP_EOL;
	printf('Usage: php %s http://server/jsonrpc.php apitoken trellokey trellotoken trelloboard [userId]%s', $argv[0], PHP_EOL);
	echo 'To get the Trello key and token, login to Trello and go to https://trello.com/app-key'.PHP_EOL;
	echo 'The user id is optional. If you provide it, comments will be created with that userId.'.PHP_EOL;
	die;
}

$server = $argv[1];
$token = $argv[2];
$trellokey = $argv[3];
$trellotoken = $argv[4];
$trelloboard = $argv[5];
$userId = null;
if (isset($argv[6])) {
	$userId = $argv[6];
}

$jsonString = file_get_contents("https://trello.com/1/boards/".$trelloboard."?key=".$trellokey."&token=".$trellotoken);
$trelloObj = json_decode($jsonString);
if (empty($trelloObj)) {
	printf($jsonString);
	printf('Unable to parse JSON response, is it valid? %s', json_last_error_msg());
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

$users = $client->getAllUsers();
foreach ($users as $user) {
	if ($user['username'] == $userId || $userId == null) {
		$userId = $user['id'];
	}
}

if (!is_array($projects)) {
	echo 'Unable to fetch the list of projects, is the server url / token correct?' . PHP_EOL;
	die(1);
}

//variables
$trelloLists = array();
$trelloLabels = array(); //we will store all label names, but not add them immediately, only when used
$trelloAttachments = array();

//create the project
echo 'Creating project.' . PHP_EOL;
$projectId = $client->createProject($trelloObj->name);
$counter=0;
while (empty($projectId)) {
$projectId = $client->createProject($trelloObj->name.$counter++);
//  die("We could not create the project, perhaps it already exists?".PHP_EOL);
}

//remove the columns created by default
$columns = $client->getColumns($projectId);
foreach ($columns as $column) {
	$client->removeColumn($column['id']);
}

//set the public/private status of the project
if ($trelloObj->prefs->permissionLevel=="private") {
        echo "project is private".PHP_EOL;
	// $client->updateProject(array('id' => $projectId, 'is_public' => false));
}

# will only get lists that are not archived
$jsonString = file_get_contents("https://trello.com/1/boards/".$trelloboard."/lists?key=".$trellokey."&token=".$trellotoken);
$trelloObjLists = json_decode($jsonString);

//add the lists
echo 'Adding lists.' . PHP_EOL;
foreach ($trelloObjLists as $list) {
	if ($list->closed) {
		// ignore archived lists
		continue;
	}
	echo 'Creating list '.$list->name.PHP_EOL;
	$columnId = $client->addColumn($projectId, $list->name);
	$trelloLists[$list->id] = $columnId;

	//add each card
	echo 'Adding cards.' . PHP_EOL;
        $query="https://trello.com/1/lists/".$list->id."?key=".$trellokey."&token=".$trellotoken."&cards=open&card_fields=all&card_checklists=all&members=all&member_fields=all&membersInvited=all&checklists=all&organization=true&organization_fields=all&fields=all"; // &actions=commentCard,copyCommentCard&card_attachments=true";
        $jsonCards = file_get_contents($query);
	$trelloObjCards = json_decode($jsonCards);

	foreach ($trelloObjCards->cards as $card) {
		addCard($projectId, $columnId, $card);
	}
}

echo 'All done!' . PHP_EOL;
die;

function addCard($projectId, $columnId, $card)
{
global $trellokey;
global $trellotoken;
global $trelloLabels;
global $client;
global $userId;

	if ($card->closed) {
		// ignore archived cards
		return;
	}

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
	//TODO temporary disabled, seems to cause errors when addings tasks in later Kanboard versions >1.0.30
	/*if ($userId !== null) {
		$params['owner_id'] = $userId;
	}*/
	$taskId = $client->createTask($params);

	if ($card->badges->comments > 0) {
		$jsonString = 
			file_get_contents("https://trello.com/1/cards/".
				$card->shortLink."/actions?key=".$trellokey."&token=".$trellotoken."&filter=all");
		$cardDetails = json_decode($jsonString);
		addComments($cardDetails, $taskId);
	}

	if ($card->badges->checkItems > 0) {
		$jsonString = 
			file_get_contents("https://trello.com/1/cards/".
				$card->shortLink."/checklists?key=".$trellokey."&token=".$trellotoken);
		$cardDetails = json_decode($jsonString);
		addCheckItems($cardDetails, $taskId);
	}

	if ($card->badges->attachments > 0) {
		$jsonString = 
			file_get_contents("https://trello.com/1/cards/".
				$card->shortLink."/attachments?key=".$trellokey."&token=".$trellotoken);
		$cardDetails = json_decode($jsonString);
		addAttachments($card, $cardDetails, $taskId, $projectId);
	}
}

//download attachments
function addAttachments($card, $cardDetails, $taskId, $projectId)
{
global $userId;
global $client;

	foreach ($cardDetails as $attachment) {
		if ($attachment->isUpload) {
			$filename = $taskId . '_' . $attachment->name;
			printf('Downloading attachment for task %s to %s.%s', $card->name, $filename, PHP_EOL);

			//Here is the file we are downloading, replace spaces with %20
			$ch = curl_init($attachment->url);
		 
			curl_setopt($ch, CURLOPT_TIMEOUT, 50);
		 
			//return file in variable
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		 
			$data = curl_exec($ch);//get curl response
			if ($data === false) {
				printf('Unable to download attachment: %s%s', curl_error($ch), PHP_EOL);
			}

			//done
			curl_close($ch);

			// upload file as an attachment
			$client->createTaskFile(array('task_id' => $taskId, 'filename' => $filename, 'project_id' => $projectId, 'blob' => base64_encode($data)));
		} else {
			// just an url, add a comment
			$text = $attachment->url;
			$client->createComment(array('task_id' => $taskId, 'user_id' => $userId, 'content' => $text));
		}
	}
}

//add checklists as subtasks
function addCheckItems($cardDetails, $taskId)
{
global $userId;
global $client;

$statusTodo = 0;
$statusDone = 2;

	foreach ($cardDetails as $checkList) {
		foreach ($checkList->checkItems as $checkItem) {
			$title = $checkList->name . ' - ' . $checkItem->name;
			$status = $checkItem->state === 'incomplete' ? $statusTodo : $statusDone;
			$client->createSubtask(array('task_id' => $taskId, 'title' => $title, 'status' => $status));
		}
	}
}

function addComments($cardDetails, $taskId)
{
global $userId;
global $client;
	foreach ($cardDetails as $comment) {
		if ($comment->type === 'commentCard') {
			$text = $comment->data->text;
			$client->createComment(array('task_id' => $taskId, 'user_id' => $userId, 'content' => $text));
		}
	}
}

