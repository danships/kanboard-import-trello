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
	echo 'The user id is optional. If you provide it, cards and comments will be created with that userId.'.PHP_EOL;
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
// Adds the original trello user info to cards and comments
$addTrelloUserInfo = TRUE;

$jsonString = file_get_contents("https://trello.com/1/boards/".$trelloboard."?key=".$trellokey."&token=".$trellotoken);
$trelloObj = json_decode($jsonString);
if (empty($trelloObj)) {
	printf($jsonString);
	echo PHP_EOL;
	printf('Unable to parse JSON response for trelloObj, is it valid? %s', json_last_error_msg());
	echo PHP_EOL;
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

$dateFormatter = new IntlDateFormatter(
		#'de_DE',
		'en_US',
		IntlDateFormatter::LONG,
		IntlDateFormatter::LONG,
		#'Europe/Berlin',
		'America/Los_Angeles',
		IntlDateFormatter::GREGORIAN
);


//stats
$attachmentCount = 0;
$attachmentTotalSizeInBytes = 0;

//variables
$trelloLists = array();
$trelloLabels = array(); //we will store all label names, but not add them immediately, only when used
$trelloAttachments = array();
$trelloUsers = array();

//create the project
echo "Creating project '" . $trelloObj->name . "' ..." . PHP_EOL;
$projectId = $client->createProject($trelloObj->name);
$counter=0;
while (empty($projectId)) {
$projectId = $client->createProject($trelloObj->name.$counter++);
//  die("We could not create the project, perhaps it already exists?".PHP_EOL);
}
echo "Created project '" . $trelloObj->name . "' (projectId=$projectId)" . PHP_EOL;

if ($userId !== null) {
	$projectPermission = $client->addProjectUser($projectId, $userId, 'project-manager');
	if ($projectPermission === TRUE) {
		echo " Add user $userId as project-manager" . PHP_EOL;
	} else {
		echo " Could not add user $userId as project-manager!" . PHP_EOL;
		die(1);
	}
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
if (empty($trelloObjLists)) {
	printf($jsonString);
	echo PHP_EOL;
	printf('Unable to parse JSON response for trelloObjLists, is it valid? %s', json_last_error_msg());
	echo PHP_EOL;
	die(1);
}


//add the lists
echo "Found " . count($trelloObjLists) . " lists" . PHP_EOL;
foreach ($trelloObjLists as $list) {
	if ($list->closed) {
		// ignore archived lists
		echo "  List {$list->name} is closed/archived. Ignored!" . PHP_EOL;
		continue;
	}
	echo '  Creating list "' . $list->name . '"' . PHP_EOL;
	$columnId = $client->addColumn($projectId, $list->name);
	if ($columnId === false) {
		echo "Error creating column! (projectId=$projectId, name='{$list->name}')" . PHP_EOL;
		die(1);
	}
	$trelloLists[$list->id] = $columnId;

	//add each card
	$query="https://trello.com/1/lists/".$list->id."?key=".$trellokey."&token=".$trellotoken."&cards=open&card_fields=all&card_checklists=all&members=all&member_fields=all&membersInvited=all&checklists=all&organization=true&organization_fields=all&fields=all"; // &actions=commentCard,copyCommentCard&card_attachments=true";
	$jsonCards = file_get_contents($query);
	$trelloObjCards = json_decode($jsonCards);
	if (empty($trelloObjCards)) {
		printf($jsonString);
		echo PHP_EOL;
		printf('Unable to parse JSON response for trelloObjCards, is it valid? %s', json_last_error_msg());
		echo PHP_EOL;
		die(1);
	}

	echo "  Found " . count($trelloObjCards->cards) . " cards..." . PHP_EOL;

	foreach ($trelloObjCards->cards as $card) {
		addCard($projectId, $columnId, $card);
	}
}

echo 'All done!' . PHP_EOL;
echo "Project '" . $trelloObj->name . "' (projectId=$projectId)" . PHP_EOL;
echo "  Number of attachments: {$attachmentCount}" . PHP_EOL;
echo "  Total size of attachments: {$attachmentTotalSizeInBytes} bytes" . PHP_EOL;
die;

function resolveTrelloUserId($idMember)
{
global $trellokey;
global $trellotoken;
global $trelloUsers;
	if (isset($trelloUsers[$idMember])) {
		return $trelloUsers[$idMember];
	}

	echo "- Resolving trello user id {$idMember}..." . PHP_EOL;
	$jsonString = 
			@file_get_contents("https://api.trello.com/1/members/".$idMember.
				"?key=".$trellokey."&token=".$trellotoken."&fields=id,fullName,username");
	if ($jsonString === FALSE) {
		$memberDetails = "unknown";
	} else {
		$memberDetails = json_decode($jsonString);
		if (empty($memberDetails)) {
			printf($jsonString);
			echo PHP_EOL;
			printf('Unable to parse JSON response for memberDetails, is it valid? %s', json_last_error_msg());
			echo PHP_EOL;
			die(1);
		}
		$memberDetails = "{$memberDetails->username} ({$memberDetails->fullName})";
	}
	$trelloUsers[$idMember] = $memberDetails;
	echo "  -> {$memberDetails}" . PHP_EOL;
	return $memberDetails;
}

function formatActionMemberCreator($action)
{
global $dateFormatter;
	if ($action->memberCreator) {
		$memberDetails = "{$action->memberCreator->username} ({$action->memberCreator->fullName})";
	} else {
		$memberDetails = "unknown";
	}
	$formattedDate = $dateFormatter->format(new DateTimeImmutable($action->date));
	return "by {$memberDetails} on {$formattedDate}";
}

function addCard($projectId, $columnId, $card)
{
global $trellokey;
global $trellotoken;
global $trelloLabels;
global $client;
global $userId;
global $addTrelloUserInfo;
global $dateFormatter;

	if ($card->closed) {
		// ignore archived cards
		echo "    card '{$card->name}' is closed/archived -> ignored." . PHP_EOL;
		return;
	}

	# read all actions: https://developer.atlassian.com/cloud/trello/rest/api-group-boards/#api-boards-boardid-actions-get
	# types: https://developer.atlassian.com/cloud/trello/guides/rest-api/action-types/
	# interesting types: createCard, commentCard, copyCard, copyCommentCard
	$jsonString = 
		file_get_contents("https://trello.com/1/cards/".
			$card->shortLink."/actions?key=".$trellokey."&token=".$trellotoken."&filter=createCard,commentCard,copyCard,copyCommentCard&memberCreator=true&memberCreator_fields=fullName,username&limit=200");
	$cardActions = json_decode($jsonString);
	if (empty($cardActions)) {
		print_r($card);
		printf($jsonString);
		echo PHP_EOL;
		printf('Unable to parse JSON response for cardActions, is it valid? %s', json_last_error_msg());
		echo PHP_EOL;
		die(1);
	}

	$createdInfo = "";
	if ($addTrelloUserInfo === TRUE) {
		foreach($cardActions as $action) {
			if ($action->type === 'createCard') {
				$createdInfo = "\n\n--\nCreated " . formatActionMemberCreator($action);
			} else if ($action->type === 'copyCard') {
				$createdInfo = "\n\n--\nCopied " . formatActionMemberCreator($action);
			}
		}
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
			$name = $trelloLabel->name;
			if ($name == "") {
				$name = "({$colorId})";
			}
			$categoryId = $client->createCategory($projectId, $name);
			if ($categoryId === false) {
				echo "Error creating category! projectId=$projectId, name='$name'" . PHP_EOL;
				print_r($card);
				die(1);
			}
			echo "    Created category id=$categoryId projectId=$projectId name='$name'" . PHP_EOL;
			$trelloLabels[$trelloLabel->id] = $categoryId;
		}
	}

	$params = array(
		'title' => $card->name,
		'project_id' => $projectId,
		'column_id' => $columnId
	);
	if ($card->desc !== null) {
		$params['description'] = $card->desc . $createdInfo;
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
	if ($userId !== null) {
		$params['creator_id'] = $userId;
	}
	$taskId = $client->createTask($params);
	if ($taskId === false) {
		echo "Error creating task! " . print_r($params, true) . PHP_EOL;
		die(1);
	}
	echo '    Added card \'' . $card->name . '\' with id ' . $taskId . PHP_EOL;

	addComments($cardActions, $taskId);

	if ($card->badges->checkItems > 0) {
		$jsonString = 
			file_get_contents("https://trello.com/1/cards/".
				$card->shortLink."/checklists?key=".$trellokey."&token=".$trellotoken);
		$cardDetails = json_decode($jsonString);
		if (empty($cardDetails)) {
			printf($jsonString);
			echo PHP_EOL;
			printf('Unable to parse JSON response for checklists, is it valid? %s', json_last_error_msg());
			echo PHP_EOL;
			die(1);
		}
		addCheckItems($cardDetails, $taskId);
	}

	if ($card->badges->attachments > 0) {
		$jsonString = 
			file_get_contents("https://trello.com/1/cards/".
				$card->shortLink."/attachments?key=".$trellokey."&token=".$trellotoken);
		$cardDetails = json_decode($jsonString);
		if (empty($cardDetails)) {
			printf($jsonString);
			echo PHP_EOL;
			printf('Unable to parse JSON response for attachments, is it valid? %s', json_last_error_msg());
			echo PHP_EOL;
			die(1);
		}
		addAttachments($card, $cardDetails, $taskId, $projectId);
	}
}

//download attachments
function addAttachments($card, $cardDetails, $taskId, $projectId)
{
global $trellokey;
global $trellotoken;
global $userId;
global $client;
global $addTrelloUserInfo;
global $dateFormatter;
global $attachmentCount;
global $attachmentTotalSizeInBytes;

	echo "      Adding " . count($cardDetails) . " attachments..." . PHP_EOL;

	foreach ($cardDetails as $attachment) {
		if ($attachment->isUpload) {
			$filename = $taskId . '_' . $attachment->name;
			printf('        Downloading attachment for task %s to %s.%s', $card->name, $filename, PHP_EOL);

			//Here is the file we are downloading, replace spaces with %20
			echo "        Downloading from " . $attachment->url . PHP_EOL;
			$ch = curl_init($attachment->url);
		 
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		 
			//return file in variable
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: OAuth oauth_consumer_key=\"$trellokey\", oauth_token=\"$trellotoken\""]);
		 
			$data = curl_exec($ch);//get curl response
			if ($data === false || curl_error($ch)) {
				printf('Unable to download attachment: %s%s', curl_error($ch), PHP_EOL);
				die(1);
			}

			//done
			curl_close($ch);

			// upload file as an attachment
			$data_size = strlen($data);
			$blob = base64_encode($data);
			$blobSize = strlen($blob);
			echo "        Uploading $filename for task=$taskId projectId=$projectId data_size=$data_size blob size=$blobSize" . PHP_EOL;
			$attachmentCount += 1;
			$attachmentTotalSizeInBytes += $data_size;

			$fileId = $client->createTaskFile(array('task_id' => $taskId, 'filename' => $filename, 'project_id' => $projectId, 'blob' => $blob));
			if ($fileId === false) {
				echo "Error uploading file!" . PHP_EOL;
				die(1);
			}
			echo "        Attachment (fileId=$fileId) created" . PHP_EOL;
		} else {
			// just an url, add a comment
			$text = $attachment->url;
			$originalAuthor = "";
			if ($addTrelloUserInfo === TRUE) {
				$memberDetails = resolveTrelloUserId($attachment->idMember);
				$formattedDate = $dateFormatter->format(new DateTimeImmutable($attachment->date));
				$originalAuthor = "\n\n--\nCreated by {$memberDetails} on {$formattedDate}";
			}
			$commentId = $client->createComment(array('task_id' => $taskId, 'user_id' => $userId, 'content' => $text . $originalAuthor));
			if ($commentId === false) {
				echo "Error creating comment for attachment (task_id=$taskId, user_id=$userId, content=$text)!" . PHP_EOL;
				print_r($attachment);
				die(1);
			}
			echo "        Created comment for attachment (id=$commentId)" . PHP_EOL;
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

	echo "      Adding " . count($cardDetails) . " check lists" . PHP_EOL;
	foreach ($cardDetails as $checkList) {
		foreach ($checkList->checkItems as $checkItem) {
			$title = $checkList->name . ' - ' . $checkItem->name;
			$status = $checkItem->state === 'incomplete' ? $statusTodo : $statusDone;
			$subtaskId = $client->createSubtask(array('task_id' => $taskId, 'title' => $title, 'status' => $status));
			if ($subtaskId === false) {
				echo "Error creating subtask! (task_id=$taskId, title=$title, status=$status)" . PHP_EOL;
				die(1);
			}
		}
	}
}

function addComments($cardActions, $taskId)
{
global $userId;
global $client;
global $addTrelloUserInfo;
global $dateFormatter;
	$commentCount = 0;
	foreach ($cardActions as $action) {
		if ($action->type === 'commentCard' || $action->type === 'copyCommentCard') {
			$commentCount += 1;
		}
	}
	if ($commentCount === 0) {
		return;
	}

	echo "      Adding {$commentCount} comments..." . PHP_EOL;
	foreach ($cardActions as $comment) {
		if ($comment->type === 'commentCard' || $comment->type === 'copyCommentCard') {
			$text = $comment->data->text;
			$originalAuthor = "";
			if ($addTrelloUserInfo === TRUE) {
				$originalAuthor = "\n\n--\nComment created " . formatActionMemberCreator($comment);
			}
			$commentId = $client->createComment(array('task_id' => $taskId, 'user_id' => $userId, 'content' => $text . $originalAuthor));
			if ($commentId === false) {
				echo "Error creating comment! (task_id=$taskId, user_id=$userId, content length=" . strlen($text) . ")" . PHP_EOL;
				die(1);
			}
			echo "        Created comment (id=$commentId)" . PHP_EOL;
		}
	}
}

