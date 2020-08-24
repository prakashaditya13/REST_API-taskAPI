<?php 

require_once('db.php');
require_once('../model/Response.php');

try{
    $writeDB = DB::connectWriteDB();
}catch(PDOException $ex){
    error_log("Database Connection error - ".$ex,0);
    $response = new Response();
    $response->sethttpstatuscode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit;
}

if(array_key_exists("sessionid",$_GET)){

    $sessionid = $_GET['sessionid'];

    if($sessionid === '' || !is_numeric($sessionid)){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        ($sessionid === '' ? $response->addMessage("Sesion ID must not be blank") :false);
        (!is_numeric($sessionid) ? $response->addMessage("Sesion ID must be numeric") :false);
        $response->send();
        exit;
    }

    if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
        $response = new Response();
        $response->sethttpstatuscode(401);
        $response->setSuccess(false);
        (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access Token is missing from the header") : false);
        (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access Token cannot be blank") : false);
        $response->send();
        exit;
    }

    $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

    if($_SERVER['REQUEST_METHOD'] === 'DELETE'){

        try{

            $query = $writeDB->prepare('delete from tblsessions where id = :sessionid and accesstoken = :accesstoken');
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to logout of this session");
                $response->send();
                exit;
            }

            $returnData = array();

            $returnData['session_id'] = intval($sessionid);

            $response = new Response();
            $response->sethttpstatuscode(200);
            $response->setSuccess(true);
            $response->setData($returnData);
            $response->addMessage("Logged out");
            $response->send();
            exit;
        }catch(PDOException $ex){
            error_log("Database error - ".$ex,0);
            $response = new Response();
            $response->sethttpstatuscode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue with logging out - Please try again");
            $response->send();
            exit;

        }
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){

            if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header cannot set to JSON");
                $response->send();
                exit;
            }

            $rawPatchData = file_get_contents('php://input');

            if(!$jsonData = json_decode($rawPatchData)){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                $response->addMessage("Request Body is not a valid JSON");
                $response->send();
                exit;
            }

            if(!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1){
                $response = new Response();
                $response->sethttpstatuscode(400);
                $response->setSuccess(false);
                (!isset($jsonData->refresh_token) ? $response->addMessage("Refresh Token is not Provided") : false);
                (strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh Token cannot be blank") : false);
                $response->send();
                exit;
            }

            try{
                $refreshtoken = $jsonData->refresh_token;

                $query = $writeDB->prepare('select tblsessions.id as sessionid, tblsessions.userid as userid, accesstoken, refreshtoken, useractive, loginattempts, accesstokenexpiry, refreshtokenexpiry from tblsessions, tblusers where tblusers.id = tblsessions.userid and tblsessions.id = :sessionid and tblsessions.accesstoken = :accesstoken and tblsessions.refreshtoken = :refreshtoken');
                $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
                $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
                $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
                $query->execute();
                $rowCount = $query->rowCount();

                if($rowCount === 0){
                    $response = new Response();
                    $response->sethttpstatuscode(401);
                    $response->setSuccess(false);
                    $response->addMessage("Access Token and Refresh Token is incorrect for the current session");
                    $response->send();
                    exit;
                }

                $row = $query->fetch(PDO::FETCH_ASSOC);
                $returned_sessionid = $row['sessionid'];
                $returned_userid = $row['userid'];
                $returned_accesstoken = $row['accesstoken'];
                $returned_refreshtoken = $row['refreshtoken'];
                $returned_useractive = $row['useractive'];
                $returned_loginattempts = $row['loginattempts'];
                $returned_accesstokenexpiry = $row['accesstokenexpiry'];
                $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

                if($returned_useractive !== 'Y'){
                    $response = new Response();
                    $response->sethttpstatuscode(401);
                    $response->setSuccess(false);
                    $response->addMessage("User account is not active");
                    $response->send();
                    exit;
                }

                if($returned_loginattempts >=3){
                    $response = new Response();
                    $response->sethttpstatuscode(401);
                    $response->setSuccess(false);
                    $response->addMessage("User account is locked out");
                    $response->send();
                    exit;
                }

                if(strtotime($returned_refreshtokenexpiry) < time()){
                    $response = new Response();
                    $response->sethttpstatuscode(401);
                    $response->setSuccess(false);
                    $response->addMessage("Refresh Token is expired - Please Login again");
                    $response->send();
                    exit;
                }

                $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));
                $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));

                $access_token_expiry_seconds = 1200;
                $refresh_token_expiry_seconds = 1209600;

                $query = $writeDB->prepare('update tblsessions set accesstoken = :accesstoken, accesstokenexpiry = date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), refreshtoken = :refreshtoken, refreshtokenexpiry = date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND) where id = :sessionid and userid = :userid and accesstoken = :returnedaccesstoken and refreshtoken = :returnedrefreshtoken');
                $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
                $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
                $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
                $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
                $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
                $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
                $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);

                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount === 0){
                    $response = new Response();
                    $response->sethttpstatuscode(401);
                    $response->setSuccess(false);
                    $response->addMessage("Access could not be refreshed - Please login again");
                    $response->send();
                    exit;
                }

                $returnData = array();

                $returnData['session_id'] = $returned_sessionid;
                $returnData['accesstoken'] = $accesstoken;
                $returnData['access_token_expiry'] = $access_token_expiry_seconds;
                $returnData['refreshtoken'] = $refreshtoken;
                $returnData['refresh_token_expiry'] = $refresh_token_expiry_seconds;

                $response = new Response();
                $response->sethttpstatuscode(200);
                $response->setSuccess(true);
                $response->setData($returnData);
                $response->addMessage("Token refreshed");
                $response->send();
                exit;
            }catch(PDOException $ex){
                $response = new Response();
                $response->sethttpstatuscode(500);
                $response->setSuccess(false);
                $response->addMessage("There was an issue to refreshing a token");
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
        exit;
    }
}
elseif(empty($_GET)){

    if($_SERVER['REQUEST_METHOD'] !== 'POST'){
        $response = new Response();
        $response->sethttpstatuscode(405);
        $response->setSuccess(false);
        $response->addMessage("Request Method is not allowed");
        $response->send();
        exit;
    }

    sleep(1);

    if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        $response->addMessage("Request Data is not valid JSON format");
        $response->send();
        exit;
    }

    $rawPostData = file_get_contents('php://input');

    if(!$jsonData = json_decode($rawPostData)){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        $response->addMessage("RequestBody is not Valid JSON");
        $response->send();
        exit;
    }

    if(!isset($jsonData->username) || !isset($jsonData->password)){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        (!isset($jsondata->username) ? $response->addMessage("Username is not provided") : false);
        (!isset($jsondata->password) ? $response->addMessage("Password is not provided") : false);
        $response->send();
        exit;
    }

    if(strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
        $response = new Response();
        $response->sethttpstatuscode(400);
        $response->setSuccess(false);
        (strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be blank") : false);
        (strlen($jsonData->username) > 255 ? $response->addMessage("Fullname cannot exceeds 255 characters") : false);
        (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
        (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot exceeds 255 characters") : false);
        $response->send();
        exit;
    }

    try{

        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDB->prepare('select id, fullname, username, password, useractive, loginattempts from tblusers where username = :username');
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            $response = new Response();
            $response->sethttpstatuscode(401);
            $response->setSuccess(false);
            $response->addMessage("Username and password is incorrect");
            $response->send();
            exit;
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);
        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_userActive = $row['useractive'];
        $returned_loginAttempts = $row['loginattempts'];

        if($returned_userActive !== 'Y'){
            $response = new Response();
            $response->sethttpstatuscode(401);
            $response->setSuccess(false);
            $response->addMessage("User account is not active");
            $response->send();
            exit;
        }

        if($returned_loginAttempts >= 3){
            $response = new Response();
            $response->sethttpstatuscode(401);
            $response->setSuccess(false);
            $response->addMessage("User account is currently locked out");
            $response->send();
            exit;
        }

        if(!password_verify($password, $returned_password)){
            $query = $writeDB->prepare('update tblusers set loginattempts = loginattempts+1 where id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->sethttpstatuscode(401);
            $response->setSuccess(false);
            $response->addMessage("Username and Password is incorrect");
            $response->send();
            exit;
        }

      $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
      $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

      $access_token_expiry_seconds = 1200;
      $refresh_token_expiry_seconds = 1209600; //14 days expiry
    }catch(PDOException $ex){
        $response = new Response();
        $response->sethttpstatuscode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in");
        $response->send();
        exit;
    }

    try {
        
        $writeDB->beginTransaction();
        $query = $writeDB->prepare('update tblusers set loginattempts = 0 where id = :id');
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDB->prepare('insert into tblsessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) values (:userid, :accesstoken, date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))');
        $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
        $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
        $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
        $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionId = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = array();
        $returnData['session_id'] = intval($lastSessionId);
        $returnData['access_token'] = $accesstoken;
        $returnData['access_token_expire'] = $access_token_expiry_seconds;
        $returnData['refresh_token'] = $refreshtoken;
        $returnData['access_token_expire'] = $access_token_expiry_seconds;
        $returnData['refresh_token_expire'] = $refresh_token_expiry_seconds;

        $response = new Response();
        $response->sethttpstatuscode(201);
        $response->setSuccess(true);
        $response->setData($returnData);
        $response->send();
        exit;

    } catch (PDOException $ex) {
        $writeDB->rollBack();
        $response = new Response();
        $response->sethttpstatuscode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in...please try again");
        $response->send();
        exit;
    }
}
else{
    $response = new Response();
    $response->sethttpstatuscode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint Not Found");
    $response->send();
    exit;
}