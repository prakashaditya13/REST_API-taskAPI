<?php 

class TaskException extends Exception{ }

class Tasks{

    private $_id;
    private $_title;
    private $_description;
    private $_deadline;
    private $_completed;

    public function __construct($id,$title,$description,$deadline,$completed){
        $this->setId($id);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setDeadline($deadline);
        $this->setCompleted($completed);
    }

    public function getID(){
        return $this->_id;
    }

    public function getTitle(){
        return $this->_title;
    }

    public function getDescription(){
        return $this->_description;
    }

    public function getDeadline(){
        return $this->_deadline;
    }

    public function getStatus(){
        return $this->_completed;
    }

    public function setId($id){
        if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)){
            throw new TaskException("Task ID Error");
        }
        $this->_id = $id;
    }

    public function setTitle($title){
        if(strlen($title) < 0 || strlen($title) > 255){
            throw new TaskException("Task Title Error");
        }
        $this->_title = $title;
    }

    public function setDescription($description){
        if(($description !== null) && (strlen($description) > 16777215)){
            throw new TaskException("Task Description Error");
        }
        $this->_description = $description;
    }

    public function setDeadline($deadline){
        if(($deadline !== null) &&  date_format(date_create_from_format('d/m/Y H:i',$deadline), 'd/m/Y H:i') != $deadline){
            throw new TaskException("Task Deadline Error");
        }
        $this->_deadline = $deadline; 
    }
    public function setCompleted($completed){
        if(strtoupper($completed) !== 'Y' && strtoupper($completed) !== 'N'){
            throw new TaskException("Task Completion Error");
        }
        $this->_completed = $completed;
    }

    //Helper Method to convert the Task data into Array

    public function returnTaskAsArray(){
        $task = array();
        $task['id'] = $this->getID();
        $task['title'] = $this->getTitle();
        $task['description'] = $this->getDescription();
        $task['deadline'] = $this->getDeadline();
        $task['completed'] = $this->getStatus();
        return $task;
    }


}
