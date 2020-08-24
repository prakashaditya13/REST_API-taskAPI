<?php

require_once('db.php');
require_once('../model/Response.php');
require_once('../model/Tasks.php');

try{
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
}catch(PDOException $ex){
    error_log("Connection Error - ".$ex,0);
    $response = new Response();
    $response->sethttpstatuscode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}

//begin Auth Script
 
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
    $response = new Response();
    $response->sethttpstatuscode(401);
    $response->setSuccess(false);
    (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access Token is missing from the header") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
    $response->send();
    exit;
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];


try{
    $query = $writeDB->prepare('select userid, accesstokenexpiry, useractive, loginattempts from tblsessions, tblusers where tblsessions.userid = tblusers.id and accesstoken = :accesstoken');
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $response = new Response();
        $response->sethttpstatuscode(401);
        $response->setSuccess(false);
        $response->addMessage("Invalid Access Token");
        $response->send();
        exit();
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];

    if($returned_useractive !== 'Y'){
        $response = new Response();
        $response->sethttpstatuscode(401);
        $response->setSuccess(false);
        $response->addMessage("User Account is not active");
        $response->send();
        exit();
    }

    if($returned_loginattempts >= 3){
        $response = new Response();
        $response->sethttpstatuscode(401);
        $response->setSuccess(false);
        $response->addMessage("User Account is currently locked out");
        $response->send();
        exit();
    }

    if(strtotime($returned_accesstokenexpiry) < time()){
        $response = new Response();
        $response->sethttpstatuscode(401);
        $response->setSuccess(false);
        $response->addMessage("Access Token is expired");
        $response->send();
        exit();
    }
}catch(PDOException $ex){
    error_log("Database error - ".$ex,0);
    $response = new Response();
    $response->sethttpstatuscode(500);
    $response->setSuccess(false);
    $response->addMessage("There is an issue authoticating - Please try again");
    $response->send();
    exit();
}
//end Auth script

if(array_key_exists("taskid",$_GET)){
    $taskid = $_GET['taskid'];
    if($taskid == '' || !is_numeric($taskid)){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        $response->addMessage("Task ID cannot be blank or must be numeric");
        $response->send();
        exit;
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        try{
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where id = :taskid and userid = :userid');
            $query->bindParam(':taskid',$taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();
            
            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(404);
                $response->setSuccess(false);
                $response->addMessage("Task Not Found");
                $response->send();
                exit;
            }
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnTask = array();
            $returnTask['row_returned'] = $rowCount;
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->settoCache(true);
            $response->setData($returnTask);
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }    
        catch(PDOException $ex){
            error_log("Database Query Error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task");
            $response->send();
            exit();
        }
    }elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){

        try{
            $query = $writeDB->prepare('delete from tbltask where id = :taskid and userid = :userid');
            $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(404);
                $response->setSuccess(false);
                $response->addMessage("Task Not Found");
                $response->send();
                exit;
            }

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->addMessage("Task Deleted");
            $response->send();
            exit;

        }catch(PDOException $ex){
            error_log("Database Query Error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete Task");
            $response->send();
            exit();
        }
    }elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){

        try{

            if($_SERVER['CONTENT_TYPE'] !== "application/json"){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Content Type header not set to Json");
                $response->send();
                exit;
            }

            $rawPatchData = file_get_contents("php://input");

            if(!$jsonData = json_decode($rawPatchData)){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not valid JSON");
                $response->send();
                exit;
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queryField = "";

            if(isset($jsonData->title)){
                $title_updated = true;
                $queryField .= "title = :title, ";
            }
            if(isset($jsonData->description)){
                $description_updated = true;
                $queryField .= "description = :description, ";
            }
            if(isset($jsonData->deadline)){
                $deadline_updated = true;
                $queryField .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
            }
            if(isset($jsonData->completed)){
                $completed_updated = true;
                $queryField .= "completed = :completed, ";
            }

            $queryField = rtrim($queryField,", ");

            if($title_updated === false && $description_updated === false && $deadline_updated === false && $completed_updated === false){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("No task field is provided");
                $response->send();
                exit;
            }

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where id = :taskid nad userid = :userid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(404);
                $response->setSuccess(false);
                $response->addMessage("No such task of this is found");
                $response->send();
                exit;
            }


            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
            }

            $queryString = "update tbltask set ".$queryField." where id = :taskid and userid = :userid";
            $query = $writeDB->prepare($queryString);
            
            if($title_updated === true){
                $task->setTitle($jsonData->title);
                $up_title = $task->getTitle();
                $query->bindParam(':title', $up_title, PDO::PARAM_STR);
            }
            if($description_updated === true){
                $task->setDescription($jsonData->description);
                $up_description = $task->getDescription();
                $query->bindParam(':description', $up_description, PDO::PARAM_STR);
            }
            if($deadline_updated === true){
                $task->setDeadline($jsonData->deadline);
                $up_deadline = $task->getDeadline();
                $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
            }
            if($completed_updated === true){
                $task->setCompleted($jsonData->completed);
                $up_completed = $task->getStatus();
                $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
            }

            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Task not updated");
                $response->send();
                exit;
            }

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where id = :taskid and userid = :userid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found after update");
                $response->send();
                exit;
            }

            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnTask = array();
            $returnTask['row_returned'] = $rowCount;
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->addMessage("Task Updated");
            $response->setData($returnTask);
            $response->send();
            exit;






        }catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(400);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }catch(PDOException $ex){
            error_log("Database Query Error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update Task");
            $response->send();
            exit();
        }
    }else{
        $response = new Response();
        $response->sethttpstatuscode(405);
        $response->setSuccess(false);
        $response->addMessage("Request Method is not allowed");
        $response->send();
        exit();
    }
}elseif(array_key_exists("completed",$_GET)){
    $completed = $_GET['completed'];

    if($completed !== 'Y' && $completed !== 'N'){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        $response->addMessage("Filter must be Y and N");
        $response->send();
        exit;
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        try{

            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where completed = :completed and userid = :userid');
            $query->bindParam(':completed',$completed, PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnTask = array();
            $returnTask['row_returned'] = $rowCount;
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->settoCache(true);
            $response->setData($returnTask);
            $response->send();
            exit;

        }catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit();
        }
        catch(PDOException $ex){
            error_log("Database Query Error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get completed task");
            $response->send();
            exit();
        }
    }else{
        $response = new Response();
        $response->sethttpstatuscode(405);
        $response->setSuccess(false);
        $response->addMessage("Request Method is not allowed");
        $response->send();
        exit();
    }
}
elseif(array_key_exists("page",$_GET)){
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        $pages = $_GET['page'];

        if($pages == '' || !is_numeric($pages)){
            $response = new Response();
            $response->sethttpstatuscode(400);
            $response->setSuccess(false);
            $response->addMessage("Page number is not blanked or must be numeric");
            $response->send();
            exit;
        }

        $limitPageNumber = 20;

        try{
            $query = $readDB->prepare('select count(id) as totalnoTasks from tbltask where userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $tasksCount = intval($row['totalnoTasks']);
            $numOfPages = ceil($tasksCount/$limitPageNumber);

            if($numOfPages == 0){
                $numOfPages = 1;
            }

            if($pages > $numOfPages || $pages == 0){
                $response = new Response();
                $response->sethttpstatuscode(404);
                $response->setSuccess(false);
                $response->addMessage("Page not found");
                $response->send();
                exit;
            }
            
            $offset = ($pages == 1 ? 0 : ($limitPageNumber*($pages-1)));

            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where userid = :userid limit :pglimit offset :offset');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pglimit',$limitPageNumber,PDO::PARAM_INT);
            $query->bindParam(':offset',$offset,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnTask = array();
            $returnTask['row_returned'] = $rowCount;
            $returnTask['total_rows'] = $tasksCount;
            $returnTask['total_pages'] = $numOfPages;
            ($pages < $numOfPages ? $returnTask['has_next_page'] = true : $returnTask['has_next_page'] = false);
            ($pages > 1 ? $returnTask['has_previous_page'] = true : $returnTask['has_previous_page'] = false);
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->settoCache(true);
            $response->setData($returnTask);
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit();
        }
        catch(PDOException $ex){
            error_log("Database Query Error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get a task");
            $response->send();
            exit();
        }
    }
    else{
        $response = new Response();
        $response->sethttpstatuscode(405);
        $response->setSuccess(false);
        $response->addMessage("Request Method is not allowed");
        $response->send();
        exit;
    }
}

