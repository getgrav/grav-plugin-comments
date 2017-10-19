<?php

namespace Grav\Plugin;

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
		$this->value['level'] = $level;
		$comments[] = $this->value;
		
		foreach($this->children as $child) {
			$comments[] = $child->getContent($level + 1);
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