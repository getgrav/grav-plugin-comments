<?php

class Comment 
{
    private $id = 0;
    private $value = array();
    private $parent = null;
    private $children = array();

	public function __construct($id, $content) {
		$this->id = $id;
		$this->value = $content;
	}
	
    public function addItem($obj, $key = null) {
    }

    public function deleteItem($key) {
    }

    public function getItem($key) {
    }

    public function getContent($level = 0) {
		$comments = $this->value;
		$comments['level'] = $level;
		
		foreach($this->children as $child) {
			array_merge($comments, $child->getContent($level + 1));
		}
		return $comments;
    }

	public function setParent($parent) {
		$this->parent = $parent;
    }
    public function addSubComment($obj) {
		$this->children[] = $obj;
    }

}