elseif(empty($_GET)){
    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        try{
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();
            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray(); 
            }

            $returnTask = array();
            $returnTask['rows_returned'] = $rowCount;
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->settoCache(true);
            $response->setData($returnTask);
            $response->send();
            exit;


        }catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;

        }catch(PDOException $ex){
            error_log("Database query error -".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get task");
            $response->send();
            exit;
        }
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'POST'){

        try{
            if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage('Content type header is not JSON type');
                $response->send();
                exit;
            }

            $rawPostData = file_get_contents('php://input');

            if(!$jsonData = json_decode($rawPostData)){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage('Request body is not valid JSON');
                $response->send();
                exit;
            }
            if(!isset($jsonData->title) || !isset($jsonData->completed)){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                (!isset($jsonData->title) ? $response->addMessage("Task title is manadatory") : false);
                (!isset($jsonData->completed) ? $response->addMessage("Task completion is manadatory") : false);
                $response->send();
                exit;
            }

            $newTask = new Tasks(null,$jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed);

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getStatus();

            $query = $writeDB->prepare('insert into tbltask (title,description,deadline,completed,userid) values(:title, :description, STR_TO_DATE(:deadline,\'%d/%m/%Y %H:%i\'), :completed, :userid)');
            $query->bindParam(':title',$title,PDO::PARAM_STR);
            $query->bindParam(':description',$description,PDO::PARAM_STR);
            $query->bindParam(':deadline',$deadline,PDO::PARAM_STR);
            $query->bindParam(':completed',$completed,PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to create task");
                $response->send();
                exit;
            }

            $lastTaskID = $writeDB->lastInsertId();

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") deadline, completed from tbltask where id = :taskid and userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':taskid', $lastTaskID,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve task after creation");
                $response->send();
                exit;
            }
            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Tasks($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $returnTask = array();
            $returnTask['row_returned'] = $rowCount;
            $returnTask['tasks'] = $taskArray;

            $response = new Response();
            $response->sethttpstatuscode(201);
            $response->setSuccess(true);
            $response->addMessage("Task Created successfully");
            $response->setData($returnTask);
            $response->send();
            exit;
        }catch(TaskException $ex){
            $response = new Response();
            $response->sethttpstatuscode(400);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }catch(PDOException $ex){
            error_log("Database query error -".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert task");
            $response->send();
            exit;
        }
    }
    else{
        $response = new Response();
        $response->sethttpstatuscode(405);
        $response->setSuccess(false);
        $response->addMessage("Request Method is not allowed");
        $response->send();
        exit();
    }
}
else{
    $response = new Response();
    $response->sethttpstatuscode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit;
}

