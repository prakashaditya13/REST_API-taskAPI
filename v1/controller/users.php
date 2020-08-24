<?php

require_once('db.php');
require_once('../model/Response.php');

try{

    $writeDB = DB::connectWriteDB();


}catch(PDOException $ex){
    error_log("Connection error -".$ex,0);
    $response = new Response();
    $response->sethttpstatuscode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    $response = new Response();
    $response->sethttpstatuscode(405);
    $response->setSuccess(false);
    $response->addMessage("Request Method is not allowed");
    $response->send();
    exit;
}

if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
    $response = new Response();
    $response->sethttpstatuscode(400);
    $response->setSuccess(false);
    $response->addMessage("Content type must be application/json");
    $response->send();
    exit;
}

$rawPostData = file_get_contents('php://input');

if(!$jsonData = json_decode($rawPostData)){
    $response = new Response();
    $response->sethttpstatuscode(400);
    $response->setSuccess(false);
    $response->addMessage("Request Body is not valid JSON");
    $response->send();
    exit;
}

if(!isset($jsonData->fullname) || !isset($jsonData->username) || !isset($jsonData->password)){
    $response = new Response();
    $response->sethttpstatuscode(400);
    $response->setSuccess(false);
    (!isset($jsonData->fullname) ? $response->addMessage("Fullname is not supplied") : false);
    (!isset($jsonData->username) ? $response->addMessage("Username is not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password is not supplied") : false);
    $response->send();
    exit;
}

if(strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
    $response = new Response();
    $response->sethttpstatuscode(400);
    $response->setSuccess(false);
    (strlen($jsonData->fullname) < 1 ? $response->addMessage("Fullname cannot be blank") : false);
    (strlen($jsonData->fullname) > 255 ? $response->addMessage("Fullname cannot exceeds 255 characters") : false);
    (strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
    (strlen($jsonData->username) > 255 ? $response->addMessage("Fullname cannot exceeds 255 characters") : false);
    (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
    (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot exceeds 255 characters") : false);

    $response->send();
    exit;
}

$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try{

    $query = $writeDB->prepare('select id from tblusers where username = :username');
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();
    if($rowCount !== 0){
        $response = new Response();
        $response->sethttpstatuscode(409);
        $response->setSuccess(false);
        $response->addMessage("Username is already exists");
        $response->send();
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $query = $writeDB->prepare('insert into tblusers (fullname, username, password) values (:fullname, :username, :password)');
    $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $response = new Response();
        $response->sethttpstatuscode(500);
        $response->setSuccess(false);
        $response->addMessage("There is an issue to create account");
        $response->send();
        exit;
    }

    $lastUserId = $writeDB->lastInsertId();

    $returnData = array();
    $returnData['User_ID'] = $lastUserId;
    $returnData['fullname'] = $fullname;
    $returnData['username'] = $username;

    $response = new Response();
    $response->sethttpstatuscode(201);
    $response->setSuccess(true);
    $response->setData($returnData);
    $response->addMessage("User created Successfuly");
    $response->send();
    exit;







}catch(PDOException $ex){
    error_log("Database query error - ".$ex,0);
    $response = new Response();
    $response->sethttpstatuscode(500);
    $response->setSuccess(false);
    $response->addMessage("Their was an issue related to query, Please try again");
    $response->send();
    exit;
}
